<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\RoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectMemberController extends Controller
{
    public function __construct(
        protected ProjectService $projects,
        protected RoleAssignment $roles,
    ) {
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $query = $project->users();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'ilike', "%{$search}%")
                    ->orWhere('users.email', 'ilike', "%{$search}%");
            });
        }

        $members = $query->orderBy('users.name')->paginate($this->perPage($request));
        $roles = $this->roles->rolesByUser(
            collect($members->items())->pluck('id')->all(),
            $project->organization_id,
            $project->id,
        );

        foreach ($members->items() as $member) {
            $member->setAttribute('scoped_roles', $roles->get($member->id, []));
        }

        return $this->paginated($members, MemberResource::class);
    }

    public function store(StoreMemberRequest $request, Project $project)
    {
        $this->authorize('manageUsers', $project);

        $member = User::where('email', $request->validated('email'))->first()
            ?? throw ApiException::invalid('Validation failed', [
                'email' => ['No registered user has this email address'],
            ]);

        $this->projects->addMember($project, $request->user(), $member, $request->validated('role'));

        return $this->respond($project, $member, 'Member added successfully', 201);
    }

    public function update(UpdateMemberRequest $request, Project $project, User $user)
    {
        $this->authorize('manageUsers', $project);

        $this->projects->changeMemberRole($project, $request->user(), $user, $request->validated('role'));

        return $this->respond($project, $user, 'Member role updated successfully');
    }

    /**
     * Remove a member, or leave the project when removing yourself.
     */
    public function destroy(Request $request, Project $project, User $user)
    {
        $this->authorize($request->user()->is($user) ? 'view' : 'manageUsers', $project);

        $this->projects->removeMember($project, $request->user(), $user);

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully',
        ]);
    }

    protected function respond(Project $project, User $user, string $message, int $status = 200): JsonResponse
    {
        $member = $project->users()->whereKey($user->id)->firstOrFail();
        $member->setAttribute(
            'scoped_roles',
            $this->roles->rolesByUser([$member->id], $project->organization_id, $project->id)->get($member->id, []),
        );

        return response()->json([
            'success' => true,
            'data' => new MemberResource($member),
            'message' => $message,
        ], $status);
    }
}
