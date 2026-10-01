<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\Permission;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = app(AuthService::class);
        
        // Run the role and permission seeder
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $result = $this->authService->login('test@example.com', 'password123');

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertEquals($user->id, $result['user']->id);
        $this->assertNotEmpty($result['token']);
    }

    public function test_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $this->authService->login('test@example.com', 'wrongpassword');
    }

    public function test_login_with_inactive_account(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'status' => 'inactive',
        ]);

        $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $this->authService->login('test@example.com', 'password123');
    }

    public function test_register_new_user(): void
    {
        $data = [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $result = $this->authService->register($data);

        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertEquals('Test User', $result['user']->name);
        $this->assertEquals('newuser@example.com', $result['user']->email);
        $this->assertNotEmpty($result['token']);
    }

    public function test_register_with_role(): void
    {
        $data = [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_slug' => 'viewer',
        ];

        $result = $this->authService->register($data);

        $this->assertTrue($result['user']->hasRole('viewer'));
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');
        $user->withAccessToken($token->accessToken);

        $this->authService->logout($user);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_logout_all(): void
    {
        $user = User::factory()->create();
        $user->createToken('token1');
        $user->createToken('token2');
        $user->createToken('token3');

        $this->assertDatabaseCount('personal_access_tokens', 3);

        $this->authService->logoutAll($user);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_refresh_token(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->createToken('test-token');
        $user->withAccessToken($oldToken->accessToken);

        $result = $this->authService->refreshToken($user);

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEquals($oldToken->plainTextToken, $result['token']);
    }

    public function test_verify_organization_access(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $user->organizations()->attach($organization->id);

        $this->assertTrue($this->authService->verifyOrganizationAccess($user, $organization->id));
        $this->assertFalse($this->authService->verifyOrganizationAccess($user, $otherOrganization->id));
    }

    public function test_verify_project_access(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();

        $user->projects()->attach($project->id);

        $this->assertTrue($this->authService->verifyProjectAccess($user, $project->id));
        $this->assertFalse($this->authService->verifyProjectAccess($user, $otherProject->id));
    }

    public function test_switch_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $user->organizations()->attach($organization->id);

        $result = $this->authService->switchOrganization($user, $organization->id);

        $this->assertArrayHasKey('current_organization', $result);
        $this->assertEquals($organization->id, $result['current_organization']->id);
    }

    public function test_switch_organization_without_access(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $this->authService->switchOrganization($user, $organization->id);
    }

    public function test_switch_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $user->projects()->attach($project->id);

        $result = $this->authService->switchProject($user, $project->id);

        $this->assertArrayHasKey('current_project', $result);
        $this->assertEquals($project->id, $result['current_project']->id);
    }

    public function test_switch_project_without_access(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $this->authService->switchProject($user, $project->id);
    }
}
