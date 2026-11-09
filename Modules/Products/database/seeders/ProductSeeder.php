<?php

namespace Modules\Products\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Electronics' => [
                ['name' => 'Wireless Headphones', 'description' => 'Over-ear Bluetooth headphones with noise cancellation.', 'price' => 89.99, 'image' => 'wireless-headphones.png'],
                ['name' => 'Smart Watch', 'description' => 'Fitness tracking, heart-rate monitor, and notifications.', 'price' => 149.00, 'image' => 'smart-watch.png'],
            ],
            'Fashion' => [
                ['name' => 'Cotton T-Shirt', 'description' => 'Soft, breathable everyday T-shirt.', 'price' => 14.50, 'image' => 'cotton-t-shirt.png'],
                ['name' => 'Running Shoes', 'description' => 'Lightweight shoes with a cushioned sole.', 'price' => 64.95, 'image' => 'running-shoes.png'],
            ],
            'Home & Kitchen' => [
                ['name' => 'Stainless Steel Cookware Set', 'description' => 'Five-piece set of pots and pans with lids.', 'price' => 119.00, 'image' => 'stainless-steel-cookware-set.png'],
                ['name' => 'Electric Kettle', 'description' => '1.7 L fast-boil kettle with automatic shut-off.', 'price' => 29.99, 'image' => 'electric-kettle.png'],
            ],
            'Beauty & Personal Care' => [
                ['name' => 'Hydrating Face Cream', 'description' => 'Daily moisturizer for all skin types.', 'price' => 18.75, 'image' => 'hydrating-face-cream.png'],
                ['name' => 'Electric Beard Trimmer', 'description' => 'Rechargeable trimmer with adjustable length settings.', 'price' => 39.50, 'image' => 'electric-beard-trimmer.png'],
            ],
            'Sports & Outdoors' => [
                ['name' => 'Yoga Mat', 'description' => 'Non-slip 6 mm mat with a carrying strap.', 'price' => 24.99, 'image' => 'yoga-mat.png'],
                ['name' => 'Camping Tent', 'description' => 'Waterproof four-person tent with quick setup.', 'price' => 129.00, 'image' => 'camping-tent.png'],
            ],
            'Books' => [
                ['name' => 'Learn PHP in 30 Days', 'description' => 'A hands-on guide for beginner programmers.', 'price' => 27.00, 'image' => 'learn-php-in-30-days.png'],
                ['name' => 'World History Atlas', 'description' => 'Illustrated maps tracing major world events.', 'price' => 35.50, 'image' => 'world-history-atlas.png'],
            ],
            'Toys & Games' => [
                ['name' => 'Wooden Building Blocks', 'description' => '100-piece set of colorful wooden blocks.', 'price' => 22.99, 'image' => 'wooden-building-blocks.png'],
                ['name' => 'Family Board Game', 'description' => 'A strategy game for two to six players.', 'price' => 31.00, 'image' => 'family-board-game.png'],
            ],
            'Groceries' => [
                ['name' => 'Organic Olive Oil', 'description' => '750 ml bottle of cold-pressed extra virgin olive oil.', 'price' => 12.99, 'image' => 'organic-olive-oil.png'],
                ['name' => 'Arabica Coffee Beans', 'description' => '1 kg bag of medium-roast whole beans.', 'price' => 19.50, 'image' => 'arabica-coffee-beans.png'],
            ],
        ];

        foreach ($catalog as $categoryName => $products) {
            $category = Category::where('name', $categoryName)->firstOrFail();

            foreach ($products as $product) {
                $imagePath = 'products/'.$product['image'];

                // storage/ is gitignored, so copy the bundled image to the public disk on every run.
                Storage::disk('public')->put(
                    $imagePath,
                    file_get_contents(__DIR__.'/images/products/'.$product['image'])
                );

                Product::firstOrCreate(
                    ['name' => $product['name']],
                    [
                        'category_id' => $category->id,
                        'description' => $product['description'],
                        'price' => $product['price'],
                        'image' => $imagePath,
                    ]
                );
            }
        }
    }
}
