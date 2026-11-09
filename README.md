# E-commerce Project

متجر إلكتروني مبني بـ Laravel، يتعامل مع المخزون عبر خدمة مستقلة اسمها **Warehouse** (خدمة Python تعمل بـ gRPC).
يشرح هذا الدليل تجهيز المشروعين وتشغيلهما على جهاز جديد، خطوة بخطوة، باستخدام Docker.

| المشروع | الفرع على GitHub | ماذا يفعل |
|---|---|---|
| Laravel (هذا المستودع) | `feature/secondRequirement` | واجهة المتجر والـ API والطلبات والمستخدمين |
| Warehouse | `feature/laravel-integration` | يملك المخزون والحجوزات فقط، ولا يتصل به المستخدم مباشرة |

المشروعان موجودان في **نفس المستودع** `https://github.com/basher-aldaas/eco-requirement1.git`، لكن كل واحد في فرع مستقل، ويُنزَّل في مجلد مستقل.

> **اصطلاح الأوامر في هذا الدليل**
> - **PowerShell (Windows):** أوامر تُكتب في نافذة PowerShell على جهازك، من داخل مجلد مشروع Laravel إلا إذا ذُكر غير ذلك.
> - **داخل الحاوية:** أوامر تعمل داخل حاوية Docker. نشغّلها من PowerShell عبر `docker compose exec <اسم-الخدمة> ...`، أو بعد الدخول إلى الحاوية (القسم 10).

---

## 1. المتطلبات الأساسية

| الأداة | لماذا | التحقق |
|---|---|---|
| **Docker Desktop** (مع WSL 2 على Windows) | يشغّل الحاويات الخمس | `docker --version` و `docker compose version` |
| **Git** | لتنزيل المشروعين | `git --version` |
| متصفح ويب | لفتح المتجر و phpMyAdmin | — |

لا حاجة لتثبيت PHP أو Composer أو Python أو MySQL على جهازك: كلها تعمل داخل الحاويات.

**تثبيت Docker Desktop:**
1. نزّله من الموقع الرسمي: https://www.docker.com/products/docker-desktop/
2. أثناء التثبيت اترك خيار **Use WSL 2** مفعّلاً.
3. أعد تشغيل الجهاز إذا طُلب منك.
4. افتح Docker Desktop وانتظر حتى يظهر **Engine running** في أسفل النافذة.

**تثبيت Git:** نزّله من https://git-scm.com/download/win وثبّته بالإعدادات الافتراضية.

**التحقق (PowerShell):**
```powershell
docker --version
docker compose version
git --version
docker info
```
إذا تأخر `docker info` ولم يرجع شيئاً، فالمحرك لا يعمل بعد. راجع القسم 11.

**المنافذ المطلوب أن تكون فارغة على جهازك:** `8000` (Laravel)، `8080` (phpMyAdmin)، `3307` (MySQL الخاص بـ Laravel).

---

## 2. تنزيل المشروعين

أنشئ مجلداً واحداً يجمع المشروعين، ثم نزّل كل فرع في مجلد مستقل.

**PowerShell:**
```powershell
# مجلد يجمع المشروعين (اختر أي مكان)
New-Item -ItemType Directory -Force "$HOME\Desktop\eco"
Set-Location "$HOME\Desktop\eco"

# مشروع Laravel
git clone -b feature/secondRequirement https://github.com/basher-aldaas/eco-requirement1.git E-commerce

# مشروع Warehouse (نفس المستودع، فرع مختلف)
git clone -b feature/laravel-integration https://github.com/basher-aldaas/eco-requirement1.git warehouse-service
```

**التحقق من الفرع في كل مجلد:**
```powershell
git -C E-commerce branch --show-current          # feature/secondRequirement
git -C warehouse-service branch --show-current   # feature/laravel-integration
```

> اسم المجلد الثاني يجب أن يكون **`warehouse-service`**، لأن هذا الاسم هو ما نضعه في `WAREHOUSE_SERVICE_PATH` في القسم 4.

---

## 3. بنية المجلدات

بعد التنزيل يصبح الشكل هكذا:

```
eco\
├── E-commerce\                  ← Laravel (feature/secondRequirement)
│   ├── docker-compose.yml       ← يعرّف الحاويات الخمس
│   ├── Dockerfile               ← صورة PHP 8.3 مع إضافة grpc
│   ├── docker\
│   │   ├── entrypoint.sh        ← ما يحدث عند تشغيل حاوية app
│   │   └── phpmyadmin\config.user.inc.php
│   ├── .env.example
│   ├── Modules\                 ← Users و Products و Orders و Notifications
│   └── public\app.html          ← واجهة المتجر
└── warehouse-service\           ← Warehouse (feature/laravel-integration)
    ├── Dockerfile               ← صورة Python 3.12
    ├── docker\entrypoint.sh     ← ينشئ الجداول ثم يشغّل خادم gRPC
    ├── proto\warehouse.proto    ← تعريف عقد gRPC
    ├── sql\schema.sql           ← جداول قاعدة warehouse
    └── app\                     ← كود الخدمة
```

`docker-compose.yml` موجود في مشروع Laravel فقط، ويبني صورة Warehouse من المجلد المحدد في `WAREHOUSE_SERVICE_PATH`.
القيمة الافتراضية له هي `../../warehouse-service`، وهي **لا تناسب** البنية أعلاه، لذلك نضبطه في الخطوة التالية.

---

## 4. إعداد متغيرات البيئة

### 4.1 مشروع Laravel: ملف `.env`

**PowerShell (داخل `E-commerce`):**
```powershell
Set-Location "$HOME\Desktop\eco\E-commerce"
Copy-Item .env.example .env
```

على Linux أو macOS: `cp .env.example .env`

افتح `.env` وأضف في آخره هذا السطر، ليعرف Docker مكان مشروع Warehouse:
```dotenv
WAREHOUSE_SERVICE_PATH=../warehouse-service
```

> `docker compose` يقرأ ملف `.env` الموجود بجانب `docker-compose.yml`، فيستخدم هذه القيمة لمسار بناء صورة Warehouse.
> إذا كان مجلد Warehouse في مكان آخر، اكتب مساره النسبي من مجلد `E-commerce`.

**تحقّق أن المسار صحيح (PowerShell):**
```powershell
docker compose config | Select-String "context:"
```
يجب أن يظهر سطران: واحد ينتهي بـ `E-commerce` والآخر ينتهي بـ `warehouse-service`.

**متغيرات يضبطها `docker-compose.yml` تلقائياً (لا تغيّرها في `.env` للتشغيل عبر Docker):**

| المتغير | القيمة داخل Docker | ملاحظة |
|---|---|---|
| `DB_CONNECTION` | `mysql` | في `.env.example` القيمة `sqlite`، لكن Docker يستبدلها |
| `DB_HOST` / `DB_PORT` | `mysql` / `3306` | اسم خدمة MySQL على شبكة Docker |
| `DB_DATABASE` | `E-commerce` | |
| `DB_USERNAME` / `DB_PASSWORD` | معرّفة في `docker-compose.yml` | |
| `WAREHOUSE_GRPC_ENABLED` | `true` | في `.env.example` القيمة `false`، لكن Docker يستبدلها |
| `WAREHOUSE_GRPC_TARGET` | `warehouse:50051` | انظر القسم 7 |

المتغيرات التي تبقى من `.env` (لا يستبدلها Docker)، ويمكن تعديلها:

| المتغير | القيمة في `.env.example` | المعنى |
|---|---|---|
| `APP_KEY` | فارغ | **يجب توليده** (القسم 5، الخطوة 3) |
| `JWT_SECRET` | غير موجود | **يجب توليده** (القسم 5، الخطوة 3) |
| `WAREHOUSE_GRPC_READ_TIMEOUT_MS` | `1000` | مهلة قراءة المخزون بالملّي ثانية |
| `WAREHOUSE_GRPC_WRITE_TIMEOUT_MS` | `3000` | مهلة الحجز والتأكيد والإرجاع |
| `WAREHOUSE_GRPC_WRITE_RETRIES` | `1` | عدد إعادة المحاولة لعمليات الكتابة |
| `API_RATE_LIMIT_PER_MINUTE` | `60` | حد الطلبات العام للـ API |

> **لا ترفع ملف `.env` إلى GitHub أبداً.** فيه `APP_KEY` و `JWT_SECRET`. الملف مستثنى أصلاً في `.gitignore`.

### 4.2 مشروع Warehouse

**لا يحتاج ملف `.env` عند التشغيل عبر Docker.** الخدمة تقرأ إعداداتها من متغيرات البيئة (`app/config.py`)، و `docker-compose.yml` في مشروع Laravel يضبطها:

| المتغير | القيمة داخل Docker |
|---|---|
| `WAREHOUSE_DB_HOST` / `WAREHOUSE_DB_PORT` | `warehouse-mysql` / `3306` |
| `WAREHOUSE_DB_NAME` | `warehouse` |
| `WAREHOUSE_DB_USER` / `WAREHOUSE_DB_PASSWORD` | معرّفة في `docker-compose.yml` (خدمتا `warehouse` و `warehouse-mysql`) |
| `WAREHOUSE_GRPC_HOST` / `WAREHOUSE_GRPC_PORT` | `0.0.0.0` / `50051` (من `Dockerfile` الخاص بـ Warehouse) |
| `WAREHOUSE_SEED` | `true` افتراضياً: يضيف مخزوناً تجريبياً للمنتجات 1 و 2 و 3 |

**كلمات مرور قواعد البيانات:** القيم الموجودة في `docker-compose.yml` مخصصة للتطوير المحلي فقط. لتغيير كلمة مرور Warehouse، عدّل `MYSQL_PASSWORD` في خدمة `warehouse-mysql` و `WAREHOUSE_DB_PASSWORD` في خدمة `warehouse` بنفس القيمة، **قبل** أول تشغيل. MySQL لا يطبّق هذه القيم إلا عند إنشاء الـ volume لأول مرة.

لتشغيل Warehouse بدون Docker، راجع `README.md` في مشروع `warehouse-service`.

---

## 5. بناء وتشغيل Docker

### الخطوة 1: البناء والتشغيل

**PowerShell (داخل `E-commerce`):**
```powershell
docker compose up -d --build
```

- **أول بناء يأخذ تقريباً 10 إلى 15 دقيقة**، لأن إضافة `grpc` الخاصة بـ PHP تُترجم من الكود المصدري. البناءات التالية أسرع لأن Docker يحتفظ بالنتيجة.
- رسائل `warning: ... is deprecated` أثناء ترجمة `grpc` طبيعية وليست أخطاء.

ترتيب التشغيل مضبوط في `docker-compose.yml` عبر `depends_on`:

```
mysql (healthy) ─────────────────────────────────┐
warehouse-mysql (healthy) → warehouse (healthy) ─┴─→ app
mysql + warehouse-mysql ─────────────────────────────→ phpmyadmin
```

### الخطوة 2: انتظار جاهزية Laravel

عند أول تشغيل، تنفّذ حاوية `app` (من `docker/entrypoint.sh`) بالترتيب:
1. إنشاء مجلدات `storage` و `bootstrap/cache`.
2. `composer install` إذا كان مجلد `vendor` غير موجود (يأخذ عدة دقائق في أول مرة).
3. `php artisan storage:link`.
4. `php artisan migrate --force`.
5. `php artisan db:seed --force`.
6. تشغيل الخادم: `php artisan serve --host=0.0.0.0 --port=8000 --no-reload`.

تابع السجل حتى يظهر `Server running on [http://0.0.0.0:8000]`:

**PowerShell:**
```powershell
docker compose logs -f app
```
اضغط `Ctrl+C` للخروج من المتابعة (لا يوقف الحاوية).

### الخطوة 3: توليد المفاتيح (مرة واحدة فقط على الجهاز الجديد)

**داخل الحاوية (من PowerShell):**
```powershell
docker compose exec app php artisan key:generate
docker compose exec app php artisan jwt:secret
```
الأمران يكتبان القيم في ملف `.env` على جهازك، لأن مجلد المشروع مربوط بالحاوية.
إذا كان المفتاح موجوداً، يسألك `jwt:secret` قبل استبداله. **لا تستبدله** إذا كان النظام يعمل، لأن ذلك يُبطل كل الـ tokens الحالية.

ثم أعد تشغيل حاوية Laravel لتقرأ المفاتيح الجديدة:
```powershell
docker compose restart app
```

### الخطوة 4: التأكد

```powershell
docker compose ps
```
راجع القسم 6.

---

## 6. التحقق من الخدمات

### 6.1 حالة الحاويات

**PowerShell:**
```powershell
docker compose ps
```

النتيجة المتوقعة (خمس حاويات):

| الخدمة | اسم الحاوية | الحالة المتوقعة | المنفذ على جهازك |
|---|---|---|---|
| `app` | `ecommerce-app` | `Up` | `8000` |
| `mysql` | `ecommerce-mysql` | `Up (healthy)` | `3307` |
| `warehouse` | `ecommerce-warehouse` | `Up (healthy)` | لا يوجد (مقصود) |
| `warehouse-mysql` | `ecommerce-warehouse-mysql` | `Up (healthy)` | لا يوجد (مقصود) |
| `phpmyadmin` | `ecommerce-phpmyadmin` | `Up` | `8080` |

`app` و `phpmyadmin` ليس لهما healthcheck، لذلك تظهر حالتهما `Up` فقط. تحقق منهما بالخطوات التالية.

### 6.2 Laravel وقاعدة بياناته

**داخل الحاوية (من PowerShell):**
```powershell
# حالة الـ migrations: كل السطور يجب أن تكون Ran
docker compose exec app php artisan migrate:status

# الاتصال بقاعدة E-commerce مباشرة
docker compose exec mysql mysql -u root E-commerce -e "SELECT COUNT(*) AS products FROM products;"
```

**من المتصفح:** http://localhost:8000/app.html يجب أن تفتح صفحة الدخول.

### 6.3 Warehouse وقاعدة بياناته

**داخل الحاوية (من PowerShell):**
```powershell
# هل خادم gRPC يستقبل الاتصالات؟
docker compose exec warehouse python -c "import grpc; grpc.channel_ready_future(grpc.insecure_channel('127.0.0.1:50051')).result(timeout=3); print('warehouse gRPC: OK')"

# السجل: يجب أن يظهر schema ready ثم warehouse gRPC listening on 0.0.0.0:50051
docker compose logs warehouse

# جدول المخزون (سيطلب كلمة مرور المستخدم warehouse من docker-compose.yml)
docker compose exec warehouse-mysql mysql -u warehouse -p warehouse -e "SELECT * FROM stock;"
```

### 6.4 إضافة gRPC في PHP

```powershell
docker compose exec app php -m | Select-String -Pattern "^grpc$"
```
يجب أن يظهر `grpc`. إذا لم يظهر، فالصورة بُنيت قبل إضافتها: `docker compose build --no-cache app`.

### 6.5 phpMyAdmin

افتح http://localhost:8080 واختر الخادم من قائمة **Server choice** في صفحة الدخول:

| الخادم | المستخدم | قواعد البيانات التي تظهر |
|---|---|---|
| `mysql` | `root` (بدون كلمة مرور، كما في `MYSQL_ALLOW_EMPTY_PASSWORD`) | `E-commerce` |
| `warehouse-mysql` | `warehouse` (كلمة المرور من `docker-compose.yml`) | `warehouse` فقط |

المستخدم `warehouse` لا يرى قاعدة Laravel، وهذا مقصود: بيانات المخزون منفصلة تماماً.

---

## 7. الاتصال عبر gRPC

```
المتصفح ──HTTP:8000──▶ app (Laravel) ──gRPC: warehouse:50051──▶ warehouse (Python) ──▶ warehouse-mysql
                         │
                         └──▶ mysql (قاعدة E-commerce)
```

- **العقد:** معرّف في `proto/warehouse.proto` في مشروع Warehouse. Laravel يستخدم نسخة مولّدة منه في `app/Grpc/Warehouse`.
- **العميل في Laravel:** الواجهة `app/Services/Warehouse/WarehouseGateway.php`، وتنفيذها الحقيقي `GrpcWarehouseGateway.php`. العمليات السبع: `getStock` و `reserve` و `confirm` و `release` و `returnStock` و `initializeStock` و `setStock`.
- **العنوان داخل Docker هو `warehouse:50051`.** `warehouse` هو اسم الخدمة في `docker-compose.yml`، و Docker يحوّله إلى عنوان الحاوية على الشبكة الداخلية. لا تستخدم `127.0.0.1` داخل Docker: داخل حاوية `app` هذا العنوان يعني الحاوية نفسها، وليس Warehouse.
- **العنوان مضبوط في `docker-compose.yml`** (`WAREHOUSE_GRPC_TARGET: warehouse:50051`)، وهو يتقدم على قيمة `.env` (`127.0.0.1:50051`، المخصصة للتشغيل بدون Docker).
- **لماذا `--no-reload` في `docker/entrypoint.sh`:** بدونه، `php artisan serve` يشغّل خادم PHP بدون متغيرات `WAREHOUSE_*` القادمة من Docker، فيرجع إلى قيمة `.env` ويفشل الاتصال. لا تحذف هذا الخيار.
- **Warehouse غير مكشوف للخارج:** لا يوجد له `ports:` في `docker-compose.yml`، فلا يمكن الوصول إليه من جهازك ولا من الشبكة. فقط حاويات نفس الشبكة تصل إليه.
- **تعطيل التكامل:** إذا كانت `WAREHOUSE_GRPC_ENABLED=false`، لا يتصل Laravel بأي شيء: يظهر المخزون "غير متاح" وترجع الطلبات `503`.

---

## 8. الوصول إلى التطبيق

| ماذا | الرابط |
|---|---|
| واجهة المتجر | http://localhost:8000/app.html |
| الصفحة الرئيسية لـ Laravel | http://localhost:8000 |
| توثيق الـ API (Swagger) | http://localhost:8000/api/documentation |
| phpMyAdmin | http://localhost:8080 |
| MySQL الخاص بـ Laravel من برنامج على جهازك | المضيف `127.0.0.1`، المنفذ `3307` |
| Warehouse و MySQL الخاص به | غير متاحين من جهازك (مقصود). استخدم phpMyAdmin أو `docker compose exec` |

**حسابات التجربة:** ينشئها الـ seeder في `Modules/Users/database/seeders/UserSeeder.php` (حساب admin وحساب مستخدم وعدة حسابات customer). البريد وكلمة المرور مكتوبان في ذلك الملف. غيّرها إذا كان النظام سيُستخدم خارج جهازك.

---

## 9. Migrations و Seeders

### ما يحدث تلقائياً

عند **كل** تشغيل لحاوية `app`، يُنفَّذ `php artisan migrate --force` ثم `php artisan db:seed --force`.
الأمران آمنان على البيانات الموجودة:
- `migrate` ينفّذ فقط الـ migrations الجديدة.
- الـ seeders تستخدم `firstOrCreate` / `findOrCreate`، فلا تكرر البيانات.

عند كل تشغيل لحاوية `warehouse`:
- `python -m app.cli init-db`: يستخدم `CREATE TABLE IF NOT EXISTS`، فلا يلمس الصفوف الموجودة.
- `python -m app.cli seed`: يستخدم `INSERT IGNORE`، فالكميات الموجودة تبقى كما هي. يمكن تعطيله بـ `WAREHOUSE_SEED=false`.

### أوامر آمنة يدوياً

**داخل الحاوية (من PowerShell):**
```powershell
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

### ⚠️ أوامر تحذف البيانات: لا تستخدمها إلا إذا كنت متأكداً

| الأمر | ماذا يحذف |
|---|---|
| `docker compose down -v` | **يحذف الـ volumes:** قاعدتا `E-commerce` و `warehouse` بالكامل |
| `docker volume rm ...` | يحذف قاعدة البيانات المرتبطة بذلك الـ volume |
| `php artisan migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` | يحذف جداول Laravel وبياناتها |
| `php artisan test` | **يحذف كل بيانات قاعدة `E-commerce`** ويتركها فارغة (انظر "الاختبارات" أدناه) |
### الاختبارات

> **⚠️ الاختبارات تعمل على قاعدة `E-commerce` الحقيقية نفسها، وتحذف كل بياناتها.**
> `RefreshDatabase` يحذف كل الجداول ويعيد إنشاءها في بداية التشغيل. بعد الانتهاء تبقى الجداول **فارغة**: لا مستخدمين ولا منتجات ولا طلبات.
> خذ نسخة احتياطية قبل التشغيل (الأوامر أعلاه)، ولا تشغّل الاختبارات على نظام فيه بيانات تحتاجها.

**الإعدادات الفعلية في `phpunit.xml`:**

| المتغير | القيمة في الاختبارات | لماذا |
|---|---|---|
| `DB_CONNECTION` | `mysql` | نفس اتصال التطبيق، بدون SQLite |
| `DB_DATABASE` | `E-commerce` | نفس قاعدة التطبيق |
| `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` | لا تُضبط في `phpunit.xml` | تأتي من بيئة التشغيل: داخل Docker من `docker-compose.yml`، وخارجه من `.env` |
| `WAREHOUSE_GRPC_ENABLED` | `false` | الاختبارات تستخدم Warehouse وهمياً (`tests/Support/FakeWarehouseGateway.php`) ولا تتصل بالخدمة الحقيقية |
| `WAREHOUSE_GRPC_TARGET` | `127.0.0.1:1` | عنوان لا يستمع عليه شيء، كحماية إضافية |

كل متغير من هذه يُضبط بطريقتين: `<env force="true">` و `<server>`. داخل Docker هذه المتغيرات معرّفة مسبقاً من `docker-compose.yml`، و Laravel يقرأ `$_SERVER` أولاً. `<env force>` وحده لا يغيّر `$_SERVER`، فتبقى قيم الحاوية هي المستخدمة.
`ApiTestCase` يرفض التشغيل إذا لم يكن الاتصال `mysql/E-commerce`، حتى لا يحذف `RefreshDatabase` أي قاعدة أخرى.

**تشغيل الاختبارات (داخل الحاوية):**
```powershell
docker compose exec app php artisan test
```

**إعادة بيانات التطبيق بعد الاختبارات:**
- الحسابات والفئات والمنتجات التجريبية: `docker compose exec app php artisan db:seed --force`، أو `docker compose restart app` لأن `entrypoint.sh` يشغّل الـ seeders.
  انتبه: أرقام المنتجات الجديدة قد لا تبدأ من 1، لأن عدّاد `AUTO_INCREMENT` في MySQL يستمر بعد الاختبارات. عندها لن تطابق المخزون التجريبي في Warehouse (المنتجات 1 و 2 و 3)، فأدخل الكميات من **Manage products** ← **Save stock**.
- البيانات الأصلية كاملة: استعِد النسخة الاحتياطية التي أخذتها قبل التشغيل.

ملاحظات:
- اختبارات `tests/Integration` تُتخطّى (skipped) داخل Docker، لأنها تحتاج قاعدة `warehouse_test` وبيئة Python الافتراضية (`venv`) الخاصة بـ Warehouse. لا تلمس قاعدة `warehouse` الحقيقية.
- خارج Docker تتصل الاختبارات بالخادم المحدد في `.env` (`DB_HOST` و `DB_PORT`)، وتحذف قاعدة `E-commerce` الموجودة عليه.

**قاعدة Warehouse:** الأمر `python -m app.cli migrate` يرفض العمل على قاعدة لا ينتهي اسمها بـ `_test` إلا مع `--apply-to-real-database`. خذ نسخة احتياطية قبل استخدامه. التفاصيل في `README.md` الخاص بـ Warehouse.

### نسخة احتياطية

**PowerShell:** ننشئ النسخة داخل الحاوية ثم ننسخها إلى جهازك. لا تستخدم `>` في PowerShell 5.1 هنا، لأنه يحفظ الملف بترميز UTF-16 فيصبح غير صالح للاستعادة.
```powershell
docker compose exec mysql sh -c "mysqldump -u root E-commerce > /tmp/backup-ecommerce.sql"
docker compose cp mysql:/tmp/backup-ecommerce.sql .\backup-ecommerce.sql
docker compose exec mysql rm /tmp/backup-ecommerce.sql
```

---

## 10. إدارة الحاويات

كل الأوامر التالية تُنفّذ من **PowerShell داخل مجلد `E-commerce`**.

| المطلوب | الأمر |
|---|---|
| حالة الحاويات | `docker compose ps` |
| كل السجلات (متابعة مباشرة) | `docker compose logs -f` |
| سجل خدمة واحدة | `docker compose logs -f app` (أو `warehouse`، `mysql`، `warehouse-mysql`، `phpmyadmin`) |
| آخر 100 سطر فقط | `docker compose logs --tail 100 app` |
| الدخول إلى حاوية Laravel | `docker compose exec app bash` |
| الدخول إلى حاوية Warehouse | `docker compose exec warehouse sh` |
| واجهة MySQL الخاصة بـ Laravel | `docker compose exec mysql mysql -u root E-commerce` |
| واجهة MySQL الخاصة بـ Warehouse | `docker compose exec warehouse-mysql mysql -u warehouse -p warehouse` |
| إيقاف الحاويات (بدون حذفها) | `docker compose stop` |
| تشغيلها مرة أخرى | `docker compose start` |
| إعادة تشغيل خدمة | `docker compose restart app` |
| إيقاف وحذف الحاويات **مع الإبقاء على البيانات** | `docker compose down` (بدون `-v`) |
| إعادة البناء بعد تعديل `Dockerfile` | `docker compose up -d --build` |
| إعادة بناء خدمة واحدة | `docker compose up -d --build warehouse` |
| بناء من الصفر بدون cache | `docker compose build --no-cache app` ثم `docker compose up -d` |

**متى أحتاج إعادة البناء؟**
- كود Laravel: **لا**. المجلد مربوط بالحاوية، والتعديلات تظهر مباشرة.
- كود Warehouse: **نعم**. الكود منسوخ داخل الصورة: `docker compose up -d --build warehouse`.
- `Dockerfile` أو `requirements.txt`: **نعم**.
- `docker-compose.yml`: `docker compose up -d` يكفي.

---

## 11. حل المشاكل الشائعة

| المشكلة | السبب | الحل |
|---|---|---|
| أي أمر `docker` يتأخر ولا يرجع شيئاً | محرك Docker Desktop معلّق أو لم يبدأ | أعد تشغيل Docker Desktop (أيقونة شريط المهام ← Quit ثم افتحه)، أو `docker desktop restart`. انتظر **Engine running** |
| `unable to prepare context` / مسار `warehouse-service` غير موجود | `WAREHOUSE_SERVICE_PATH` غير مضبوط أو خاطئ | القسم 4.1: أضف `WAREHOUSE_SERVICE_PATH=../warehouse-service` إلى `.env`، وتحقق بـ `docker compose config` |
| `port is already allocated` على 8000 أو 8080 أو 3307 | برنامج آخر يستخدم المنفذ (XAMPP، MySQL محلي...) | أوقف ذلك البرنامج، أو غيّر الرقم الأيسر في `ports:` داخل `docker-compose.yml` |
| `Conflict. The container name "/ecommerce-..." is already in use` | حاوية قديمة بنفس الاسم | `docker compose down` (بدون `-v`) ثم `docker compose up -d` |
| المتجر يعرض "Stock unavailable right now" والطلبات ترجع `503` | Laravel لا يصل إلى Warehouse | 1) `docker compose ps`: هل `warehouse` بحالة healthy؟ 2) `docker compose logs warehouse`. 3) في `storage/logs/laravel.log` ابحث عن `Warehouse call failed`: إذا ظهر `127.0.0.1:50051` فالعنوان خاطئ، فتأكد من وجود `--no-reload` في `docker/entrypoint.sh` ثم `docker compose restart app` |
| منتج يعرض "Out of stock" (الكمية 0) مع أنه يجب أن يكون متوفراً | المنتج ليس له سجل في جدول `stock` في Warehouse، و Laravel يعرض المنتج غير المسجّل كأنه نفد. الـ seed يضيف المنتجات 1 و 2 و 3 فقط، وبعد الاختبارات تأخذ المنتجات أرقاماً جديدة | من حساب admin: صفحة **Manage products** ← أدخل الكمية ← **Save stock**. `setStock` ينشئ السجل إذا لم يكن موجوداً |
| خطأ `Class "Grpc\..." not found`، أو `composer install` يفشل لأن الحزمة تتطلب `ext-grpc` | إضافة `grpc` غير مثبتة في الصورة | `docker compose build --no-cache app` ثم `docker compose up -d` |
| `No application encryption key has been specified` | `APP_KEY` فارغ | `docker compose exec app php artisan key:generate` ثم `docker compose restart app` |
| تسجيل الدخول يفشل، وفي `storage/logs/laravel.log` يظهر `SecretMissingException` | `JWT_SECRET` غير موجود في `.env` | `docker compose exec app php artisan jwt:secret` ثم `docker compose restart app` |
| حاوية `app` تتوقف فوراً (`Exited`) | فشلت إحدى خطوات `entrypoint.sh` (مثل composer أو migrate) | `docker compose logs app` لمعرفة الخطوة الفاشلة |
| `set: Illegal option -` أو `\r: not found` عند تشغيل `entrypoint.sh` | الملف حُفظ بنهايات أسطر Windows (CRLF) | احفظه بـ LF (في VS Code: أسفل النافذة `CRLF` ← `LF`). `.gitattributes` يضبط ذلك عند التنزيل من Git |
| phpMyAdmin: `Access denied` على `warehouse-mysql` | كلمة المرور غير مطابقة لـ `docker-compose.yml`، أو تغيّرت بعد إنشاء الـ volume | استخدم القيمة الموجودة في `docker-compose.yml`. MySQL يطبّق `MYSQL_PASSWORD` فقط عند أول إنشاء للـ volume |
| phpMyAdmin يرفض الدخول بـ `root` بدون كلمة مرور | ملف `docker/phpmyadmin/config.user.inc.php` غير مربوط | تأكد من وجوده، ثم `docker compose up -d phpmyadmin` |
| أول بناء طويل جداً وتظهر رسائل `deprecated` كثيرة | ترجمة إضافة `grpc` من المصدر | طبيعي. انتظر (10 إلى 15 دقيقة تقريباً). البناءات التالية تستخدم الـ cache |
| تعديل `.env` لم يظهر أثره | قيمة المتغير يحددها `docker-compose.yml`، أو الحاوية لم تُعد تشغيلها | المتغيرات في جدول القسم 4.1 تأتي من `docker-compose.yml`. لغيرها: `docker compose restart app` |

---

## 12. اختبار تكامل المشروعين

### 12.1 من سطر الأوامر: قراءة المخزون عبر gRPC (لا تغيّر أي بيانات)

**داخل الحاوية (من PowerShell):**
```powershell
docker compose exec app php artisan tinker --execute="dump(app(App\Services\Warehouse\WarehouseGateway::class)->getStock([1, 2, 3]));"
```
النتيجة المتوقعة: ثلاثة عناصر `StockLevel` مع `tracked: true`. مع المخزون التجريبي: `quantity` تساوي 10 و 5 و 0 (إلا إذا تغيّرت).
إذا ظهر `WarehouseUnavailableException`، راجع القسم 11.

### 12.2 من واجهة المتجر: الدورة الكاملة

افتح http://localhost:8000/app.html. استخدم حسابات القسم 8، وتابع المخزون في phpMyAdmin (خادم `warehouse-mysql`، جدول `stock`):

| الخطوة | العملية في Warehouse | المتوقع |
|---|---|---|
| 1. ادخل بحساب customer وافتح **Shop** | `getStock` | الكمية تظهر بجانب كل منتج، مثلاً "10 in stock" |
| 2. أضف منتجاً إلى **Cart** ثم **Place order** | `reserve` ثم `confirm` | رسالة "Order #… placed!" وتنقص الكمية |
| 3. اطلب كمية أكبر من المتوفر | `reserve` يُرفض | رسالة "Insufficient stock…" (`409`) والكمية لا تتغير |
| 4. اخرج وادخل بحساب admin ← **All orders** ← اختر `cancelled` ← **Update** | `returnStock` | الحالة تصبح `cancelled` وترجع الكمية |
| 5. حاول إعادة الطلب الملغى إلى `pending` | — | يُرفض (`409`): الطلب الملغى لا يُعاد فتحه |
| 6. admin ← **Manage products** ← أضف منتجاً جديداً مع Stock | `initializeStock` | المنتج يظهر في المتجر بالكمية المدخلة |
| 7. admin ← **Manage products** ← غيّر الكمية ← **Save stock** | `setStock` | الكمية الجديدة تظهر في المتجر وفي جدول `stock` |

`release` لا يظهر كزر: Laravel يستدعيه تلقائياً إذا فشل إنشاء الطلب بعد الحجز.

### 12.3 أين أرى ما حدث؟

- **Laravel:** جدول `order_reservations` في قاعدة `E-commerce`. حالاته (من `Modules/Orders/app/Models/OrderReservation.php`): `pending` و `rejected` و `release_pending` و `released` و `saved` و `confirmed` و `return_pending` و `returned` و `attention`.
- **Warehouse:** جدولا `reservations` (`RESERVED` و `CONFIRMED` و `RELEASED` و `RETURNED`) و `stock` في قاعدة `warehouse`.
- **الأخطاء:** `storage/logs/laravel.log` (ابحث عن `Warehouse call failed`) و `docker compose logs warehouse`.

> **الحجوزات المعلّقة:** إذا كان Warehouse متوقفاً أو بطيئاً لحظة الطلب، تبقى الحجوزات بحالة مثل `release_pending` أو `saved` أو `return_pending`.
> الأمر `php artisan warehouse:reconcile` يكمل هذه الحجوزات (`--older-than=2` افتراضياً: فقط ما مرّ عليه دقيقتان).
> الـ scheduler في Laravel مضبوط لتشغيله كل دقيقة (`OrdersServiceProvider`)، **لكن لا توجد حاوية تشغّل الـ scheduler** في `docker-compose.yml`. لذلك شغّله يدوياً عند الحاجة:
> ```powershell
> docker compose exec app php artisan warehouse:reconcile
> ```
