# شرح الكلاسات — المتطلب الرابع (RabbitMQ + Outbox + Worker)

هذا الملف للدراسة. يشرح كل كلاس أُضيف أو عُدّل: **أين هو، ماذا يفعل، كيف يعمل، ولماذا صُمّم هكذا**.
اقرأه بالترتيب، لأنه يتبع مسار الطلب من لحظة إنشائه حتى وصول البريد.

---

## 0. الصورة الكاملة في دقيقة

المشكلة: عند إنشاء طلب نريد إرسال بريد تأكيد، لكن:

1. لا نريد أن ينتظر المستخدم إرسال البريد (بطء).
2. لا نريد أن يفشل الطلب إذا تعطل البريد.
3. لا نريد أن يضيع البريد إذا تعطل أي شيء في الطريق.

الحل من ثلاث مراحل، كل مرحلة في حاوية مختلفة:

```
[app]            يحفظ الطلب + "حدث" في جدول outbox_messages (نفس الـ transaction) → يرجع 201 فوراً
   │
[outbox-relay]   يقرأ الأحداث غير المنشورة من MySQL → ينشرها إلى RabbitMQ → يعلّمها "منشورة"
   │
[RabbitMQ]       يحفظ الرسالة على القرص في queue دائم
   │
[email-worker]   يستلم الرسالة → يرسل البريد → ACK  (أو يعيدها عند الفشل، وبعد 4 مرات → DLQ)
```

| الطبقة | الكلاسات |
|---|---|
| المرحلة 1: حفظ الحدث | `OrderPlacementService` (معدّل)، `OrderCreatedEvent`، `Outbox`، `OutboxMessage` |
| المرحلة 2: النشر | `RelayOutbox` (أمر)، `OutboxRelay`، `OutboxPublisher` (interface)، `RabbitMqOutboxPublisher`، `RabbitMqConnector`، `Topology` |
| المرحلة 3: الاستهلاك والبريد | `ConsumeOrderCreatedEmails` (أمر)، `OrderCreatedEmailConsumer`، `OrderCreatedMessage`، `InvalidMessageException`، `DeliveryOutcome`، `OrderConfirmationSender`، `OrderConfirmationMail` |
| الصيانة | `ReplayOrderEmailDeadLetters` (أمر)، `DeadLetterReplayer` |

لاحظ التقسيم: الكود المشترك (البنية التحتية) في `app/Messaging`، وكود الطلبات في `Modules/Orders`، وكود البريد في `Modules/Notifications`.
وحدة Orders **لا تعرف شيئاً** عن البريد، ووحدة Notifications **لا تستعمل أي كلاس** من Orders، فقط تقرأ JSON. هذا هو Separation of Concerns.

---

## المرحلة 1: حفظ الطلب والحدث معاً

### 1.1 `OrderPlacementService` (معدّل)

**المكان:** `Modules/Orders/app/Services/OrderPlacementService.php`

**ما الذي تغيّر:**

قبل: بعد حفظ الطلب كان يستدعي `$this->notifier->orderPlaced(...)` ⇒ البريد يُرسل داخل نفس طلب HTTP.

بعد: داخل `saveOrder()`، وداخل نفس `DB::transaction` التي تحفظ الطلب، يكتب الحدث:

```php
return DB::transaction(function () use (...) {
    $order = Order::create([...]);
    // ... حفظ order_items وحساب المجموع ...
    // ... تحويل الحجز إلى placed ...

    $order->load('items');

    $this->outbox->record(
        OrderCreatedEvent::TYPE,           // 'order.created'
        'order',
        $order->id,
        OrderCreatedEvent::data($order, $user, $products),
    );

    return $order;
});
```

**لماذا داخل نفس الـ transaction؟** هذه هي فكرة **Transactional Outbox** كلها:

| الحالة | بدون Outbox (نشر مباشر إلى RabbitMQ) | مع Outbox |
|---|---|---|
| الطلب حُفظ، RabbitMQ متوقف | الطلب موجود والبريد **ضاع** | الحدث محفوظ في MySQL، يُنشر لاحقاً |
| نشرنا الرسالة، ثم فشل commit الطلب | بريد لطلب **غير موجود** | rollback يحذف الطلب والحدث معاً |

هذه المشكلة اسمها **Dual Write**: الكتابة في نظامين (MySQL و RabbitMQ) لا يمكن جعلها ذرية. الـ Outbox يحوّلها إلى كتابة واحدة في MySQL فقط.

> سؤال متوقع: «لماذا لا تنشر إلى RabbitMQ بعد الـ commit مباشرة؟»
> الجواب: لأن التطبيق قد يموت بين الـ commit والنشر، أو يكون RabbitMQ متوقفاً، فيضيع الحدث. مع الـ Outbox الحدث محفوظ على القرص قبل أن نرجع للمستخدم.

---

### 1.2 `OrderCreatedEvent`

**المكان:** `Modules/Orders/app/Messaging/OrderCreatedEvent.php`

**الدور:** يحدد **محتوى** الحدث `order.created`، أي «العقد» بين خدمة الطلبات ومن يستمع لها.

```php
final class OrderCreatedEvent
{
    public const TYPE = 'order.created';

    public static function data(Order $order, User $user, Collection $products): array
    {
        return [
            'order' => [
                'id' => ..., 'status' => ..., 'total_amount' => '28.00', 'created_at' => ...,
                'items' => [ ['product_id', 'name', 'quantity', 'unit_price'], ... ],
            ],
            'customer' => ['id', 'name', 'email'],
        ];
    }
}
```

**قرارات التصميم:**

- **الحدث "سمين" (fat event):** يحمل اسم المنتج والسعر وبريد العميل. لماذا؟ حتى لا يحتاج الـ Worker إلى قراءة قاعدة بيانات الطلبات. الـ Worker لا يملك اتصالاً بـ MySQL أصلاً، وهذا يجعله مستقلاً تماماً.
- **المبالغ نصوص** (`"18.75"`) وليست float، حتى لا تظهر أخطاء مثل `18.749999`.
- **`final`** و **static**: لا حالة داخلية، مجرد دالة تحويل.

---

### 1.3 `Outbox`

**المكان:** `app/Messaging/Outbox/Outbox.php`

**الدور:** الكلاس الوحيد الذي يكتب في جدول الـ outbox. واجهة بسيطة لأي وحدة تريد نشر حدث.

```php
public function record(string $type, string $aggregateType, string|int $aggregateId, array $data): OutboxMessage
{
    if (DB::transactionLevel() === 0) {
        throw new LogicException('Outbox::record() must run inside the transaction ...');
    }

    $messageId = (string) Str::uuid();

    return OutboxMessage::create([
        'message_id' => $messageId,
        'type' => $type,
        'aggregate_type' => $aggregateType,
        'aggregate_id' => (string) $aggregateId,
        'available_at' => now(),
        'payload' => [
            'event_id' => $messageId,
            'event_type' => $type,
            'event_version' => 1,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ],
    ]);
}
```

**نقاط مهمة:**

- **الحماية `transactionLevel() === 0`:** إذا استدعاه أحد خارج transaction يرمي خطأ. لماذا؟ لأن كتابة الحدث في transaction منفصلة ترجع مشكلة Dual Write. الكود يمنع الاستعمال الخاطئ بدل أن يعتمد على أن المبرمج يتذكر.
- **`event_id` = UUID** ويُستعمل لاحقاً كـ `message_id` في RabbitMQ وكـ `Message-ID` في البريد ⇒ يمكن تتبع الحدث الواحد عبر كل الأنظمة.
- **الغلاف (envelope):** `event_type`, `event_version`, `occurred_at`, ثم `data`. حقل `event_version` يسمح بتغيير شكل الحدث مستقبلاً دون كسر المستهلكين القدامى.
- **aggregate_type / aggregate_id:** مصطلح من DDD. «الكيان» الذي حدث له الحدث (هنا الطلب رقم X). مفيد للبحث: «ما أحداث الطلب 14؟».

---

### 1.4 `OutboxMessage` (Model)

**المكان:** `app/Messaging/Outbox/OutboxMessage.php`
**الجدول:** `database/migrations/2026_11_17_000000_create_outbox_messages_table.php`

**الأعمدة ومعناها:**

| العمود | المعنى |
|---|---|
| `message_id` (unique) | رقم الحدث (UUID) |
| `type` | اسم الحدث، ويُستعمل كـ routing key في RabbitMQ |
| `payload` (json) | الرسالة كاملة |
| `attempts` | كم مرة حاول الـ relay نشره |
| `last_error` | آخر خطأ عند النشر (للمراجعة) |
| `available_at` | لا تحاول قبل هذا الوقت (backoff بعد الفشل) |
| `published_at` | NULL = لم يُنشر بعد. يُملأ فقط بعد تأكيد RabbitMQ |

**الفهرس** `(published_at, available_at, id)` مصمم لاستعلام الـ relay بالضبط:
«الصفوف غير المنشورة، المستحقة الآن، الأقدم أولاً».

**في الكلاس:**
- `UPDATED_AT = null`: الجدول ليس فيه `updated_at`.
- `casts`: `payload` → array تلقائياً.
- `body()`: يحوّل الـ payload إلى JSON، وهي البايتات التي تُرسل فعلاً إلى RabbitMQ.

---

## المرحلة 2: النشر من MySQL إلى RabbitMQ

### 2.1 `RelayOutbox` (أمر Artisan)

**المكان:** `app/Console/Commands/RelayOutbox.php`
**الأمر:** `php artisan outbox:relay` (يعمل باستمرار في حاوية `outbox-relay`)، أو `--once` لدفعة واحدة.

**الدور:** حلقة لا نهائية:

```
do {
    نتيجة = relay->relayBatch()           // دفعة واحدة
    إذا خطأ (الوسيط متوقف) → انتظر 1، 2، 4 ... حتى 30 ثانية
    إذا الدفعة أقل من الحد الأقصى → انتظر 500ms
} while (لم يصل SIGTERM)
```

**نقاط مهمة:**

- **`$this->trap([SIGTERM, SIGINT], ...)`:** عند `docker compose stop` يصل SIGTERM. لا نقتل البرنامج فوراً بل نرفع علماً `$stopping = true`، فيكمل الدفعة الحالية ثم يخرج. اسمه **Graceful Shutdown**.
- **`pause()`** تنام بخطوات صغيرة (50ms) حتى تستجيب لإشارة الإيقاف بسرعة، وتستدعي `$publisher->keepAlive()` في كل خطوة (انظر 2.4، مشكلة الـ heartbeat).
- **دفعة ممتلئة = هناك المزيد** ⇒ لا ينام، يكمل مباشرة.
- الأمر نفسه لا يحتوي منطقاً، فقط حلقة وتوقيت. المنطق في `OutboxRelay`. هذا يجعل المنطق قابلاً للاختبار دون حلقة لا نهائية.

---

### 2.2 `OutboxRelay`

**المكان:** `app/Messaging/Outbox/OutboxRelay.php`

**الدور:** دفعة واحدة: «اقرأ ← انشر ← سجّل النتيجة».

```php
public function relayBatch(): array
{
    return DB::transaction(function () {
        $messages = OutboxMessage::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit(50)
            ->lock('for update skip locked')
            ->get();

        try {
            $results = $this->publisher->publish($messages->all());
        } catch (Throwable $e) {
            // الوسيط غير متاح: سجّل الخطأ على كل الصفوف، لا شيء يُعلّم منشوراً
            ...
        }

        foreach ($messages as $message) {
            if ($results[$message->message_id] === null) {     // null = نجح
                $message->published_at = now(); ...
            } else {
                $this->markFailed($message, $error);            // attempts++, last_error, backoff
            }
        }
    });
}
```

**المفاهيم المهمة:**

1. **`FOR UPDATE SKIP LOCKED`:**
   - `FOR UPDATE` يقفل الصفوف المقروءة حتى نهاية الـ transaction.
   - `SKIP LOCKED` يجعل أي relay ثانٍ **يتخطى** الصفوف المقفولة بدل أن ينتظر.
   - النتيجة: يمكن تشغيل أكثر من relay بأمان، ولا يُنشر صف مرتين في نفس الوقت.

2. **`published_at` يُملأ بعد التأكيد فقط.** إذا لم يؤكد RabbitMQ، يبقى الصف NULL ويُعاد لاحقاً.

3. **Exponential backoff** في `markFailed()`:
   `delay = min(2^attempts, 60)` ⇒ 2، 4، 8، 16، 32، 60، 60 ثانية...
   لماذا؟ حتى لا نضرب وسيطاً متعطلاً عشرات المرات في الثانية.

4. **ما الضمان؟ at-least-once (مرة واحدة على الأقل):**
   لو مات الـ relay بعد أن أكد RabbitMQ وقبل commit في MySQL، سيرى الصف غير منشور وينشره مرة ثانية ⇒ رسالة مكررة. هذا مقبول ومقصود: **تكرار أفضل من ضياع**.

---

### 2.3 `OutboxPublisher` (Interface)

**المكان:** `app/Messaging/Outbox/OutboxPublisher.php`

```php
interface OutboxPublisher
{
    /** @return array<message_id, null|error> */
    public function publish(array $messages): array;
    public function keepAlive(): void;
    public function disconnect(): void;
}
```

**لماذا interface؟** هذا **Dependency Inversion** (حرف D من SOLID):
- `OutboxRelay` يعتمد على «شيء ينشر»، لا على RabbitMQ تحديداً.
- في الإنتاج: `RabbitMqOutboxPublisher` (الربط في `AppServiceProvider`).
- في الاختبارات: `FakeOutboxPublisher` (في الذاكرة) ⇒ نختبر منطق الـ relay دون RabbitMQ حقيقي.
- لو تغيّر الوسيط يوماً (Kafka مثلاً) نكتب تطبيقاً جديداً فقط.

الربط في `app/Providers/AppServiceProvider.php`:
```php
$this->app->singleton(OutboxPublisher::class, RabbitMqOutboxPublisher::class);
```
`singleton` = نسخة واحدة ⇒ اتصال واحد بـ RabbitMQ يُعاد استعماله.

---

### 2.4 `RabbitMqOutboxPublisher`

**المكان:** `app/Messaging/RabbitMq/RabbitMqOutboxPublisher.php`

**الدور:** النشر الفعلي إلى RabbitMQ بثلاث ضمانات:

| الضمان | الكود | يحمي من |
|---|---|---|
| **Persistent** | `'delivery_mode' => 2` | ضياع الرسالة إذا أعيد تشغيل RabbitMQ (تُكتب على القرص) |
| **Mandatory** | `basic_publish(..., mandatory: true)` | رسالة لا يوجد queue يستقبلها. بدونه تُحذف بصمت، ومعه يعيدها الوسيط (`basic.return`) |
| **Publisher Confirms** | `confirm_select()` + `wait_for_pending_acks_returns()` | الاعتقاد أن الرسالة وصلت بينما لم تُخزّن. الوسيط يرد `basic.ack` بعد أن يخزنها فعلاً |

**كيف تعمل النتيجة:**

```php
$this->results[$id] = 'not confirmed by the broker';   // افتراضياً: فشل
basic_publish(...);
wait_for_pending_acks_returns(5 ثوان);

// callbacks:
set_return_listener → results[id] = 'returned by the broker: 312 NO_ROUTE'
set_ack_handler     → results[id] = null    (نجح، إلا إذا كان مُرجَعاً)
set_nack_handler    → results[id] = 'rejected by the broker'
```

**تفصيلة دقيقة:** رسالة مُرجَعة (returned) يصلها `ack` أيضاً بعدها! لذلك الـ ack handler لا يكتب null إذا كانت النتيجة تبدأ بـ "returned". هذه حالة يخطئ فيها كثيرون.

**`channel()` (اتصال كسول، lazy):** لا يتصل إلا عند أول نشر. وعند الاتصال يستدعي `Topology::declare()` ⇒ الـ queues موجودة قبل أي نشر.

**`keepAlive()` ومشكلة الـ Heartbeat (اكتُشفت أثناء الاختبار):**
- RabbitMQ يرسل نبضات كل 30 ثانية، وإذا لم يسمع من العميل خلال ~60 ثانية يعتبره ميتاً ويغلق الاتصال.
- مكتبة php-amqplib لا تتعامل مع النبضات إلا عندما تقرأ أو تكتب.
- الـ relay الخامل لا يقرأ ولا يكتب ⇒ انقطع الاتصال ⇒ فشل أول نشر بعدها.
- الحل: `keepAlive()` يستدعي `checkHeartBeat()` أثناء الانتظار، فيرسل نبضة عند الحاجة.

---

### 2.5 `RabbitMqConnector`

**المكان:** `app/Messaging/RabbitMq/RabbitMqConnector.php`

**الدور:** مصنع (Factory) صغير يفتح اتصالاً من `config('messaging.rabbitmq')`.

```php
return new AMQPStreamConnection(
    host, port, user, password, vhost,
    connection_timeout: 3.0,
    read_write_timeout: 60.0,   // يجب أن يكون ≥ 2 × heartbeat
    keepalive: true,
    heartbeat: 30,
);
```

لماذا كلاس منفصل؟ لأن الـ relay والـ worker وأمر الـ replay كلهم يحتاجون اتصالاً بنفس الإعدادات ⇒ مكان واحد (DRY). وكلمات المرور من `.env` وليست في الكود.

---

### 2.6 `Topology`

**المكان:** `app/Messaging/RabbitMq/Topology.php`

**الدور:** يعرّف كل الـ exchanges والـ queues والروابط.

```
ecommerce.events (topic, durable)
  └─ order.created ─► notifications.order-created.email (quorum)
                         x-delivery-limit = 3
                         x-dead-letter-exchange = ecommerce.events.dlx
                         ▼ بعد آخر محاولة فاشلة
ecommerce.events.dlx (topic, durable)
  └─ order.created ─► notifications.order-created.email.dlq (classic, durable)
```

**مصطلحات RabbitMQ يجب أن تفهمها:**

- **Exchange:** «مكتب البريد». المنتج ينشر إليه، وهو يقرر أي queue يأخذ الرسالة.
- **Topic exchange:** يوجّه حسب الـ routing key (`order.created`). يمكن مستقبلاً ربط queue آخر بـ `order.*` مثلاً.
- **Binding:** قاعدة «الرسائل بمفتاح X تذهب إلى queue Y».
- **Durable:** الـ exchange/queue يبقى بعد إعادة تشغيل RabbitMQ.

**الـ queue الرئيسي (`emailQueueArguments`):**

| الوسيط | المعنى |
|---|---|
| `x-queue-type: quorum` | نوع حديث، دائماً durable، يكتب على القرص، **ويعدّ محاولات التسليم بنفسه** |
| `x-delivery-limit: 3` | بعد 3 تسليمات فاشلة إضافية (4 إجمالاً) يتخلص منها الوسيط |
| `x-dead-letter-exchange` | «يتخلص منها» = يرسلها إلى هذا الـ exchange بدل حذفها |
| `x-dead-letter-strategy: at-least-once` | لا يحذف الرسالة من الـ queue الأصلي حتى يؤكد الـ DLQ استلامها |
| `x-overflow: reject-publish` | شرط مطلوب لـ at-least-once |

**الـ DLQ (`deadLetterQueueArguments`) من نوع classic، لماذا؟**
في RabbitMQ 4.x كل quorum queue له حد تسليم **افتراضي = 20**. الـ DLQ ليس له DLX، فلو فحص أحد رسالة ميتة من الواجهة 20 مرة (Get messages + requeue) كانت **ستُحذف نهائياً**. الـ classic queue ليس له حد. (اكتُشف أثناء الاختبار.)

**لماذا التعريف idempotent ومن الطرفين؟** `queue_declare` بنفس الوسائط لا يفعل شيئاً إذا كان الـ queue موجوداً. الـ relay والـ worker كلاهما يعرّفانه ⇒ لا يهم من يبدأ أولاً. أهم حالة: الـ relay ينشر قبل أن يعمل الـ worker أبداً، فيجد الـ queue موجوداً ولا تضيع الرسالة.

---

## المرحلة 3: الـ Worker والبريد

### 3.1 `ConsumeOrderCreatedEmails` (أمر Artisan)

**المكان:** `Modules/Notifications/app/Console/ConsumeOrderCreatedEmails.php`
**الأمر:** `php artisan notifications:consume-order-created` (حاوية `email-worker`)

```php
while (! $this->stopping) {
    try {
        $connection = $connector->connect();
        $channel = $connection->channel();
        $topology->declare($channel);
        $channel->basic_qos(0, 1, false);                 // prefetch = 1
        $channel->basic_consume($queue, '', false,
                                no_ack: false,             // ACK يدوي
                                ..., callback: fn($m) => $consumer->handle($m));

        while (! $this->stopping && $channel->is_consuming()) {
            $channel->wait(null, false, 2);                // استيقظ كل 2 ثانية لتفحص الإيقاف
        }
    } catch (Throwable $e) {
        // انقطع الاتصال (RabbitMQ أُعيد تشغيله): أعد الاتصال بعد 1، 2، 4 ... 30 ثانية
    }
}
```

**المفاهيم:**

- **`no_ack = false` ⇒ Manual Acknowledgment:** الوسيط لا يحذف الرسالة عند تسليمها، بل ينتظر أن نقول ACK. إذا انقطع الاتصال قبل ACK يعيدها لمستهلك آخر.
- **`prefetch = 1`:** الوسيط يعطي العامل رسالة واحدة فقط غير مؤكدة. إذا مات العامل تكون في الطريق رسالة واحدة على الأكثر، وتعود.
- **إعادة الاتصال التلقائية:** في اختبار إعادة إنشاء حاوية RabbitMQ استمر العامل وحده دون تدخل.
- **Graceful shutdown:** نفس فكرة الـ relay. `docker compose stop` ⇒ يكمل الرسالة الحالية ويعمل لها ACK ثم يخرج.
- كالـ relay: الأمر حلقة فقط، والقرار في `OrderCreatedEmailConsumer`.

---

### 3.2 `OrderCreatedEmailConsumer` ⭐ (أهم كلاس في المتطلب)

**المكان:** `Modules/Notifications/app/Messaging/OrderCreatedEmailConsumer.php`

**الدور:** يقرر مصير كل رسالة: ACK أو NACK أو REJECT.

```php
public function handle(AMQPMessage $message): DeliveryOutcome
{
    $attempt = self::attempt($message);              // 1، 2، 3، 4
    $maxAttempts = maxRetries + 1;                   // 4

    // (1) رسالة تالفة؟ → REJECT بدون إعادة → DLQ فوراً
    try {
        $event = OrderCreatedMessage::fromJson($message->getBody());
    } catch (InvalidMessageException $e) {
        log error;
        $message->reject(false);
        return DeliveryOutcome::Rejected;
    }

    $this->respectRateLimit();                       // انتظر الحد الأدنى بين رسالتين

    // (2) أرسل البريد
    try {
        $receipt = $this->sender->send($event);
    } catch (Throwable $e) {
        // (3) فشل → سجّل الخطأ، انتظر قليلاً، NACK مع إعادة. لا ACK أبداً
        log error (attempt, error_class, error);
        if (! $final) usleep(backoff × attempt);
        $message->nack(true);                        // requeue = true
        return DeliveryOutcome::Retried;
    }

    // (4) نجح → الآن فقط ACK
    $message->ack();
    log info (to, email_message_id, smtp_reply);
    return DeliveryOutcome::Acked;
}
```

**جدول القرارات (احفظه):**

| الحالة | الرد للوسيط | ماذا يحدث للرسالة |
|---|---|---|
| البريد أُرسل | `basic.ack` | تُحذف من الـ queue |
| فشل الإرسال | `basic.nack(requeue=true)` | تعود للـ queue، والوسيط يزيد عدّاد المحاولات |
| فشل للمرة الرابعة | `basic.nack(requeue=true)` | الوسيط يرى أنها تجاوزت الحد ⇒ يرسلها للـ DLQ |
| JSON تالف / بريد غير صالح | `basic.reject(requeue=false)` | DLQ فوراً (لا فائدة من الإعادة) |
| العامل مات قبل الرد | (لا شيء) | الوسيط يعيدها تلقائياً، ويعدّها محاولة فاشلة |

**لماذا الوسيط يعدّ المحاولات وليس الكود؟**
- `attempt()` يقرأ الـ header اسمه `x-delivery-count` الذي يضيفه الـ quorum queue بنفسه.
- **ميزة كبيرة:** رسالة تسبب انهيار العامل في كل مرة (poison message) لا يستطيع الكود عدّها لأنه يموت قبل أن يعدّ، لكن الوسيط يعدّها ويرسلها للـ DLQ. لو كان العدّ في الكود لعلقت الرسالة إلى الأبد.

**لماذا لا ACK عند الفشل؟** لأن المتطلب يقول ذلك، ولأن ACK يعني «انتهيت، احذفها». حذفها قبل نجاح البريد = ضياعها.

**`respectRateLimit()`:** Mailtrap المجاني يرفض الإرسال المتقارب (`550 Too many emails per second`). ننتظر `ORDER_EMAIL_MIN_INTERVAL_MS` بين محاولتين. الانتظار والرسالة غير مؤكدة آمن تماماً، لأنها ما زالت في الـ queue.

**معلومات الخطأ للمراجعة:** كل فشل يُكتب في `storage/logs/order-email-worker.log` مع `event_id` ورقم المحاولة ونص الخطأ. والرسالة في الـ DLQ تحمل `x-death` (السبب، الـ queue، العدد، الوقت). بـ `event_id` تربط الاثنين.

---

### 3.3 `DeliveryOutcome` (Enum)

**المكان:** `Modules/Notifications/app/Messaging/DeliveryOutcome.php`

```php
enum DeliveryOutcome: string {
    case Acked = 'acked';
    case Retried = 'retried';
    case Rejected = 'rejected';
}
```

لماذا enum وليس string؟ لا يمكن كتابة قيمة خاطئة (`'ackd'`)، والاختبارات تقارن بقيم ثابتة. الأمر يطبعها: `message ... attempt 2 -> acked`.

---

### 3.4 `OrderCreatedMessage` (Value Object)

**المكان:** `Modules/Notifications/app/Messaging/OrderCreatedMessage.php`

**الدور:** يحوّل JSON الخام إلى كائن موثوق، ويرفض أي شيء غير صالح.

```php
public static function fromJson(string $body): self
{
    // JSON صالح؟                       وإلا InvalidMessageException
    // event_type === 'order.created'؟
    // يوجد order و customer؟
    // البريد صالح (FILTER_VALIDATE_EMAIL)؟
    // يوجد event_id و order.id و items؟
    return new self(eventId, occurredAt, orderId, status, totalAmount, items, customerName, customerEmail);
}
```

**مفاهيم:**
- **Value Object:** كل الخصائص `public readonly` ⇒ لا تتغير بعد الإنشاء.
- **Named constructor** (`fromJson`): مكان واحد للتحقق ⇒ إذا وصلنا لكائن، فهو صالح.
- **لا يستعمل أي كلاس من وحدة Orders.** العقد بين الوحدتين هو JSON فقط. لو فُصل الـ Worker إلى مشروع آخر يعمل كما هو.

### 3.5 `InvalidMessageException`

**المكان:** `Modules/Notifications/app/Messaging/InvalidMessageException.php`

استثناء خاص يميّز «رسالة تالفة دائماً» عن «فشل مؤقت». الأول ⇒ REJECT للـ DLQ، والثاني ⇒ NACK وإعادة. هذا التمييز مهم: إعادة رسالة تالفة 4 مرات مضيعة.

---

### 3.6 `OrderConfirmationSender`

**المكان:** `Modules/Notifications/app/Services/OrderConfirmationSender.php`

```php
public function send(OrderCreatedMessage $event): array
{
    $sent = Mail::to($event->customerEmail, $event->customerName)
        ->send(new OrderConfirmationMail($event));

    // استخرج رد خادم SMTP: "250 2.0.0 Ok: queued"
    preg_match_all("/< (250 [^\r\n]*)/", $sent->getDebug(), $m);

    return ['email_message_id' => ..., 'smtp_reply' => ...];
}
```

- **منطق البريد هنا وليس في Controller ولا في المستهلك** (Single Responsibility). المستهلك يقرر ACK/NACK، والمرسل يرسل فقط.
- **يرمي استثناء عند الفشل** (`TransportException`) ⇒ المستهلك يلتقطه ولا يعمل ACK.
- يرجع رد الخادم (`250 ... queued`) ليُسجّل كدليل أن Mailtrap قبل البريد.
- الإعدادات (`MAIL_HOST`, `MAIL_USERNAME`...) من `.env` عبر Laravel Mail، لا شيء في الكود.

---

### 3.7 `OrderConfirmationMail` (Mailable، منقول ومعدّل)

**المكان:** `Modules/Notifications/app/Mail/OrderConfirmationMail.php`
**القالب:** `Modules/Notifications/resources/views/emails/order-confirmation.blade.php`

**ما تغيّر عن النسخة القديمة (التي كانت في Orders):**

| قبل | بعد |
|---|---|
| يأخذ `Order` model (يحتاج قاعدة البيانات) | يأخذ `OrderCreatedMessage` (من الرسالة فقط) |
| `implements ShouldQueue` | **ليس** ShouldQueue |
| في وحدة Orders | في وحدة Notifications |

**لماذا أزلنا `ShouldQueue`؟** لو بقي، `Mail::send()` كان سيضعه في طابور Laravel ويرجع فوراً، فيعمل العامل ACK **قبل** أن يُرسل البريد فعلاً. نحن نريد إرسالاً متزامناً **داخل العامل** حتى نعرف النتيجة قبل ACK.

**`headers()`:**
```php
new Headers(messageId: "{$eventId}@order-events.dukkan", text: [
    'X-Order-Id' => ..., 'X-Event-Id' => ...,
]);
```
`Message-ID` ثابت لكل حدث ⇒ لو وصل البريد مرتين (at-least-once) يظهر أنه نفس البريد. تحققنا منه في Mailtrap: `Message-Id: <ea3bca39-…@order-events.dukkan>`.

---

## الصيانة: إعادة رسائل الـ DLQ

### `DeadLetterReplayer` و `ReplayOrderEmailDeadLetters`

**المكان:** `Modules/Notifications/app/Messaging/DeadLetterReplayer.php` و `Console/ReplayOrderEmailDeadLetters.php`
**الأمر:** `php artisan notifications:replay-dead-letters --limit=10`

**متى؟** بعد إصلاح سبب الفشل (مثلاً كلمة مرور SMTP)، نعيد الرسائل الميتة لتُرسل.

```
لكل رسالة في الـ DLQ:
    انسخها (احذف headers الوسيط: x-death, x-delivery-count)
    أضف x-replay-count و x-replayed-death-reason
    انشرها إلى ecommerce.events مع confirm + mandatory
    إذا تأكدت → ACK على نسخة الـ DLQ (تُحذف من الـ DLQ)
    إذا لا   → NACK (تبقى في الـ DLQ) وتوقف
```

**نفس قاعدة الأمان:** لا نحذف من الـ DLQ قبل أن يؤكد الوسيط وصول النسخة الجديدة. حذف `x-delivery-count` يعطي الرسالة 4 محاولات جديدة.
استُعمل فعلاً: 6 رسائل ميتة أُعيدت ووصل بريدها كلها.

---

## الإعدادات: `config/messaging.php`

| المفتاح | من `.env` | الافتراضي | المعنى |
|---|---|---|---|
| `rabbitmq.*` | `RABBITMQ_HOST/PORT/USER/PASSWORD/VHOST` | | الاتصال |
| `rabbitmq.heartbeat` | `RABBITMQ_HEARTBEAT` | 30 | ثواني |
| `topology.order_created_email.max_retries` | `ORDER_EMAIL_MAX_RETRIES` | 3 | ⇒ x-delivery-limit |
| `…retry_backoff_ms` | `ORDER_EMAIL_RETRY_BACKOFF_MS` | 2000 | انتظار × رقم المحاولة قبل NACK |
| `…min_interval_ms` | `ORDER_EMAIL_MIN_INTERVAL_MS` | 0 (عندنا 15000) | rate limit لمزود البريد |
| `outbox.batch_size` | `OUTBOX_BATCH_SIZE` | 50 | صفوف لكل دفعة |
| `outbox.poll_interval_ms` | | 500 | الانتظار عند الفراغ |
| `outbox.max_backoff_seconds` | | 60 | سقف الـ backoff |

---

## الاختبارات وما يثبته كل ملف

| الملف | نوعه | يثبت |
|---|---|---|
| `tests/Feature/Requirement4/OrderCreatedOutboxTest.php` | Feature | الطلب يكتب حدثاً واحداً كاملاً؛ لا بريد داخل الطلب؛ فشل الحدث ⇒ rollback للطلب؛ طلب مرفوض ⇒ لا حدث؛ بريد معطل لا يؤثر |
| `tests/Feature/Requirement4/OutboxRelayTest.php` | Feature (Fake publisher) | نشر وتعليم؛ لا إعادة نشر؛ الفشل يبقى مع backoff؛ الوسيط المتوقف لا يضيع شيئاً؛ الـ backoff 2، 4، 8، 10، 10 |
| `tests/Feature/Requirement4/OrderCreatedEmailConsumerTest.php` | Feature (Mock channel) | ACK بعد الإرسال فقط؛ NACK عند الفشل بلا ACK؛ المحاولة الأخيرة؛ REJECT للتالف؛ محتوى البريد و Message-ID؛ الـ rate limit |
| `tests/Unit/Requirement4/MessagingContractTest.php` | Unit | وسائط الـ queues؛ التحقق من JSON |
| `tests/Integration/RabbitMqIntegrationTest.php` | على RabbitMQ حقيقي | confirm + persistent؛ رسالة unroutable تُرفض؛ delivery-limit ⇒ DLQ مع `x-death`؛ الـ replay |
| `tests/Support/FakeOutboxPublisher.php` | أداة | وسيط وهمي في الذاكرة |

**كيف يُختبر المستهلك بدون RabbitMQ؟** نصنع `AMQPMessage` حقيقية ونعطيها channel وهمياً (Mockery)، ثم نتحقق أن `basic_ack` أو `basic_nack(99, false, true)` أو `basic_reject(99, false)` استُدعيت. `ack()` في php-amqplib يستدعي `$channel->basic_ack(...)` فقط.

---

## أسئلة متوقعة من الدكتور مع أجوبة قصيرة

1. **ما Transactional Outbox؟** كتابة الحدث في جدول بنفس transaction البيانات، ثم نشره من عملية منفصلة. يحل مشكلة Dual Write.
2. **لماذا لا ترسل البريد بـ Laravel Queue العادي؟** المتطلب يطلب RabbitMQ مع تحكم صريح بـ ACK و DLQ. طابور Laravel على RabbitMQ يعيد المحاولة بنشر نسخة جديدة وعمل ACK للقديمة، وهذا عكس «لا ACK عند الفشل».
3. **ما الفرق بين ACK و NACK و REJECT؟** ACK = نجح احذفها. NACK(requeue) = فشل أعدها. REJECT(no requeue) = لا تعدها (تذهب لـ DLX إن وُجد).
4. **من يعدّ المحاولات؟** الوسيط (quorum queue، `x-delivery-count`)، ولذلك حتى رسالة تقتل العامل تصل للـ DLQ.
5. **ماذا لو مات العامل بعد إرسال البريد وقبل ACK؟** الوسيط يعيد الرسالة ⇒ بريد مكرر. الضمان at-least-once، والتكرار ظاهر بنفس Message-ID. منعه تماماً يحتاج تخزين `event_id` المرسَلة (idempotent consumer)، وهو غير منفذ.
6. **لماذا prefetch = 1؟** رسالة واحدة على الأكثر قيد المعالجة. عند الموت تعود رسالة واحدة فقط، والتوزيع عادل بين أكثر من عامل.
7. **كيف تبقى الرسائل بعد حذف حاوية RabbitMQ؟** queue durable + رسالة persistent + Docker volume + hostname ثابت (البيانات محفوظة باسم العقدة `rabbit@ecommerce-rabbitmq`).
8. **لماذا الـ Worker في حاوية مستقلة؟** يمكن إيقافه وتوسيعه وإعادة تشغيله دون لمس الـ API، ولا يحتاج قاعدة البيانات.
9. **كيف تمنع relay ثانياً من نشر نفس الصف؟** `FOR UPDATE SKIP LOCKED`.
10. **أين معلومات الخطأ للرسالة الميتة؟** headers الـ `x-death` في الـ DLQ + سطر لكل محاولة في `order-email-worker.log` بنفس `event_id`.
