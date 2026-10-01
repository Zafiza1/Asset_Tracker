<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateModule;
use App\Models\TemplateVersion;
use App\Services\ModuleService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TemplateService $templateService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateService = app(TemplateService::class);
    }

    public function test_create_template_with_initial_version(): void
    {
        $data = [
            'name' => 'Test Template',
            'slug' => 'test-template',
            'description' => 'Test description',
            'category' => 'general',
            'version' => '1.0.0',
        ];

        $template = $this->templateService->create($data);

        $this->assertDatabaseHas('templates', [
            'name' => 'Test Template',
            'slug' => 'test-template',
        ]);

        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version' => '1.0.0',
        ]);

        $this->assertNotNull($template->current_version_id);
    }

    public function test_create_template_version(): void
    {
        $template = Template::factory()->create();

        $versionData = [
            'version' => '2.0.0',
            'description' => 'New version',
        ];

        $version = $this->templateService->createVersion($template, $versionData);

        $this->assertEquals('2.0.0', $version->version);
        $this->assertEquals($template->id, $version->template_id);
    }

    public function test_cannot_create_duplicate_version(): void
    {
        $template = Template::factory()->create();
        TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version' => '1.0.0',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Version 1.0.0 already exists');

        $this->templateService->createVersion($template, ['version' => '1.0.0']);
    }

    public function test_set_current_version(): void
    {
        $template = Template::factory()->create();
        $version1 = TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version' => '1.0.0',
        ]);
        $version2 = TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version' => '2.0.0',
        ]);

        $template = $this->templateService->setCurrentVersion($template, $version2);

        $this->assertEquals($version2->id, $template->current_version_id);
    }

    public function test_associate_module_to_version(): void
    {
        $template = Template::factory()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id]);
        $module = Module::factory()->create();

        $templateModule = $this->templateService->associateModuleToVersion($version, [
            'module_slug' => $module->slug,
            'required' => true,
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('template_modules', [
            'template_version_id' => $version->id,
            'module_id' => $module->id,
            'required' => true,
        ]);
    }

    public function test_dissociate_module_from_version(): void
    {
        $template = Template::factory()->create();
        $version = TemplateVersion::factory()->create(['template_id' => $template->id]);
        $module = Module::factory()->create();
        TemplateModule::factory()->create([
            'template_version_id' => $version->id,
            'module_id' => $module->id,
        ]);

        $this->templateService->dissociateModule($version, $module->slug);

        $this->assertDatabaseMissing('template_modules', [
            'template_version_id' => $version->id,
            'module_id' => $module->id,
        ]);
    }

    public function test_deprecate_template(): void
    {
        $template = Template::factory()->create(['status' => 'available']);

        $template = $this->templateService->deprecate($template);

        $this->assertEquals('deprecated', $template->status);
    }

    public function test_cannot_delete_template_in_use(): void
    {
        $template = Template::factory()->create();
        $organization = Organization::factory()->create();
        Project::factory()->create([
            'organization_id' => $organization->id,
            'template_id' => $template->id,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cannot delete template that is in use');

        $this->templateService->delete($template);
    }

    public function test_delete_template_not_in_use(): void
    {
        $template = Template::factory()->create();

        $this->templateService->delete($template);

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_get_available_templates(): void
    {
        Template::factory()->create(['status' => 'available']);
        Template::factory()->create(['status' => 'deprecated']);

        $templates = $this->templateService->getAvailable();

        $this->assertCount(1, $templates);
        $this->assertEquals('available', $templates->first()->status);
    }

    public function test_get_templates_by_category(): void
    {
        Template::factory()->create(['category' => 'general', 'status' => 'available']);
        Template::factory()->create(['category' => 'industrial', 'status' => 'available']);
        Template::factory()->create(['category' => 'general', 'status' => 'available']);

        $templates = $this->templateService->getByCategory('general');

        $this->assertCount(2, $templates);
        $this->assertEquals('general', $templates->first()->category);
    }
}
