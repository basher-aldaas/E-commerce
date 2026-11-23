<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clear cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        // 2. Define Customer Permissions
        $customerPermissions = [
            'view_notifications',
            'view_products',
            'view_categories',
            'create_order',
            'view_orders',
            'track_order_status',
        ];

        // 3. Define Admin Permissions
        $adminPermissions = [
            'create_product',
            'update_product',
            'view_products',
            'create_category',
            'view_categories',
            'view_orders',
            'update_order_status',
            'track_order_status',
            'view_system_status',
        ];

        // 4. Create all permissions
        $allPermissions = array_unique(
            array_merge($customerPermissions, $adminPermissions)
        );

        foreach ($allPermissions as $permissionName) {
            Permission::findOrCreate($permissionName, $guard);
        }

        // 5. Create Roles
        $customer = Role::findOrCreate('customer', $guard);

        $admin = Role::findOrCreate('admin', $guard);

        // 6. Assign permissions to roles
        $customer->syncPermissions($customerPermissions);
        $admin->syncPermissions($adminPermissions);

        // 7. Clear cache again
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
