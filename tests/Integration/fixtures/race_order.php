<?php

/**
 * One racer in the concurrency tests, run as its own PHP process by
 * OrderFlowIntegrationTest. It only runs against a temporary SQLite file.
 */

$database = (string) getenv('DB_DATABASE');

if (getenv('DB_CONNECTION') !== 'sqlite' || ! str_starts_with(realpath($database) ?: '', realpath(sys_get_temp_dir()))) {
    fwrite(STDERR, "Refusing to run: this script needs a temporary SQLite database.\n");
    exit(1);
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Artisan;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderPlacementService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

switch ($argv[1]) {
    case 'setup':
        Artisan::call('migrate', ['--force' => true]);

        $category = Category::create(['name' => 'Race']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Last unit', 'price' => 5, 'is_active' => true]);

        $userIds = [];
        for ($i = 0; $i < (int) $argv[2]; $i++) {
            $userIds[] = User::create(['name' => "Racer {$i}", 'email' => "racer{$i}@example.test", 'password' => 'password123'])->id;
        }

        echo json_encode(['product_id' => $product->id, 'user_ids' => $userIds]);
        break;

    case 'place':
        [, , $userId, $productId, $quantity, $wakeAt, $key] = $argv;

        // Sharing a key only means something for one user, so racers use the first.
        $user = User::findOrFail($key === 'shared' ? User::min('id') : $userId);
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

    case 'orders':
        echo Order::count();
        break;
}
