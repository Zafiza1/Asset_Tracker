<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RoleAndPermissionSeeder']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('platform-admin');

        $this->regularUser = User::factory()->create();
    }

    public function test_admin_can_list_templates(): void
    {
        Template::factory()->count(3)->create(['status' => 'available']);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/templates');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_regular_user_can_list_available_templates(): void
    {
        Template::factory()->count(2)->create(['status' => 'available']);
        Template::factory()->create(['status' => 'deprecated']);

        $response = $this->actingAs($this->regularUser)
            ->getJson('/api/v1/templates');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_create_template(): void
    {
        $data = [
            'name' => 'New Template',
            'slug' => 'new-template',
            'description' => 'Test template',
            'category' => 'general',
            'version' => '1.0.0',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/templates', $data);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'New Template',
                    'slug' => 'new-template',
                ],
            ]);

        $this->assertDatabaseHas('templates', ['slug' => 'new-template']);
    }

    public function test_regular_user_cannot_create_template(): void
    {
        $data = [
            'name' => 'New Template',
            'slug' => 'new-template',
        ];

        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/v1/templates', $data);

        $response->assertStatus(403);
    }

    public function test_admin_can_view_template(): void
    {
        $template = Template::factory()->create(['status' => 'available']);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $template->id,
                    'name' => $template->name,
                ],
            ]);
    }

    public function test_regular_user_can_view_available_template(): void
    {
        $template = Template::factory()->create(['status' => 'available']);

        $response = $this->actingAs($this->regularUser)
            ->getJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_view_deprecated_template(): void
    {
        $template = Template::factory()->create(['status' => 'deprecated']);

        $response = $this->actingAs($this->regularUser)
            ->getJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_update_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/templates/{$template->id}", [
                'name' => 'Updated Template',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'name' => 'Updated Template',
                ],
            ]);
    }

    public function test_regular_user_cannot_update_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->regularUser)
            ->putJson("/api/v1/templates/{$template->id}", [
                'name' => 'Updated Template',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Template deleted successfully',
            ]);

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_regular_user_cannot_delete_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->regularUser)
            ->deleteJson("/api/v1/templates/{$template->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_create_template_version(): void
    {
        $template = Template::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/templates/{$template->id}/versions", [
                'version' => '2.0.0',
                'description' => 'New version',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version' => '2.0.0',
        ]);
    }

    public function test_admin_can_list_template_versions(): void
    {
        $template = Template::factory()->create();
        TemplateVersion::factory()->count(3)->create(['template_id' => $template->id]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/templates/{$template->id}/versions");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_set_current_version(): void
    {
        $template = Template::factory()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/templates/{$template->id}/current-version", [
                'version_id' => $version->id,
            ]);

        $response->assertStatus(200);

        $template->refresh();
        $this->assertEquals($version->id, $template->current_version_id);
    }

    public function test_admin_can_associate_module_to_version(): void
    {
        $template = Template::factory()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id]);
        $module = Module::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/templates/{$template->id}/versions/{$version->id}/modules", [
                'module_slug' => $module->slug,
                'required' => true,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('template_modules', [
            'template_version_id' => $version->id,
            'module_id' => $module->id,
        ]);
    }

    public function test_admin_can_deprecate_template(): void
    {
        $template = Template::factory()->create(['status' => 'available']);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/templates/{$template->id}/deprecate");

        $response->assertStatus(200);

        $template->refresh();
        $this->assertEquals('deprecated', $template->status);
    }

    public function test_can_filter_templates_by_category(): void
    {
        Template::factory()->create(['category' => 'general', 'status' => 'available']);
        Template::factory()->create(['category' => 'industrial', 'status' => 'available']);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/templates?category=general');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
