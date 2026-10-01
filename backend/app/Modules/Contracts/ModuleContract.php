<?php

namespace App\Modules\Contracts;

use App\Models\ProjectModule;

/**
 * Lifecycle hooks a module may implement (Section 14/70).
 *
 * Hooks run inside the lifecycle transaction in App\Services\ModuleService:
 * throwing aborts and rolls back the transition. Modules use them to prepare
 * their own data (e.g. seed default statuses) — they must not reach into Core
 * tables directly (Section 60); go through Core services/events instead.
 */
interface ModuleContract
{
    public function onInstall(ProjectModule $projectModule): void;

    public function onConfigure(ProjectModule $projectModule, array $previousConfiguration): void;

    public function onEnable(ProjectModule $projectModule): void;

    public function onDisable(ProjectModule $projectModule): void;

    public function onUninstall(ProjectModule $projectModule): void;

    public function onUpgrade(ProjectModule $projectModule, string $fromVersion, string $toVersion): void;
}
