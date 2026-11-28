# Requirement 4 — البريد غير المتزامن عبر RabbitMQ

إنشاء الطلب يرجع للمستخدم فوراً، وبريد تأكيد الطلب يُرسَل لاحقاً من حاوية Worker مستقلة.
لا يضيع أي حدث بين MySQL و RabbitMQ و Worker، حتى عند توقف أي منها.

## 1. المعمارية

```
 Client (Postman)
    │ POST /api/v1/orders
    ▼
 app (Laravel API) ── MySQL transaction واحدة ──►  orders + order_items + outbox_messages(order.created)
    │                                                       │
    │ 201 فوراً (لا اتصال بـ RabbitMQ ولا SMTP)             │ SELECT ... FOR UPDATE SKIP LOCKED
    ▼                                                       ▼
                                                   outbox-relay (php artisan outbox:relay)
                                                       │ persistent + mandatory + publisher confirms
                                                       ▼
                                   ecommerce.events (topic, durable)
                                       │ order.created
                                       ▼
                     notifications.order-created.email  (quorum, durable, x-delivery-limit=3)
                                       │ prefetch 1, manual ACK
                                       ▼
                       worker1-3 (php artisan notifications:consume-order-created) ──SMTP──► Mailtrap
                                       │ بعد آخر محاولة فاشلة (الوسيط نفسه)
                                       ▼
                     ecommerce.events.dlx (topic) ──► notifications.order-created.email.dlq (classic, durable)
```

| الحاوية | الأمر | يحتاج | المسؤولية |
|---|---|---|---|
| `app` | `php artisan serve` | MySQL, Warehouse | يحفظ الطلب وحدث `order.created` في نفس الـ transaction. لا يرسل بريداً ولا يتصل بـ RabbitMQ |
| `outbox-relay` | `php artisan outbox:relay` | MySQL, RabbitMQ | ينشر صفوف الـ outbox غير المنشورة، ويعلّمها منشورة بعد تأكيد الوسيط فقط |
| `worker1`-`worker3` | `php artisan notifications:consume-order-created` | RabbitMQ, SMTP, Redis (وMySQL لمهمة `ORDER_WORKER_ACTION=db` فقط) | يستهلك الحدث، يرسل البريد، ثم ACK |
| `rabbitmq` | `rabbitmq:4.1-management` | Volume `rabbitmq-data` | الوسيط + واجهة الإدارة |

### الملفات

| الملف | الدور |
|---|---|
| `database/migrations/2026_11_17_000000_create_outbox_messages_table.php` | جدول الـ outbox |
| `app/Messaging/Outbox/Outbox.php` | `record()`: يكتب الحدث، ويرفض العمل خارج transaction |
| `app/Messaging/Outbox/OutboxRelay.php` | دفعة واحدة: claim → publish → mark / backoff |
| `app/Messaging/RabbitMq/RabbitMqOutboxPublisher.php` | persistent, mandatory, publisher confirms, heartbeat |
| `app/Messaging/RabbitMq/Topology.php` | تعريف الـ exchanges والـ queues (idempotent) |
| `app/Console/Commands/RelayOutbox.php` | `outbox:relay` (حلقة مستمرة أو `--once`) |
| `Modules/Orders/app/Messaging/OrderCreatedEvent.php` | محتوى حدث `order.created` |
| `Modules/Orders/app/Services/OrderPlacementService.php` | يسجّل الحدث داخل `saveOrder()` (نفس الـ transaction) |
| `Modules/Notifications/app/Console/ConsumeOrderCreatedEmails.php` | حلقة الـ Worker (prefetch 1، إعادة اتصال، إيقاف نظيف) |
| `Modules/Notifications/app/Messaging/OrderCreatedEmailConsumer.php` | قرار ACK / NACK / REJECT لكل رسالة |
| `Modules/Notifications/app/Messaging/OrderCreatedMessage.php` | قراءة والتحقق من JSON الحدث |
| `Modules/Notifications/app/Mail/OrderConfirmationMail.php` + `resources/views/emails/order-confirmation.blade.php` | البريد |
| `Modules/Notifications/app/Console/ReplayOrderEmailDeadLetters.php` + `Messaging/DeadLetterReplayer.php` | إعادة رسائل الـ DLQ بعد إصلاح السبب |
| `config/messaging.php` | كل الإعدادات (من `.env`) |

## 2. محتوى الحدث `order.created`

```json
{
  "event_id": "uuid (= AMQP message_id)",
  "event_type": "order.created",
  "event_version": 1,
  "occurred_at": "2026-11-17T10:23:30+00:00",
  "data": {
    "order": { "id": 1, "status": "pending", "total_amount": "18.75", "created_at": "...",
               "items": [ { "product_id": 7, "name": "Hydrating Face Cream", "quantity": 1, "unit_price": "18.75" } ] },
    "customer": { "id": 2, "name": "Test Customer", "email": "user@system.com" }
  }
}
```

الحدث يحمل كل ما يحتاجه البريد، لذلك الـ Worker لا يقرأ قاعدة بيانات الطلبات.

## 3. آليات ضمان عدم ضياع الرسائل

| الخطر | الآلية |
|---|---|
| الطلب حُفظ لكن النشر فشل (dual write) | **Transactional Outbox**: الحدث يُكتب في `outbox_messages` داخل نفس transaction الطلب. إما الاثنان أو لا شيء (اختبار `test_if_the_event_cannot_be_stored_the_order_is_rolled_back_and_the_stock_released`) |
| RabbitMQ متوقف أو غير موجود | الطلب ينجح (201). الصف يبقى `published_at = NULL` مع `last_error` و backoff (2^n ثانية، حد 60) ويُنشر عند عودة الوسيط |
| الوسيط استلم لكن لم يخزّن | **Publisher confirms**: الصف لا يُعلَّم منشوراً قبل `basic.ack` |
| لا يوجد queue مرتبط (رسالة unroutable) | **mandatory**: الوسيط يعيدها (`basic.return`) فتُعتبر فشلاً. والـ relay يعرّف الـ queues بنفسه قبل النشر |
| إعادة تشغيل/حذف حاوية RabbitMQ | **Durable** exchanges/queues + **persistent** messages (`delivery_mode=2`) + Volume `rabbitmq-data` + `hostname` ثابت (`rabbit@ecommerce-rabbitmq`) |
| توقف الـ Worker | الرسائل تنتظر في الـ queue بحالة Ready |
| الـ Worker مات قبل ACK | **Manual ACK**: الوسيط يعيد تسليم الرسالة غير المؤكدة. `prefetch=1` = رسالة واحدة على الأكثر قيد المعالجة |
| فشل SMTP | **لا ACK**: `basic.nack(requeue=true)` بعد انتظار `ORDER_EMAIL_RETRY_BACKOFF_MS × attempt` |
| فشل متكرر | الـ quorum queue يعدّ التسليمات (`x-delivery-count`)، وبعد `x-delivery-limit` يرسلها الوسيط نفسه إلى **DLX → DLQ** (`x-dead-letter-strategy=at-least-once`) |
| رسالة غير قابلة للمعالجة (JSON خاطئ) | `basic.reject(requeue=false)` → DLQ مباشرة |
| مراجعة الخطأ | الـ DLQ يحتفظ بالرسالة الأصلية + `x-death` (reason, queue, count, time). سطر لكل محاولة مع نص الخطأ في `storage/logs/order-email-worker.log` (مرتبط بـ `event_id`) |
| فحص الـ DLQ من الواجهة | الـ DLQ من نوع **classic**: RabbitMQ 4.x يعطي كل quorum queue حد تسليم افتراضي 20، فالضغط على "Get messages (requeue)" 20 مرة كان سيحذف الرسالة |
| إصلاح السبب ثم إعادة الإرسال | `notifications:replay-dead-letters`: إعادة نشر مع confirm، ثم ACK للـ DLQ (لا حذف قبل التأكيد) |

**الضمان: at-least-once.** في حالتين نادرتين قد يصل بريد مكرر: موت الـ relay بعد تأكيد الوسيط وقبل commit، أو موت الـ Worker بعد قبول SMTP وقبل ACK. لذلك `Message-ID` البريد مشتق من `event_id` (`<event_id>@order-events.dukkan`) ليظهر التكرار.

## 4. الإعداد

### متغيرات `.env` (لا تُرفع إلى Git)

```dotenv
# RabbitMQ (docker compose يرفض البدء بدون كلمة مرور)
RABBITMQ_USER=ecommerce
RABBITMQ_PASSWORD=<any local value>
RABBITMQ_VHOST=/

# SMTP للـ Worker فقط (حاوية app تبقى MAIL_MAILER=log)
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=<Mailtrap inbox username>
MAIL_PASSWORD=<Mailtrap inbox password>
MAIL_FROM_ADDRESS="orders@dukkan.test"
MAIL_TIMEOUT=15

# المحاولات
ORDER_EMAIL_MAX_RETRIES=3            # 1 + 3 = 4 محاولات ثم DLQ
ORDER_EMAIL_RETRY_BACKOFF_MS=2000    # انتظار 2s × رقم المحاولة قبل NACK
ORDER_EMAIL_MIN_INTERVAL_MS=15000    # Mailtrap المجاني يرفض الإرسال المتقارب (550 Too many emails per second)
```

قيم Mailtrap: **Email Testing → Inboxes → (inbox) → SMTP Settings**. بعد تغيير `.env`: `docker compose up -d --force-recreate worker1 worker2 worker3`.

> تغيير `ORDER_EMAIL_MAX_RETRIES` بعد إنشاء الـ queue يحتاج حذف `notifications.order-created.email` من الواجهة (وسائط الـ queue لا تتغير). الـ Worker أو الـ relay يعيد تعريفه.

### التشغيل الأول

```powershell
docker compose build app          # يضيف ext-sockets (مرة واحدة)
docker compose up -d              # rabbitmq + outbox-relay + worker1-3 مع باقي الخدمات
docker compose ps                 # rabbitmq يجب أن يكون (healthy)
```

> **العمّال يعملون افتراضياً على مهمة قاعدة بيانات (`ORDER_WORKER_ACTION=db`، Requirement 5) ولا يرسلون بريداً.** قبل اختبارات هذا الملف شغّلهم على البريد، وبعدها أرجعهم:
>
> ```powershell
> $env:ORDER_WORKER_ACTION='email'; docker compose up -d worker1 worker2 worker3; Remove-Item Env:ORDER_WORKER_ACTION
> docker compose up -d worker1 worker2 worker3      # الرجوع إلى db
> ```

## 5. تشغيل وإيقاف الـ Worker

```powershell
docker compose stop worker1 worker2 worker3       # إيقاف نظيف: يكمل الرسالة الحالية ثم يخرج ("Email worker stopped.")
docker compose start worker1 worker2 worker3
docker compose restart worker1 worker2 worker3    # بعد تعديل كود PHP (OPcache)
docker compose logs -f worker1 worker2 worker3    # سطر لكل رسالة: attempt N -> acked / retried / rejected
docker kill -s KILL ecommerce-worker1   # موت مفاجئ (للاختبار): الرسالة غير المؤكدة تعود للـ queue
```

الـ relay بنفس الطريقة (`outbox-relay`). إيقاف أي منهما لا يؤثر على الـ API.

سجل المحاولات: `storage/logs/order-email-worker.log`.

## 6. المراقبة من RabbitMQ Management UI

http://localhost:15672 (بيانات الدخول من `RABBITMQ_USER` / `RABBITMQ_PASSWORD`).

- **Queues and Streams**:
  - `notifications.order-created.email`: **Ready** = تنتظر Worker، **Unacked** = قيد المعالجة (0 أو 1 بسبب prefetch 1)، **Consumers** = 1 عندما يعمل الـ Worker. في **Features** تظهر `D` (durable)، `DLX`، `delivery-limit`.
  - `notifications.order-created.email.dlq`: الرسائل التي استنفدت المحاولات.
- مراجعة رسالة ميتة: افتح الـ DLQ → **Get messages** → Ack mode **Nack message requeue true** → Get. تظهر الرسالة كاملة والـ headers: `x-first-death-reason = delivery_limit`، `x-death` (queue, count, time).
  - تنبيه: على الـ queue الرئيسي (quorum) كل "Get … requeue" تُحسب محاولة فاشلة. للفحص استعمل الـ DLQ.
- **Exchanges**: `ecommerce.events` و `ecommerce.events.dlx` وروابطهما (Bindings).
- من الطرفية:

```powershell
docker exec ecommerce-rabbitmq rabbitmqctl list_queues name type durable messages_ready messages_unacknowledged consumers
docker exec ecommerce-rabbitmq rabbitmqctl list_bindings source_name destination_name routing_key
docker compose exec mysql mysql -uroot E-commerce -e "SELECT id,type,aggregate_id,attempts,published_at,last_error FROM outbox_messages ORDER BY id DESC LIMIT 10"
```

### إعادة رسائل الـ DLQ بعد إصلاح السبب

```powershell
docker compose exec worker1 php artisan notifications:replay-dead-letters --limit=10
```

كل رسالة تُنشر من جديد إلى `ecommerce.events` (persistent، مع confirm)، ولا تُحذف من الـ DLQ إلا بعد تأكيد الوسيط. النسخة تبدأ بعدّاد محاولات جديد، وتحمل `x-replay-count` و `x-replayed-death-reason`.

## 7. خطوات اختبارات القبول

النتائج الفعلية لكل خطوة في `docs/messaging/acceptance-2026-11-17/`.

Postman: استورد `postman/E-commerce-Requirement-4.postman_collection.json` (Login ثم Create order، مع اختبار 201 والزمن). أو من Docker بدون تثبيت شيء:

```powershell
docker run --rm --network e-commerce_default -v "${PWD}/postman:/etc/newman" postman/newman:6-alpine run E-commerce-Requirement-4.postman_collection.json --env-var baseUrl=http://app:8000 --env-var productId=7 -n 1
```

| # | السيناريو | الأوامر | المتوقع |
|---|---|---|---|
| 1 | طلب عادي | newman `-n 1` ثم `docker compose logs worker1 worker2 worker3` | 201، صف outbox `published_at` مملوء، `attempt 1 -> acked`، البريد في Mailtrap |
| 2 | Worker متوقف + 5 طلبات | `docker compose stop worker1 worker2 worker3`، newman `-n 5`، `rabbitmqctl list_queues`، `docker compose start worker1 worker2 worker3` | 5 طلبات 201، Ready=5 Consumers=0، ثم 5 `acked` و Ready=0 |
| 3 | موت قبل ACK | أوقف الـ Worker، طلبان، شغّله، عندما `unacked=1` نفّذ `docker kill -s KILL ecommerce-worker1`، ثم `start` | بعد القتل Ready=1، بعد التشغيل نفس `message_id` بـ `attempt 2 -> acked` |
| 4 | فشل SMTP | `docker compose stop worker1 worker2 worker3` ثم `docker compose run -d --no-deps --name ecommerce-email-worker-broken -e MAIL_HOST=smtp.invalid -e ORDER_EMAIL_MIN_INTERVAL_MS=0 worker1`، طلب واحد | 4 محاولات (`retried`)، ثم DLQ Ready=1 مع `x-first-death-reason=delivery_limit`، والخطأ في `order-email-worker.log`. بعدها `docker rm -f ecommerce-email-worker-broken` |
| 5 | إعادة إنشاء RabbitMQ | Worker متوقف، 3 طلبات، `docker compose stop rabbitmq; docker compose rm -f rabbitmq; docker compose up -d rabbitmq` | container id جديد، نفس الـ queues ونفس الرسائل (و الـ DLQ)، ثم المعالجة عند تشغيل الـ Worker |

> **لا تحقن فشل SMTP بكلمة مرور خاطئة.** محاولات الدخول الفاشلة المتكررة تقفل حساب Mailtrap مؤقتاً (`535 5.7.0 Too many failed login attempts`)، فيفشل حتى الـ Worker الصحيح. استخدم `MAIL_HOST=smtp.invalid` (نطاق محجوز لا يوجد، فشل DNS بدون أي محاولة دخول).

## 8. الاختبارات الآلية

```powershell
docker compose stop outbox-relay      # الاختبارات تعيد إنشاء الجداول التي يقرؤها الـ relay
docker compose exec app php artisan test --testsuite=Unit,Feature
docker compose exec -e RABBITMQ_INTEGRATION=1 app php artisan test tests/Integration/RabbitMqIntegrationTest.php
docker compose start outbox-relay
docker compose exec app php artisan migrate:fresh --seed --force   # الاختبارات تمسح قاعدة E-commerce
```

| الملف | ما يثبته |
|---|---|
| `tests/Feature/Requirement4/OrderCreatedOutboxTest.php` | حدث واحد كامل لكل طلب، لا بريد داخل الطلب، rollback للطلب إذا فشل الحدث، لا حدث لطلب مرفوض، الطلب ينجح مع بريد معطّل |
| `tests/Feature/Requirement4/OutboxRelayTest.php` | النشر والتعليم، لا إعادة نشر، الفشل يبقى مع الخطأ و backoff، وسيط غير متاح لا يضيع شيئاً، الأمر `--once` |
| `tests/Feature/Requirement4/OrderCreatedEmailConsumerTest.php` | ACK بعد الإرسال فقط، NACK(requeue) عند الفشل بدون ACK، المحاولة الأخيرة، REJECT للرسائل الخاطئة، محتوى البريد، rate limit |
| `tests/Unit/Requirement4/MessagingContractTest.php` | وسائط الـ queues، التحقق من JSON |
| `tests/Integration/RabbitMqIntegrationTest.php` | على RabbitMQ حقيقي: confirm + persistent، رسالة unroutable، delivery-limit → DLQ مع `x-death`، replay |

## 9. نتائج اختبارات القبول الفعلية (2026-11-17)

كل الأرقام من التشغيل الفعلي، والمخرجات الكاملة في `docs/messaging/acceptance-2026-11-17/`. لا توجد نتائج مقدّرة.

| # | السيناريو | النتيجة |
|---|---|---|
| 1 | طلب من Postman (newman) | 201. التشغيل الأول بعد إعادة تشغيل الحاوية: **1706 ms** (فشل شرط < 1500 ms؛ OPcache بارد، تكرر 1869 ms في السيناريو 2 والـ Worker متوقف تماماً، إذن ليس البريد). بعد التسخين: **340 / 456 / 422 ms**. الصف نُشر خلال ~1 ثانية، والـ Worker أرسل وعمل ACK |
| 2 | Worker متوقف + 5 طلبات | 5/5 طلبات 201 (305–467 ms بعد الأول). الـ queue: **Ready=5، Unacked=0، Consumers=0**، والرسائل `delivery_mode=2`. بعد التشغيل: **5/5 ACK، 0 مفقود، DLQ=0**. 4 رسائل احتاجت محاولة ثانية بسبب `550 Too many emails per second` من Mailtrap (لذلك أُضيف `ORDER_EMAIL_MIN_INTERVAL_MS`) |
| 3 | قتل الـ Worker قبل ACK | قبل القتل `ready=0 unacked=1`؛ بعد `SIGKILL` (exit 137) `ready=1 unacked=0`؛ بعد التشغيل نفس `message_id` (`5b8ca89d…`) بـ **attempt 2 -> acked** |
| 4 | فشل SMTP | **4 محاولات** (1 + 3) لكل رسالة، بدون ACK، ثم الوسيط نقلها إلى DLQ مع `x-first-death-reason=delivery_limit` و `x-death` (queue, count=1, exchange, routing-keys, time). نص الخطأ لكل محاولة في `order-email-worker.log`. جُرّب بكلمة مرور خاطئة (run 1/2) وبـ `MAIL_HOST=smtp.invalid` (run 3). فحص الـ DLQ مرتين لم يحذف الرسالة |
| 5 | إعادة إنشاء RabbitMQ | container id تغيّر (`62c6db54738e` → `42af9bd7445f`) على نفس الـ Volume. بقيت الـ queues و**3 رسائل Ready + رسالة الـ DLQ**. طلب أثناء غياب الوسيط تماماً: **201 في 506 ms**، وصف الـ outbox نُشر عند عودة الوسيط (attempts=4). الـ Worker استهلك الرسائل الأربع بعدها |
| 6 | إعادة الـ DLQ | `replay-dead-letters`: **6/6** رسائل أُعيدت، الـ DLQ أصبح 0، وأُرسل البريد للـ 6 |
| 7 | خدمة البريد الخارجية | Mailtrap: **Total sent = 17** = عدد عمليات الإرسال الحقيقية التي عمل لها الـ Worker ACK (17). البريد #14 في Mailtrap يحمل `Message-Id: <ea3bca39-…@order-events.dukkan>` = `event_id` |
| — | الاختبارات الآلية | Unit + Feature: **275 passed** (2890 assertions). RabbitMQ Integration: **5 passed** |

### مشاكل ظهرت أثناء الاختبار وأُصلحت

1. **Heartbeat للـ relay**: اتصال AMQP خامل > 60 ثانية أُغلق (`Missed server heartbeat`)، ففشل نشر صف واحد مرة ثم نُشر بعد 2 ثانية (لا فقدان). الإصلاح: `keepAlive()` أثناء الانتظار. بعده لم يتكرر (خمول 4 دقائق ثم نشر من أول محاولة).
2. **DLQ من نوع quorum**: حد تسليم افتراضي 20 في RabbitMQ 4.x قد يحذف رسالة ميتة عند الفحص المتكرر. أصبح classic.
3. **Rate limit لـ Mailtrap المجاني**: أُضيف `ORDER_EMAIL_MIN_INTERVAL_MS` (15000 هنا).
4. **قفل حساب Mailtrap**: حقن الفشل بكلمة مرور خاطئة أدى إلى `535 Too many failed login attempts` لمدة ~10 دقائق، ففشل 4 رسائل صحيحة إلى الـ DLQ (لم تضع، أُعيدت لاحقاً). طريقة الحقن الموثقة الآن: `MAIL_HOST=smtp.invalid`.

### ما لم يُتحقق منه

- التكرار at-least-once لم يُختبر حياً (موت الـ relay بين تأكيد الوسيط و commit، أو موت الـ Worker بين قبول SMTP و ACK). الـ Worker لا يمنع التكرار؛ `Message-ID` المشتق من `event_id` يجعله ظاهراً فقط.
- طلب #8: رسالته الميتة حُذفت عند تحويل الـ DLQ إلى classic، فلم يُرسل بريده (بيانات اختبار).
- RabbitMQ عقدة واحدة: الـ quorum queue بلا نسخ على عقد أخرى؛ الحماية هنا القرص (Volume) فقط.
- لا تنظيف لصفوف `outbox_messages` المنشورة القديمة.
- الأداء تحت حمل (معدل الرسائل في الثانية) لم يُقس؛ مع Mailtrap المجاني السرعة ~بريد كل 15 ثانية.
