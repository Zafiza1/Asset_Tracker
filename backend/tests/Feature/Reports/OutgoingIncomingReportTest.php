<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reports on outgoing/incoming transactions (F-04): new Field Registry entries, filters,
 * template default filters, and the ready-made samples users copy and adapt.
 */
class OutgoingIncomingReportTest extends TestCase
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
        $this->actingAs($this->admin, 'web');
    }

    public function test_samples_are_listed_and_copied_as_valid_drafts(): void
    {
        $samples = collect($this->getJson('/api/report-templates/samples')->assertOk()->json('data'));
        $this->assertSame(['outgoing', 'incoming', 'not_returned', 'handover'], $samples->pluck('key')->all());

        foreach ($samples as $sample) {
            $template = $this->postJson('/api/report-templates/from-sample', ['sample' => $sample['key']])->assertCreated()
                ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.data_source', 'asset_transaction')->json('data');
            $this->assertSame($sample['code'], $template['code']);

            $check = $this->postJson("/api/report-templates/{$template['id']}/validate")->assertOk()->json('data');
            $this->assertTrue($check['valid'], $sample['key'].': '.json_encode($check['issues']));
        }

        // A second copy gets a free code instead of failing.
        $this->postJson('/api/report-templates/from-sample', ['sample' => 'outgoing'])->assertCreated()
            ->assertJsonPath('data.code', 'RPT-TRX-KELUAR-COPY');
        $this->assertErrorCode($this->postJson('/api/report-templates/from-sample', ['sample' => 'nope']), 422, 'VALIDATION_FAILED');
    }

    public function test_outgoing_sample_preselects_outgoing_rows_and_resolves_the_new_fields(): void
    {
        [$laptop, $printer] = [$this->asset('Laptop'), $this->asset('Printer')];
        $this->out($laptop, ['document_number' => 'SJ-01', 'counterparty_org' => 'PT Mitra', 'counterparty_contact' => '0812-1111']);
        $this->record($printer, 'note', ['notes' => 'Dicek']);

        $template = $this->fromSample('outgoing');
        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');

        $this->assertSame(1, $data['total']);   // default filter type=outgoing: registrations and notes left out
        $row = $data['rows'][0];
        $this->assertSame('Laptop', $row['transaction.asset_name']);
        $this->assertSame('Sementara', $row['transaction.outgoing_nature']);
        $this->assertSame('Perbaikan di vendor', $row['transaction.reason']);
        $this->assertSame('CV Servis', $row['transaction.counterparty_name']);
        $this->assertSame('PT Mitra', $row['transaction.counterparty_org']);
        $this->assertSame('SJ-01', $row['transaction.document_number']);
        $this->assertSame('Belum kembali', $row['transaction.return_status']);

        // The request still overrides the template's default filter.
        $notes = $this->postJson("/api/report-templates/{$template}/preview", ['filters' => ['type' => 'note']])->assertOk()->json('data');
        $this->assertSame(1, $notes['total']);
        $this->assertSame('Printer', $notes['rows'][0]['transaction.asset_name']);
    }

    public function test_not_returned_report_tracks_overdue_and_returned_assets(): void
    {
        [$late, $back] = [$this->asset('Proyektor'), $this->asset('Kamera')];
        $this->out($late, ['transaction_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->subDays(3)->toDateString()]);
        $this->out($back, ['transaction_date' => now()->subDays(4)->toDateString()]);
        $this->record($back, 'incoming', ['transaction_date' => now()->subDay()->toDateString()])->assertCreated();

        $template = $this->fromSample('not_returned');
        // Row keys contain dots: plain array access, not Collection::pluck (which reads dots as paths).
        $rows = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data.rows');
        $this->assertSame(['Proyektor'], array_column($rows, 'transaction.asset_name'));
        $this->assertSame('Terlambat', $rows[0]['transaction.return_status']);
        $this->assertSame('10', $rows[0]['transaction.days_out']);

        // On the outgoing list, the returned asset shows its return date and time away.
        $incoming = $this->createTemplate([
            ['key' => 'transaction.asset_name', 'label' => 'Aset'],
            ['key' => 'transaction.return_status', 'label' => 'Status'],
            ['key' => 'transaction.returned_on', 'label' => 'Kembali'],
            ['key' => 'transaction.days_out', 'label' => 'Hari'],
        ], ['type' => 'outgoing']);
        $kamera = collect($this->postJson("/api/report-templates/{$incoming}/preview")->json('data.rows'))->first(fn (array $r) => $r['transaction.asset_name'] === 'Kamera');
        $this->assertSame('Sudah kembali', $kamera['transaction.return_status']);
        $this->assertSame(now()->subDay()->format('d/m/Y'), $kamera['transaction.returned_on']);
        $this->assertSame('3', $kamera['transaction.days_out']);
    }

    public function test_incoming_sample_links_back_to_the_outgoing_transaction(): void
    {
        $asset = $this->asset('Laptop');
        $outNumber = $this->out($asset)['transaction_number'];
        $this->record($asset, 'incoming', ['to_status' => 'under_maintenance'])->assertCreated();

        $row = $this->postJson('/api/report-templates/'.$this->fromSample('incoming').'/preview')->assertOk()->json('data.rows.0');
        $this->assertSame($outNumber, $row['transaction.outgoing_number']);
        $this->assertSame('CV Servis', $row['transaction.counterparty_name']);
        $this->assertSame('Dalam Perbaikan', $row['transaction.to_status']);
    }

    public function test_outside_party_contact_needs_its_own_permission(): void
    {
        $this->out($this->asset('Laptop'), ['counterparty_contact' => '0812-1111']);
        $template = $this->createTemplate([
            ['key' => 'transaction.counterparty_name', 'label' => 'Pihak'],
            ['key' => 'transaction.counterparty_contact', 'label' => 'Kontak'],
        ], ['type' => 'outgoing']);

        $this->assertSame('0812-1111', $this->postJson("/api/report-templates/{$template}/preview")->json('data.rows.0')['transaction.counterparty_contact']);

        $role = $this->createRole($this->org, 'REPORTER', ['organization.view', 'asset.view', 'transaction.view', 'report.view', 'report.preview'])->code;
        $this->actingAs($this->createTenantUser($this->org, [$role]), 'web');
        $data = $this->postJson("/api/report-templates/{$template}/preview")->assertOk()->json('data');
        $this->assertArrayNotHasKey('transaction.counterparty_contact', $data['rows'][0]);
        $this->assertSame('CV Servis', $data['rows'][0]['transaction.counterparty_name']);
    }

    public function test_template_default_filters_are_validated(): void
    {
        $template = $this->createTemplate([['key' => 'transaction.number', 'label' => 'No']], ['type' => 'bogus', 'colour' => 'red', 'not_returned' => true]);
        $issues = collect($this->postJson("/api/report-templates/{$template}/validate")->assertOk()->json('data.issues'));

        $this->assertSame(['filters.colour'], $issues->where('code', 'UNKNOWN_FILTER')->pluck('path')->values()->all());
        $this->assertSame(['filters.type'], $issues->where('code', 'INVALID_FILTER')->pluck('path')->values()->all());
    }

    public function test_new_fields_are_registered_for_the_fields_api(): void
    {
        $keys = collect($this->getJson('/api/report-fields/asset_transaction')->assertOk()->json('data'))->pluck('field_key');
        foreach (['transaction.outgoing_nature', 'transaction.reason', 'transaction.counterparty_name', 'transaction.counterparty_contact',
            'transaction.document_number', 'transaction.returned_on', 'transaction.return_status', 'transaction.days_out', 'transaction.outgoing_number'] as $key) {
            $this->assertContains($key, $keys, $key);
        }
        $this->assertSame('transaction.contact.view', DB::table('report_field_registry')->where('field_key', 'transaction.counterparty_contact')->value('required_permission'));
    }

    private function asset(string $name): string
    {
        return $this->postJson('/api/assets', [
            'name' => $name, 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $data */
    private function record(string $assetId, string $type, array $data = [])
    {
        return $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => $type, ...$data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function out(string $assetId, array $data = []): array
    {
        return $this->record($assetId, 'outgoing', [
            'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair', 'counterparty_name' => 'CV Servis',
            'due_date' => now()->addWeek()->toDateString(), ...$data,
        ])->assertCreated()->json('data');
    }

    private function fromSample(string $key): string
    {
        return $this->postJson('/api/report-templates/from-sample', ['sample' => $key])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  array<string, mixed>  $filters
     */
    private function createTemplate(array $columns, array $filters): string
    {
        return $this->postJson('/api/report-templates', [
            'code' => 'RPT-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Uji',
            'data_source' => 'asset_transaction',
            'design' => [
                'schema' => 1,
                'page' => ['size' => 'A4', 'orientation' => 'portrait', 'marginTop' => 15, 'marginRight' => 15, 'marginBottom' => 15, 'marginLeft' => 15],
                'filters' => $filters,
                'elements' => [['id' => 't1', 'type' => 'table', 'x' => 0, 'y' => 0, 'w' => 180, 'h' => 100, 'z' => 1, 'style' => [], 'props' => ['columns' => $columns]]],
            ],
        ])->assertCreated()->json('data.id');
    }
}
