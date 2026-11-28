# Requirement 5 — التوسع وتوزيع الأحمال

النظام يعمل بثلاث نسخ من الـ API خلف Nginx، وثلاثة عمّال يتقاسمون نفس الطابور.
توقف نسخة أو عامل لا يوقف النظام. كل رقم في هذا الملف مأخوذ من ملفات `evidence/`.

## 1. المعمارية

```
 Client (Postman / k6 / المتصفح)
    │ http://localhost:8000
    ▼
 nginx (nginx:1.28-alpine) ── least_conn + failover ──┬──► app   (hostname app1)
    المنفذ الوحيد المنشور                             ├──► app2  (hostname app2)
                                                      └──► app3  (hostname app3)
                                                             │  كل استجابة: X-Instance: appN
                       ┌─────────────────────────────────────┼──────────────────────────────┐
                       ▼                                     ▼                              ▼
              MySQL (طلبات، حجوزات،              Redis (كاش، rate limiter،        volume: product-images
               Idempotency-Key، outbox)           JWT blacklist، أقفال)            (صور المنتجات)
                       │
                       ▼ outbox-relay ──► RabbitMQ: notifications.order-created.email
                                                 │ prefetch 1, manual ACK
                                  ┌──────────────┼──────────────┐
                                  ▼              ▼              ▼
                               worker1        worker2        worker3
```

| الخدمة | الدور |
|---|---|
| `app-init` | يشغّل `migrate` و `app:seed-if-empty` **مرة واحدة** ثم يخرج بـ 0. النسخ لا تبدأ قبله (`service_completed_successfully`) |
| `app`, `app2`, `app3` | نسخ متطابقة من قالب واحد `x-app-instance` (نفس الإعدادات حرفياً)، `entrypoint.sh serve`، بلا منافذ منشورة، `healthcheck` على `/up` |
| `nginx` | نقطة الدخول الوحيدة `8000:80`. `LB_METHOD=least_conn` (افتراضي) أو `round_robin` |
| `worker1`, `worker2`, `worker3` | عمّال متطابقون على طابور `order.created`. `ORDER_WORKER_ACTION=db` (افتراضي) أو `email` |

الملفات: `docker-compose.yml`، `docker/entrypoint.sh`، `docker/nginx/templates/default.conf.template`، `docker/nginx/lb/*.conf`، `app/Http/Middleware/InstanceHeader.php`، `Modules/Notifications/app/Messaging/OrderCreatedEmailConsumer.php`، `Modules/Notifications/app/Services/OrderStatsDbTask.php`.

## 2. استراتيجية التوزيع: لماذا `least_conn`

`least_conn` يرسل كل طلب جديد إلى النسخة التي لديها أقل عدد من الطلبات المفتوحة الآن. `round_robin` يوزّع بالدور مهما كان انشغال النسخة.

**القياس:**

| الحالة | `round_robin` | `least_conn` | الملف |
|---|---|---|---|
| 3 نسخ متساوية، 200 طلب شراء + 500 قراءة كتالوج معاً، p95 الكتالوج (جولتان) | 3.51 s / 2.74 s | 2.97 s / 2.93 s | `02` |
| نسخة واحدة بطيئة (`app3` بنصف CPU)، 500 قراءة، p95 (جولتان) | **6.37 s / 6.34 s** | **2.87 s / 2.86 s** | `03` |
| نفس الحالة، توزيع الطلبات | 166 / 167 / 167 | 202 / 198 / **100** | `03` |

- **النسخ المتساوية:** لا فرق يمكن قياسه. الفرق بين جولتين من نفس الطريقة (حوالي 15%) أكبر من الفرق بين الطريقتين.
- **نسخة بطيئة:** `round_robin` يعطيها ثلث الطلبات رغم أنها مشغولة، فينتظر ثلث المستخدمين. `least_conn` يلاحظ أن طلباتها لم تنتهِ بعد، فيعطيها النصف تقريباً (100 بدل 167). النتيجة: p95 ينخفض **55%** (من 6.37 إلى 2.87 ثانية).
- **عندنا مدد الطلبات غير متساوية:** طلب الشراء يستدعي الـ Warehouse عبر gRPC ويستغرق أكثر من قراءة كتالوج من Redis. وأي نسخة قد تبطؤ مؤقتاً (عملية ثقيلة، إعادة تشغيل). `least_conn` يتكيّف مع ذلك، و`round_robin` لا يتكيّف.
- **لماذا لم نختر `ip_hash` أو جلسة لاصقة (sticky):** النسخ لا تحتفظ بأي حالة للمستخدم. الـ JWT والجلسات والكاش كلها في MySQL و Redis، فأي نسخة تخدم أي طلب. الجلسة اللاصقة كانت ستحمّل نسخة واحدة بكل طلبات المستخدم، وتفقده جلسته إذا توقفت.

```powershell
$env:LB_METHOD='round_robin'; docker compose up -d --no-deps nginx; Remove-Item Env:LB_METHOD
docker compose up -d --no-deps nginx     # الرجوع إلى least_conn
```

انتبه لـ `--no-deps`: بدونه يعيد Compose إنشاء `app` و`app2` و`app3` أيضاً، بقيم البيئة الحالية.

## 3. الإثبات تحت الضغط

| الاختبار | النتيجة | الملف |
|---|---|---|
| 500 طلب عبر Nginx، 50 مستخدماً متزامناً | 501/501 ناجح (500 + تسجيل الدخول)، 0% فشل، التوزيع 160 / 160 / 180 | `01` |
| 1500 طلب، وخلالها `stop app2` ثم `kill app3` (بقيت `app1` وحدها) ثم إعادتهما | **1501/1501 ناجح، 0% فشل**. `app1` خدمت 741. أعاد Nginx إرسال 42 محاولة فشلت على النسخ المتوقفة إلى نسخة حيّة | `04` |
| 300 طلب شراء، وخلالها `kill app2` | 294 × 201 و 6 × 502. في قاعدة البيانات 298 طلباً، **0 مفاتيح مكررة**، كل الحجوزات `placed` | `05` |

**لماذا 6 × 502 في طلبات الشراء ولا شيء في القراءات:** Nginx يعيد إرسال `GET` إلى نسخة أخرى (`proxy_next_upstream`) لأن تكراره آمن. أما `POST` الذي وصل فعلاً إلى نسخة ماتت، فلا يعيده Nginx لأنه قد يكون أنشأ الطلب. 4 من الـ 6 أُنشئت فعلاً قبل موت `app2` (298 طلباً مقابل 294 جواب 201).

**الحل عند العميل:** الإعادة بنفس `Idempotency-Key`. الواجهة تفعل ذلك (`public/assets/js/core/checkout.js` تحتفظ بالمفتاح بعد أخطاء الشبكة والسيرفر)، فيرجع الطلب الموجود ولا يُنشأ طلب ثانٍ. لا يوجد موزّع أحمال يعرف هل حُفظ الطلب قبل موت النسخة.

### العمّال

| الاختبار | النتيجة | الملف |
|---|---|---|
| 500 طلب → 500 رسالة، مهمة `db` مدتها ثانية | 500/500، 0 أخطاء، 0 تكرار، التوزيع 167 / 167 / 166، خلال 168 ثانية | `08` |
| عامل واحد مقابل ثلاثة | 1.0 رسالة/ثانية مقابل **3.1 رسالة/ثانية** (30 لكل عامل) | `11` |
| `stop worker2` أثناء العمل | 90/90 عولجت. الرسالة التي سُلّمت له ولم يبدأ بها عادت إلى `worker1` (attempt 2) | `09` |
| `kill -9 worker3` والطابور فيه 86 رسالة | 150/150، كلها رسائل مختلفة. رسالته غير المؤكَّدة عولجت مرة واحدة عند `worker1` | `10` |

`prefetch 1` مع ACK يدوي: لا يحجز العامل أكثر من رسالة، والرسالة لا تُحذف إلا بعد نجاحها. إذا مات العامل يعيدها RabbitMQ لغيره.

## 4. المشاكل التي ظهرت عند الانتقال من نسخة إلى عدة نسخ

| # | ما كان محفوظاً محلياً / ما تعطّل | قبل | بعد | الملف |
|---|---|---|---|---|
| 1 | **كل نسخة تشغّل `migrate` و seed عند الإقلاع** | 3 نسخ معاً على قاعدة فارغة: `app` فشلت بـ `Table 'migrations' already exists` (exit=1)، ومع `set -eu` لا تبدأ أبداً | `app-init` يشغّلها مرة واحدة، والنسخ تنتظره | `12` |
| 2 | **حد Mailtrap (رسالة/15 ثانية) في ذاكرة كل عامل** (`$lastSendAt`) | 3 طلبات → 3 محاولات فاشلة `550 5.7.0 Too many emails per second` | وقت آخر إرسال في Redis، والعامل يمسك قفل `order-email:send-slot` طوال الإرسال: 3/3 ناجحة، 0 أخطاء، 15 ثانية بين الرسائل | `06`, `07` |
| 3 | **صور المنتجات على قرص النسخة** | volume لكل نسخة (`PRODUCT_IMAGES_PER_INSTANCE=1`): صورة رُفعت عبر `app2` → 3 × 200 من `app2`، و6 × 403 من `app1` و`app3` | volume مشترك `product-images`: 9/9 × 200 | `13` |
| 4 | **ملف السجل المشترك لا يقول أي نسخة كتبت** | `local.DEBUG: From: ...` بلا نسخة | `Log::shareContext`: كل سطر ينتهي بـ `{"instance":"appN"}`. 348 من 349 سطر HTTP (الباقي أمر artisan) | `14` |
| 5 | **أوامر التوثيق `up -d app` تغيّر نسخة واحدة** | تغيير الكاش أو الـ rate limit يطبَّق على ثلث الطلبات | كل الأوامر تذكر `app app2 app3` (و`--no-deps` لـ nginx) | README، DEMO.md |
| 6 | **Laravel يرى عنوان Nginx بدل عنوان العميل** | كل الزوار يتقاسمون عدّاد الـ rate limit ومحاولات الدخول (`email\|172.18.0.16` = Nginx) | **مشكلة معروفة، أُبقيت بقرار** (بدون كود إضافي). الحل: TrustProxies. قيس في القسم 1: كان المفتاح يصبح `\|172.18.0.1` (العميل) | `16` |

### ما بقي سليماً (حالته مشتركة أصلاً)

| الحالة | أين تُحفظ | الإثبات |
|---|---|---|
| `Idempotency-Key` | MySQL، `unique(user_id, idempotency_key)` | 10 طلبات متزامنة بنفس المفتاح على 3 نسخ: 1 × 201 و 9 × 409، حجز واحد وطلب واحد (`15`) |
| إلغاء JWT عند الخروج | Redis (cache store) | logout على `app2` → نفس التوكن 401 على النسخ الثلاث (`15`) |
| عدّاد محاولات الدخول، rate limiter، كاش الكتالوج | Redis | 3 محاولات على 3 نسخ كتبت مفتاحاً واحداً (القسم 1) |
| الجلسات | MySQL (`SESSION_DRIVER=database`) | — |
| مهمة الـ scheduler | حاوية واحدة + `onOneServer()` بقفل Redis | — |
| نشر الـ outbox | `SELECT ... FOR UPDATE SKIP LOCKED` | بالتصميم: relay ثانٍ يتخطى الصفوف المقفلة (لم يُختبر هنا، الـ relay واحد) |
| OPcache (`validate_timestamps=0`) | ذاكرة كل نسخة | ليس عطلاً، لكن بعد تعديل كود PHP يجب `restart app app2 app3`، وإلا تعمل نسخ مختلفة من الكود معاً |

## 5. كيف تعيد الاختبارات

```powershell
# تحضير: مخزون، rate limit مرفوع، العمّال على مهمة db (بلا Mailtrap)
docker compose exec warehouse python -m app.cli set-stock 7 1000
$env:API_RATE_LIMIT_PER_MINUTE='100000'; $env:ORDER_WORKER_ACTION='db'
docker compose up -d app app2 app3 worker1 worker2 worker3
Remove-Item Env:API_RATE_LIMIT_PER_MINUTE, Env:ORDER_WORKER_ACTION

# 500 طلب موزّعة على النسخ (العدّ لكل نسخة في نهاية النتيجة)
Get-Content tests/load/distribution.js | docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run -
# أثناء تشغيله، في نافذة ثانية:  docker compose stop app2   /   docker kill ecommerce-app3

# 500 طلب شراء → 500 رسالة للعمّال
Get-Content tests/load/workers.js | docker run --rm -i --network e-commerce_default grafana/k6:0.54.0 run -
docker exec ecommerce-rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers

# الرجوع للوضع الطبيعي (حد 60، والعمّال يبقون على db)
docker compose up -d app app2 app3 worker1 worker2 worker3
docker compose start app2 app3      # إذا أوقفت نسخة بـ stop أو kill
```

عرض "قبل" لكل مشكلة، بلا تعديل كود:

| المشكلة | قبل | بعد |
|---|---|---|
| صور المنتجات | `$env:PRODUCT_IMAGES_PER_INSTANCE='1'; docker compose up -d app app2 app3` | `Remove-Item Env:PRODUCT_IMAGES_PER_INSTANCE; docker compose up -d app app2 app3` |
| حد Mailtrap | `$env:ORDER_EMAIL_WORKER_CACHE='array'; docker compose up -d worker1 worker2 worker3` | بدون المتغير |
| استراتيجية التوزيع | `LB_METHOD=round_robin` | `least_conn` |

تحذير: عرض "قبل" لحد Mailtrap يرسل رسائل حقيقية ويستهلك حصة Mailtrap المجانية.

**العمّال يعملون افتراضياً على `db`** (`docker-compose.yml`: `ORDER_WORKER_ACTION: ${ORDER_WORKER_ACTION:-db}`)، فلا يُرسل بريد تأكيد الطلب. لإرسال البريد (عرض Requirement 4 أو حد Mailtrap):

```powershell
$env:ORDER_WORKER_ACTION='email'; docker compose up -d worker1 worker2 worker3; Remove-Item Env:ORDER_WORKER_ACTION
docker compose up -d worker1 worker2 worker3      # الرجوع إلى db
```

## 6. ملاحظات صادقة عن القياسات

- **الأزمنة (1.5–2 ثانية للطلب)** سببها بيئة التطوير: `php artisan serve` بـ 8 عمليات لكل نسخة، وملفات على Windows bind mount. هي لا تقيس التصميم. الذي يُقاس هنا: التوزيع، ونسبة الفشل، والمقارنات بين وضعين في نفس البيئة.
- **`docker kill` لا يعيد تشغيل الحاوية** مع `restart: unless-stopped`، لأن Docker يعتبره إيقافاً يدوياً. الانهيار من داخل العملية هو الذي يعيدها.
- **`docker update --cpus 0` لا يزيل حد الـ CPU على Docker Desktop.** بعد اختبار النسخة البطيئة أُعيد إنشاء `app3`.
- **جولة أولى من المقارنة استُبعدت:** أعاد `docker compose up -d nginx` إنشاء النسخ بحد 60، فظهرت 429. وجولة ثانية استُبعدت لأن مخزون المنتج نفد (409). الأرقام في `02` من الجولات الصحيحة فقط.
- **حالة لم تُعالج:** إذا مات عامل بعد قبول Mailtrap للرسالة وقبل الـ ACK، يُعاد تسليمها ويصل البريد مرتين. كانت ممكنة مع عامل واحد أيضاً.
