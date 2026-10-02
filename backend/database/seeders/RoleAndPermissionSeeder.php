<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create system roles
        $roles = [
            [
                'name' => 'Platform Admin',
                'slug' => 'platform-admin',
                'description' => 'Full platform administrator access',
                'level' => 100,
                'is_system' => true,
            ],
            [
                'name' => 'Organization Owner',
                'slug' => 'organization-owner',
                'description' => 'Full organization access',
                'level' => 90,
                'is_system' => true,
            ],
            [
                'name' => 'Project Admin',
                'slug' => 'project-admin',
                'description' => 'Full project access',
                'level' => 80,
                'is_system' => true,
            ],
            [
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Manage assets and operations',
                'level' => 60,
                'is_system' => true,
            ],
            [
                'name' => 'Operator',
                'slug' => 'operator',
                'description' => 'Operational access',
                'level' => 40,
                'is_system' => true,
            ],
            [
                'name' => 'Viewer',
                'slug' => 'viewer',
                'description' => 'Read-only access',
                'level' => 20,
                'is_system' => true,
            ],
            [
                'name' => 'Integration Manager',
                'slug' => 'integration-manager',
                'description' => 'Manage integrations and devices',
                'level' => 70,
                'is_system' => true,
            ],
            [
                'name' => 'Module Manager',
                'slug' => 'module-manager',
                'description' => 'Manage modules and templates',
                'level' => 75,
                'is_system' => true,
            ],
        ];

        $createdRoles = [];
        foreach ($roles as $role) {
            $createdRoles[$role['slug']] = Role::firstOrCreate(
                ['slug' => $role['slug']],
                $role
            );
        }

        // Create system permissions
        $permissions = [
            // Organization permissions
            ['name' => 'View Organizations', 'slug' => 'organization.view', 'module' => 'organization', 'description' => 'View organization details'],
            ['name' => 'Create Organizations', 'slug' => 'organization.create', 'module' => 'organization', 'description' => 'Create new organizations'],
            ['name' => 'Update Organizations', 'slug' => 'organization.update', 'module' => 'organization', 'description' => 'Update organization details'],
            ['name' => 'Delete Organizations', 'slug' => 'organization.delete', 'module' => 'organization', 'description' => 'Delete organizations'],
            ['name' => 'Manage Organization Users', 'slug' => 'organization.manage-users', 'module' => 'organization', 'description' => 'Manage organization users'],

            // Project permissions
            ['name' => 'View Projects', 'slug' => 'project.view', 'module' => 'project', 'description' => 'View project details'],
            ['name' => 'Create Projects', 'slug' => 'project.create', 'module' => 'project', 'description' => 'Create new projects'],
            ['name' => 'Update Projects', 'slug' => 'project.update', 'module' => 'project', 'description' => 'Update project details'],
            ['name' => 'Delete Projects', 'slug' => 'project.delete', 'module' => 'project', 'description' => 'Delete projects'],
            ['name' => 'Manage Project Users', 'slug' => 'project.manage-users', 'module' => 'project', 'description' => 'Manage project users'],

            // Asset permissions
            ['name' => 'View Assets', 'slug' => 'asset.view', 'module' => 'asset', 'description' => 'View asset details'],
            ['name' => 'Create Assets', 'slug' => 'asset.create', 'module' => 'asset', 'description' => 'Create new assets'],
            ['name' => 'Update Assets', 'slug' => 'asset.update', 'module' => 'asset', 'description' => 'Update asset details'],
            ['name' => 'Delete Assets', 'slug' => 'asset.delete', 'module' => 'asset', 'description' => 'Delete assets'],
            ['name' => 'Manage Asset Custom Fields', 'slug' => 'asset.manage-custom-fields', 'module' => 'asset', 'description' => 'Manage asset custom fields'],

            // Location permissions
            ['name' => 'View Locations', 'slug' => 'location.view', 'module' => 'location', 'description' => 'View location details'],
            ['name' => 'Create Locations', 'slug' => 'location.create', 'module' => 'location', 'description' => 'Create new locations'],
            ['name' => 'Update Locations', 'slug' => 'location.update', 'module' => 'location', 'description' => 'Update location details'],
            ['name' => 'Delete Locations', 'slug' => 'location.delete', 'module' => 'location', 'description' => 'Delete locations'],

            // Movement permissions
            ['name' => 'View Movements', 'slug' => 'movement.view', 'module' => 'movement', 'description' => 'View movement history'],
            ['name' => 'Create Movements', 'slug' => 'movement.create', 'module' => 'movement', 'description' => 'Create new movements'],
            ['name' => 'Update Movements', 'slug' => 'movement.update', 'module' => 'movement', 'description' => 'Update movement details'],
            ['name' => 'Delete Movements', 'slug' => 'movement.delete', 'module' => 'movement', 'description' => 'Delete movements'],

            // Device permissions
            ['name' => 'View Devices', 'slug' => 'device.view', 'module' => 'device', 'description' => 'View device details'],
            ['name' => 'Create Devices', 'slug' => 'device.create', 'module' => 'device', 'description' => 'Create new devices'],
            ['name' => 'Update Devices', 'slug' => 'device.update', 'module' => 'device', 'description' => 'Update device details'],
            ['name' => 'Delete Devices', 'slug' => 'device.delete', 'module' => 'device', 'description' => 'Delete devices'],
            ['name' => 'Manage Device Bindings', 'slug' => 'device.manage-bindings', 'module' => 'device', 'description' => 'Manage device to asset bindings'],

            // Integration permissions
            ['name' => 'View Integrations', 'slug' => 'integration.view', 'module' => 'integration', 'description' => 'View integration details'],
            ['name' => 'Connect Integrations', 'slug' => 'integration.connect', 'module' => 'integration', 'description' => 'Connect new integrations'],
            ['name' => 'Disconnect Integrations', 'slug' => 'integration.disconnect', 'module' => 'integration', 'description' => 'Disconnect integrations'],
            ['name' => 'Configure Integrations', 'slug' => 'integration.configure', 'module' => 'integration', 'description' => 'Configure integration settings'],
            ['name' => 'Test Integrations', 'slug' => 'integration.test', 'module' => 'integration', 'description' => 'Test integration connections'],

            // Module permissions
            ['name' => 'View Modules', 'slug' => 'module.view', 'module' => 'module', 'description' => 'View available modules'],
            ['name' => 'Install Modules', 'slug' => 'module.install', 'module' => 'module', 'description' => 'Install modules to project'],
            ['name' => 'Configure Modules', 'slug' => 'module.configure', 'module' => 'module', 'description' => 'Configure module settings'],
            ['name' => 'Enable Modules', 'slug' => 'module.enable', 'module' => 'module', 'description' => 'Enable modules'],
            ['name' => 'Disable Modules', 'slug' => 'module.disable', 'module' => 'module', 'description' => 'Disable modules'],
            ['name' => 'Uninstall Modules', 'slug' => 'module.uninstall', 'module' => 'module', 'description' => 'Uninstall modules from project'],

            // Template permissions
            ['name' => 'View Templates', 'slug' => 'template.view', 'module' => 'template', 'description' => 'View available templates'],
            ['name' => 'Create Templates', 'slug' => 'template.create', 'module' => 'template', 'description' => 'Create new templates'],
            ['name' => 'Update Templates', 'slug' => 'template.update', 'module' => 'template', 'description' => 'Update template details'],
            ['name' => 'Delete Templates', 'slug' => 'template.delete', 'module' => 'template', 'description' => 'Delete templates'],

            // User permissions
            ['name' => 'View Users', 'slug' => 'user.view', 'module' => 'user', 'description' => 'View user details'],
            ['name' => 'Manage Users', 'slug' => 'user.manage', 'module' => 'user', 'description' => 'Manage user accounts'],
            ['name' => 'Delete Users', 'slug' => 'user.delete', 'module' => 'user', 'description' => 'Delete user accounts'],

            // Role permissions
            ['name' => 'View Roles', 'slug' => 'role.view', 'module' => 'role', 'description' => 'View available roles'],
            ['name' => 'Manage Roles', 'slug' => 'role.manage', 'module' => 'role', 'description' => 'Manage and assign roles'],
            ['name' => 'Assign Roles', 'slug' => 'role.assign', 'module' => 'role', 'description' => 'Assign roles to users'],

            // Permission permissions
            ['name' => 'View Permissions', 'slug' => 'permission.view', 'module' => 'permission', 'description' => 'View available permissions'],
            ['name' => 'Manage Permissions', 'slug' => 'permission.manage', 'module' => 'permission', 'description' => 'Manage permissions'],

            // Report permissions
            ['name' => 'View Reports', 'slug' => 'report.view', 'module' => 'report', 'description' => 'View reports and analytics'],
            ['name' => 'Create Reports', 'slug' => 'report.create', 'module' => 'report', 'description' => 'Create custom reports'],
            ['name' => 'Export Reports', 'slug' => 'report.export', 'module' => 'report', 'description' => 'Export report data'],

            // Audit permissions
            ['name' => 'View Audit Logs', 'slug' => 'audit.view', 'module' => 'audit', 'description' => 'View audit logs'],
            ['name' => 'View Activity Logs', 'slug' => 'audit.view-activity', 'module' => 'audit', 'description' => 'View activity logs'],
            ['name' => 'View Event Logs', 'slug' => 'audit.view-events', 'module' => 'audit', 'description' => 'View event logs'],

            // Webhook permissions
            ['name' => 'View Webhooks', 'slug' => 'webhook.view', 'module' => 'webhook', 'description' => 'View webhook configurations'],
            ['name' => 'Create Webhooks', 'slug' => 'webhook.create', 'module' => 'webhook', 'description' => 'Create new webhooks'],
            ['name' => 'Update Webhooks', 'slug' => 'webhook.update', 'module' => 'webhook', 'description' => 'Update webhook configurations'],
            ['name' => 'Delete Webhooks', 'slug' => 'webhook.delete', 'module' => 'webhook', 'description' => 'Delete webhooks'],
            ['name' => 'Test Webhooks', 'slug' => 'webhook.test', 'module' => 'webhook', 'description' => 'Test webhook delivery'],
            ['name' => 'Manage Webhooks', 'slug' => 'webhook.manage', 'module' => 'webhook', 'description' => 'Manage webhook deliveries and retries'],

            // Machine access & standard event ingestion
            ['name' => 'View API Keys', 'slug' => 'api-key.view', 'module' => 'api-key', 'description' => 'View project API keys (never their secrets)'],
            ['name' => 'Manage API Keys', 'slug' => 'api-key.manage', 'module' => 'api-key', 'description' => 'Create and revoke project API keys'],
            ['name' => 'Ingest Events', 'slug' => 'event.ingest', 'module' => 'event', 'description' => 'Publish standard events via POST /api/v1/events'],
        ];

        $createdPermissions = [];
        foreach ($permissions as $permission) {
            $createdPermissions[$permission['slug']] = Permission::firstOrCreate(
                ['slug' => $permission['slug']],
                array_merge($permission, ['is_system' => true])
            );
        }

        // Assign permissions to roles

        // Platform Admin - All permissions
        $createdRoles['platform-admin']->permissions()->sync(array_values($createdPermissions));

        // Organization Owner - Full organization and project management
        $orgOwnerPermissions = [
            'organization.view', 'organization.update', 'organization.manage-users',
            'project.view', 'project.create', 'project.update', 'project.delete', 'project.manage-users',
            'asset.view', 'asset.create', 'asset.update', 'asset.delete', 'asset.manage-custom-fields',
            'location.view', 'location.create', 'location.update', 'location.delete',
            'movement.view', 'movement.create', 'movement.update', 'movement.delete',
            'device.view', 'device.create', 'device.update', 'device.delete', 'device.manage-bindings',
            'integration.view', 'integration.connect', 'integration.disconnect', 'integration.configure', 'integration.test',
            'module.view', 'module.install', 'module.configure', 'module.enable', 'module.disable', 'module.uninstall',
            'template.view',
            'user.view', 'user.manage',
            'role.view', 'role.assign',
            'report.view', 'report.create', 'report.export',
            'audit.view', 'audit.view-activity', 'audit.view-events',
            'webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete', 'webhook.test', 'webhook.manage',
            'api-key.view', 'api-key.manage', 'event.ingest',
        ];
        $createdRoles['organization-owner']->permissions()->sync(
            collect($orgOwnerPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Project Admin - Full project management
        $projectAdminPermissions = [
            'project.view', 'project.update', 'project.manage-users',
            'asset.view', 'asset.create', 'asset.update', 'asset.delete', 'asset.manage-custom-fields',
            'location.view', 'location.create', 'location.update', 'location.delete',
            'movement.view', 'movement.create', 'movement.update', 'movement.delete',
            'device.view', 'device.create', 'device.update', 'device.delete', 'device.manage-bindings',
            'integration.view', 'integration.connect', 'integration.disconnect', 'integration.configure', 'integration.test',
            'module.view', 'module.configure', 'module.enable', 'module.disable',
            'template.view',
            'user.view',
            'role.view', 'role.assign',
            'report.view', 'report.create', 'report.export',
            'audit.view', 'audit.view-activity',
            'webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete', 'webhook.test', 'webhook.manage',
            'api-key.view', 'api-key.manage', 'event.ingest',
        ];
        $createdRoles['project-admin']->permissions()->sync(
            collect($projectAdminPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Manager - Asset and operations management
        $managerPermissions = [
            'project.view',
            'asset.view', 'asset.create', 'asset.update', 'asset.delete',
            'location.view', 'location.create', 'location.update',
            'movement.view', 'movement.create', 'movement.update',
            'device.view', 'device.update', 'device.manage-bindings',
            'integration.view', 'integration.test',
            'webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete', 'webhook.test',
            'report.view', 'report.export',
            'audit.view-activity',
        ];
        $createdRoles['manager']->permissions()->sync(
            collect($managerPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Operator - Operational access
        $operatorPermissions = [
            'project.view',
            'asset.view', 'asset.update',
            'location.view',
            'movement.view', 'movement.create',
            'device.view',
            'integration.view',
            'report.view',
        ];
        $createdRoles['operator']->permissions()->sync(
            collect($operatorPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Viewer - Read-only access
        $viewerPermissions = [
            'project.view',
            'asset.view',
            'location.view',
            'movement.view',
            'device.view',
            'integration.view',
            'report.view',
        ];
        $createdRoles['viewer']->permissions()->sync(
            collect($viewerPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Integration Manager - Integration and device management
        $integrationManagerPermissions = [
            'project.view',
            'device.view', 'device.create', 'device.update', 'device.delete', 'device.manage-bindings',
            'integration.view', 'integration.connect', 'integration.disconnect', 'integration.configure', 'integration.test',
            'audit.view-events',
            'api-key.view', 'api-key.manage', 'event.ingest',
        ];
        $createdRoles['integration-manager']->permissions()->sync(
            collect($integrationManagerPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        // Module Manager - Module and template management
        $moduleManagerPermissions = [
            'project.view',
            'module.view', 'module.install', 'module.configure', 'module.enable', 'module.disable', 'module.uninstall',
            'template.view', 'template.create', 'template.update', 'template.delete',
        ];
        $createdRoles['module-manager']->permissions()->sync(
            collect($moduleManagerPermissions)->map(fn($slug) => $createdPermissions[$slug]->id)->toArray()
        );

        $this->command->info('Roles and permissions seeded successfully.');
    }
}
