<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Module;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateModule;
use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Template Engine — manages template lifecycle, versioning, and module associations.
 * Phase 7 implementation per Section 16 and 43.
 */
class TemplateService
{
    /**
     * Create a new template with an initial version.
     */
    public function create(array $data): Template
    {
        return DB::transaction(function () use ($data) {
            $template = Template::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'general',
                'version' => $data['version'] ?? '1.0.0',
                'status' => $data['status'] ?? 'available',
                'default_modules' => $data['default_modules'] ?? [],
                'default_settings' => $data['default_settings'] ?? [],
                'metadata' => $data['metadata'] ?? [],
            ]);

            // Create initial version
            $version = $this->createVersion($template, [
                'version' => $data['version'] ?? '1.0.0',
                'description' => $data['version_description'] ?? 'Initial release',
                'default_modules' => $data['default_modules'] ?? [],
                'default_settings' => $data['default_settings'] ?? [],
                'metadata' => $data['metadata'] ?? [],
                'status' => 'available',
                'released_at' => $data['released_at'] ?? now(),
            ]);

            $template->update(['current_version_id' => $version->id]);

            // Associate modules if provided
            if (isset($data['modules']) && is_array($data['modules'])) {
                foreach ($data['modules'] as $moduleData) {
                    $this->associateModule($template, $version, $moduleData);
                }
            }

            return $template->fresh();
        });
    }

    /**
     * Update template metadata (not version-specific).
     */
    public function update(Template $template, array $data): Template
    {
        $template->update($data);
        return $template->fresh();
    }

    /**
     * Create a new version of an existing template.
     */
    public function createVersion(Template $template, array $data): TemplateVersion
    {
        // Check if version already exists
        $existing = $template->versions()->where('version', $data['version'])->first();
        if ($existing) {
            throw ApiException::conflict("Version {$data['version']} already exists for this template");
        }

        return DB::transaction(function () use ($template, $data) {
            $version = $template->versions()->create([
                'version' => $data['version'],
                'description' => $data['description'] ?? null,
                'default_modules' => $data['default_modules'] ?? [],
                'default_settings' => $data['default_settings'] ?? [],
                'metadata' => $data['metadata'] ?? [],
                'status' => $data['status'] ?? 'available',
                'released_at' => $data['released_at'] ?? now(),
            ]);

            // Associate modules if provided
            if (isset($data['modules']) && is_array($data['modules'])) {
                foreach ($data['modules'] as $moduleData) {
                    $this->associateModuleToVersion($version, $moduleData);
                }
            }

            return $version;
        });
    }

    /**
     * Set the current version for a template.
     */
    public function setCurrentVersion(Template $template, TemplateVersion $version): Template
    {
        if ($version->template_id !== $template->id) {
            throw ApiException::invalid('Version does not belong to this template');
        }

        $template->update(['current_version_id' => $version->id]);
        return $template->fresh();
    }

    /**
     * Associate a module with a template version.
     */
    public function associateModuleToVersion(TemplateVersion $version, array $data): TemplateModule
    {
        $module = Module::where('slug', $data['module_slug'])->first();
        if (!$module) {
            throw ApiException::notFound("Module {$data['module_slug']} not found");
        }

        // Check if already associated
        $existing = TemplateModule::where('template_version_id', $version->id)
            ->where('module_id', $module->id)
            ->exists();
        if ($existing) {
            throw ApiException::conflict('Module already associated with this template version');
        }

        return TemplateModule::create([
            'template_version_id' => $version->id,
            'module_id' => $module->id,
            'version_constraint' => $data['version_constraint'] ?? null,
            'required' => $data['required'] ?? true,
            'default_config' => $data['default_config'] ?? [],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * Associate a module with a template (defaults to current version).
     */
    public function associateModule(Template $template, ?TemplateVersion $version, array $data): TemplateModule
    {
        $targetVersion = $version ?? $template->currentVersion ?? $template->versions()->latest()->first();
        if (!$targetVersion) {
            throw ApiException::invalid('Template has no version to associate module with');
        }

        return $this->associateModuleToVersion($targetVersion, $data);
    }

    /**
     * Remove a module association from a template version.
     */
    public function dissociateModule(TemplateVersion $version, string $moduleSlug): void
    {
        $module = Module::where('slug', $moduleSlug)->first();
        if (!$module) {
            throw ApiException::notFound("Module {$moduleSlug} not found");
        }

        $templateModule = TemplateModule::where('template_version_id', $version->id)
            ->where('module_id', $module->id)
            ->first();

        if (!$templateModule) {
            throw ApiException::notFound('Module not associated with this template');
        }

        $templateModule->delete();
    }

    /**
     * Apply a template to a project by installing its modules.
     * This is called by ProjectService when creating a project from a template.
     */
    public function applyToProject(Template $template, Project $project): void
    {
        $version = $template->getCurrentVersion();
        if (!$version) {
            throw ApiException::invalid('Template has no available version');
        }

        DB::transaction(function () use ($version, $project) {
            $moduleService = app(ModuleService::class);

            foreach ($version->modules as $module) {
                $pivot = $module->pivot;

                // Install module in project
                $moduleService->installModule($project, $module, [
                    'configuration' => $pivot->default_config ?? [],
                    'version_constraint' => $pivot->version_constraint,
                ]);

                // Enable required modules immediately
                if ($pivot->required) {
                    $moduleService->enableModule($project, $module);
                }
            }
        });
    }

    /**
     * Deprecate a template (marks as deprecated, doesn't delete).
     */
    public function deprecate(Template $template): Template
    {
        $template->update(['status' => 'deprecated']);
        return $template->fresh();
    }

    /**
     * Archive a template version.
     */
    public function archiveVersion(TemplateVersion $version): TemplateVersion
    {
        $version->update(['status' => 'deprecated']);
        return $version->fresh();
    }

    /**
     * Delete a template (cascades to versions and module associations).
     */
    public function delete(Template $template): void
    {
        // Check if template is used by any projects
        if ($template->projects()->exists()) {
            throw ApiException::conflict('Cannot delete template that is in use by projects');
        }

        DB::transaction(function () use ($template) {
            $template->delete();
        });
    }

    /**
     * Get available templates by category.
     */
    public function getByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return Template::available()
            ->byCategory($category)
            ->with('currentVersion')
            ->get();
    }

    /**
     * Get all available templates.
     */
    public function getAvailable(): \Illuminate\Database\Eloquent\Collection
    {
        return Template::available()
            ->with('currentVersion')
            ->get();
    }
}
