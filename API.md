# Warehouse Service API

خدمة المستودع تعرض واجهة **gRPC** (service اسمه `Warehouse`). الملف التعريفي: `proto/warehouse.proto`.

## 1. الاتصال

| البند | القيمة |
|---|---|
| البروتوكول | gRPC (HTTP/2)، بدون TLS حالياً (insecure) |
| المنفذ الافتراضي | `50051` (يتغير بـ `WAREHOUSE_GRPC_PORT`) |
| الـ package | بدون package، والـ service اسمه `Warehouse` |

## 2. العمليات

| العملية | الوصف | idempotent |
|---|---|---|
| `GetStock` | يرجّع الكمية المتوفرة لكل منتج | نعم (قراءة) |
| `Reserve` | يحجز كميات لطلب كامل، بشكل كامل أو لا شيء | نعم (حسب order_id) |
| `Confirm` | يحوّل الحجز من RESERVED إلى CONFIRMED | نعم |
| `Release` | يلغي الحجز ويرجّع الكميات (من RESERVED فقط) | نعم |

## 3. الرسائل

### GetStock

```proto
message StockRequest  { repeated int32 product_ids = 1; }
message StockResponse { repeated StockItem items = 1; }
message StockItem     { int32 product_id = 1; int32 quantity = 2; }
```

- المنتجات المكررة بتنحذف، والنتيجة مرتبة حسب `product_id`.
- منتج غير موجود في المخزون بيرجع بكمية `0` (ما بيعطي خطأ).
- قائمة فاضية بترجع `items` فاضية.
- `product_id` لازم يكون رقم موجب، وإلا `INVALID_ARGUMENT`.

### Reserve

```proto
message ReserveRequest  { int32 order_id = 1; repeated StockItem items = 2; }
message ReserveResponse { bool success = 1; string reason = 2; }
```

- `order_id` لازم يكون موجب.
- `items` لازم تكون غير فاضية، وكل `quantity` لازم يكون أكبر من 0، وإلا `INVALID_ARGUMENT`.
- نفس `product_id` ممكن يجي مرتين بالقائمة، والخدمة بتجمع الكميات.
- الحجز كله بـ transaction واحدة: إذا منتج واحد ما يكفي، ما بينخصم شي ويرجع `success=false`.

| الحالة | success | reason |
|---|---|---|
| تم الحجز | true | (فاضي) |
| الكمية غير كافية | false | `insufficient stock for product <id>: requested <n>, available <m>` |
| المنتج غير موجود | false | `product <id> not found in stock` |
| الطلب محجوز مسبقاً (RESERVED أو CONFIRMED) | true | `already reserved` |
| الطلب محجوز ثم أُلغي | false | `reservation was already released` |

### Confirm

```proto
message ConfirmRequest  { int32 order_id = 1; }
message ConfirmResponse { bool success = 1; string reason = 2; }
```

| الحالة | success | reason |
|---|---|---|
| تحويل من RESERVED إلى CONFIRMED | true | (فاضي) |
| الحجز already CONFIRMED | true | `already confirmed` |
| الحجز غير موجود | false | `reservation not found` |
| الحجز RELEASED | false | `cannot confirm reservation in status RELEASED` |

### Release

```proto
message ReleaseRequest  { int32 order_id = 1; }
message ReleaseResponse { bool success = 1; string reason = 2; }
```

| الحالة | success | reason |
|---|---|---|
| الحجز RESERVED، تم الإرجاع للمخزون | true | (فاضي) |
| الحجز already RELEASED | true | `already released` |
| الحجز CONFIRMED | false | `cannot release a confirmed reservation` |
| الحجز غير موجود | false | `reservation not found` |

## 4. الأخطاء (gRPC status codes)

الرفض المنطقي (كمية غير كافية، حجز غير موجود...) بيرجع كـ `success=false` مع `reason`، وما بيرمي خطأ gRPC. الأخطاء gRPC بتكون فقط لحالات تقنية:

| الكود | متى | إجراء العميل |
|---|---|---|
| `OK` | الطلب نُفّذ (حتى لو success=false) | اقرأ `success` و `reason` |
| `INVALID_ARGUMENT` | قيمة غير صالحة (order_id سالب، كمية صفر، items فاضية) | صحّح الطلب، لا تعيد الإرسال |
| `INTERNAL` | خطأ داخلي (مثلاً قاعدة البيانات) | أعد المحاولة بعد فترة |
| `UNAVAILABLE` | الخدمة متوقفة أو ما وصلت | أعد المحاولة، وإذا استمر اعرض رسالة للمستخدم |
| `DEADLINE_EXCEEDED` | انتهت المهلة | أعد المحاولة (العمليات idempotent) |

## 5. توصيات للعميل (Laravel وغيره)

- **المهلة (deadline):** 3 ثواني لكل استدعاء.
- **إعادة المحاولة:** آمنة للعمليات الأربع لأنها idempotent. أعد المحاولة عند `UNAVAILABLE` و `DEADLINE_EXCEEDED` فقط، مع حد أقصى 2 أو 3 محاولات.
- **دورة الطلب:**
  1. `Reserve` عند إنشاء الطلب (pending).
  2. `Confirm` بعد تأكيد الطلب.
  3. `Release` عند إلغاء الطلب قبل التأكيد.
- **إذا الحجز نجح بس فشل حفظ الطلب عندك:** استدعِ `Release` فوراً.

## 6. أمثلة

### Python

```python
import grpc
from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc

with grpc.insecure_channel("localhost:50051") as channel:
    stub = pb_grpc.WarehouseStub(channel)

    stock = stub.GetStock(pb.StockRequest(product_ids=[1, 2]), timeout=3)

    reply = stub.Reserve(
        pb.ReserveRequest(order_id=5001, items=[pb.StockItem(product_id=1, quantity=2)]),
        timeout=3,
    )
    if not reply.success:
        print("رفض:", reply.reason)

    stub.Confirm(pb.ConfirmRequest(order_id=5001), timeout=3)
    # أو عند الإلغاء قبل التأكيد:
    # stub.Release(pb.ReleaseRequest(order_id=5001), timeout=3)
```

### PHP (Laravel)

```php
$client = new \App\Grpc\WarehouseClient('warehouse:50051', [
    'credentials' => \Grpc\ChannelCredentials::createInsecure(),
]);

$request = new \App\Grpc\ReserveRequest();
$request->setOrderId($order->id);
// أضف العناصر عبر setItems([...])

[$response, $status] = $client->Reserve($request, [], ['timeout' => 3000000])->wait();

if ($status->code !== \Grpc\STATUS_OK) {
    // UNAVAILABLE أو DEADLINE_EXCEEDED: رسالة نهائية للمستخدم
} elseif (!$response->getSuccess()) {
    // اعرض $response->getReason()
}
```

## 7. أدوات تجربة يدوية

- **grpcurl:** `grpcurl -plaintext -proto proto/warehouse.proto -d '{"product_ids":[1]}' localhost:50051 warehouse.Warehouse/GetStock`
  (ملاحظة: لو الـ proto بدون package، الاسم الكامل بيكون `Warehouse/GetStock`. جرّب الاثنين إذا فشل الأول.)
- **BloomRPC / Postman (gRPC):** حمّل الـ proto وشغّل الطلبات من الواجهة.

## 8. الإصدار

الإصدار الحالي: v1. أي تغيير يكسر التوافق (حذف حقل، تغيير نوع) يحتاج إصدار جديد من الـ proto.
