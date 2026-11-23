<?php

/**
 * One racer in the concurrency tests, run as its own PHP process by
 * OrderFlowIntegrationTest: an order request (`place`), a stalled order request
 * (`stall`, then `wake`) or a `warehouse:reconcile` run (`reconcile`), always
 * against the real Warehouse. It works on the application's MySQL database
 * E-commerce (the only Laravel database), commits its own rows so that the other
 * processes can see them, and `cleanup` removes exactly those rows again.
 */

if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'E-commerce') {
    fwrite(STDERR, "Refusing to run: this fixture only works on the MySQL database E-commerce.\n");
    exit(1);
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderReservation;
use Modules\Orders\Services\OrderPlacementService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

switch ($argv[1]) {
    case 'setup':
        $run = bin2hex(random_bytes(4));
        $category = Category::create(['name' => "Race {$run}"]);
        $product = Product::create(['category_id' => $category->id, 'name' => "Last unit {$run}", 'price' => 5, 'is_active' => true]);

        $userIds = [];
        for ($i = 0; $i < (int) $argv[2]; $i++) {
            $userIds[] = User::create(['name' => "Racer {$i}", 'email' => "racer{$i}-{$run}@example.test", 'password' => 'password123'])->id;
        }

        echo json_encode(['product_id' => $product->id, 'category_id' => $category->id, 'user_ids' => $userIds]);
        break;

    case 'place':
        [, , $userId, $productId, $quantity, $wakeAt, $key] = $argv;

        // With a shared key the parent passes the same user to every racer.
        $user = User::findOrFail((int) $userId);
        $service = $app->make(OrderPlacementService::class);

        time_sleep_until((float) $wakeAt);

        try {
            $order = $service->place($user, [['product_id' => (int) $productId, 'quantity' => (int) $quantity]], $key);
            echo json_encode(['status' => 201, 'order_id' => $order->id]);
        } catch (ApiException $e) {
            echo json_encode(['status' => $e->getHttpStatusCode(), 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 500, 'error' => get_class($e).': '.$e->getMessage()]);
        }
        break;

    // A request that reserved stock in the real Warehouse and then stalled before
    // saving its order: a pending reservation, idle for 10 minutes.
    case 'stall':
        [, , $userId, $productId, $quantity] = $argv;
        $key = (string) Illuminate\Support\Str::uuid();
        $result = $app->make(App\Services\Warehouse\WarehouseGateway::class)->reserve($key, [(int) $productId => (int) $quantity]);
        if (! $result->success) {
            fwrite(STDERR, "Reserve refused: {$result->code->name}\n");
            exit(1);
        }
        $reservation = OrderReservation::create([
            'reservation_key' => $key,
            'user_id' => (int) $userId,
            'request_hash' => hash('sha256', json_encode([(int) $productId => (int) $quantity])),
            'status' => OrderReservation::PENDING,
        ]);
        DB::table('order_reservations')->where('id', $reservation->id)->update(['updated_at' => now()->subMinutes(10)]);
        echo json_encode(['reservation_id' => $reservation->id]);
        break;

    case 'reconcile':
        // Load the console commands before the start line, so the run itself starts on time.
        Illuminate\Support\Facades\Artisan::all();
        time_sleep_until((float) $argv[2]);
        $started = microtime(true);
        $code = Illuminate\Support\Facades\Artisan::call('warehouse:reconcile', ['--older-than' => 2]);
        echo json_encode(['exit' => $code, 'output' => trim(Illuminate\Support\Facades\Artisan::output()), 'started' => $started, 'finished' => microtime(true)]);
        break;

    // The stalled request wakes up and finishes exactly as OrderPlacementService::place()
    // does after Reserve: save the order, or release the reservation if that fails.
    case 'wake':
        [, , $reservationId, $productId, $quantity, $wakeAt] = $argv;
        $service = $app->make(OrderPlacementService::class);
        $reservation = OrderReservation::findOrFail((int) $reservationId);
        $user = User::findOrFail($reservation->user_id);
        time_sleep_until((float) $wakeAt);
        $started = microtime(true);
        try {
            (new ReflectionMethod($service, 'saveOrder'))->invoke($service, $user, $reservation, [(int) $productId => (int) $quantity]);
            echo json_encode(['saved' => true, 'at' => $started]);
        } catch (Throwable $e) {
            (new ReflectionMethod($service, 'releaseAfterFailedSave'))->invoke($service, $app->make(App\Services\Warehouse\WarehouseGateway::class), $reservation, $e);
            echo json_encode(['saved' => false, 'error' => $e->getMessage(), 'at' => $started]);
        }
        break;

    case 'reservations':
        echo OrderReservation::query()->whereIn('user_id', json_decode($argv[2], true))->orderBy('id')
            ->get(['id', 'reservation_key', 'status', 'order_id'])->toJson();
        break;

    case 'orders':
        echo Order::query()->whereHas('items', fn ($q) => $q->where('product_id', (int) $argv[2]))->count();
        break;

    case 'order-ids':
        echo Order::query()->whereHas('items', fn ($q) => $q->where('product_id', (int) $argv[2]))->pluck('id')->toJson();
        break;

    case 'cleanup':
        $ids = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($ids) {
            $orderIds = Order::query()->whereIn('user_id', $ids['user_ids'])->pluck('id');
            OrderReservation::query()->whereIn('user_id', $ids['user_ids'])->delete();
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            Order::query()->whereIn('id', $orderIds)->delete();
            Product::query()->whereKey($ids['product_id'])->delete();
            Category::query()->whereKey($ids['category_id'])->delete();
            User::query()->whereIn('id', $ids['user_ids'])->delete();
        });
        break;
}
