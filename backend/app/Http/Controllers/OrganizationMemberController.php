<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationService;
use App\Services\RoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationMemberController extends Controller
{
    public function __construct(
        protected OrganizationService $organizations,
        protected RoleAssignment $roles,
    ) {
    }

    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);

        $query = $organization->users();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'ilike', "%{$search}%")
                    ->orWhere('users.email', 'ilike', "%{$search}%");
            });
        }

        $members = $query->orderBy('users.name')->paginate($this->perPage($request));
        $roles = $this->roles->rolesByUser(collect($members->items())->pluck('id')->all(), $organization->id);

        foreach ($members->items() as $member) {
            $member->setAttribute('scoped_roles', $roles->get($member->id, []));
        }

        return $this->paginated($members, MemberResource::class);
    }

    public function store(StoreMemberRequest $request, Organization $organization)
    {
        $this->authorize('manageUsers', $organization);

        $member = User::where('email', $request->validated('email'))->first()
            ?? throw ApiException::invalid('Validation failed', [
                'email' => ['No registered user has this email address'],
            ]);

        $this->organizations->addMember($organization, $request->user(), $member, $request->validated('role'));

        return $this->respond($organization, $member, 'Member added successfully', 201);
    }

    public function update(UpdateMemberRequest $request, Organization $organization, User $user)
    {
        $this->authorize('manageUsers', $organization);

        $this->organizations->changeMemberRole($organization, $request->user(), $user, $request->validated('role'));

        return $this->respond($organization, $user, 'Member role updated successfully');
    }

    /**
     * Remove a member, or leave the organization when removing yourself.
     */
    public function destroy(Request $request, Organization $organization, User $user)
    {
        $this->authorize($request->user()->is($user) ? 'view' : 'manageUsers', $organization);

        $this->organizations->removeMember($organization, $request->user(), $user);

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully',
        ]);
    }

    protected function respond(Organization $organization, User $user, string $message, int $status = 200): JsonResponse
    {
        $member = $organization->users()->whereKey($user->id)->firstOrFail();
        $member->setAttribute(
            'scoped_roles',
            $this->roles->rolesByUser([$member->id], $organization->id)->get($member->id, []),
        );

        return response()->json([
            'success' => true,
            'data' => new MemberResource($member),
            'message' => $message,
        ], $status);
    }
}
