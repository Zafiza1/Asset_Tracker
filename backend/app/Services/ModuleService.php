<?php

namespace App\Services;

use App\Events\ModuleLifecycleChanged;
use App\Exceptions\ModuleException;
use App\Models\Module;
use App\Models\ModuleVersion;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Support\VersionConstraint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Runs the module lifecycle for a project (Section 14/15/70):
 *
 *   AVAILABLE → INSTALLED → CONFIGURED → ENABLED ⇄ DISABLED → UNINSTALLED
 *
 * Each transition is one transaction that locks the project_modules row,
 * checks state and dependencies, runs the module's ModuleContract hook and
 * dispatches ModuleLifecycleChanged once committed. A failing hook rolls the
 * whole transition back.
 */
class ModuleService
{
    protected const ENABLEABLE = [
        ProjectModule::STATUS_INSTALLED,
        ProjectModule::STATUS_CONFIGURED,
        ProjectModule::STATUS_DISABLED,
    ];

    public function __construct(protected ModuleRegistry $registry)
    {
    }

    /**
     * The module's installation in this project (any state but uninstalled).
     */
    public function findForProject(Project $project, string $moduleSlug): ProjectModule
    {
        $projectModule = ProjectModule::query()
            ->with(['module', 'moduleVersion'])
            ->where('project_id', $project->id)
            ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
            ->active()
            ->first();

        if (!$projectModule) {
            throw ModuleException::notFound("Module [{$moduleSlug}] is not installed in this project");
        }

        return $projectModule;
    }

    /**
     * Install a module version (latest published when none is given).
     */
    public function install(Project $project, string $moduleSlug, ?string $version = null, array $configuration = [], ?User $user = null): ProjectModule
    {
        $module = $this->registry->find($moduleSlug)
            ?? throw ModuleException::notFound("Module [{$moduleSlug}] does not exist");

        if ($module->is_core) {
            throw ModuleException::conflict("Module [{$moduleSlug}] is a core module and is always available");
        }

        if (!$module->isAvailable()) {
            throw ModuleException::conflict("Module [{$moduleSlug}] is no longer available for new installations");
        }

        $moduleVersion = $this->resolveInstallableVersion($module, $version);

        $projectModule = DB::transaction(function () use ($project, $module, $moduleVersion, $configuration, $user) {
            $existing = ProjectModule::query()
                ->where('project_id', $project->id)
                ->where('module_id', $module->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->isInstalled()) {
                throw ModuleException::conflict("Module [{$module->slug}] is already installed in this project");
            }

            $this->assertDependencies($project, $moduleVersion, requireEnabled: false);

            $resolved = $this->validateConfiguration(
                $moduleVersion,
                array_merge($moduleVersion->defaultConfiguration(), $configuration),
            );

            $attributes = [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'module_id' => $module->id,
                'module_version_id' => $moduleVersion->id,
                'status' => $configuration ? ProjectModule::STATUS_CONFIGURED : ProjectModule::STATUS_INSTALLED,
                'configuration' => $resolved,
                'installed_by' => $user?->id,
                'installed_at' => now(),
                'enabled_at' => null,
                'disabled_at' => null,
                'uninstalled_at' => null,
            ];

            $projectModule = $existing
                ? tap($existing)->update($attributes)
                : ProjectModule::create($attributes);

            $projectModule->setRelation('module', $module)->setRelation('moduleVersion', $moduleVersion);
            $this->registry->handler($module->slug)->onInstall($projectModule);

            return $projectModule;
        });

        ModuleLifecycleChanged::dispatch($projectModule, 'project.module.installed', null, $user?->id);

        return $projectModule;
    }

    /**
     * Merge settings into the module's configuration. Partial updates are
     * allowed while the module is not enabled; an enabled module must stay
     * fully configured.
     */
    public function configure(ProjectModule $projectModule, array $configuration, ?User $user = null): ProjectModule
    {
        return $this->transition($projectModule, 'project.module.configured', $user, function (ProjectModule $locked) use ($configuration) {
            $previous = $locked->configuration ?? [];
            $version = $locked->moduleVersion;

            $locked->configuration = $this->validateConfiguration(
                $version,
                array_merge($previous, $configuration),
                requireComplete: $locked->isEnabled(),
            );

            if ($locked->status === ProjectModule::STATUS_INSTALLED) {
                $locked->status = ProjectModule::STATUS_CONFIGURED;
            }

            $locked->save();
            $this->registry->handler($locked->module->slug)->onConfigure($locked, $previous);
        });
    }

    public function enable(ProjectModule $projectModule, ?User $user = null): ProjectModule
    {
        return $this->transition($projectModule, 'project.module.enabled', $user, function (ProjectModule $locked) {
            if ($locked->isEnabled()) {
                throw ModuleException::conflict("Module [{$locked->module->slug}] is already enabled");
            }

            if (!in_array($locked->status, self::ENABLEABLE, true)) {
                throw ModuleException::conflict("Module [{$locked->module->slug}] cannot be enabled from status [{$locked->status}]");
            }

            $this->validateConfiguration($locked->moduleVersion, $locked->configuration ?? [], requireComplete: true);
            $this->assertDependencies($locked->project, $locked->moduleVersion, requireEnabled: true);

            $locked->update([
                'status' => ProjectModule::STATUS_ENABLED,
                'enabled_at' => now(),
            ]);

            $this->registry->handler($locked->module->slug)->onEnable($locked);
        });
    }

    public function disable(ProjectModule $projectModule, ?User $user = null): ProjectModule
    {
        return $this->transition($projectModule, 'project.module.disabled', $user, function (ProjectModule $locked) {
            if (!$locked->isEnabled()) {
                throw ModuleException::conflict("Module [{$locked->module->slug}] is not enabled");
            }

            $dependents = $this->dependentsOf($locked, enabledOnly: true);
            if ($dependents->isNotEmpty()) {
                throw ModuleException::conflict(
                    "Module [{$locked->module->slug}] is required by enabled module(s): " . $dependents->implode(', ')
                );
            }

            $locked->update([
                'status' => ProjectModule::STATUS_DISABLED,
                'disabled_at' => now(),
            ]);

            $this->registry->handler($locked->module->slug)->onDisable($locked);
        });
    }

    /**
     * Uninstall keeps the row (status "uninstalled") for traceability; the
     * module's own data is the handler's onUninstall responsibility.
     */
    public function uninstall(ProjectModule $projectModule, ?User $user = null): ProjectModule
    {
        return $this->transition($projectModule, 'project.module.uninstalled', $user, function (ProjectModule $locked) {
            if ($locked->isEnabled()) {
                throw ModuleException::conflict("Module [{$locked->module->slug}] must be disabled before it is uninstalled");
            }

            $dependents = $this->dependentsOf($locked, enabledOnly: false);
            if ($dependents->isNotEmpty()) {
                throw ModuleException::conflict(
                    "Module [{$locked->module->slug}] is required by installed module(s): " . $dependents->implode(', ')
                );
            }

            $this->registry->handler($locked->module->slug)->onUninstall($locked);

            $locked->update([
                'status' => ProjectModule::STATUS_UNINSTALLED,
                'uninstalled_at' => now(),
            ]);
        });
    }

    /**
     * Move a project to a newer version of an installed module (latest
     * published when none is given). Downgrades are refused: a module's data
     * migrations only run forward.
     */
    public function upgrade(ProjectModule $projectModule, ?string $version = null, array $configuration = [], ?User $user = null): ProjectModule
    {
        $fromVersion = $projectModule->moduleVersion->version;
        $target = $this->resolveInstallableVersion($projectModule->module, $version);

        if (version_compare($target->version, $fromVersion, '<=')) {
            throw ModuleException::conflict(
                "Module [{$projectModule->module->slug}] is on {$fromVersion}; upgrade target must be a newer version (got {$target->version})"
            );
        }

        return $this->transition(
            $projectModule,
            'project.module.upgraded',
            $user,
            function (ProjectModule $locked) use ($target, $fromVersion, $configuration) {
                $this->assertDependencies($locked->project, $target, requireEnabled: $locked->isEnabled());
                $this->assertDependentsAccept($locked, $target);

                // Carry over settings the new version still understands, fill
                // in its new defaults, then apply any settings sent along.
                $carried = array_intersect_key($locked->configuration ?? [], $target->configSchema());

                $locked->configuration = $this->validateConfiguration(
                    $target,
                    array_merge($target->defaultConfiguration(), $carried, $configuration),
                    requireComplete: $locked->isEnabled(),
                );
                $locked->module_version_id = $target->id;
                $locked->save();
                $locked->setRelation('moduleVersion', $target);

                $this->registry->handler($locked->module->slug)->onUpgrade($locked, $fromVersion, $target->version);
            },
            ['from_version' => $fromVersion, 'to_version' => $target->version],
        );
    }

    /**
     * Validate a configuration against a version's config_schema and return
     * it with unknown keys rejected. With $requireComplete, every required
     * setting must have a value.
     */
    public function validateConfiguration(ModuleVersion $version, array $configuration, bool $requireComplete = false): array
    {
        $schema = $version->configSchema();
        $errors = [];

        foreach (array_keys(array_diff_key($configuration, $schema)) as $unknown) {
            $errors["configuration.{$unknown}"] = ["Unknown setting [{$unknown}] for this module version"];
        }

        $rules = [];
        foreach ($schema as $key => $definition) {
            $rules[$key] = $this->rulesFor($definition, $requireComplete);
        }

        $validator = Validator::make($configuration, $rules);
        foreach ($validator->errors()->messages() as $key => $messages) {
            $errors["configuration.{$key}"] = $messages;
        }

        if ($errors) {
            throw ModuleException::invalid(
                $requireComplete ? 'Module configuration is invalid or incomplete' : 'Module configuration is invalid',
                $errors,
            );
        }

        return array_intersect_key($configuration, $schema);
    }

    protected function rulesFor(array $definition, bool $requireComplete): array
    {
        $rules = [
            $requireComplete && ($definition['required'] ?? false) ? 'required' : 'nullable',
        ];

        array_push($rules, ...match ($definition['type']) {
            'string' => ['string', 'max:255'],
            'text' => ['string', 'max:65535'],
            'integer' => ['integer'],
            'number' => ['numeric'],
            'boolean' => ['boolean'],
            'array', 'object' => ['array'],
        });

        if (isset($definition['options'])) {
            $rules[] = Rule::in($definition['options']);
        }

        if (isset($definition['min'])) {
            $rules[] = 'min:' . $definition['min'];
        }

        if (isset($definition['max'])) {
            $rules[] = 'max:' . $definition['max'];
        }

        return $rules;
    }

    protected function resolveInstallableVersion(Module $module, ?string $version): ModuleVersion
    {
        $moduleVersion = $version ? $module->findVersion($version) : $module->latestVersion();

        if (!$moduleVersion) {
            throw ModuleException::notFound(
                $version
                    ? "Module [{$module->slug}] has no version {$version}"
                    : "Module [{$module->slug}] has no published version"
            );
        }

        if (!$moduleVersion->isPublished()) {
            throw ModuleException::conflict("Module [{$module->slug}] version {$moduleVersion->version} is not published");
        }

        return $moduleVersion;
    }

    /**
     * Every dependency of $version must be present in the project at a
     * matching version (enabled too, when $requireEnabled). Core modules are
     * always present at their latest published version.
     */
    protected function assertDependencies(Project $project, ModuleVersion $version, bool $requireEnabled): void
    {
        foreach ($version->dependencyMap() as $slug => $constraint) {
            $dependency = $this->registry->find($slug)
                ?? throw ModuleException::conflict("Required module [{$slug}] does not exist in the registry");

            if ($dependency->is_core) {
                $coreVersion = $dependency->latestVersion()?->version;
                if (!$coreVersion || !VersionConstraint::satisfies($coreVersion, $constraint)) {
                    throw ModuleException::conflict("Requires core module [{$slug}] {$constraint}");
                }

                continue;
            }

            $installed = ProjectModule::query()
                ->with('moduleVersion')
                ->where('project_id', $project->id)
                ->where('module_id', $dependency->id)
                ->active()
                ->first();

            if (!$installed) {
                throw ModuleException::conflict("Requires module [{$slug}] {$constraint} to be installed first");
            }

            if (!VersionConstraint::satisfies($installed->moduleVersion->version, $constraint)) {
                throw ModuleException::conflict(
                    "Requires module [{$slug}] {$constraint}, but version {$installed->moduleVersion->version} is installed"
                );
            }

            if ($requireEnabled && !$installed->isEnabled()) {
                throw ModuleException::conflict("Requires module [{$slug}] to be enabled first");
            }
        }
    }

    /**
     * Installed modules that would break if $target replaced the current
     * version of $projectModule.
     */
    protected function assertDependentsAccept(ProjectModule $projectModule, ModuleVersion $target): void
    {
        $slug = $projectModule->module->slug;

        foreach ($this->dependentInstallations($projectModule, enabledOnly: false) as $dependent) {
            $constraint = $dependent->moduleVersion->dependencyMap()[$slug];

            if (!VersionConstraint::satisfies($target->version, $constraint)) {
                throw ModuleException::conflict(
                    "Module [{$dependent->module->slug}] requires [{$slug}] {$constraint}; cannot upgrade to {$target->version}"
                );
            }
        }
    }

    /**
     * @return Collection<int, string> slugs of modules depending on $projectModule
     */
    protected function dependentsOf(ProjectModule $projectModule, bool $enabledOnly): Collection
    {
        return $this->dependentInstallations($projectModule, $enabledOnly)
            ->map(fn (ProjectModule $dependent) => $dependent->module->slug)
            ->values();
    }

    /**
     * @return Collection<int, ProjectModule>
     */
    protected function dependentInstallations(ProjectModule $projectModule, bool $enabledOnly): Collection
    {
        $slug = $projectModule->module->slug;

        return ProjectModule::query()
            ->with(['module', 'moduleVersion'])
            ->where('project_id', $projectModule->project_id)
            ->whereKeyNot($projectModule->getKey())
            ->when($enabledOnly, fn ($q) => $q->enabled(), fn ($q) => $q->active())
            ->get()
            ->filter(fn (ProjectModule $candidate) => $candidate->moduleVersion->dependsOn($slug));
    }

    /**
     * Lock the row, run $apply, commit, then announce the transition.
     */
    protected function transition(ProjectModule $projectModule, string $event, ?User $user, callable $apply, array $metadata = []): ProjectModule
    {
        $previousStatus = $projectModule->status;

        $locked = DB::transaction(function () use ($projectModule, $apply) {
            $locked = ProjectModule::query()
                ->with(['module', 'moduleVersion', 'project'])
                ->whereKey($projectModule->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (!$locked->isInstalled()) {
                throw ModuleException::notFound("Module [{$locked->module->slug}] is not installed in this project");
            }

            $apply($locked);

            return $locked;
        });

        ModuleLifecycleChanged::dispatch($locked, $event, $previousStatus, $user?->id, $metadata);

        return $locked;
    }
}
