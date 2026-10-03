<?php

namespace App\Modules;

use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectModule;

/**
 * A project's configuration values for a module (validated against the
 * module version's config_schema when set).
 */
class ModuleSettings
{
    public function get(Project $project, string $moduleSlug, string $key, mixed $default = null): mixed
    {
        $moduleId = Module::where('slug', $moduleSlug)->value('id');

        $configuration = ProjectModule::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('module_id', $moduleId)
            ->value('configuration');

        if (is_string($configuration)) {
            $configuration = json_decode($configuration, true);
        }

        return $configuration[$key] ?? $default;
    }
}
