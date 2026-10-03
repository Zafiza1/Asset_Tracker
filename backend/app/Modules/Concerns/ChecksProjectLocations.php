<?php

namespace App\Modules\Concerns;

use App\Exceptions\ApiException;
use App\Models\Location;
use App\Models\Project;

/**
 * For modules that reference Core locations: the location must be a live
 * location of the same project.
 */
trait ChecksProjectLocations
{
    protected function assertLocationInProject(Project $project, ?int $locationId, string $field = 'location_id'): void
    {
        if ($locationId === null) {
            return;
        }

        $exists = Location::withoutGlobalScopes()
            ->where('id', $locationId)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->exists();

        if (!$exists) {
            throw ApiException::invalid('Validation failed', [$field => ['The location does not exist in this project']]);
        }
    }
}
