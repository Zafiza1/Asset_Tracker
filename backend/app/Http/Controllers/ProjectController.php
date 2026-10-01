<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Organization;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\Request;

/**
 * Projects — Control Plane. Listed and created under their organization,
 * addressed by id afterwards.
 */
class ProjectController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
    }

    /**
     * Projects of the organization the user can access: all of them with an
     * organization-level role (or as platform admin), otherwise the ones they
     * are a member of.
     */
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);

        $user = $request->user();
        $query = Project::query()->with('template')->where('organization_id', $organization->id);

        $seesAll = $user->isPlatformAdmin() || $user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->wherePivotNull('project_id')
            ->exists();

        if (!$seesAll) {
            $query->whereIn('id', $user->projects()->select('projects.id'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $sort = (string) $request->query('sort', 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'slug', 'status', 'created_at'], true)) {
            $query->orderBy($column, $direction);
        }

        return $this->paginated($query->paginate($this->perPage($request)), ProjectResource::class);
    }

    public function store(StoreProjectRequest $request, Organization $organization)
    {
        $this->authorize('create', [Project::class, $organization]);

        $project = $this->projects->create($organization, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project->load('template')),
            'message' => 'Project created successfully',
        ], 201);
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project->load('template')),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $this->authorize('update', $project);

        $project = $this->projects->update($project, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectResource($project->load('template')),
            'message' => 'Project updated successfully',
        ]);
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $this->projects->delete($project);

        return response()->json([
            'success' => true,
            'message' => 'Project deleted successfully',
        ]);
    }
}
