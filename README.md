# E-Commerce & Warehouse System

نظام تجارة إلكترونية مبني باستخدام **Laravel**، مع خدمة مستقلة لإدارة المستودعات مبنية باستخدام **Python + gRPC** (grpcio و PyMySQL).

يعمل المشروعان باستخدام Docker Compose، ولكل خدمة قاعدة بيانات MySQL مستقلة.

---

## 1. Project Architecture

يتكون النظام من مشروعين مستقلين داخل Repository واحد على GitHub (`https://github.com/basher-aldaas/E-commerce.git`)، لكن كل مشروع موجود على Branch مختلف.

| Project | Branch | Technology |
|---|---|---|
| E-Commerce | `feature/secondRequirement` | Laravel 12 / PHP |
| Warehouse Service | `feature/laravel-integration` | Python / gRPC (grpcio, PyMySQL) |

### Folder Structure

يجب أن يكون ترتيب المجلدات بالشكل التالي:

```text
eco/
│
├── E-commerce/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── .env
│   ├── docker/
│   └── Laravel files
│
└── warehouse-service/
    ├── Dockerfile
    ├── proto/warehouse.proto
    ├── requirements.txt
    └── app/ (gRPC server, inventory logic, CLI)
```

**Important:** يجب أن يكون المشروعان في نفس المجلد الأب حتى يستطيع Docker الوصول إلى Warehouse Service باستخدام المسار النسبي.

---

## 2. Requirements

قبل البدء، يجب تثبيت البرامج التالية:

- Git
- Docker Desktop
- Docker Compose (يأتي مع Docker Desktop)

بعد التثبيت، شغّل Docker Desktop وتأكد أن Docker يعمل.

للتحقق:

```powershell
docker --version
docker compose version
```

---

## 3. Clone Both Projects

افتح PowerShell ونفّذ الأوامر التالية:

### Step 1: Create the Main Folder

```powershell
mkdir "$HOME\Desktop\eco"
cd "$HOME\Desktop\eco"
```

### Step 2: Clone Laravel Project

```powershell
git clone -c core.longpaths=true -b feature/secondRequirement https://github.com/basher-aldaas/E-commerce.git E-commerce
```

**Note:** الخيار `-c core.longpaths=true` ضروري على Windows، لأن أحد ملفات Postman داخل المشروع مساره أطول من 260 حرفاً. بدونه يفشل الـ clone.

### Step 3: Clone Warehouse Project

```powershell
git clone -b feature/laravel-integration https://github.com/basher-aldaas/E-commerce.git warehouse-service
```

الآن أصبح لديك المشروعان في مجلدين منفصلين.

---

## 4. Configure Laravel Environment

ادخل إلى مجلد Laravel:

```powershell
cd E-commerce
```

أنشئ ملف `.env` اعتماداً على الملف النموذجي:

```powershell
Copy-Item .env.example .env
```

افتح ملف `.env` وأضف السطر التالي:

```env
WAREHOUSE_SERVICE_PATH=../warehouse-service
```

هذا المسار يسمح لـ Docker Compose بالوصول إلى مشروع Warehouse أثناء بناء صورة Docker الخاصة به.

**Important:** لا تضع هذا المسار داخل Dockerfile. إنه متغير يستخدمه Docker Compose لتحديد مكان ملفات المشروع على جهازك.

تأكد أيضاً من إعدادات قاعدة البيانات واتصال gRPC الموجودة في `.env` وملف `docker-compose.yml`.

---

## 5. Build and Start Docker Containers

تأكد أنك داخل مجلد `E-commerce` وأن Docker Desktop يعمل.

نفّذ:

```powershell
docker compose up -d --build
```

يقوم هذا الأمر بـ:

1. بناء صورة Laravel.
2. بناء صورة Warehouse Service.
3. إنشاء حاوية MySQL الخاصة بـ Laravel.
4. إنشاء حاوية MySQL الخاصة بـ Warehouse.
5. إنشاء حاوية phpMyAdmin.
6. تشغيل الخدمات وفق ترتيب الاعتماد والـ Health Checks المحدد في Docker Compose.

**Note:** أول عملية Build قد تستغرق وقتاً أطول لأنها تحتاج إلى تنزيل الصور والحزم وبناء الخدمات.

---

## 6. Monitor Docker Containers

افتح Terminal جديداً، وادخل إلى مجلد `E-commerce` نفسه.

لمتابعة سجلات التشغيل:

```powershell
docker compose logs -f
```

لمتابعة Laravel فقط:

```powershell
docker compose logs -f app
```

لمتابعة Warehouse:

```powershell
docker compose logs -f warehouse
```

لمشاهدة جميع الحاويات:

```powershell
docker compose ps -a
```

للخروج من متابعة السجلات اضغط:

```text
CTRL + C
```

هذا لا يوقف الحاويات؛ يوقف متابعة السجلات فقط.

---

## 7. Generate Laravel Application Keys

بعد تشغيل حاوية Laravel، نفّذ الأوامر التالية لأول تشغيل فقط:

### Generate APP_KEY

```powershell
docker compose exec app php artisan key:generate
```

### Generate JWT Secret

```powershell
docker compose exec app php artisan jwt:secret
```

هذه المفاتيح خاصة ببيئة التشغيل الجديدة، ولا ينبغي مشاركة قيمها أو رفع ملف `.env` إلى GitHub.

بعد ذلك أعد تشغيل Laravel:

```powershell
docker compose restart app
```

---

## 8. Access the Applications

بعد نجاح تشغيل الحاويات، يمكنك الوصول إلى الخدمات من المتصفح:

| Service | URL |
|---|---|
| Laravel E-Commerce API | http://localhost:8000 |
| phpMyAdmin | http://localhost:8080 |

---

## 9. Database Configuration

يحتوي النظام على قاعدتي بيانات مستقلتين، وكل واحدة تعمل داخل MySQL Container منفصل.

### Database 1: Laravel MySQL

| Setting | Value |
|---|---|
| Container | `ecommerce-mysql` |
| Service | `mysql` |
| Database | `E-commerce` |
| Username | `root` |
| Password | Empty |
| Internal Port | `3306` |
| External Port | `3307` |

### Database 2: Warehouse MySQL

| Setting | Value |
|---|---|
| Container | `ecommerce-warehouse-mysql` |
| Service | `warehouse-mysql` |
| Database | `warehouse` |
| Username | `warehouse` |
| Password | `warehouse` |
| Internal Port | `3306` |
| External Port | Not exposed |

**Important:** قاعدة بيانات Warehouse غير مكشوفة مباشرة على جهاز المضيف؛ تتصل بها خدمة Warehouse عبر شبكة Docker الداخلية.

### Access Databases Through phpMyAdmin

افتح:

http://localhost:8080

ستجد خياري الاتصال:

**Laravel Database**

```text
Server: mysql
Username: root
Password: (leave empty)
```

**Warehouse Database**

```text
Server: warehouse-mysql
Username: warehouse
Password: warehouse
```

اختر السيرفر المناسب عند تسجيل الدخول.

---

## 10. How Laravel Communicates With Warehouse

تتواصل Laravel مع Warehouse باستخدام gRPC عبر شبكة Docker الداخلية.

```text
                 Client / Flutter
                        |
                        v
                  Laravel API
                   Port 8000
                        |
                        | gRPC
                        | warehouse:50051
                        v
                Warehouse Service
                  Python / gRPC
                        |
                        v
                 Warehouse MySQL
```

عنوان الاتصال داخل Docker:

```env
WAREHOUSE_GRPC_ENABLED=true
WAREHOUSE_GRPC_TARGET=warehouse:50051
```

**Important:**

- `warehouse` هو اسم الخدمة داخل Docker Compose.
- `50051` هو منفذ gRPC الداخلي.
- لا تحتاج إلى نشر منفذ gRPC على جهازك للوصول إليه من Laravel.
- لا تستخدم `localhost` بدلاً من `warehouse` داخل حاوية Laravel؛ لأن localhost يشير إلى حاوية Laravel نفسها.

---

## 10.1 عقد gRPC (ثلاث عمليات فقط)

المستودع هو المصدر الوحيد لكميات المخزون. Laravel لا يخزّن أي كمية ولا يملك أي endpoint لتعديلها.

| العملية | الاستخدام في Laravel |
|---|---|
| `GetStock` | عرض الكمية الحية في صفحات المنتجات (`stock` و `stock_status`) |
| `Reserve` | حجز كل منتجات الطلب دفعة واحدة، كلها أو لا شيء، بنفس الكميات المطلوبة |
| `Release` | التعويض عند فشل حفظ الطلب بعد نجاح الحجز، وتحرير الحجز عند إلغاء الطلب |

`stock_status` في استجابة المنتج: `in_stock` أو `out_of_stock` أو `not_stocked` (المنتج غير مسجل في المستودع: "Not stocked yet") أو `unavailable` (تعذّر سؤال المستودع؛ الصفحة تبقى تعمل).

عند نقص المخزون يرجع `POST /api/v1/orders` الكود `409` مع كل المنتجات الناقصة في `data.items` (`product_id`, `name`, `requested_quantity`, `available_quantity`, `stocked`). الكمية المطلوبة لا تُعدَّل أبداً.

### إدارة المخزون (من CLI المستودع فقط)

```powershell
docker compose exec warehouse python -m app.cli show-stock
docker compose exec warehouse python -m app.cli set-stock 5 40
```

المنتج الجديد الذي يُنشأ من Laravel يظهر "Not stocked yet" إلى أن يضيف مشغّل المستودع كميته بـ `set-stock`.

### توليد ملفات gRPC من `proto/warehouse.proto`

الملف الوحيد للعقد موجود في `warehouse-service/proto/warehouse.proto`. الملفات المولّدة لا تُعدَّل يدوياً.

**Python** (داخل `warehouse-service`، أداة موجودة في المشروع):

```bash
./generate_proto.sh
```

**PHP** (من مجلد الأب الذي يحتوي `E-commerce` و `warehouse-service`، بحاوية مؤقتة؛ تستخدم `protoc` الرسمي من protocolbuffers/protobuf و `grpc_php_plugin` من حزمة Debian `protobuf-compiler-grpc`):

```bash
docker run --rm -v "$PWD/warehouse-service/proto:/proto:ro" -v "$PWD/php-gen:/out" debian:bookworm-slim sh -c '
  apt-get update -qq && apt-get install -y -qq --no-install-recommends protobuf-compiler-grpc curl unzip ca-certificates >/dev/null &&
  curl -fsSL -o /tmp/protoc.zip https://github.com/protocolbuffers/protobuf/releases/download/v36.2/protoc-36.2-linux-x86_64.zip &&
  unzip -q /tmp/protoc.zip -d /opt/protoc &&
  /opt/protoc/bin/protoc -I /proto --php_out=/out --grpc_out=/out --plugin=protoc-gen-grpc=/usr/bin/grpc_php_plugin /proto/warehouse.proto'
```

ثم استبدل `E-commerce/app/Grpc/Warehouse` بمحتوى `php-gen/App/Grpc/Warehouse` (يشمل الـ client المولّد `WarehouseClient.php`)، وشغّل الاختبارات قبل اعتماد الملفات.

---

## 10.2 التهيئة وإعادة تعبئة البيانات

- عند كل تشغيل للحاوية `app`: `php artisan migrate --force` ثم `php artisan app:seed-if-empty`، الذي يعبّي البيانات التجريبية **فقط إذا كانت قاعدة البيانات فارغة**؛ إعادة التشغيل لا تكرر البيانات ولا الصور.
- حاوية `warehouse` تعبّي مخزون البداية فقط إذا كان جدول `stock` فارغاً (المنتجات 1-15 مطابقة لترتيب `ProductSeeder`؛ المنتج 13 فيه وحدة واحدة، 14 نفد، 16 غير مسجل).

إعادة بناء قاعدة Laravel `E-commerce` من الصفر (تحذف كل بياناتها):

```powershell
docker compose exec app php artisan migrate:fresh --seed --force
```

إعادة تعبئة مخزون المستودع (بعد تفريغ الجداول، مثلاً بعد الاختبارات):

```powershell
docker compose exec warehouse python -m app.cli seed
```

---

## 10.3 Scheduler

الحاوية `scheduler` تشغّل `php artisan schedule:work` من نفس صورة Laravel، وتنفذ `warehouse:reconcile` كل دقيقة: تحرّر الحجوزات التي لم يصل تعويضها أو إلغاؤها إلى المستودع وقت الطلب (`release_pending` و `cancel_pending`). المهمة محمية بـ `withoutOverlapping()` و `onOneServer()`، فلا تعمل نسختان في نفس الوقت. تشغيل يدوي:

```powershell
docker compose exec app php artisan warehouse:reconcile --older-than=0
```

الـ scheduler يعيد محاولة `release_pending` و `cancel_pending` فوراً في كل تشغيل، و `pending` بعد دقيقتين (لأن طلبه قد يكون ما زال يعمل).

في بيئة التطوير `QUEUE_CONNECTION=sync` و `MAIL_MAILER=log`: الرسائل تُكتب في `storage/logs/laravel.log` ولا تُرسل فعلياً (الإرسال الحقيقي عبر RabbitMQ وworkers من متطلبات الجزء الرابع).

---

## 10.4 الاختبارات

**تنبيه:** اختبارات Laravel تستخدم قاعدة `E-commerce` نفسها و `RefreshDatabase` يعيد إنشاء جداولها، فتُحذف بياناتها. أعد التعبئة بعدها بـ `migrate:fresh --seed`.

```powershell
# Unit + Feature (بدون مستودع حقيقي؛ Gateway وهمي)
docker compose exec app php artisan test --testsuite=Unit,Feature

# Integration: خادم Python gRPC حقيقي + MySQL حقيقي (يُشغَّل من الجهاز، يحتاج PHP مع grpc و venv المستودع)
# يحذف كل صفوف قاعدة warehouse، لذلك يحتاج تأكيداً صريحاً بـ WAREHOUSE_TESTS_DATABASE
$env:DB_HOST="127.0.0.1"; $env:DB_PORT="3307"; $env:WAREHOUSE_TESTS_DATABASE="warehouse"; php artisan test --testsuite=Integration

# اختبارات Python للمستودع
docker compose exec -e WAREHOUSE_TESTS_DATABASE=warehouse warehouse python -m unittest discover -s tests -t . -v

# بعد الاختبارات
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec warehouse python -m app.cli seed
```

`warehouse-mysql` منشور على `127.0.0.1:3308` فقط لاختبارات Integration؛ خدمة gRPC نفسها غير منشورة.

---

## 10.5 فشل المستودع: 503 و 504 والميزانية الزمنية

كل استدعاء gRPC له مرحلتان:

| المرحلة | الحد | عند الفشل |
|---|---|---|
| الاتصال (`waitForReady`) | `WAREHOUSE_GRPC_CONNECT_TIMEOUT_MS` = 500 | لم يُرسل شيء: **503** (المستودع متوقف أو لا يمكن الوصول إليه) |
| الاستدعاء (gRPC deadline) | قراءة 1000، حجز 2500، تحرير 1500 ms | أُرسل الطلب ولم يصل رد: **504** (النتيجة غير معروفة) |

كل استدعاءات طلب HTTP واحد (Reserve ثم Release التعويضي) تشترك في ميزانية واحدة `WAREHOUSE_GRPC_REQUEST_BUDGET_MS` = 5000، فلا تتراكم المهل. لا توجد إعادة محاولة تلقائية: إعادة المحاولة الآمنة تكون بنفس `Idempotency-Key`، والتحرير الذي لم يصل يبقى `release_pending` ويحرره `warehouse:reconcile` في الدقيقة التالية.

تجربة يدوية:

```powershell
docker compose stop warehouse     # الطلب يرجع 503 خلال ~1.5 ثانية، وصفحة المنتج stock_status=unavailable
docker compose start warehouse
docker compose pause warehouse    # الطلب يرجع 504 خلال ~4.5 ثانية
docker compose unpause warehouse
```

---

## 10.6 الكاش (Redis) و ETag

- يُخزَّن في Redis فقط بيانات الكتالوج: صفحات المنتجات وتفاصيل المنتج (v1 و v2) والتصنيفات. **المخزون الحي لا يُخزَّن أبداً**: يُقرأ من المستودع في كل طلب بعد الكاش. الطلبات وبيانات المستخدمين لا تُخزَّن.
- فلتر `in_stock` في v2 يعتمد على المخزون الحي، فلا يُخزَّن (`X-Cache: SKIP`).
- مفاتيح الكاش تشمل الإصدار والصفحة والفلاتر، وتبدأ برقم نسخة. أي إنشاء أو تعديل أو حذف لمنتج أو تصنيف يزيد رقم النسخة، فتُبطَل كل المدخلات مرة واحدة. `PRODUCT_CACHE_TTL` (300 ثانية) حد إضافي.
- كل استجابة كتالوج فيها `X-Cache` (`HIT` أو `MISS` أو `BYPASS` أو `SKIP` أو `OFF`) و `Server-Timing` (`catalog` = زمن جلب الكتالوج من Redis أو MySQL، `stock` = زمن GetStock).
- ETag يُحسب من الاستجابة الفعلية بعد إضافة المخزون الحي (بدون `timestamp`)، فأي تغيير في البيانات أو المخزون ينتج ETag جديداً ولا يمكن أن يرجع 304 قديم. `If-None-Match` المطابق يرجع **304** بدون body.

### مقارنة قبل وبعد تفعيل الكاش

مجموعة Postman `postman/E-commerce-Requirement-1-2.postman_collection.json`، المجلد **Requirement 3 - Cache, ETag and comparison**. الطلب الأخير يرسل نفس الطلبات على نفس البيانات مرة عبر الكاش ومرة مع `X-Cache-Bypass: 1` (مسموح خارج production فقط)، ويعرض p50/p95/p99 وزمن جلب الكتالوج من القياس الفعلي.

المقارنة ترسل نحو 300 طلب، أكثر من حد 60 طلباً في الدقيقة، فشغّلها هكذا:

```powershell
$env:API_RATE_LIMIT_PER_MINUTE="100000"; docker compose up -d app        # الكاش مفعّل
$env:PRODUCT_CACHE_ENABLED="false"; docker compose up -d app             # بدون كاش (X-Cache: OFF)
Remove-Item Env:PRODUCT_CACHE_ENABLED, Env:API_RATE_LIMIT_PER_MINUTE; docker compose up -d app   # الإعدادات الافتراضية
```

### OPcache وزمن الاستجابة

`PHP_OPCACHE_VALIDATE_TIMESTAMPS=0` في `docker-compose.yml`: زمن طلب ثابت (~0.25 ثانية)، لكن بعد تعديل كود PHP نفّذ `docker compose restart app`. القيمة 1 تطبّق التعديلات فوراً، لكن كل طلب يصبح أبطأ بـ 1-2 ثانية على Windows bind mount.

---

## 11. Useful Docker Commands

يجب تنفيذ الأوامر التالية من مجلد `E-commerce` الذي يحتوي على `docker-compose.yml`.

### Start Containers

```powershell
docker compose up -d
```

### Stop Containers

```powershell
docker compose down
```

### Rebuild Images

```powershell
docker compose up -d --build
```

### View Running Containers

```powershell
docker compose ps
```

### View All Containers

```powershell
docker compose ps -a
```

### View Logs

```powershell
docker compose logs -f
```

### Restart Laravel

```powershell
docker compose restart app
```

### Enter Laravel Container

```powershell
docker compose exec app bash
```

### Enter Warehouse Container

```powershell
docker compose exec warehouse bash
```

### Stop and Remove Containers

```powershell
docker compose down
```

**Warning:** لا تستخدم الأمر التالي إلا إذا كنت تريد حذف بيانات قواعد البيانات نهائياً:

```powershell
docker compose down -v
```

لأنه يحذف Docker Volumes التي تحتوي بيانات MySQL.

---

## 12. Important Notes

1. لا ترفع ملف `.env` إلى GitHub.
2. لا ترفع كلمات المرور أو المفاتيح السرية إلى GitHub.
3. يجب تنزيل المشروعين من الفرعين الصحيحين.
4. يجب الحفاظ على أسماء المجلدات `E-commerce` و`warehouse-service` أو تحديث مسارات الإعدادات عند تغييرها.
5. يجب تشغيل Docker Desktop قبل تنفيذ أوامر Docker.
6. قواعد بيانات Laravel وWarehouse منفصلة تماماً من ناحية الحاويات والـ Volumes.
7. بيانات MySQL تبقى محفوظة حتى بعد إيقاف الحاويات أو حذفها باستخدام `docker compose down`، ما دامت الـ Volumes لم تُحذف.
8. لا تحتاج إلى تثبيت PHP أو Python أو MySQL محلياً لتشغيل الخدمات داخل Docker.

---

## 13. Complete Installation Workflow

يمكن تلخيص عملية تثبيت النظام على أي جهاز جديد بالترتيب التالي:

```text
1. Install Git
       |
       v
2. Install Docker Desktop
       |
       v
3. Start Docker Desktop
       |
       v
4. Create eco folder
       |
       v
5. Clone Laravel Branch
       |
       v
6. Clone Warehouse Branch
       |
       v
7. Create Laravel .env
       |
       v
8. Configure WAREHOUSE_SERVICE_PATH
       |
       v
9. docker compose up -d --build
       |
       v
10. Generate APP_KEY and JWT Secret
       |
       v
11. Restart Laravel
       |
       v
12. Access Laravel and phpMyAdmin
       |
       v
13. Test Laravel <-> Warehouse gRPC
```

**Result:** أصبح النظام جاهزاً للتشغيل باستخدام Docker، مع مشروع Laravel مستقل، وخدمة Warehouse مستقلة، وقاعدتي بيانات MySQL منفصلتين.
