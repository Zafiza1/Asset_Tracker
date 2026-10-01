<?php

namespace App\Modules;

use App\Models\ProjectModule;
use App\Modules\Contracts\ModuleContract;

/**
 * No-op ModuleContract. Used for modules without a handler class, and as the
 * base for handlers that only need a few of the hooks.
 */
class BaseModule implements ModuleContract
{
    public function onInstall(ProjectModule $projectModule): void
    {
    }

    public function onConfigure(ProjectModule $projectModule, array $previousConfiguration): void
    {
    }

    public function onEnable(ProjectModule $projectModule): void
    {
    }

    public function onDisable(ProjectModule $projectModule): void
    {
    }

    public function onUninstall(ProjectModule $projectModule): void
    {
    }

    public function onUpgrade(ProjectModule $projectModule, string $fromVersion, string $toVersion): void
    {
    }
}
