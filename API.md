# Warehouse Service API

خدمة المستودع تعرض واجهة **gRPC** (service اسمه `Warehouse`). الملف التعريفي: `proto/warehouse.proto`.

## 1. الاتصال

| البند | القيمة |
|---|---|
| البروتوكول | gRPC (HTTP/2)، بدون TLS وبدون مصادقة |
| العنوان الافتراضي | `127.0.0.1:50051` (يتغير بـ `WAREHOUSE_GRPC_HOST` و `WAREHOUSE_GRPC_PORT`) |
| الـ package | بدون package، فمسار العملية مثل `/Warehouse/GetStock` |

الخدمة لا تملك مصادقة، لذلك تستمع محلياً فقط افتراضياً. لا تعرضها على الشبكة العامة؛ المستخدمون يصلون للمخزون عبر Laravel فقط.

## 2. العمليات

| العملية | الوصف | إعادة الإرسال آمنة |
|---|---|---|
| `GetStock` | يرجّع الكمية المتوفرة لكل منتج | نعم (قراءة) |
| `Reserve` | يحجز كميات طلب كامل، كلها أو لا شيء | نعم (حسب `reservation_key`) |
| `Confirm` | يحوّل الحجز من RESERVED إلى CONFIRMED | نعم |
| `Release` | يلغي حجزاً غير مؤكد ويرجّع كمياته | نعم |
| `ReturnStock` | يرجّع كميات حجز مؤكد، مثلاً عند إلغاء الطلب | نعم |
| `InitializeStock` | ينشئ مخزون منتج جديد، ولا يغيّر مخزوناً موجوداً | نعم (يرجع الكمية الحالية) |
| `SetStock` | يضبط الكمية المتوفرة لمنتج | نعم (قيمة مطلقة) |

### حالات الحجز

```
Reserve -> RESERVED --Confirm--> CONFIRMED --ReturnStock--> RETURNED
              |
              +--Release--> RELEASED
```

## 3. الرسائل

كل رد فيه `success` و `reason` و `code`. اعتمد على `code` في البرمجة، لأن `reason` نص توضيحي للسجلات وقد يتغير.

| `code` | المعنى |
|---|---|
| `RESULT_OK` | تمت العملية |
| `RESULT_INSUFFICIENT_STOCK` | الكمية غير كافية |
| `RESULT_PRODUCT_NOT_FOUND` | لا يوجد مخزون مسجل لهذا المنتج |
| `RESULT_RESERVATION_NOT_FOUND` | لا يوجد حجز بهذا المفتاح |
| `RESULT_KEY_CONFLICT` | المفتاح مستخدم مع منتجات أو كميات مختلفة |
| `RESULT_ALREADY_RELEASED` | الحجز ملغى مسبقاً |
| `RESULT_INVALID_STATE` | حالة الحجز لا تسمح بهذه العملية |
| `RESULT_ALREADY_INITIALIZED` | مخزون المنتج موجود مسبقاً |

### GetStock

```proto
message StockRequest  { repeated int32 product_ids = 1; }
message StockResponse { repeated StockLevel items = 1; }
message StockLevel    { int32 product_id = 1; int32 quantity = 2; bool tracked = 3; }
```

- المنتجات المكررة تُحذف، والنتيجة مرتبة حسب `product_id`.
- منتج بلا مخزون مسجل يرجع بكمية `0` و `tracked=false`.
- `product_id` لازم يكون رقماً موجباً، وإلا `INVALID_ARGUMENT`.

### Reserve

```proto
message ReserveRequest  { repeated StockItem items = 2; string reservation_key = 3; }
message ReserveResponse { bool success = 1; string reason = 2; ResultCode code = 3; StockShortage shortage = 4; }
message StockItem       { int32 product_id = 1; int32 quantity = 2; }
message StockShortage   { int32 product_id = 1; int32 requested = 2; int32 available = 3; }
```

- `reservation_key` هو UUID يولّده العميل قبل إنشاء الطلب.
- `items` لازم تكون غير فارغة وكل `quantity` أكبر من 0، وإلا `INVALID_ARGUMENT`.
- الحجز كله في transaction واحدة: إذا منتج واحد لا يكفي، لا يُخصم شيء.

| الحالة | success | code |
|---|---|---|
| تم الحجز | true | `RESULT_OK` |
| إعادة إرسال بنفس المفتاح والمنتجات | true | `RESULT_OK` (`already reserved`) |
| الكمية غير كافية | false | `RESULT_INSUFFICIENT_STOCK` مع `shortage` |
| لا يوجد مخزون للمنتج | false | `RESULT_PRODUCT_NOT_FOUND` مع `shortage` |
| نفس المفتاح بمنتجات مختلفة | false | `RESULT_KEY_CONFLICT` |
| المفتاح ملغى مسبقاً | false | `RESULT_ALREADY_RELEASED` |
| تم إرجاع مخزون الحجز | false | `RESULT_INVALID_STATE` |

### Confirm

```proto
message ConfirmRequest  { string reservation_key = 2; }
message ConfirmResponse { bool success = 1; string reason = 2; ResultCode code = 3; }
```

| الحالة | success | code |
|---|---|---|
| من RESERVED إلى CONFIRMED | true | `RESULT_OK` |
| مؤكد مسبقاً | true | `RESULT_OK` (`already confirmed`) |
| الحجز غير موجود | false | `RESULT_RESERVATION_NOT_FOUND` |
| الحجز ملغى | false | `RESULT_ALREADY_RELEASED` |
| تم إرجاع مخزون الحجز | false | `RESULT_INVALID_STATE` |

### Release

```proto
message ReleaseRequest  { string reservation_key = 2; }
message ReleaseResponse { bool success = 1; string reason = 2; ResultCode code = 3; }
```

| الحالة | success | code |
|---|---|---|
| RESERVED، تم إرجاع الكميات | true | `RESULT_OK` |
| ملغى مسبقاً | true | `RESULT_OK` (`already released`) |
| المفتاح غير موجود | true | `RESULT_OK`، ويُسجل المفتاح كملغى |
| CONFIRMED أو RETURNED | false | `RESULT_INVALID_STATE` |

**لماذا يُسجل مفتاح غير موجود؟** بعد انتهاء المهلة قد يكون `Reserve` ما زال في الطريق. لو لم نسجل المفتاح، سيصل `Reserve` بعد `Release` ويحجز كمية لن يرجعها أحد. بعد التسجيل يُرفض `Reserve` المتأخر بـ `RESULT_ALREADY_RELEASED`.

### ReturnStock

```proto
message ReturnStockRequest  { string reservation_key = 1; }
message ReturnStockResponse { bool success = 1; string reason = 2; ResultCode code = 3; }
```

| الحالة | success | code |
|---|---|---|
| CONFIRMED، تم إرجاع الكميات | true | `RESULT_OK` |
| تم الإرجاع مسبقاً | true | `RESULT_OK` (`already returned`) |
| الحجز غير موجود | false | `RESULT_RESERVATION_NOT_FOUND` |
| RESERVED أو RELEASED | false | `RESULT_INVALID_STATE` |

### InitializeStock

```proto
message InitializeStockRequest  { int32 product_id = 1; int32 quantity = 2; }
message InitializeStockResponse { bool success = 1; string reason = 2; ResultCode code = 3; int32 quantity = 4; }
```

| الحالة | success | code | quantity |
|---|---|---|---|
| تم إنشاء المخزون | true | `RESULT_OK` | الكمية الجديدة |
| المخزون موجود مسبقاً (لا يتغير) | false | `RESULT_ALREADY_INITIALIZED` | الكمية الحالية |

الكمية `0` مسموحة، والكمية السالبة ترجع `INVALID_ARGUMENT`.

### SetStock

```proto
message SetStockRequest  { int32 product_id = 1; int32 quantity = 2; }
message SetStockResponse { bool success = 1; string reason = 2; ResultCode code = 3; int32 quantity = 4; }
```

يستبدل الكمية المتوفرة أو ينشئها إن لم تكن موجودة. هذا تعديل إداري مقصود؛ للمنتجات الجديدة استخدم `InitializeStock`. الحجوزات الحالية لا تتأثر لأن كمياتها مخصومة أصلاً.

## 4. الأخطاء (gRPC status codes)

الرفض المنطقي يرجع `success=false` مع `code`، ولا يرمي خطأ gRPC. أخطاء gRPC للحالات التقنية فقط:

| الكود | متى | إجراء العميل |
|---|---|---|
| `OK` | نُفذ الطلب (حتى لو success=false) | اقرأ `success` و `code` |
| `INVALID_ARGUMENT` | قيمة غير صالحة | صحّح الطلب ولا تُعد الإرسال |
| `INTERNAL` | خطأ داخلي، مثل قاعدة البيانات | النتيجة غير معروفة؛ أعد المحاولة بنفس المفتاح |
| `UNAVAILABLE` | الخدمة متوقفة أو لا يمكن الوصول إليها | النتيجة غير معروفة؛ أعد المحاولة بنفس المفتاح |
| `DEADLINE_EXCEEDED` | انتهت المهلة | النتيجة غير معروفة؛ أعد المحاولة بنفس المفتاح |

انتهاء المهلة **لا يعني** أن العملية فشلت؛ قد تكون نجحت والرد لم يصل.

## 5. توصيات للعميل (Laravel وغيره)

- **المهلة (deadline):** 3 ثوانٍ لكل استدعاء.
- **إعادة المحاولة:** عند `UNAVAILABLE` و `DEADLINE_EXCEEDED` فقط، بنفس المفتاح، وبحد أقصى محدود.
- **لا تستدعِ gRPC داخل database transaction:** قاعدة العميل لا تستطيع التراجع عن عملية تمت في المستودع.
- **دورة الطلب:**
  1. ولّد `reservation_key` واحفظه محلياً.
  2. `Reserve` بكل منتجات الطلب.
  3. احفظ الطلب وتفاصيله في transaction.
  4. `Confirm` بعد نجاح الحفظ.
- **إذا نجح الحجز وفشل حفظ الطلب:** استدعِ `Release`.
- **إذا انتهت مهلة `Reserve` نهائياً:** استدعِ `Release`، فهو آمن حتى لو لم يصل الحجز.
- **إذا أُلغي طلب مؤكد:** استدعِ `ReturnStock`.

## 6. أمثلة

### Python

```python
import uuid

import grpc
from app import warehouse_pb2 as pb
from app import warehouse_pb2_grpc as pb_grpc

with grpc.insecure_channel("localhost:50051") as channel:
    stub = pb_grpc.WarehouseStub(channel)
    key = str(uuid.uuid4())

    stock = stub.GetStock(pb.StockRequest(product_ids=[1, 2]), timeout=3)

    reply = stub.Reserve(
        pb.ReserveRequest(reservation_key=key, items=[pb.StockItem(product_id=1, quantity=2)]),
        timeout=3,
    )
    if not reply.success:
        print("رفض:", reply.code, reply.reason)
    else:
        stub.Confirm(pb.ConfirmRequest(reservation_key=key), timeout=3)
```

### PHP

الكلاسات المولدة ضمن namespace `App\Grpc\Warehouse`. المهلة في PHP بالميكروثانية.

```php
use App\Grpc\Warehouse\ReserveRequest;
use App\Grpc\Warehouse\StockItem;

$request = new ReserveRequest();
$request->setReservationKey($key);
$request->setItems([
    (new StockItem())->setProductId(1)->setQuantity(2),
]);

[$response, $status] = $client->Reserve($request, [], ['timeout' => 3_000_000])->wait();

if ($status->code !== \Grpc\STATUS_OK) {
    // UNAVAILABLE أو DEADLINE_EXCEEDED: النتيجة غير معروفة
} elseif (! $response->getSuccess()) {
    // اقرأ $response->getCode() و $response->getShortage()
}
```

## 7. أدوات تجربة يدوية

- **grpcurl:** `grpcurl -plaintext -proto proto/warehouse.proto -d '{"product_ids":[1]}' localhost:50051 Warehouse/GetStock`
- **Postman (gRPC):** حمّل الـ proto وشغّل الطلبات من الواجهة.
- **client_example.py:** يقرأ المخزون فقط. الخيار `--reserve-demo` يحجز ويلغي وحدة على قاعدة البيانات التي يستخدمها السيرفر.

## 8. الإصدار

الإصدار الحالي: v2. تغيّر عن v1: استُبدل `order_id` بـ `reservation_key`، وأُضيفت `ReturnStock` و `InitializeStock` و `SetStock` و `code` و `shortage` و `tracked`، وصار `Release` يسجل المفاتيح غير الموجودة.
