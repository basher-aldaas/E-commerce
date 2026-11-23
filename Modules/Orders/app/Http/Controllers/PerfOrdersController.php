<?php

namespace Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;

class PerfOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = (int) ($input['per_page'] ?? 15);

        $queries = 0;
        $dbMs = 0.0;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$dbMs) {
            $queries++;
            $dbMs += $query->time;
        });


        //3 lines for solve N+1 problem
        $orders = Order::query()->with(['user:id,name', 'items.product:id,name'])
            ->latest()->orderByDesc('id')->paginate($perPage);
        $implementation = 'A: eager loading';

        //جيب الطلبات اولا وبعدها جيب العلاقات لكل طلب من خلال الماب
        //3 lines for N+1 problem
//         $orders = Order::query()
//             ->latest()->orderByDesc('id')->paginate($perPage);
//         $implementation = 'B: lazy loading (N+1)';

        $data = $orders->getCollection()->map(fn (Order $order) => [
            'id' => $order->id,
            'status' => $order->status,
            'total_amount' => (string) $order->total_amount,
            'created_at' => $order->created_at?->toIso8601String(),
            'customer' => ['id' => $order->user->id, 'name' => $order->user->name],//هون تبدأ المشكلة بحيث يجيب معلومات الممستخدم لكل طلب لحاله
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
            ])->values()->all(),
        ])->values()->all();

        return response()->json([
            'status' => 1,
            'data' => ['orders' => $data],
            'meta' => [
                'implementation' => $implementation,
                'queries' => $queries,
                'db_ms' => round($dbMs, 2),
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'data_sha256' => hash('sha256', json_encode($data)),
            ],
            'message' => 'Orders for the N+1 demonstration',
            'status_code' => 200,
            'timestamp' => now()->toIso8601String(),
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Server-Timing' => sprintf('db;dur=%.2f;desc="queries=%d"', $dbMs, $queries),
        ]);
    }
}
