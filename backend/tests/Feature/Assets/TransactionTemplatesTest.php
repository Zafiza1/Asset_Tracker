<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Notifications\TransactionFlowNotification;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\AssetTransactions\Templates\TransactionTemplates;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-08c (D-40): the ready-made templates. Each one makes a draft organization type whose flow
 * passes the F-07b checks, and every path of its flow runs to its end.
 */
class TransactionTemplatesTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** Records the transactions; the admin (Super Admin) and Supervisors approve them (no self-approval). */
    private User $recorder;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $this->actingAs($this->admin, 'web');
    }

    public function test_every_template_makes_a_draft_type_whose_flow_can_be_published(): void
    {
        $made = 0;
        foreach (TransactionTemplates::all() as $key => $template) {
            if ($template['closes'] !== null) {
                continue;
            }
            $response = $this->fromTemplate($key)->assertCreated()
                ->assertJsonPath('data.status', 'draft')
                ->assertJsonPath('data.source_template_key', $key)
                ->assertJsonPath('data.code', $template['code'])
                ->assertJsonPath('data.name', $template['name'])
                ->assertJsonPath('data.core_type', $template['core_type'])
                ->assertJsonPath('data.flow.draft_version', 1);
            $this->assertSame(array_column($template['fields'], 'key'), array_column($response->json('data.fields'), 'key'));
            $ids = [$response->json('data.id')];
            if ($template['pair'] !== null) {
                $pair = TransactionTemplates::find($template['pair']);
                $response->assertJsonPath('meta.pair.source_template_key', $template['pair'])
                    ->assertJsonPath('meta.pair.closes_type.id', $ids[0])
                    ->assertJsonPath('meta.pair.code', $pair['code']);
                $ids[] = $response->json('meta.pair.id');
            } else {
                $response->assertJsonPath('meta.pair', null);
            }
            foreach ($ids as $id) {
                $this->postJson("/api/transaction-types/{$id}/flow/validate")->assertOk()->assertJsonPath('data.issues', []);
                $this->postJson("/api/transaction-types/{$id}/flow/publish")->assertOk()->assertJsonPath('data.status', 'published');
                $made++;
            }
        }
        $this->assertSame(count(TransactionTemplates::all()), $made);
        $this->assertTrue(DB::table('audit_logs')->where('organization_id', $this->org->id)->where('action', 'transaction_type.created_from_template')->exists());
    }

    public function test_the_gallery_shows_each_card_with_its_documentation_and_makes_nothing(): void
    {
        $viewer = $this->createRole($this->org, 'LIHAT', ['asset.view', 'transaction.view']);
        $this->actingAs($this->createTenantUser($this->org, [$viewer->code]), 'web');
        $before = DB::table('transaction_type_definitions')->where('organization_id', $this->org->id)->count();

        $data = $this->getJson('/api/transaction-type-templates')->assertOk()
            ->assertJsonPath('meta.categories.perpindahan', 'Perpindahan')->json('data');
        $cards = collect($data)->keyBy('key');
        // The closing template only comes with its pair.
        $this->assertFalse($cards->has('kembali_kalibrasi'));
        $this->assertSame('kembali_kalibrasi', $cards['kirim_kalibrasi']['pair']['key']);

        $mutasi = $cards['mutasi_lokasi'];
        $this->assertSame([5, 2, 'perpindahan'], [$mutasi['field_count'], $mutasi['approval_count'], $mutasi['category']]);
        $this->assertSame('Mutasi antar lokasi', $mutasi['documentation']['title']);
        $sentences = implode(' ', array_column($mutasi['documentation']['steps'], 'sentence'));
        $this->assertStringContainsString('Rp 10.000.000', $sentences);
        $this->assertStringContainsString('Lokasi tujuan', implode(' ', $mutasi['documentation']['effects']));
        // Roles are named for this organization, never as a template code.
        $this->assertStringContainsString('Super Admin', $sentences);
        $this->assertSame([2, 'outgoing'], [$cards['penghapusan_aset']['approval_count'], $cards['penghapusan_aset']['core_type']]);

        $this->assertSame($before, DB::table('transaction_type_definitions')->where('organization_id', $this->org->id)->count());
    }

    public function test_mutasi_needs_the_supervisor_then_super_admin_for_costly_assets_and_the_receiver_confirms(): void
    {
        $type = $this->activeType('mutasi_lokasi');
        $target = $this->location('LOC2', 'Gudang 2');
        $receiver = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $values = fn () => ['lokasi_tujuan' => $target, 'alasan' => 'Proyek baru', 'penerima' => $receiver->id];

        // Low value: Supervisor lokasi, then the receiver, then the asset moves.
        $asset = $this->asset(5_000_000);
        $run = $this->start($type, $asset, $values())->assertCreated()->json('data');
        $this->assertSame('setuju_spv', $run['current_step']['id']);
        $run = $this->complete($run, ['decision' => 'approved'])->assertOk()->json('data');
        $this->assertSame('terima', $run['current_step']['id']);
        $this->complete($run, ['field_values' => ['no_bast' => 'BAST-01']], $receiver)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.location_id', $target)->assertJsonPath('data.status', 'in_use');

        // High value: Super Admin approves as well.
        $costly = $this->asset(15_000_000);
        $run = $this->start($type, $costly, $values())->assertCreated()->json('data');
        $run = $this->complete($run, ['decision' => 'approved'])->json('data');
        $this->assertSame('setuju_sa', $run['current_step']['id']);
        $run = $this->complete($run, ['decision' => 'approved'])->json('data');
        $this->assertSame('terima', $run['current_step']['id']);
        $this->complete($run, ['field_values' => []], $receiver)->assertOk()->assertJsonPath('data.state', 'completed');

        // Rejected by the Super Admin: the asset stays where it was.
        $rejected = $this->asset(15_000_000);
        $run = $this->start($type, $rejected, $values())->json('data');
        $run = $this->complete($run, ['decision' => 'approved'])->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->getJson("/api/assets/{$rejected}")->assertJsonPath('data.location_id', $this->masters['location']->id);

        // Rejected by the supervisor.
        $run = $this->start($type, $rejected, $values())->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');

        // An asset on loan cannot be moved.
        $lent = $this->asset(1_000_000);
        $this->postJson('/api/transactions', ['asset_id' => $lent, 'type' => 'loan', 'borrower_name' => 'Budi', 'due_date' => now()->addWeek()->toDateString()])->assertCreated();
        $this->assertErrorCode($this->start($type, $lent, $values()), 422, 'PRECONDITION_FAILED');
    }

    public function test_kirim_kalibrasi_waits_for_kembali_dari_kalibrasi_which_sets_the_condition(): void
    {
        Storage::fake(TransactionAttachments::DISK);
        app(CategoryAttributeService::class)->createDefaultAttributes($this->masters['category']);
        $this->relaxDefaultCondition();
        $made = $this->fromTemplate('kirim_kalibrasi', ['code' => 'KLB', 'pair' => ['code' => 'KLBK', 'name' => 'Kalibrasi selesai']])->assertCreated();
        $made->assertJsonPath('meta.pair.code', 'KLBK')->assertJsonPath('meta.pair.name', 'Kalibrasi selesai');
        $send = $this->activate($made->json('data.id'));
        $back = $this->activate($made->json('meta.pair.id'));
        $conditionKey = app(CategoryAttributeService::class)->conditionAttribute($this->masters['category']->id)->key;

        foreach (['RUSAK_RINGAN' => 'under_maintenance', 'BAIK' => 'in_use'] as $result => $status) {
            $asset = $this->asset(1_000_000);
            $run = $this->start($send, $asset, ['vendor' => ['name' => 'PT Kalibrasi'], 'rencana_kembali' => now()->addMonth()->toDateString()])
                ->assertCreated()->json('data');
            $run = $this->complete($run, ['decision' => 'approved'])->assertOk()->json('data');
            $this->assertSame(['running', 'tunggu'], [$run['state'], $run['current_step']['id']]);
            $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

            $this->actingAs($this->recorder, 'web');
            $file = $this->post("/api/transaction-types/{$back}/attachments", ['field_key' => 'sertifikat', 'file' => UploadedFile::fake()->create('sertifikat.pdf', 10, 'application/pdf')],
                ['Accept' => 'application/json'])->assertCreated()->json('data.id');
            $this->actingAs($this->admin, 'web');
            $this->start($back, $asset, ['tanggal_kembali' => now()->toDateString(), 'sertifikat' => $file, 'hasil' => $result])
                ->assertCreated()->assertJsonPath('data.state', 'completed');

            $this->assertSame('completed', $this->getJson("/api/transaction-runs/{$run['id']}")->json('data.state'));
            $asset = $this->getJson("/api/assets/{$asset}")->json('data');
            $this->assertSame([$status, $result], [$asset['status'], $asset['attributes'][$conditionKey]]);
        }

        // The supervisor may refuse sending it out.
        $asset = $this->asset(1_000_000);
        $run = $this->start($send, $asset, ['vendor' => ['name' => 'PT Kalibrasi'], 'rencana_kembali' => now()->addMonth()->toDateString()])->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'available');

        // The closing template is made only with its pair.
        $this->fromTemplate('kembali_kalibrasi')->assertStatus(422)->assertJsonValidationErrors(['template'], 'error.details.fields');
    }

    public function test_perbaikan_ke_vendor_needs_the_super_admin_for_a_large_estimate(): void
    {
        $type = $this->activeType('perbaikan_vendor');
        $values = fn (int $cost) => ['vendor' => ['name' => 'Bengkel'], 'keluhan' => 'Tidak menyala', 'estimasi_biaya' => $cost];

        $asset = $this->asset(1_000_000);
        $run = $this->start($type, $asset, $values(1_000_000))->assertCreated()->json('data');
        $this->complete($run, ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'under_maintenance');

        $asset = $this->asset(1_000_000);
        $run = $this->start($type, $asset, $values(6_000_000))->json('data');
        $run = $this->complete($run, ['decision' => 'approved'])->json('data');
        $this->assertSame('setuju_sa', $run['current_step']['id']);
        $this->complete($run, ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');

        $run = $this->start($type, $this->asset(1_000_000), $values(6_000_000))->json('data');
        $run = $this->complete($run, ['decision' => 'approved'])->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');
        $run = $this->start($type, $this->asset(1_000_000), $values(1_000_000))->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');
    }

    public function test_peminjaman_ke_karyawan_lends_to_the_holder_and_emails_them(): void
    {
        Notification::fake();
        $type = $this->activeType('peminjaman_karyawan');
        $holder = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $due = now()->addWeek()->toDateString();

        $asset = $this->asset(1_000_000);
        $run = $this->start($type, $asset, ['pemegang' => $holder->id, 'rencana_kembali' => $due])
            ->assertCreated()->assertJsonPath('data.state', 'completed')->json('data');
        $data = $this->getJson("/api/assets/{$asset}")->json('data');
        $this->assertSame(['on_loan', $holder->name, $due], [$data['status'], $data['borrower_name'], substr((string) $data['loan_due_date'], 0, 10)]);
        Notification::assertSentTo($holder, TransactionFlowNotification::class, fn ($n) => $n->transactionNumber === $run['transaction_number']);

        // Only an Aktif or Tidak Aktif asset may be lent.
        $this->assertErrorCode($this->start($type, $asset, ['pemegang' => $holder->id, 'rencana_kembali' => $due]), 422, 'PRECONDITION_FAILED');
    }

    public function test_penghapusan_aset_disposes_after_the_supervisor_and_the_super_admin(): void
    {
        $type = $this->activeType('penghapusan_aset');
        $supervisor = $this->createTenantUser($this->org, ['SUPERVISOR']);
        $core = ['outgoing_nature' => 'permanent', 'reason_code' => 'sold', 'counterparty_name' => 'PT Pembeli'];

        $asset = $this->asset(1_000_000);
        $run = $this->start($type, $asset, ['nilai_jual' => 500_000], $core)->assertCreated()->json('data');
        $this->assertSame('setuju_spv', $run['current_step']['id']);
        // The Supervisor step belongs to Supervisor holders.
        $this->assertSame(403, $this->complete($run, ['decision' => 'approved'])->status());
        $run = $this->complete($run, ['decision' => 'approved'], $supervisor)->assertOk()->json('data');
        $this->assertSame('setuju_sa', $run['current_step']['id']);
        $this->complete($run, ['decision' => 'approved'])->assertOk()->assertJsonPath('data.state', 'completed');
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'disposed');
        $this->assertContains(TransactionType::OUTGOING, DB::table('asset_transactions')->where('asset_id', $asset)->pluck('type')->all());

        $kept = $this->asset(1_000_000);
        $run = $this->start($type, $kept, [], $core)->json('data');
        $run = $this->complete($run, ['decision' => 'approved'], $supervisor)->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertOk()->assertJsonPath('data.state', 'rejected');
        $this->getJson("/api/assets/{$kept}")->assertJsonPath('data.status', 'available');

        $run = $this->start($type, $kept, [], $core)->json('data');
        $this->complete($run, ['decision' => 'rejected', 'note' => 'Tidak disetujui.'], $supervisor)->assertOk()->assertJsonPath('data.state', 'rejected');
    }

    public function test_approvers_get_the_organizations_roles_and_a_missing_role_must_be_chosen_again(): void
    {
        $id = $this->fromTemplate('penghapusan_aset')->assertCreated()->json('data.id');
        $roles = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => Role::query()->pluck('id', 'template_code')->all());
        $approvers = $this->draftApprovers($id);
        $this->assertEquals([
            'setuju_spv' => ['type' => 'role', 'id' => $roles[RoleTemplates::SUPERVISOR]],
            'setuju_sa' => ['type' => 'role', 'id' => $roles[RoleTemplates::SUPER_ADMIN]],
        ], $approvers);
        // Supervisor replaced by a custom role: the step keeps no approver, and publishing asks for one (F08-04).
        // Supervisor replaced by a custom role: the step keeps no approver: publishing asks for one (F08-04).
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => Role::query()->where('template_code', RoleTemplates::SUPERVISOR)->update(['template_code' => null]));
        $other = $this->fromTemplate('penghapusan_aset', ['code' => 'HPSB'])->assertCreated()->json('data.id');
        $this->assertEquals(['type' => 'role', 'id' => null], $this->draftApprovers($other)['setuju_spv']);
        $issues = $this->postJson("/api/transaction-types/{$other}/flow/publish")->assertStatus(422)->json('error.details.issues');
        $this->assertContains('setuju_spv', array_column($issues, 'node_id'));
    }

    public function test_making_from_a_template_needs_both_rights_and_a_free_code(): void
    {
        $this->fromTemplate('mutasi_lokasi')->assertCreated();
        // The template's code is taken now; another copy takes its own, and stays independent.
        $this->fromTemplate('mutasi_lokasi')->assertStatus(422)->assertJsonValidationErrors(['code'], 'error.details.fields');
        $this->fromTemplate('kirim_kalibrasi', ['pair' => ['code' => 'MUT']])->assertStatus(422)->assertJsonValidationErrors(['pair.code'], 'error.details.fields');
        $this->assertFalse(DB::table('transaction_type_definitions')->where('organization_id', $this->org->id)->where('code', 'KAL')->exists(), 'nothing is kept when the pair fails');
        $this->fromTemplate('tidak_ada')->assertStatus(422)->assertJsonValidationErrors(['template'], 'error.details.fields');
        $this->fromTemplate('mutasi_lokasi', ['code' => 'TRF'])->assertStatus(422);

        $second = $this->fromTemplate('mutasi_lokasi', ['code' => 'MUTB', 'name' => 'Mutasi proyek'])->assertCreated()
            ->assertJsonPath('data.name', 'Mutasi proyek')->json('data.id');
        $this->putJson("/api/transaction-types/{$second}", ['fields' => [
            ['key' => 'alasan', 'label' => 'Alasan mutasi', 'data_type' => 'textarea', 'is_required' => true],
        ]])->assertOk();
        $this->assertCount(5, TransactionTemplates::find('mutasi_lokasi')['fields']);
        $this->getJson('/api/transaction-type-templates')->assertOk()->assertJsonPath('data.0.field_count', 5);

        $this->createRole($this->org, 'JENIS', ['asset.view', 'transaction.view', 'transaction_type.manage']);
        $this->actingAs($this->createTenantUser($this->org, ['JENIS']), 'web');
        $this->assertErrorCode($this->fromTemplate('perbaikan_vendor'), 403, 'FORBIDDEN');
    }

    /** @param array<string, mixed> $body */
    private function fromTemplate(string $key, array $body = []): TestResponse
    {
        return $this->postJson('/api/transaction-types/from-template', ['template' => $key, ...$body]);
    }

    /** An active type made from the template, its flow published. */
    private function activeType(string $key): string
    {
        return $this->activate($this->fromTemplate($key)->assertCreated()->json('data.id'));
    }

    private function activate(string $id): string
    {
        $this->postJson("/api/transaction-types/{$id}/flow/publish")->assertOk();
        $this->putJson("/api/transaction-types/{$id}", ['status' => 'active'])->assertOk();

        return $id;
    }

    /** @return array<string, array<string, mixed>> approval node id => approver of the draft flow */
    private function draftApprovers(string $typeId): array
    {
        $graph = json_decode((string) DB::table('transaction_flow_versions')->where('type_definition_id', $typeId)->where('status', 'draft')->value('graph'), true);

        return collect($graph['nodes'])->where('type', 'approval')->mapWithKeys(fn ($n) => [$n['id'] => $n['config']['approver']])->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $core  the built-in input of a type with a core action
     */
    private function start(string $typeId, string $assetId, array $values, array $core = []): TestResponse
    {
        $this->actingAs($this->recorder, 'web');
        $response = $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => 'custom', 'type_definition_id' => $typeId, 'field_values' => $values, ...$core]);
        $this->actingAs($this->admin, 'web');

        return $response;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $body
     * @param  User|null  $as  who works the task; the admin by default
     */
    private function complete(array $run, array $body, ?User $as = null): TestResponse
    {
        $this->actingAs($as ?? $this->admin, 'web');
        $response = $this->postJson("/api/transaction-runs/{$run['id']}/tasks/{$run['open_task']['id']}/complete", $body);
        $this->actingAs($this->admin, 'web');

        return $response;
    }

    private function asset(int $cost): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Alat '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available', 'acquisition_cost' => $cost,
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    private function location(string $code, string $name): string
    {
        $location = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name]);
        $location->save();

        return $location->id;
    }
}
