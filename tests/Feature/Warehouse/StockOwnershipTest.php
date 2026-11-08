<?php

namespace Tests\Feature\Warehouse;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The Warehouse is the only owner of stock, so application code must never touch
 * its tables or use Laravel's old stock column.
 */
class StockOwnershipTest extends TestCase
{
    public function test_application_code_never_touches_warehouse_tables_or_the_old_column(): void
    {
        $forbidden = [
            'a Warehouse table' => '/\bwarehouse\.(stock|reservations|reservation_items)\b/i',
            'a Warehouse database connection' => '/connection\(\s*[\'"]warehouse/i',
            'a direct stock deduction' => '/(increment|decrement)\(\s*[\'"]stock[\'"]/',
            'a query on the stock column' => '/(where|orderBy|whereIn)\(\s*[\'"]stock[\'"]/',
            'a write to the stock column' => '/(->stock\s*=[^=>])|([\'"]stock[\'"]\s*=>\s*\$(?!this->stock\b))/',
        ];

        $files = Finder::create()
            ->files()
            ->name('*.php')
            ->in([base_path('app'), base_path('Modules')])
            ->notPath(['Grpc/', 'database/migrations/']);

        $this->assertGreaterThan(50, iterator_count($files));

        foreach ($files as $file) {
            foreach ($forbidden as $description => $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $file->getContents(),
                    "{$file->getRelativePathname()} uses {$description}"
                );
            }
        }
    }
}
