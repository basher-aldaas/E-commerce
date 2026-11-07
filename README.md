# خدمة المستودع (Warehouse Service)

خدمة gRPC مستقلة (Python) لإدارة المخزون والحجوزات. تستخدم MySQL بقاعدة منفصلة `warehouse`.

## الهيكل

```
proto/warehouse.proto        تعريف الواجهة
generate_proto.sh            يولّد كلاسات Python من الـ proto
sql/schema.sql               جداول قاعدة warehouse
app/config.py                الإعدادات من متغيرات البيئة
app/db.py                    اتصال MySQL و transaction
app/repository.py            استعلامات SQL
app/inventory_service.py     منطق الحجز (بدون gRPC)
app/grpc_server.py           تحويل رسائل gRPC للخدمة
app/cli.py                   init-db و seed
tests/                       اختبارات وحدة + race condition + gRPC end-to-end
client_example.py            مثال عميل للتجربة اليدوية
```

## الإعداد

```bash
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt
./generate_proto.sh
```

## متغيرات البيئة

| المتغير | الافتراضي |
|---|---|
| WAREHOUSE_DB_HOST | 127.0.0.1 |
| WAREHOUSE_DB_PORT | 3306 |
| WAREHOUSE_DB_USER | warehouse |
| WAREHOUSE_DB_PASSWORD | (فارغ) |
| WAREHOUSE_DB_NAME | warehouse |
| WAREHOUSE_GRPC_PORT | 50051 |

## قاعدة البيانات

أنشئ قاعدة `warehouse` ومستخدم MySQL له صلاحيات عليها فقط، وبعدين:

```bash
python -m app.cli init-db
python -m app.cli seed
```

## التشغيل

```bash
python -m app.grpc_server
```

## الاختبارات

اختبارات الـ race condition بتكتب على قاعدة الاختبار، فاستخدم قاعدة منفصلة مش الحقيقية:

```bash
WAREHOUSE_DB_NAME=warehouse_test python -m app.cli init-db
WAREHOUSE_DB_NAME=warehouse_test python -m unittest discover -s tests -t . -v
```

## ملاحظات

- الحجز يعمل transaction واحدة، والخصم بشرط `quantity >= n` داخل نفس الـ UPDATE، فما بيصير overselling.
- `order_id` فريد، فإعادة الإرسال ما بتخصم مرتين.
- Release يرجّع الكميات من RESERVED فقط. على CONFIRMED بيرجّع `success=false`.
- الخدمة ما بتقرا أو تكتب على قاعدة مشروع الزميل.
