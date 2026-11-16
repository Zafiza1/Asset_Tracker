<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\ReportDesigner\TemplateStatus;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 of the Custom Report Designer: template CRUD/lifecycle API, the field registry,
 * permission split (Admin template holds no report_designer codes), tenant isolation and
 * the database guarantees (composite FKs, RLS, immutable published versions).
 */
class ReportDesignerFoundationTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $tenantAdminA;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->orgA, 'admin' => $this->adminA] = $this->createOrganization('ALPHA');
        ['organization' => $this->orgB, 'admin' => $this->adminB] = $this->createOrganization('BETA');
        // The Admin template role: report.view/report.export but no report_designer codes.
        $this->tenantAdminA = $this->createTenantUser($this->orgA, ['ADMIN']);
    }

    public function test_field_registry_is_seeded_and_readable_per_source(): void
    {
        $this->actingAs($this->adminA, 'web');

        $all = $this->getJson('/api/report-fields')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['asset', 'asset_transaction', 'context'], array_keys($all));
        $this->assertContains('asset.asset_number', array_column($all['asset'], 'field_key'));
        $this->assertContains('transaction.number', array_column($all['asset_transaction'], 'field_key'));
        $this->assertContains('organization.name', array_column($all['context'], 'field_key'));

        $asset = $this->getJson('/api/report-fields/asset')->assertOk()->json('data');
        $this->assertSame('asset.view', collect($asset)->firstWhere('field_key', 'asset.name')['required_permission']);

        $this->getJson('/api/report-fields/bogus')->assertStatus(404)->assertJsonPath('error.code', 'UNKNOWN_SOURCE');
    }

    public function test_template_lifecycle_create_update_publish_archive_delete(): void
    {
        $this->actingAs($this->adminA, 'web');

        $created = $this->postJson('/api/report-templates', [
            'code' => 'INVENTARIS', 'name' => 'Laporan Inventaris', 'data_source' => 'asset',
            'description' => 'Template uji', 'design' => ['schema' => 1, 'elements' => []],
        ])->assertCreated()->json('data');
        $this->assertSame(TemplateStatus::DRAFT, $created['status']);
        $this->assertNull($created['active_version']);
        $this->assertSame(1, $created['versions_count']);

        // Duplicate code rejected.
        $this->postJson('/api/report-templates', ['code' => 'INVENTARIS', 'name' => 'Lagi', 'data_source' => 'asset'])
            ->assertStatus(409)->assertJsonPath('error.code', 'TEMPLATE_CODE_TAKEN');

        $id = $created['id'];
        $this->putJson("/api/report-templates/{$id}", ['name' => 'Laporan Inventaris Aset'])->assertOk()
            ->assertJsonPath('data.name', 'Laporan Inventaris Aset');

        // Publish the draft: version 1 becomes published and active.
        $published = $this->postJson("/api/report-templates/{$id}/publish")->assertOk()->json('data');
        $this->assertSame(TemplateStatus::PUBLISHED, $published['status']);
        $this->assertSame(1, $published['active_version']);

        // Editing the design of a published template opens version 2 as a new draft.
        $updated = $this->putJson("/api/report-templates/{$id}", [
            'design' => ['schema' => 1, 'elements' => [['type' => 'text']]], 'change_note' => 'Tambah header',
        ])->assertOk()->json('data');
        $this->assertSame(2, $updated['versions_count']);
        $versions = $this->getJson("/api/report-templates/{$id}/versions")->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($versions, 'version_number'));
        $this->assertSame('draft', $versions[0]['status']);
        $this->assertSame('published', $versions[1]['status']);
        $this->assertSame('Tambah header', $versions[0]['change_note']);

        // Archive keeps history but blocks generation semantics (status check only for now).
        $this->postJson("/api/report-templates/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        // Publishing again from archived returns it to published with the draft version.
        $this->postJson("/api/report-templates/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(2, $this->getJson("/api/report-templates/{$id}")->json('data.active_version'));

        // Duplicate copies meta + latest design as a new draft template.
        $copy = $this->postJson("/api/report-templates/{$id}/duplicate")->assertCreated()->json('data');
        $this->assertNotSame($id, $copy['id']);
        $this->assertSame(TemplateStatus::DRAFT, $copy['status']);
        $this->assertStringEndsWith('-COPY', $copy['code']);

        $this->deleteJson("/api/report-templates/{$id}")->assertNoContent();
        $this->getJson("/api/report-templates/{$id}")->assertNotFound();
        // Soft-deleted: the row (and its versions, for export history) remains.
        $this->assertSoftDeleted('report_templates', ['id' => $id]);
    }

    public function test_published_versions_are_immutable_at_the_database_level(): void
    {
        $template = $this->insertTemplate($this->orgA, 'KODE_1');
        $version = $this->insertVersion($this->orgA, $template, 1, 'published');

        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), function () use ($version) {
            $this->expectQueryError('P0001', fn () => DB::table('report_template_versions')
                ->where('id', $version)->update(['design' => '{"hacked":true}']));
            // Deletes are denied twice: the runtime role has no DELETE grant at all, and the
            // immutability trigger also blocks the table owner.
            $this->expectQueryError('42501', fn () => DB::table('report_template_versions')->where('id', $version)->delete());
        });
        // A draft version stays editable.
        $draft = $this->insertVersion($this->orgA, $template, 2, 'draft');
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), function () use ($draft) {
            $this->assertSame(1, DB::table('report_template_versions')->where('id', $draft)->update(['change_note' => 'ok']));
        });
    }

    public function test_admin_template_role_cannot_manage_templates_but_can_view(): void
    {
        $this->actingAs($this->adminA, 'web');
        $id = $this->postJson('/api/report-templates', ['code' => 'SAJA', 'name' => 'Template', 'data_source' => 'asset'])
            ->assertCreated()->json('data.id');

        // The Admin role holds report.view but none of the report_designer permissions.
        $this->actingAs($this->tenantAdminA, 'web');
        $this->getJson('/api/report-templates')->assertOk();
        $this->getJson("/api/report-templates/{$id}")->assertOk();
        $this->getJson('/api/report-fields')->assertOk();

        $this->postJson('/api/report-templates', ['code' => 'X1', 'name' => 'X', 'data_source' => 'asset'])->assertForbidden();
        $this->putJson("/api/report-templates/{$id}", ['name' => 'Diubah'])->assertForbidden();
        $this->deleteJson("/api/report-templates/{$id}")->assertForbidden();
        $this->postJson("/api/report-templates/{$id}/duplicate")->assertForbidden();
        $this->postJson("/api/report-templates/{$id}/publish")->assertForbidden();
        $this->postJson("/api/report-templates/{$id}/archive")->assertForbidden();
    }

    public function test_templates_are_isolated_per_organization(): void
    {
        $this->actingAs($this->adminA, 'web');
        $id = $this->postJson('/api/report-templates', ['code' => 'MILIK_A', 'name' => 'Milik A', 'data_source' => 'asset'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->adminB, 'web');
        $this->getJson('/api/report-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/report-templates/{$id}")->assertNotFound();
        $this->putJson("/api/report-templates/{$id}", ['name' => 'Bajak'])->assertNotFound();
        $this->deleteJson("/api/report-templates/{$id}")->assertNotFound();
        $this->postJson("/api/report-templates/{$id}/publish")->assertNotFound();
        // The field registry is shared reference data: readable by every tenant.
        $this->getJson('/api/report-fields/asset')->assertOk();

        // RLS backstop: org B's context sees none of org A's rows.
        $this->tenancy()->runAsTenant(new TenantContext($this->orgB, $this->adminB), function () {
            $this->assertSame(0, DB::table('report_templates')->count());
            $this->assertSame(0, DB::table('report_template_versions')->count());
        });
        $this->assertSame('Milik A', DB::table('report_templates')->where('id', $id)->value('name'));
    }

    public function test_cross_organization_references_are_rejected_by_composite_keys(): void
    {
        $templateB = $this->insertTemplate($this->orgB, 'B_TEMPLATE');
        $this->tenancy()->runAsTenant(new TenantContext($this->orgA, $this->adminA), function () use ($templateB) {
            // A version cannot hang under another organization's template (composite FK).
            $this->expectQueryError('23503', fn () => DB::table('report_template_versions')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id, 'template_id' => $templateB,
                'version_number' => 1, 'status' => 'draft', 'design' => '{}', 'excel_config' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            // An export cannot pin a foreign template or version either.
            $this->expectQueryError('23503', fn () => DB::table('report_exports')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $this->orgA->id, 'template_id' => $templateB,
                'template_version_id' => (string) Str::uuid7(), 'export_number' => 'RPT-X', 'format' => 'pdf',
                'status' => 'pending', 'filters' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]));
        });
    }

    private function insertTemplate(Organization $organization, string $code): string
    {
        $id = (string) Str::uuid7();
        DB::table('report_templates')->insert([
            'id' => $id, 'organization_id' => $organization->id, 'code' => $code, 'name' => $code,
            'data_source' => 'asset', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertVersion(Organization $organization, string $templateId, int $number, string $status): string
    {
        $id = (string) Str::uuid7();
        DB::table('report_template_versions')->insert([
            'id' => $id, 'organization_id' => $organization->id, 'template_id' => $templateId,
            'version_number' => $number, 'status' => $status, 'design' => '{}', 'excel_config' => '{}',
            'published_at' => $status === 'published' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Runs the statement inside a savepoint so the surrounding test transaction stays usable. */
    private function expectQueryError(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("Expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());
        }
    }
}
