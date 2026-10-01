<?php

namespace App\Policies;

use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;

class CustomFieldPolicy
{
    public function viewAny(User $user, Project $project): bool { return $user->canAccessProject($project->id) && $user->hasPermission('asset.view', $project->organization_id, $project->id); }
    public function manage(User $user, Project $project): bool { return $user->canAccessProject($project->id) && $user->hasPermission('asset.manage-custom-fields', $project->organization_id, $project->id); }
    public function view(User $user, CustomField $field): bool { return $this->viewAny($user, Project::findOrFail($field->project_id)); }
}
