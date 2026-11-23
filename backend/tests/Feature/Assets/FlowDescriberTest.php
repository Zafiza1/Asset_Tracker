<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Flows\FlowDescriber;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * F-08b (D-41, D-43): "Cara kerja transaksi ini". Every step type, subject and operator reads as
 * a plain sentence with labels, never codes or ids; versions are compared in sentences.
 */
class FlowDescriberTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $approver;

    /** @var array<string, mixed> */
    private array $masters;

    private string $type;

    private string $supervisorRole;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->approver = $this->createTenantUser($this->org, ['SUPERVISOR'], [], ['name' => 'Rina Supervisor']);
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
        $this->supervisorRole = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => Role::query()->where('template_code', 'SUPERVISOR')->value('id'));

        $this->type = $this->postJson('/api/transaction-types', [
            'code' => 'KAL', 'name' => 'Kirim Kalibrasi', 'description' => 'Aset dikirim ke vendor kalibrasi.', 'status' => 'active', 'multi_asset' => false, 'fields' => [
                ['key' => 'vendor', 'label' => 'Vendor', 'data_type' => 'text', 'is_required' => true],
                ['key' => 'biaya', 'label' => 'Estimasi biaya', 'data_type' => 'money', 'is_required' => false],
                ['key' => 'tujuan', 'label' => 'Lokasi tujuan', 'data_type' => 'location', 'is_required' => false],
                ['key' => 'penerima', 'label' => 'Penerima', 'data_type' => 'user', 'is_required' => false],
                ['key' => 'kembali', 'label' => 'Rencana kembali', 'data_type' => 'date', 'is_required' => false],
                ['key' => 'hasil', 'label' => 'Hasil', 'data_type' => 'select', 'is_required' => false,
                    'settings' => ['options' => [['value' => 'LULUS', 'label' => 'Lulus'], ['value' => 'GAGAL', 'label' => 'Gagal']]]],
                ['key' => 'garansi', 'label' => 'Masih garansi', 'data_type' => 'boolean', 'is_required' => false],
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_every_step_reads_as_a_sentence_in_walking_order(): void
    {
        $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $this->graph()])->assertOk();
        $doc = $this->getJson("/api/transaction-types/{$this->type}/flow/describe")->assertOk()->json('data');
        $category = $this->masters['category']->name;

        $this->assertSame(['version_number' => 1, 'status' => 'draft'], $doc['version']);
        $this->assertSame(['Kirim Kalibrasi', 'Aset dikirim ke vendor kalibrasi.', false], [$doc['title'], $doc['purpose'], $doc['records_at_once']]);
        $this->assertSame([
            [1, 'start', 'Dapat dicatat oleh Supervisor. Transaksi tidak dapat dicatat bila status aset adalah Dipinjamkan. Pesan: "Kembalikan aset terlebih dahulu."'],
            [2, 'cek', "Bila nilai perolehan lebih dari Rp 10.000.000 atau kategori aset termasuk {$category} (beserta subkategorinya), lanjut ke \"Persetujuan Super Admin\"; bila tidak, lanjut ke \"Persetujuan Supervisor\"."],
            [3, 'sa', 'Perlu disetujui 2 pemegang role Supervisor. Bila ditolak, transaksi selesai sebagai Ditolak.'],
            [4, 'setuju', 'Perlu disetujui Supervisor yang membawahi aset. Bila ditolak, transaksi selesai sebagai Ditolak.'],
            [5, 'isi', 'Pengguna pada field Penerima mengisi Hasil (wajib) dan Rencana kembali dalam 2 hari.'],
            [6, 'aksi', 'Status aset diubah menjadi Dalam Perbaikan. Aset dipindah ke lokasi pada field Lokasi tujuan. Email dikirim ke pencatat: "Aset sudah dikirim."'],
            [7, 'tunggu', 'Menunggu sampai tanggal pada field Rencana kembali.'],
            [8, 'ok', 'Transaksi selesai.'],
            [9, 'tolak', 'Transaksi selesai sebagai Ditolak.'],
        ], array_map(fn ($s) => [$s['number'], $s['id'], $s['sentence']], $doc['steps']));

        // Always needed vs only on one branch; effects and people in step order.
        $this->assertSame('Transaksi ini perlu disetujui Supervisor yang membawahi aset. Pada kondisi tertentu, juga perlu disetujui 2 pemegang role Supervisor. '
            .'Ada 1 langkah isi data setelah transaksi diajukan. Hasilnya: status aset diubah menjadi Dalam Perbaikan dan aset dipindah ke lokasi pada field Lokasi tujuan.', $doc['summary']);
        $this->assertSame(['Status aset diubah menjadi Dalam Perbaikan', 'Aset dipindah ke lokasi pada field Lokasi tujuan'], $doc['effects']);
        $this->assertSame([
            '2 pemegang role Supervisor menyetujui di langkah "Persetujuan Super Admin"',
            'Supervisor yang membawahi aset menyetujui di langkah "Persetujuan Supervisor"',
            'Pengguna pada field Penerima mengisi data di langkah "Serah terima"',
            'Email dikirim ke pencatat',
        ], $doc['people']);
        // The form says which fields are asked later, in which step.
        $form = collect($doc['form'])->keyBy('key');
        $this->assertSame([null, 'Serah terima', 'Teks pendek', true], [$form['vendor']['asked_in'], $form['hasil']['asked_in'], $form['vendor']['type'], $form['vendor']['required']]);

        // Labels only: no id of a role, user, location or category anywhere in the text.
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/i', json_encode([$doc['steps'], $doc['summary'], $doc['people'], $doc['preconditions'], $doc['who_records']]));
    }

    public function test_every_subject_and_operator_reads_with_labels(): void
    {
        $location = $this->masters['location'];
        $category = $this->masters['category']->name;
        $phrases = $this->phrases([
            [['subject' => 'asset.status', 'op' => 'in', 'value' => ['available', 'under_maintenance']], 'status aset salah satu dari Tersedia atau Dalam Perbaikan'],
            [['subject' => 'asset.status', 'op' => 'not_in', 'value' => ['on_loan', 'lost']], 'status aset bukan Dipinjamkan maupun Hilang'],
            [['subject' => 'asset.condition', 'op' => 'eq', 'value' => 'RUSAK_BERAT'], 'kondisi aset adalah Rusak Berat'],
            [['subject' => 'asset.category', 'op' => 'neq', 'value' => $this->masters['category']->id], "kategori aset tidak termasuk {$category}"],
            [['subject' => 'asset.location', 'op' => 'eq', 'value' => $location->id], "lokasi aset berada di {$location->name}"],
            [['subject' => 'asset.location', 'op' => 'neq', 'value' => '00000000-0000-4000-8000-000000000000'], 'lokasi aset tidak berada di (tidak ditemukan)'],
            [['subject' => 'asset.acquisition_cost', 'op' => 'gte', 'value' => 5000000], 'nilai perolehan paling sedikit Rp 5.000.000'],
            [['subject' => 'asset.acquisition_cost', 'op' => 'lt', 'value' => 1500], 'nilai perolehan kurang dari Rp 1.500'],
            [['subject' => 'asset.acquisition_cost', 'op' => 'lte', 'value' => 0], 'nilai perolehan paling banyak Rp 0'],
            [['subject' => 'starter.role', 'op' => 'in', 'value' => [$this->supervisorRole]], 'pencatat memiliki role Supervisor'],
            [['subject' => 'starter.role', 'op' => 'not_in', 'value' => [$this->supervisorRole]], 'pencatat tidak memiliki role Supervisor'],
            [['subject' => 'field.hasil', 'op' => 'eq', 'value' => 'LULUS'], 'Hasil adalah Lulus'],
            [['subject' => 'field.garansi', 'op' => 'eq', 'value' => true], 'Masih garansi adalah Ya'],
            [['subject' => 'field.vendor', 'op' => 'empty'], 'Vendor kosong'],
            [['subject' => 'field.vendor', 'op' => 'not_empty'], 'Vendor terisi'],
            [['subject' => 'field.tujuan', 'op' => 'eq', 'value' => $location->id], "Lokasi tujuan adalah {$location->name}"],
            [['subject' => 'field.penerima', 'op' => 'eq', 'value' => $this->approver->id], 'Penerima adalah Rina Supervisor'],
            [['subject' => 'field.biaya', 'op' => 'gt', 'value' => 1000], 'Estimasi biaya lebih dari Rp 1.000'],
        ]);
        $this->assertSame(array_column($phrases, 1), array_column($phrases, 0));

        // A type for many assets says which assets a rule reads.
        $this->putJson("/api/transaction-types/{$this->type}", ['multi_asset' => true])->assertOk();
        $cost = ['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 1000];
        $this->assertSame(
            ['total nilai perolehan lebih dari Rp 1.000', 'nilai perolehan setiap aset lebih dari Rp 1.000', 'status salah satu aset adalah Dipinjamkan'],
            $this->inTenant(fn (FlowDescriber $d, TransactionTypeDefinition $t) => [
                $d->rule($t, $cost, 'total'), $d->rule($t, $cost, 'all'), $d->rule($t, ['subject' => 'asset.status', 'op' => 'eq', 'value' => 'on_loan'], 'any'),
            ]));
    }

    public function test_a_new_version_is_compared_with_the_published_one_in_sentences(): void
    {
        $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $this->graph()])->assertOk();
        $this->postJson("/api/transaction-types/{$this->type}/flow/publish")->assertOk();

        $graph = $this->graph();
        $graph['nodes'] = array_values(array_filter(array_map(fn ($n) => match ($n['id']) {
            'sa' => [...$n, 'config' => [...$n['config'], 'min_approvals' => 1]],
            'aksi' => [...$n, 'label' => 'Kirim ke laboratorium'],
            default => $n,
        }, $graph['nodes']), fn ($n) => $n['id'] !== 'tunggu'));
        $graph['nodes'][] = $this->node('kabar', 'action', 'Kabari vendor', ['actions' => [['kind' => 'notify', 'to' => ['type' => 'field', 'field' => 'penerima'], 'message' => 'Siapkan alat.']]]);
        $graph['edges'] = [...array_values(array_filter($graph['edges'], fn ($e) => ! in_array('tunggu', [$e['source'], $e['target']], true))),
            $this->edge('aksi', 'kabar'), $this->edge('kabar', 'ok')];
        $this->putJson("/api/transaction-types/{$this->type}/flow", ['graph' => $graph])->assertOk();

        $doc = $this->getJson("/api/transaction-types/{$this->type}/flow/describe")->assertOk()->json('data');
        $this->assertSame([['version_number' => 2, 'status' => 'draft'], 1], [$doc['version'], $doc['previous_version']]);
        $this->assertSame([
            // Outside the numbered steps the sentence names where an approval leads.
            'Langkah "Persetujuan Super Admin" diubah: Perlu disetujui pemegang role Supervisor. Bila disetujui, lanjut ke "Persetujuan Supervisor". Bila ditolak, transaksi selesai sebagai Ditolak. '
                .'(sebelumnya: Perlu disetujui 2 pemegang role Supervisor. Bila disetujui, lanjut ke "Persetujuan Supervisor". Bila ditolak, transaksi selesai sebagai Ditolak.)',
            'Langkah "Kirim ke vendor" diganti nama menjadi "Kirim ke laboratorium".',
            'Langkah "Kabari vendor" ditambahkan: Email dikirim ke pengguna pada field Penerima: "Siapkan alat."',
            'Langkah "Tunggu kembali" dihapus.',
        ], $doc['changes']);

        // An older version is described as it was, without a version before it.
        $this->getJson("/api/transaction-types/{$this->type}/flow/describe?version=1")->assertOk()
            ->assertJsonPath('data.version.status', 'published')
            ->assertJsonPath('data.previous_version', null)
            ->assertJsonPath('data.changes', [])
            ->assertJsonPath('data.steps.6.label', 'Tunggu kembali');
        $this->assertErrorCode($this->getJson("/api/transaction-types/{$this->type}/flow/describe?version=9"), 404, 'NOT_FOUND');
    }

    public function test_a_type_without_a_flow_records_at_once_and_anyone_who_sees_types_can_read_it(): void
    {
        $loan = $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => TransactionTypeDefinition::system('loan')->id);
        $this->createRole($this->org, 'LIHAT', ['asset.view', 'transaction.view']);
        $this->actingAs($this->createTenantUser($this->org, ['LIHAT']), 'web');

        $this->getJson("/api/transaction-types/{$loan}/flow/describe")->assertOk()
            ->assertJsonPath('data.version', null)
            ->assertJsonPath('data.records_at_once', true)
            ->assertJsonPath('data.steps', [])
            ->assertJsonPath('data.summary', 'Transaksi ini langsung tercatat tanpa persetujuan.');

        $this->createRole($this->org, 'ASET', ['asset.view']);
        $this->actingAs($this->createTenantUser($this->org, ['ASET']), 'web');
        $this->assertErrorCode($this->getJson("/api/transaction-types/{$loan}/flow/describe"), 403, 'FORBIDDEN');
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: string}>  $cases
     * @return list<array{0: string, 1: string}> [phrase, expected]
     */
    private function phrases(array $cases): array
    {
        return $this->inTenant(fn (FlowDescriber $d, TransactionTypeDefinition $t) => array_map(fn ($c) => [$d->rule($t, $c[0]), $c[1]], $cases));
    }

    private function inTenant(\Closure $fn): mixed
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => $fn(app(FlowDescriber::class), TransactionTypeDefinition::query()->findOrFail($this->type)));
    }

    private function graph(): array
    {
        return ['nodes' => [
            $this->node('start', 'start', 'Mulai', [
                'roles' => [$this->supervisorRole],
                'preconditions' => ['match' => 'all', 'assets' => 'all', 'rules' => [['subject' => 'asset.status', 'op' => 'neq', 'value' => 'on_loan']]],
                'message' => 'Kembalikan aset terlebih dahulu.',
            ]),
            $this->node('cek', 'condition', 'Cek nilai', ['match' => 'any', 'assets' => 'any', 'rules' => [
                ['subject' => 'asset.acquisition_cost', 'op' => 'gt', 'value' => 10000000],
                ['subject' => 'asset.category', 'op' => 'eq', 'value' => $this->masters['category']->id],
            ]]),
            $this->node('setuju', 'approval', 'Persetujuan Supervisor', ['approver' => ['type' => 'location_supervisor'], 'min_approvals' => 1]),
            $this->node('sa', 'approval', 'Persetujuan Super Admin', ['approver' => ['type' => 'role', 'id' => $this->supervisorRole], 'min_approvals' => 2]),
            $this->node('isi', 'input', 'Serah terima', ['assignee' => ['type' => 'field', 'field' => 'penerima'], 'fields' => ['hasil', 'kembali'], 'required' => ['hasil'], 'due_days' => 2]),
            $this->node('aksi', 'action', 'Kirim ke vendor', ['actions' => [
                ['kind' => 'set_status', 'status' => 'under_maintenance'],
                ['kind' => 'move_to_field_location', 'field' => 'tujuan'],
                ['kind' => 'notify', 'to' => ['type' => 'starter'], 'message' => 'Aset sudah dikirim.'],
            ]]),
            $this->node('tunggu', 'wait', 'Tunggu kembali', ['until' => 'date', 'field' => 'kembali']),
            $this->node('ok', 'end', 'Selesai', ['result' => 'completed']),
            $this->node('tolak', 'end', 'Ditolak', ['result' => 'rejected']),
        ], 'edges' => [
            $this->edge('start', 'cek'),
            $this->edge('cek', 'sa', 'yes'), $this->edge('cek', 'setuju', 'no'),
            $this->edge('sa', 'setuju', 'approved'), $this->edge('sa', 'tolak', 'rejected'),
            $this->edge('setuju', 'isi', 'approved'), $this->edge('setuju', 'tolak', 'rejected'),
            $this->edge('isi', 'aksi'), $this->edge('aksi', 'tunggu'), $this->edge('tunggu', 'ok'),
        ]];
    }

    private function node(string $id, string $type, string $label, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'label' => $label, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }

    private function edge(string $source, string $target, ?string $outcome = null): array
    {
        return ['id' => "{$source}-{$target}", 'source' => $source, 'target' => $target, 'outcome' => $outcome];
    }
}
