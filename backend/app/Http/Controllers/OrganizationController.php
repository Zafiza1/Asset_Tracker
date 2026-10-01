<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;

/**
 * Organizations (tenants) — Control Plane. Addressed by id in the URL, not by
 * the X-Organization-Id context (see ControlPlaneMiddleware).
 */
class OrganizationController extends Controller
{
    public function __construct(protected OrganizationService $organizations)
    {
    }

    /**
     * The organizations the user belongs to (all of them for platform admins).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->isPlatformAdmin()
            ? Organization::query()
            : $user->organizations();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('organizations.name', 'ilike', "%{$search}%")
                    ->orWhere('organizations.slug', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('organizations.status', $status);
        }

        $sort = (string) $request->query('sort', 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'slug', 'created_at'], true)) {
            $query->orderBy("organizations.{$column}", $direction);
        }

        return $this->paginated(
            $query->withCount('projects')->paginate($this->perPage($request)),
            OrganizationResource::class,
        );
    }

    public function store(StoreOrganizationRequest $request)
    {
        $this->authorize('create', Organization::class);

        $organization = $this->organizations->create($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data' => new OrganizationResource($organization),
            'message' => 'Organization created successfully',
        ], 201);
    }

    public function show(Organization $organization)
    {
        $this->authorize('view', $organization);

        return response()->json([
            'success' => true,
            'data' => new OrganizationResource($organization->loadCount('projects')),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization)
    {
        $this->authorize('update', $organization);

        return response()->json([
            'success' => true,
            'data' => new OrganizationResource($this->organizations->update($organization, $request->validated())),
            'message' => 'Organization updated successfully',
        ]);
    }

    public function destroy(Organization $organization)
    {
        $this->authorize('delete', $organization);

        $this->organizations->delete($organization);

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully',
        ]);
    }
}
