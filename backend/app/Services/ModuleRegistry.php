<?php

namespace App\Services;

use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\BaseModule;
use App\Modules\Contracts\ModuleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Platform module registry (Control Plane). Publishes module manifests into
 * modules/module_versions and resolves a module's lifecycle handler.
 *
 * The catalog shipped with the platform lives in config/modules.php and is
 * synced by `php artisan modules:sync` (also run by ModuleSeeder).
 */
class ModuleRegistry
{
    public const CONFIG_TYPES = ['string', 'text', 'integer', 'number', 'boolean', 'array', 'object'];

    /**
     * Register (or update) a module and its versions from a manifest.
     *
     * Versions are immutable once published: re-registering an existing
     * version only updates its changelog and status (e.g. to deprecate it) —
     * dependencies, config_schema and permissions of a released version never
     * change underneath the projects pinned to it (Section 15/59).
     *
     * @param array{
     *   slug: string, name: string, description?: string, category?: string,
     *   author?: string, is_core?: bool, status?: string, metadata?: array,
     *   default_role_permissions?: array<string, array<int, string>>,
     *   versions: array<int, array{
     *     version: string, changelog?: string, dependencies?: array<string, string>,
     *     config_schema?: array<string, array>, permissions?: array<int, string>,
     *     status?: string, released_at?: string
     *   }>
     * } $manifest
     */
    public function register(array $manifest): Module
    {
        $this->validateManifest($manifest);

        return DB::transaction(function () use ($manifest) {
            $module = Module::updateOrCreate(
                ['slug' => $manifest['slug']],
                [
                    'name' => $manifest['name'],
                    'description' => $manifest['description'] ?? null,
                    'category' => $manifest['category'] ?? 'business',
                    'author' => $manifest['author'] ?? null,
                    'is_core' => $manifest['is_core'] ?? false,
                    'status' => $manifest['status'] ?? 'available',
                    'metadata' => $manifest['metadata'] ?? null,
                ]
            );

            foreach ($manifest['versions'] as $definition) {
                $this->registerVersion($module, $definition);
            }

            $this->grantDefaultRolePermissions($manifest['default_role_permissions'] ?? []);

            return $module->load('versions');
        });
    }

    public function syncFromConfig(): int
    {
        $catalog = config('modules.catalog', []);

        foreach ($catalog as $manifest) {
            $this->register($manifest);
        }

        return count($catalog);
    }

    public function find(string $slug): ?Module
    {
        return Module::where('slug', $slug)->first();
    }

    /**
     * The lifecycle handler for a module: the class configured under
     * modules.handlers.{slug}, or a no-op BaseModule.
     */
    public function handler(string $slug): ModuleContract
    {
        $class = config("modules.handlers.{$slug}");

        if (!$class) {
            return new BaseModule();
        }

        $handler = app($class);

        if (!$handler instanceof ModuleContract) {
            throw new InvalidArgumentException("Module handler [{$class}] must implement " . ModuleContract::class);
        }

        return $handler;
    }

    protected function registerVersion(Module $module, array $definition): ModuleVersion
    {
        $existing = $module->versions()->where('version', $definition['version'])->first();

        if ($existing) {
            $existing->update([
                'changelog' => $definition['changelog'] ?? $existing->changelog,
                'status' => $definition['status'] ?? $existing->status,
            ]);

            return $existing;
        }

        $version = $module->versions()->create([
            'version' => $definition['version'],
            'changelog' => $definition['changelog'] ?? null,
            'dependencies' => $definition['dependencies'] ?? [],
            'config_schema' => $definition['config_schema'] ?? [],
            'permissions' => $definition['permissions'] ?? [],
            'status' => $definition['status'] ?? 'published',
            'released_at' => $definition['released_at'] ?? now(),
        ]);

        $this->registerPermissions($module, $definition['permissions'] ?? []);

        return $version;
    }

    /**
     * Make a module's permission slugs available for role assignment. Roles
     * only receive them through the manifest's default_role_permissions or an
     * explicit assignment.
     */
    protected function registerPermissions(Module $module, array $slugs): void
    {
        foreach ($slugs as $slug) {
            Permission::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => Str::headline(str_replace('.', ' ', $slug)),
                    'module' => $module->slug,
                    'description' => "Provided by the {$module->name} module",
                    'is_system' => true,
                ]
            );
        }
    }

    /**
     * The module author's recommended grants for the system roles, from the
     * manifest's default_role_permissions (role slug => permission slugs).
     * Additive only: syncing never revokes a permission from a role.
     */
    protected function grantDefaultRolePermissions(array $grants): void
    {
        foreach ($grants as $roleSlug => $permissionSlugs) {
            $role = Role::where('slug', $roleSlug)->first();

            if (!$role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all()
            );
        }
    }

    protected function validateManifest(array $manifest): void
    {
        foreach (['slug', 'name', 'versions'] as $key) {
            if (empty($manifest[$key])) {
                throw new InvalidArgumentException("Module manifest is missing [{$key}]");
            }
        }

        if (!preg_match('/^[a-z][a-z0-9-]*$/', $manifest['slug'])) {
            throw new InvalidArgumentException("Invalid module slug [{$manifest['slug']}]");
        }

        foreach ($manifest['versions'] as $version) {
            if (!preg_match('/^\d+\.\d+\.\d+$/', $version['version'] ?? '')) {
                throw new InvalidArgumentException("Module [{$manifest['slug']}] has an invalid version [" . ($version['version'] ?? '') . ']');
            }

            foreach ($version['config_schema'] ?? [] as $key => $field) {
                if (!in_array($field['type'] ?? null, self::CONFIG_TYPES, true)) {
                    throw new InvalidArgumentException("Module [{$manifest['slug']}] setting [{$key}] has an unsupported type");
                }
            }
        }
    }
}
