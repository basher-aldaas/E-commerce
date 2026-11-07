# خدمة المستودع (Warehouse Service)

خدمة gRPC مستقلة (Python) لإدارة المخزون والحجوزات. تستخدم MySQL بقاعدة منفصلة `warehouse`.

## الهيكل

```
proto/warehouse.proto        تعريف الواجهة
generate_proto.sh            يولّد كلاسات Python من الـ proto
sql/schema.sql               جداول قاعدة warehouse
sql/migrations/              تعديلات الجداول على قاعدة موجودة
app/config.py                الإعدادات من متغيرات البيئة
app/db.py                    اتصال MySQL و transaction
app/repository.py            استعلامات SQL
app/inventory_service.py     منطق الحجز (بدون gRPC)
app/grpc_server.py           تحويل رسائل gRPC للخدمة
app/cli.py                   init-db و seed و migrate
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
| WAREHOUSE_DB_CONNECT_TIMEOUT | 2 |
| WAREHOUSE_DB_IO_TIMEOUT | 5 |
| WAREHOUSE_DB_LOCK_WAIT_TIMEOUT | 2 |
| WAREHOUSE_GRPC_HOST | 127.0.0.1 |
| WAREHOUSE_GRPC_PORT | 50051 |

## قاعدة البيانات

أنشئ قاعدة `warehouse` ومستخدم MySQL له صلاحيات عليها فقط، وبعدين:

```bash
python -m app.cli init-db
python -m app.cli seed
```

### قاعدة موجودة من إصدار سابق

`init-db` لا يعدّل جداول موجودة، فالقاعدة القديمة تحتاج migration. الأمر يحافظ على الصفوف الحالية، ويمكن تشغيله أكثر من مرة، ويرفض العمل على قاعدة لا ينتهي اسمها بـ `_test` بدون الخيار الصريح. خذ نسخة احتياطية أولاً:

```bash
python -m app.cli migrate --apply-to-real-database
```

شغّل الـ migration قبل تشغيل هذا الإصدار من السيرفر على القاعدة القديمة.

## التشغيل

```bash
python -m app.grpc_server
```

## الاختبارات

الاختبارات بتحذف كل الصفوف، فبترفض تشتغل إلا إذا اسم القاعدة بينتهي بـ `_test`:

```bash
WAREHOUSE_DB_NAME=warehouse_test python -m app.cli init-db
WAREHOUSE_DB_NAME=warehouse_test python -m unittest discover -s tests -t . -v
```

## ملاحظات

- الحجز يعمل transaction واحدة، والخصم بشرط `quantity >= n` داخل نفس الـ UPDATE، فما بيصير overselling.
- `reservation_key` فريد، فإعادة الإرسال ما بتخصم مرتين، ونفس المفتاح بمنتجات مختلفة بينرفض.
- Release يرجّع الكميات من RESERVED فقط، و ReturnStock يرجّعها من CONFIRMED فقط.
- Release على مفتاح غير موجود بيسجله كملغى، فالـ Reserve المتأخر بينرفض وما بياخد مخزون.
- InitializeStock ما بيغيّر مخزون موجود. SetStock تعديل إداري مقصود.
- الخدمة ما بتقرا أو تكتب على قاعدة مشروع الزميل، وما بتستورد كميات منه.
