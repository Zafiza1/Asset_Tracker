<?php

/*
|--------------------------------------------------------------------------
| Module Catalog
|--------------------------------------------------------------------------
|
| Business modules the platform publishes (Section 13). Synced into the
| modules/module_versions tables by `php artisan modules:sync`.
|
| Everything here must stay generic: no customer names, no customer-specific
| workflows. Customer specifics belong in project configuration (Section 5).
|
| Released versions are immutable — to change a module, append a new version
| rather than editing an existing one (see App\Services\ModuleRegistry).
|
| config_schema field keys: type (string|text|integer|number|boolean|array|
| object), label, description, required, default, options, min, max.
|
*/

return [

    'catalog' => [

        // ---- Core (Level 1): always available, cannot be installed/removed.
        [
            'slug' => 'asset',
            'name' => 'Asset',
            'description' => 'Asset registry with system ID and customer serial number.',
            'category' => 'core',
            'author' => 'Asset Tracker PaaS',
            'is_core' => true,
            'versions' => [
                ['version' => '1.0.0', 'changelog' => 'Initial release.'],
            ],
        ],
        [
            'slug' => 'location',
            'name' => 'Location',
            'description' => 'Generic locations: warehouses, sites, vehicles, field locations.',
            'category' => 'core',
            'author' => 'Asset Tracker PaaS',
            'is_core' => true,
            'versions' => [
                ['version' => '1.0.0', 'changelog' => 'Initial release.'],
            ],
        ],
        [
            'slug' => 'movement',
            'name' => 'Movement',
            'description' => 'Asset movement history between locations.',
            'category' => 'core',
            'author' => 'Asset Tracker PaaS',
            'is_core' => true,
            'versions' => [
                ['version' => '1.0.0', 'changelog' => 'Initial release.'],
            ],
        ],

        // ---- Installable modules.
        [
            'slug' => 'maintenance',
            'name' => 'Maintenance',
            'description' => 'Schedule and record maintenance work on assets.',
            'category' => 'tracking',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'dependencies' => ['asset' => '>=1.0.0'],
                    'config_schema' => [
                        'default_interval_days' => ['type' => 'integer', 'label' => 'Default interval (days)', 'default' => 30, 'min' => 1],
                        'auto_schedule' => ['type' => 'boolean', 'label' => 'Auto-schedule next maintenance', 'default' => false],
                    ],
                    'permissions' => ['maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete'],
                ],
            ],
        ],
        [
            'slug' => 'inspection',
            'name' => 'Inspection',
            'description' => 'Checklist-based asset inspections.',
            'category' => 'tracking',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'dependencies' => ['asset' => '>=1.0.0'],
                    'config_schema' => [
                        'checklist_required' => ['type' => 'boolean', 'label' => 'Require a checklist', 'default' => true],
                        'default_interval_days' => ['type' => 'integer', 'label' => 'Default interval (days)', 'default' => 90, 'min' => 1],
                    ],
                    'permissions' => ['inspection.view', 'inspection.create', 'inspection.update'],
                ],
            ],
        ],
        [
            'slug' => 'customer',
            'name' => 'Customer',
            'description' => 'Customers/parties that hold or receive assets.',
            'category' => 'business',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'permissions' => ['customer.view', 'customer.create', 'customer.update', 'customer.delete'],
                ],
            ],
        ],
        [
            'slug' => 'delivery',
            'name' => 'Delivery',
            'description' => 'Deliver assets to customers and track returns.',
            'category' => 'business',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'dependencies' => ['customer' => '^1.0', 'movement' => '>=1.0.0'],
                    'config_schema' => [
                        'require_proof_of_delivery' => ['type' => 'boolean', 'label' => 'Require proof of delivery', 'default' => false],
                    ],
                    'permissions' => ['delivery.view', 'delivery.create', 'delivery.update'],
                ],
            ],
        ],
        [
            'slug' => 'inventory',
            'name' => 'Inventory',
            'description' => 'Stock levels of assets per location.',
            'category' => 'business',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'dependencies' => ['asset' => '>=1.0.0', 'location' => '>=1.0.0'],
                    'config_schema' => [
                        'low_stock_threshold' => ['type' => 'integer', 'label' => 'Low stock threshold', 'default' => 0, 'min' => 0],
                    ],
                    'permissions' => ['inventory.view', 'inventory.adjust'],
                ],
            ],
        ],
        [
            'slug' => 'rental',
            'name' => 'Rental',
            'description' => 'Rent assets out to customers.',
            'category' => 'business',
            'author' => 'Asset Tracker PaaS',
            'versions' => [
                [
                    'version' => '1.0.0',
                    'changelog' => 'Initial release.',
                    'dependencies' => ['customer' => '^1.0'],
                    'config_schema' => [
                        'default_rental_period_days' => ['type' => 'integer', 'label' => 'Default rental period (days)', 'default' => 7, 'min' => 1],
                        'late_fee_enabled' => ['type' => 'boolean', 'label' => 'Charge late fees', 'default' => false],
                    ],
                    'permissions' => ['rental.view', 'rental.create', 'rental.update'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle Handlers
    |--------------------------------------------------------------------------
    |
    | module slug => class implementing App\Modules\Contracts\ModuleContract.
    | Modules without an entry get the no-op App\Modules\BaseModule.
    |
    */

    'handlers' => [
        //
    ],

];
