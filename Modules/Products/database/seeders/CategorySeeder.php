<?php

namespace Modules\Products\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electronics', 'description' => 'Phones, computers, and other electronic devices.'],
            ['name' => 'Fashion', 'description' => 'Clothing, shoes, and accessories.'],
            ['name' => 'Home & Kitchen', 'description' => 'Furniture, appliances, and kitchenware.'],
            ['name' => 'Beauty & Personal Care', 'description' => 'Skincare, makeup, and grooming products.'],
            ['name' => 'Sports & Outdoors', 'description' => 'Fitness equipment and outdoor gear.'],
            ['name' => 'Books', 'description' => 'Printed books across all genres.'],
            ['name' => 'Toys & Games', 'description' => 'Toys, board games, and puzzles.'],
            ['name' => 'Groceries', 'description' => 'Food, beverages, and household essentials.'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                ['description' => $category['description']]
            );
        }
    }
}
