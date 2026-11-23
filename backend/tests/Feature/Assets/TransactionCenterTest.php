<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-08j (F-08 §3.9, D-50): Transaction Center lists the transactions the user may record now,
 * grouped and told in short, with their task counts; fields carry a hint for whoever fills them.
 */
class TransactionCenterTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_cards_are_the_active_types_grouped_with_their_way_of_working(): void
    {
        $mutasi = $this->activeFromTemplate('mutasi_lokasi');
        $this->postJson('/api/transaction-types/from-template', ['template' => 'perbaikan_vendor'])->assertCreated();

        $response = $this->getJson('/api/transaction-center')->assertOk()
            ->assertJsonPath('meta.groups.perpindahan', 'Perpindahan')->assertJsonPath('meta.groups.lainnya', 'Lainnya');
        $cards = collect($response->json('data'))->keyBy('name');

        // The seven system types and the published template; the draft one is not offered yet.
        $this->assertCount(8, $cards);
        $this->assertFalse($cards->has('Perbaikan ke vendor'));
        $card = $cards['Mutasi antar lokasi'];
        $this->assertSame([$mutasi, 'perpindahan', true, 1, 5], [$card['id'], $card['group'], $card['needs_approval'], $card['flow_version'], $card['field_count']]);
        $this->assertStringContainsString('Supervisor yang membawahi aset', $card['summary']);
        $this->assertSame(['peminjaman', 'Langsung tercatat.', false], [$cards['Peminjaman']['group'], $cards['Peminjaman']['summary'], $cards['Peminjaman']['needs_approval']]);
        $this->assertSame('lainnya', $cards[TransactionType::LABELS[TransactionType::NOTE]]['group']);
    }

    public function test_only_what_the_user_may_record_and_start_is_offered(): void
    {
        $mutasi = $this->activeFromTemplate('mutasi_lokasi');
        // A copy of Peminjaman needs asset.assign like the built-in loan.
        $loan = collect($this->getJson('/api/transaction-types?include_system=1')->json('data'))->firstWhere('system_type', TransactionType::LOAN)['id'];
        $copy = $this->postJson("/api/transaction-types/{$loan}/duplicate", ['code' => 'PJP', 'name' => 'Peminjaman proyek'])->assertCreated()->json('data.id');
        $this->postJson("/api/transaction-types/{$copy}/flow/publish")->assertOk();
        $this->putJson("/api/transaction-types/{$copy}", ['status' => 'active'])->assertOk();
        // Only Supervisors may start this one.
        $restricted = $this->postJson('/api/transaction-types', ['code' => 'KHS', 'name' => 'Khusus supervisor', 'status' => 'active', 'fields' => []])->assertCreated()->json('data.id');
        $node = fn (string $id, string $type, array $config = []) => ['id' => $id, 'type' => $type, 'label' => $id, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
        $this->putJson("/api/transaction-types/{$restricted}/flow", ['graph' => [
            'nodes' => [$node('start', 'start', ['roles' => [$this->roleId($this->org, RoleTemplates::SUPERVISOR)]]), $node('ok', 'end', ['result' => 'completed'])],
            'edges' => [['id' => 'e', 'source' => 'start', 'target' => 'ok', 'outcome' => null]],
        ]])->assertOk();
        $this->postJson("/api/transaction-types/{$restricted}/flow/publish")->assertOk();

        $this->createRole($this->org, 'PENCATAT', ['asset.view', 'transaction.view', 'transaction.create', 'asset.transfer']);
        $this->actingAs($this->createTenantUser($this->org, ['PENCATAT']), 'web');
        $names = collect($this->getJson('/api/transaction-center')->assertOk()->json('data'))->pluck('id', 'name');
        $this->assertTrue($names->has('Mutasi antar lokasi'));
        $this->assertTrue($names->has(TransactionType::LABELS[TransactionType::TRANSFER]));
        $this->assertFalse($names->has('Peminjaman proyek'), 'needs asset.assign');
        $this->assertFalse($names->has(TransactionType::LABELS[TransactionType::LOAN]));
        $this->assertFalse($names->has('Khusus supervisor'), 'the start step allows Supervisors only');

        $this->actingAs($this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR]), 'web');
        $this->assertContains('Khusus supervisor', collect($this->getJson('/api/transaction-center')->json('data'))->pluck('name'));

        // Without transaction.create nothing is offered; the counts still are.
        $this->createRole($this->org, 'LIHAT', ['asset.view', 'transaction.view']);
        $this->actingAs($this->createTenantUser($this->org, ['LIHAT']), 'web');
        $this->getJson('/api/transaction-center')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.tasks', 0);
    }

    public function test_the_counts_are_the_users_open_tasks_and_running_transactions(): void
    {
        $mutasi = $this->activeFromTemplate('mutasi_lokasi');
        $recorder = $this->createTenantUser($this->org, [RoleTemplates::ADMIN]);
        $receiver = $this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR]);
        $target = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC2', 'name' => 'Gudang 2']);
        $target->save();
        $assets = [$this->asset(), $this->asset()];
        $this->actingAs($recorder, 'web');
        foreach ($assets as $asset) {
            $this->postJson('/api/transactions', ['asset_id' => $asset, 'type' => 'custom', 'type_definition_id' => $mutasi,
                'field_values' => ['lokasi_tujuan' => $target->id, 'alasan' => 'Proyek', 'penerima' => $receiver->id]])->assertCreated();
        }
        $this->getJson('/api/transaction-center')->assertJsonPath('meta.running', 2)->assertJsonPath('meta.tasks', 0);

        $this->actingAs($this->admin, 'web');
        $this->getJson('/api/transaction-center')->assertJsonPath('meta.tasks', 2)->assertJsonPath('meta.running', 0);
    }

    public function test_a_field_carries_a_hint_for_whoever_fills_it_in(): void
    {
        $id = $this->postJson('/api/transaction-types', ['code' => 'PTJ', 'name' => 'Dengan petunjuk', 'status' => 'active', 'fields' => [
            ['key' => 'pemegang', 'label' => 'Pemegang', 'data_type' => 'user', 'is_required' => true, 'settings' => ['help' => '  Anggota tim yang memakai aset.  ']],
            ['key' => 'catatan', 'label' => 'Catatan', 'data_type' => 'text', 'settings' => ['max_length' => 50, 'help' => '']],
        ]])->assertCreated()
            ->assertJsonPath('data.fields.0.settings.help', 'Anggota tim yang memakai aset.')
            ->assertJsonPath('data.fields.1.settings.max_length', 50)
            ->json('data.id');
        $this->assertArrayNotHasKey('help', $this->getJson("/api/transaction-types/{$id}")->json('data.fields.1.settings'));
        $this->getJson("/api/transaction-types/{$id}/flow/describe")->assertOk()->assertJsonPath('data.form.0.help', 'Anggota tim yang memakai aset.');

        $this->putJson("/api/transaction-types/{$id}", ['fields' => [
            ['key' => 'pemegang', 'label' => 'Pemegang', 'data_type' => 'user', 'settings' => ['help' => str_repeat('a', 201)]],
        ]])->assertStatus(422)->assertJsonValidationErrors(['fields.0.settings.help'], 'error.details.fields');
    }

    private function activeFromTemplate(string $key): string
    {
        $id = $this->postJson('/api/transaction-types/from-template', ['template' => $key])->assertCreated()->json('data.id');
        $this->postJson("/api/transaction-types/{$id}/flow/publish")->assertOk();
        $this->putJson("/api/transaction-types/{$id}", ['status' => 'active'])->assertOk();

        return $id;
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Alat '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }
}
