<?php

namespace Tests\Feature\Assets;

use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-08d (F08-18..21): scenarios of every path of a flow, each named as a sentence and with
 * inputs (sample asset from the tester's scope, field values, decisions) that make the dry run
 * take exactly that path. Nothing is saved.
 */
class FlowScenariosTest extends TestCase
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
        app(CategoryAttributeService::class)->createDefaultAttributes($this->masters['category']);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_every_path_of_a_template_gets_a_named_scenario_that_the_dry_run_follows(): void
    {
        $type = $this->postJson('/api/transaction-types/from-template', ['template' => 'mutasi_lokasi'])->assertCreated()->json('data.id');
        $cheap = $this->asset(5_000_000);
        $costly = $this->asset(15_000_000);
        $lent = $this->asset(1_000_000);
        $this->postJson('/api/transactions', ['asset_id' => $lent, 'type' => 'loan', 'borrower_name' => 'Budi', 'due_date' => now()->addWeek()->toDateString()])->assertCreated();
        $conditionKey = app(CategoryAttributeService::class)->conditionAttribute($this->masters['category']->id)->key;
        $broken = $this->asset(1_000_000, [$conditionKey => 'RUSAK_BERAT']);
        $before = [DB::table('transaction_runs')->count(), DB::table('asset_transactions')->count()];

        $response = $this->postJson("/api/transaction-types/{$type}/flow/scenarios")->assertOk()
            ->assertJsonPath('meta.total', 10)->assertJsonPath('meta.truncated', false)->assertJsonPath('meta.limit', 12);
        $scenarios = collect($response->json('data'));
        $this->assertCount(10, $scenarios);
        $this->assertSame(10, $scenarios->pluck('id')->unique()->count(), 'stable, distinct ids');

        // The main path leads: every approval given, every condition met.
        $main = $scenarios->first();
        $this->assertStringStartsWith('Disetujui Supervisor yang membawahi aset, nilai perolehan', $main['name']);
        $this->assertStringEndsWith('→ Selesai', $main['name']);

        // The start refusing: preconditions (an asset on loan) and the rule "Larangan memindah aset rusak".
        $blocked = $scenarios->where('kind', 'blocked')->values();
        $this->assertSame([null, 'rusak'], $blocked->pluck('rule_id')->all());
        $this->assertSame([[$lent], [$broken]], $blocked->pluck('inputs.asset_ids')->all());
        $this->assertSame('Ditolak saat dicatat: "Aset rusak berat diperbaiki atau dihapuskan, bukan dipindah."', $blocked[1]['name']);

        // A costly asset for "Approval aset bernilai tinggi", a cheap one when it does not apply.
        $highValue = $scenarios->first(fn ($s) => in_array(['node_id' => 'rc_nilai', 'outcome' => 'yes'], array_map(fn ($st) => ['node_id' => $st['node_id'], 'outcome' => $st['outcome']], $s['steps']), true) && $s['status'] === 'ready');
        $this->assertSame([$costly], $highValue['inputs']['asset_ids']);
        $lowValue = $scenarios->first(fn ($s) => collect($s['steps'])->contains(fn ($st) => $st['node_id'] === 'rc_nilai' && $st['outcome'] === 'no') && $s['status'] === 'ready');
        $this->assertContains($lowValue['inputs']['asset_ids'][0], [$cheap, $broken, $lent]);

        // No asset has a department, so "lintas departemen" has no sample: told, not failed.
        $noSample = $scenarios->where('status', 'no_sample');
        $this->assertNotEmpty($noSample);
        $this->assertTrue($noSample->every(fn ($s) => collect($s['steps'])->contains(fn ($st) => $st['node_id'] === 'rc_lintas' && $st['outcome'] === 'yes')));
        $this->assertStringContainsString('Skenario sendiri', $noSample->first()['note']);

        // "Jalankan semua skenario": each ready scenario run through flow/simulate takes its path.
        foreach ($scenarios->where('status', 'ready') as $scenario) {
            $path = $this->postJson("/api/transaction-types/{$type}/flow/simulate", [
                'asset_ids' => $scenario['inputs']['asset_ids'], 'field_values' => (array) $scenario['inputs']['field_values'], 'decisions' => (array) $scenario['inputs']['decisions'],
            ])->assertOk()->json('data.path');
            $this->assertSame(array_column($scenario['steps'], 'outcome'), array_column($path, 'outcome'), $scenario['name']);
            $this->assertSame(array_column($scenario['steps'], 'node_id'), array_column($path, 'node_id'), $scenario['name']);
        }
        $this->assertSame($before, [DB::table('transaction_runs')->count(), DB::table('asset_transactions')->count()], 'nothing is recorded');
    }

    public function test_field_values_are_made_to_meet_the_conditions(): void
    {
        $type = $this->postJson('/api/transaction-types/from-template', ['template' => 'perbaikan_vendor'])->assertCreated()->json('data.id');
        $this->asset(1_000_000);

        $scenarios = collect($this->postJson("/api/transaction-types/{$type}/flow/scenarios")->assertOk()->json('data'));
        $this->assertTrue($scenarios->every(fn ($s) => $s['status'] === 'ready'));
        $estimates = $scenarios->map(fn ($s) => [collect($s['steps'])->firstWhere('node_id', 'rc_biaya')['outcome'] ?? null, $s['inputs']['field_values']['estimasi_biaya'] ?? null])
            ->filter(fn ($pair) => $pair[0] !== null)->unique(fn ($pair) => $pair[0])->values()->all();
        $this->assertEqualsCanonicalizing([['yes', 5_000_001], ['no', 5_000_000]], $estimates);
        $this->assertTrue($scenarios->contains(fn ($s) => str_contains($s['name'], 'Estimasi biaya paling banyak Rp 5.000.000')));
    }

    public function test_at_most_twelve_scenarios_and_a_path_without_an_end_is_a_problem(): void
    {
        $type = $this->postJson('/api/transaction-types', ['code' => 'UJI', 'name' => 'Uji', 'status' => 'active', 'fields' => [
            ['key' => 'n', 'label' => 'Nilai', 'data_type' => 'number', 'is_required' => false],
        ]])->assertCreated()->json('data.id');
        $this->asset(1);
        // Four conditions in a row: 16 paths.
        $nodes = [$this->node('start', 'start')];
        $edges = [];
        $previous = 'start';
        foreach (range(1, 4) as $i) {
            $nodes[] = $this->node("c{$i}", 'condition', ['match' => 'all', 'assets' => 'any', 'rules' => [['subject' => 'field.n', 'op' => 'gt', 'value' => $i]]]);
            $nodes[] = $this->node("m{$i}", 'action', ['actions' => [['kind' => 'set_status', 'status' => 'in_use']]]);
            array_push($edges, $this->edge($previous, "c{$i}"), $this->edge("c{$i}", "m{$i}", 'yes'), $this->edge("c{$i}", "m{$i}", 'no'));
            $previous = "m{$i}";
        }
        $nodes[] = $this->node('ok', 'end', ['result' => 'completed']);
        $edges[] = $this->edge($previous, 'ok');

        $this->postJson("/api/transaction-types/{$type}/flow/scenarios", ['graph' => ['nodes' => $nodes, 'edges' => $edges]])->assertOk()
            ->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.truncated', true);

        // A Tidak line missing: that path stops before Selesai.
        $broken = ['nodes' => [$this->node('start', 'start'), $this->node('c', 'condition', ['rules' => [['subject' => 'field.n', 'op' => 'gt', 'value' => 1]]]), $this->node('ok', 'end')],
            'edges' => [$this->edge('start', 'c'), $this->edge('c', 'ok', 'yes')]];
        $problem = collect($this->postJson("/api/transaction-types/{$type}/flow/scenarios", ['graph' => $broken])->assertOk()->json('data'))->firstWhere('status', 'problem');
        $this->assertSame(['c', 'no'], [$problem['steps'][1]['node_id'], $problem['steps'][1]['outcome']]);
        $this->assertStringContainsString('berhenti sebelum', $problem['name']);
    }

    public function test_samples_come_from_the_testers_scope_and_need_the_flow_right(): void
    {
        $type = $this->postJson('/api/transaction-types/from-template', ['template' => 'perbaikan_vendor'])->assertCreated()->json('data.id');
        $this->asset(1_000_000);

        // A Supervisor of a location without assets has nothing to try with.
        $empty = (new Location)->forceFill(['organization_id' => $this->org->id, 'code' => 'LOC9', 'name' => 'Gudang 9']);
        $empty->save();
        $this->actingAs($this->createTenantUser($this->org, [RoleTemplates::SUPERVISOR], [['scope_type' => 'location', 'ref_id' => $empty->id]]), 'web');
        $scenarios = collect($this->postJson("/api/transaction-types/{$type}/flow/scenarios")->assertOk()->json('data'));
        $this->assertTrue($scenarios->every(fn ($s) => $s['inputs']['asset_ids'] === []));
        $this->assertTrue($scenarios->every(fn ($s) => $s['status'] === 'ready' || str_contains((string) $s['note'], 'Belum ada aset')));

        $this->actingAs($this->createTenantUser($this->org, [RoleTemplates::ADMIN]), 'web');
        $this->assertErrorCode($this->postJson("/api/transaction-types/{$type}/flow/scenarios"), 403, 'FORBIDDEN');
    }

    private function node(string $id, string $type, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $id, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $source, string $target, ?string $outcome = null): array
    {
        return ['id' => "{$source}-{$target}-".($outcome ?? 'x'), 'source' => $source, 'target' => $target, 'outcome' => $outcome];
    }

    /** @param array<string, mixed> $attributes */
    private function asset(int $cost, array $attributes = []): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Alat '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available', 'acquisition_cost' => $cost,
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
            ...($attributes === [] ? [] : ['attributes' => $attributes]),
        ])->assertCreated()->json('data.id');
    }
}
