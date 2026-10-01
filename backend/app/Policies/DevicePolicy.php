<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DevicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('device.view', $project->organization_id, $project->id);
    }

    public function view(User $user, Device $device): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($device->project_id)) {
            return false;
        }

        return $user->hasPermission('device.view', $device->organization_id, $device->project_id);
    }

    public function create(User $user, Project $project): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($project->id)) {
            return false;
        }

        return $user->hasPermission('device.create', $project->organization_id, $project->id);
    }

    public function update(User $user, Device $device): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($device->project_id)) {
            return false;
        }

        return $user->hasPermission('device.update', $device->organization_id, $device->project_id);
    }

    public function delete(User $user, Device $device): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($device->project_id)) {
            return false;
        }

        return $user->hasPermission('device.delete', $device->organization_id, $device->project_id);
    }

    public function restore(User $user, Device $device): bool
    {
        return $this->delete($user, $device);
    }

    public function forceDelete(User $user, Device $device): bool
    {
        return $user->isPlatformAdmin();
    }
}
