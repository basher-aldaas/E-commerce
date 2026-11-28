<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Modules\Notifications\Messaging\OrderCreatedMessage;

// مهمة بديلة عن الإيميل لاختبار العمال الثلاثة (المتطلب 5) بدون حدود Mailtrap:
// ORDER_WORKER_ACTION=db
// استعلام حقيقي (أكثر المنتجات مبيعاً) وبعدها SLEEP على نفس الاتصال بقاعدة البيانات
// لتمثيل عملية طويلة، مدتها ORDER_WORKER_DB_TASK_MS
class OrderStatsDbTask
{
    public function run(OrderCreatedMessage $event): array
    {
        $start = microtime(true);

        $top = DB::table('order_items')
            ->selectRaw('product_id, SUM(quantity) AS sold')
            ->groupBy('product_id')
            ->orderByDesc('sold')
            ->limit(5)
            ->get();

        $seconds = max(0, (int) config('messaging.topology.order_created_email.db_task_ms')) / 1000;
        DB::select('SELECT SLEEP(?)', [$seconds]);

        return [
            'task' => 'top_products',
            'top_product_id' => $top->first()?->product_id,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
