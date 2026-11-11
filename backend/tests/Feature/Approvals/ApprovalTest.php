<?php

namespace Tests\Feature\Approvals;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Approval of asset changes and master data changes (transactions apply at once): users without
 * approval.manage submit, Super Admin and Supervisor decide (Notifications menu).
 */
class ApprovalTest extends TestCase
{
    private Organization $org;

    private User $owner;

    private User $supervisor;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->owner] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->supervisor = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $this->admin = $this->createTenantUser($this->org, ['ADMIN']);
    }

    public function test_asset_registration_by_admin_waits_for_approval_and_applies_as_the_admin(): void
    {
        $this->loginAs($this->admin);
        $response = $this->postJson('/api/assets', $this->assetInput('Laptop Dell'))->assertStatus(202)
            ->assertJsonPath('meta.approval_pending', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.category', 'asset')
            ->assertJsonPath('data.action_label', 'Penambahan aset')
            ->assertJsonPath('data.summary', 'Laptop Dell');
        $id = $response->json('data.id');
        $this->assertFalse(Asset::query()->where('name', 'Laptop Dell')->exists());
        $this->getJson('/api/approvals/summary')->assertJsonPath('data.pending', 1)->assertJsonPath('data.can_decide', false);
        $this->assertErrorCode($this->postJson("/api/approvals/{$id}/approve"), 403, 'FORBIDDEN');

        $this->loginAs($this->supervisor);
        $this->getJson('/api/approvals/summary')->assertJsonPath('data.pending', 1)->assertJsonPath('data.can_decide', true);
        $this->getJson('/api/approvals?status=pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.requester.id', $this->admin->id);
        $details = collect($this->getJson("/api/approvals/{$id}")->assertOk()->json('data.details'))->pluck('value', 'label');
        $this->assertSame('Kategori 1 (CAT1)', $details['Kategori']);
        $this->assertSame('Gudang 1 (LOC1)', $details['Lokasi']);

        $this->postJson("/api/approvals/{$id}/approve", ['note' => 'OK'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decider.id', $this->supervisor->id);
        $asset = Asset::query()->where('name', 'Laptop Dell')->firstOrFail();
        $this->assertSame($this->admin->id, $asset->created_by);
        $this->assertErrorCode($this->postJson("/api/approvals/{$id}/approve"), 409, 'APPROVAL_DECIDED');
        $this->getJson('/api/approvals/summary')->assertJsonPath('data.pending', 0);
    }

    public function test_invalid_submission_is_answered_at_once_and_not_stored(): void
    {
        $this->loginAs($this->admin);
        $this->assertErrorCode($this->postJson('/api/assets', ['name' => 'Tanpa kategori']), 422, 'VALIDATION_FAILED');
        $asset = $this->createAsset();
        $this->assertErrorCode($this->patchJson("/api/assets/{$asset->id}", ['name' => '']), 422, 'VALIDATION_FAILED');

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_transaction_by_admin_applies_at_once_without_approval(): void
    {
        $asset = $this->createAsset();
        $target = $this->location('LOC2', 'Kantor Pusat');

        $this->loginAs($this->admin);
        $this->postJson('/api/transactions', ['asset_id' => $asset->id, 'type' => 'transfer', 'to_location_id' => $target->id])->assertCreated();
        $this->assertSame($target->id, $asset->fresh()->location_id);
        $this->assertSame($this->admin->id, AssetTransaction::query()->where('type', 'transfer')->firstOrFail()->created_by);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_approval_rechecks_the_rules_and_keeps_the_request_pending_when_they_fail(): void
    {
        $asset = $this->createAsset();
        $this->loginAs($this->admin);
        $id = $this->postJson("/api/assets/{$asset->id}/archive", ['reason' => 'Rusak'])->assertStatus(202)->json('data.id');
        $this->assertSame("{$asset->asset_number} · {$asset->name}", ApprovalRequest::query()->findOrFail($id)->summary);

        // Meanwhile the asset is lent out: archiving is no longer allowed.
        $this->loginAs($this->supervisor);
        $this->postJson('/api/transactions', ['asset_id' => $asset->id, 'type' => 'loan', 'borrower_name' => 'Budi'])->assertCreated();
        $response = $this->postJson("/api/approvals/{$id}/approve");
        $this->assertErrorCode($response, 409, 'ASSET_ON_LOAN');
        $this->assertStringStartsWith('Pengajuan tidak dapat diterapkan:', $response->json('error.message'));
        $this->assertSame(ApprovalRequest::PENDING, ApprovalRequest::query()->findOrFail($id)->status);
        $this->assertNull($asset->fresh()->archived_at);

        $this->postJson("/api/approvals/{$id}/reject", ['note' => 'Aset sedang dipinjam'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Aset sedang dipinjam');
    }

    public function test_requesters_see_and_cancel_only_their_own_requests(): void
    {
        $other = $this->createTenantUser($this->org, ['ADMIN']);
        $this->loginAs($other);
        $foreign = $this->postJson('/api/locations', ['name' => 'Gudang Timur'])->assertStatus(202)->json('data.id');

        $this->loginAs($this->admin);
        $own = $this->postJson('/api/locations', ['name' => 'Gudang Barat'])->assertStatus(202)
            ->assertJsonPath('data.category', 'master')->json('data.id');
        $this->getJson('/api/approvals')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->assertErrorCode($this->getJson("/api/approvals/{$foreign}"), 404, 'NOT_FOUND');
        $this->assertErrorCode($this->postJson("/api/approvals/{$foreign}/cancel"), 404, 'NOT_FOUND');
        $this->postJson("/api/approvals/{$own}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertErrorCode($this->postJson("/api/approvals/{$own}/cancel"), 409, 'APPROVAL_DECIDED');

        $this->loginAs($this->supervisor);
        $this->getJson('/api/approvals')->assertJsonCount(2, 'data');
        $this->getJson('/api/approvals?mine=1')->assertJsonCount(0, 'data');
        $this->assertErrorCode($this->postJson("/api/approvals/{$foreign}/cancel"), 403, 'FORBIDDEN');
        $this->assertFalse(Location::query()->whereIn('name', ['Gudang Timur', 'Gudang Barat'])->exists());
    }

    public function test_master_data_change_summary_and_approval(): void
    {
        $category = $this->masters['category'];
        $this->loginAs($this->admin);
        $id = $this->patchJson("/api/tenant-masters/categories/{$category->id}", ['name' => 'Elektronik'])->assertStatus(202)
            ->assertJsonPath('data.action_label', 'Perubahan kategori aset')
            ->assertJsonPath('data.summary', 'Kategori 1 (CAT1)')->json('data.id');
        $this->assertSame('Kategori 1', $category->fresh()->name);

        $this->loginAs($this->supervisor);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();
        $this->assertSame('Elektronik', $category->fresh()->name);
    }

    public function test_update_details_show_current_and_submitted_values_with_impact_and_history(): void
    {
        $asset = $this->createAsset();
        $this->loginAs($this->admin);
        $id = $this->patchJson("/api/assets/{$asset->id}", ['name' => 'Laptop Baru', 'attributes' => ['merek' => null], 'lock_version' => $asset->lock_version])->assertStatus(202)->json('data.id');

        $this->loginAs($this->supervisor);
        $detail = $this->getJson("/api/approvals/{$id}")->assertOk()
            ->assertJsonPath('data.asset_id', $asset->id)
            ->assertJsonPath('data.impact.destructive', false)
            ->assertJsonPath('data.history.0.action', 'approval.submitted')
            ->assertJsonPath('data.history.0.actor', $this->admin->name);
        $name = collect($detail->json('data.details'))->firstWhere('label', 'Nama');
        $this->assertSame(['label' => 'Nama', 'value' => 'Laptop Baru', 'current' => 'Laptop'], $name);
        $this->assertNull(collect($detail->json('data.details'))->firstWhere('label', 'Atribut kategori'));

        $this->assertErrorCode($this->postJson("/api/approvals/{$id}/reject"), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->postJson("/api/approvals/{$id}/reject", ['note' => 'no']), 422, 'VALIDATION_FAILED');
        $this->postJson("/api/approvals/{$id}/reject", ['note' => 'Nama tidak sesuai label'])->assertOk()
            ->assertJsonPath('data.history.1.action', 'approval.rejected')
            ->assertJsonPath('data.history.1.actor', $this->supervisor->name)
            ->assertJsonPath('data.history.1.note', 'Nama tidak sesuai label');
        $this->assertSame('Laptop', $asset->fresh()->name);
    }

    public function test_counts_per_status_and_search_follow_the_list_filters(): void
    {
        $this->loginAs($this->admin);
        $first = $this->postJson('/api/locations', ['name' => 'Gudang Barat'])->assertStatus(202)->json('data.id');
        $this->postJson('/api/locations', ['name' => 'Gudang Timur'])->assertStatus(202);
        $this->postJson('/api/assets', $this->assetInput('Printer Epson'))->assertStatus(202);
        $this->postJson("/api/approvals/{$first}/cancel")->assertOk();

        $this->loginAs($this->supervisor);
        $this->getJson('/api/approvals/counts')->assertOk()
            ->assertExactJson(['data' => ['pending' => 2, 'approved' => 0, 'rejected' => 0, 'cancelled' => 1, 'all' => 3]]);
        $this->getJson('/api/approvals/counts?category=asset')->assertJsonPath('data.all', 1);
        $this->getJson('/api/approvals/counts?search=gudang')->assertJsonPath('data.pending', 1)->assertJsonPath('data.cancelled', 1);
        // Search also matches the requester's name and the type of change.
        $this->getJson('/api/approvals?search='.urlencode($this->admin->name))->assertJsonCount(3, 'data');
        $this->getJson('/api/approvals?search=penambahan%20aset')->assertJsonCount(1, 'data')->assertJsonPath('data.0.summary', 'Printer Epson');
        $this->getJson('/api/approvals?decided_by_me=1')->assertJsonCount(0, 'data');
        $this->assertErrorCode($this->getJson('/api/approvals/counts?category=unknown'), 422, 'VALIDATION_FAILED');
    }

    public function test_uploaded_file_is_kept_until_the_import_is_approved(): void
    {
        Storage::fake('local');
        $this->loginAs($this->admin);
        $id = $this->post('/api/locations/import', ['file' => $this->sheet([['name', 'code', 'parent_code', 'address'], ['Gedung Pusat', 'GP', null, null]])], ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.summary', 'data.xlsx')->json('data.id');
        $stored = ApprovalRequest::query()->findOrFail($id)->files['file']['path'];
        Storage::disk('local')->assertExists($stored);
        $this->assertFalse(Location::query()->where('code', 'GP')->exists());

        $this->loginAs($this->supervisor);
        $this->postJson("/api/approvals/{$id}/approve")->assertOk();
        $this->assertTrue(Location::query()->where('code', 'GP')->exists());
        Storage::disk('local')->assertMissing($stored);
    }

    public function test_approvers_change_data_directly(): void
    {
        foreach ([$this->owner, $this->supervisor] as $user) {
            $this->loginAs($user);
            $this->postJson('/api/assets', $this->assetInput("Aset {$user->id}"))->assertCreated();
        }
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_only_super_admin_and_supervisor_roles_can_hold_the_approval_permission(): void
    {
        $this->loginAs($this->owner);
        $roles = collect($this->getJson('/api/roles')->json('data'))->keyBy('code');
        $this->assertContains('approval.manage', $roles['SUPER_ADMIN']['permissions']);
        $this->assertContains('approval.manage', $roles['SUPERVISOR']['permissions']);
        $this->assertNotContains('approval.manage', $roles['ADMIN']['permissions']);
        $this->assertContains('approval', collect($this->getJson('/api/permissions')->json('data'))->pluck('group')->all());

        $this->assertErrorCode($this->postJson('/api/roles', ['name' => 'Penyetuju', 'permissions' => ['asset.view', 'approval.manage']]), 422, 'APPROVAL_ROLE_ONLY');
        $custom = $this->postJson('/api/roles', ['name' => 'Penyetuju', 'permissions' => ['asset.view']])->assertCreated()->json('data.id');
        $this->assertErrorCode($this->patchJson("/api/roles/{$custom}", ['permissions' => ['asset.view', 'approval.manage']]), 422, 'APPROVAL_ROLE_ONLY');
        $admin = $roles['ADMIN'];
        $this->assertErrorCode($this->patchJson("/api/roles/{$admin['id']}", ['permissions' => [...$admin['permissions'], 'approval.manage']]), 422, 'APPROVAL_ROLE_ONLY');

        // A Supervisor role without the permission submits like everyone else.
        $supervisor = $roles['SUPERVISOR'];
        $this->patchJson("/api/roles/{$supervisor['id']}", ['permissions' => array_values(array_diff($supervisor['permissions'], ['approval.manage']))])->assertOk();
        $this->loginAs($this->supervisor);
        $this->postJson('/api/assets', $this->assetInput('Printer'))->assertStatus(202);
        $this->assertErrorCode($this->getJson('/api/approvals/x'), 404, 'NOT_FOUND');
    }

    /** @return array<string, mixed> */
    private function assetInput(string $name): array
    {
        return [
            'name' => $name,
            'serial_number' => 'SN-'.Str::uuid(),
            'category_id' => $this->masters['category']->id,
            'location_id' => $this->masters['location']->id,
        ];
    }

    private function createAsset(): Asset
    {
        $this->loginAs($this->owner);
        $id = $this->postJson('/api/assets', $this->assetInput('Laptop'))->assertCreated()->json('data.id');

        return Asset::query()->findOrFail($id);
    }

    private function location(string $code, string $name): Location
    {
        $location = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $location->save();

        return $location;
    }

    /** @param list<list<mixed>> $rows */
    private function sheet(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $book->getActiveSheet()->getCell([$c + 1, $r + 1])->setValue($value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'data.xlsx', null, null, true);
    }

    private function loginAs(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'web');
    }
}
