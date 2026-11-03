<?php

namespace Tests\Feature\MasterData;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Bulk editing of categories, locations and assets: download the data as .xlsx, upload it edited. */
class SpreadsheetEditTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    /** @var list<Spreadsheet> */
    private array $books = [];

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_category_sheet_lists_existing_categories_and_applies_only_changed_rows(): void
    {
        $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->assertCreated();
        $this->postJson('/api/tenant-masters/categories', ['code' => 'LAPTOP', 'name' => 'Laptop'])->assertCreated();

        $book = $this->download('/api/tenant-masters/categories/edit-sheet');
        $data = $book->getSheetByName('Data');
        // The code cannot be changed, so it is not shown; rows are linked by the hidden ID column.
        $this->assertSame(['Nama Kategori *', 'Kategori Induk', 'Deskripsi', 'Status *'], $data->rangeToArray('A1:D1')[0]);
        $this->assertSame('ID *', $data->getCell('E1')->getValue());
        $this->assertFalse($data->getColumnDimension('E')->getVisible());
        $rows = $this->rowsByKey($data);
        $this->assertEqualsCanonicalizing(['Kategori 1', 'Perangkat IT', 'Laptop'], array_keys($rows));
        $this->assertSame('Aktif', $rows['Perangkat IT'][3]);

        // Laptop moves under IT and gets a description; IT is renamed; Kategori 1 is left as is.
        $it = $this->rowOf($data, 'Perangkat IT');
        $laptop = $this->rowOf($data, 'Laptop');
        $data->setCellValue("A{$it}", 'Perangkat Teknologi');
        $data->setCellValue("B{$laptop}", 'IT — Perangkat Teknologi')->setCellValue("C{$laptop}", 'Notebook kantor');

        $this->upload('/api/tenant-masters/categories/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 2);

        $it = TenantAssetCategory::query()->where('code', 'IT')->firstOrFail();
        $this->assertSame('Perangkat Teknologi', $it->name);
        $moved = TenantAssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
        $this->assertSame($it->id, $moved->parent_id);
        $this->assertSame('Notebook kantor', $moved->description);
        $this->assertStringStartsWith($it->path, $moved->path);
    }

    public function test_location_sheet_clears_emptied_values_and_deactivates(): void
    {
        $this->masters['location']->forceFill(['address' => 'Jl. Lama'])->save();
        $book = $this->download('/api/locations/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Nama Lokasi *', 'Lokasi Induk', 'Alamat', 'Status *'], $data->rangeToArray('A1:D1')[0]);
        $row = $this->rowOf($data, 'Gudang 1');
        $this->assertSame('Jl. Lama', $data->getCell("C{$row}")->getValue());

        $data->setCellValue("C{$row}", null)->setCellValue("D{$row}", 'Nonaktif');
        $this->upload('/api/locations/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $location = Location::query()->where('code', 'LOC1')->firstOrFail();
        $this->assertNull($location->address);
        $this->assertSame('inactive', $location->status);
    }

    public function test_asset_sheet_follows_the_list_filters_and_updates_descriptive_fields(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $other = $this->createAssetFixture($this->org, $this->masters, '2')['asset'];
        $this->postJson('/api/tenant-masters/categories', ['code' => 'MEJA', 'name' => 'Meja'])->assertCreated();
        $versions = [$asset->refresh()->lock_version, $other->refresh()->lock_version];

        $filtered = $this->download('/api/assets/edit-sheet?search=FIX-2')->getSheetByName('Data');
        $this->assertSame(['FIX-2'], array_keys($this->rowsByKey($filtered)));

        $book = $this->download('/api/assets/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Nomor Aset (info)', 'Nama Aset *', 'Kategori *'], $data->rangeToArray('A1:C1')[0]);
        $this->assertSame('Lokasi (info)', $data->getCell('H1')->getValue());
        $this->assertFalse($data->getColumnDimension('J')->getVisible());
        $row = $this->rowOf($data, 'FIX-1');
        $this->assertSame('LOC1 — Gudang 1', $data->getCell("H{$row}")->getValue());

        $data->setCellValue("B{$row}", 'Laptop Dell')
            ->setCellValue("C{$row}", 'MEJA — Meja')
            ->setCellValue("D{$row}", '00123')
            ->setCellValue("F{$row}", 15000000)
            // Reference columns (asset number, location) are ignored, even when edited.
            ->setCellValue("A{$row}", 'NOMOR-LAIN')
            ->setCellValue("H{$row}", 'TIDAK-ADA');
        $this->upload('/api/assets/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $asset->refresh();
        $this->assertSame('Laptop Dell', $asset->name);
        $this->assertSame('FIX-1', $asset->asset_number);
        $this->assertSame(TenantAssetCategory::query()->where('code', 'MEJA')->value('id'), $asset->category_id);
        $this->assertSame('00123', $asset->serial_number);
        $this->assertSame('15000000.00', $asset->acquisition_cost);
        $this->assertSame($this->masters['location']->id, $asset->location_id);
        $this->assertSame($versions[0] + 1, $asset->lock_version);
        $this->assertSame($versions[1], $other->refresh()->lock_version, 'An unchanged row is not saved.');
    }

    public function test_asset_changed_after_download_is_rejected(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $book = $this->download('/api/assets/edit-sheet');
        $this->patchJson("/api/assets/{$asset->id}", ['name' => 'Diubah orang lain'])->assertOk();

        $data = $book->getSheetByName('Data');
        $data->setCellValue('B'.$this->rowOf($data, 'FIX-1'), 'Nama dari file');
        $response = $this->upload('/api/assets/edit-sheet', $book);

        $this->assertErrorCode($response, 422, 'IMPORT_FAILED');
        $this->assertStringContainsString('telah diubah setelah file diunduh', $response->json('error.details.errors.0'));
        $this->assertSame('Diubah orang lain', $asset->refresh()->name);
    }

    public function test_invalid_rows_roll_back_the_whole_file_and_are_all_reported(): void
    {
        $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->assertCreated();
        $this->postJson('/api/tenant-masters/categories', ['code' => 'LAPTOP', 'name' => 'Laptop', 'parent_id' => TenantAssetCategory::query()->where('code', 'IT')->value('id')])->assertCreated();

        $id = fn (string $code) => TenantAssetCategory::query()->where('code', $code)->value('id');

        $response = $this->upload('/api/tenant-masters/categories/edit-sheet', $this->book([
            ['Nama Kategori', 'Kategori Induk', 'Deskripsi', 'Status', 'ID'],
            ['Kategori Baru', null, null, 'Aktif', $id('CAT1')],
            ['Baris baru', null, null, 'Aktif', null],
            ['Salinan', null, null, 'Aktif', $id('CAT1')],
            ['Perangkat IT', 'LAPTOP', null, 'Aktif', $id('IT')],
            [null, 'IT', null, 'Hapus', $id('LAPTOP')],
            ['Sudah dihapus', null, null, 'Aktif', '0199a000-0000-7000-8000-000000000000'],
        ]));

        $this->assertErrorCode($response, 422, 'IMPORT_FAILED');
        $this->assertSame([
            'Baris 3: Baris ini bukan berasal dari file unduhan. Data baru tidak dapat ditambahkan di sini; gunakan menu Import.',
            'Baris 4: Baris ini salinan dari baris lain (data yang sama muncul lebih dari sekali). Hapus salah satunya.',
            'Baris 5: Induk tidak boleh berupa data itu sendiri atau turunannya.',
            'Baris 6: Nama Kategori wajib diisi. Status tidak valid; pilih dari daftar.',
            'Baris 7: Data pada baris ini tidak ditemukan, sudah diarsipkan, atau di luar cakupan Anda. Unduh ulang file.',
        ], $response->json('error.details.errors'));
        $this->assertSame('Kategori 1', TenantAssetCategory::query()->where('code', 'CAT1')->value('name'));
    }

    public function test_sheet_without_the_hidden_id_column_is_rejected(): void
    {
        $response = $this->upload('/api/locations/edit-sheet', $this->book([
            ['Nama Lokasi', 'Lokasi Induk', 'Alamat', 'Status'],
            ['Gudang 1', null, null, 'Aktif'],
        ]));

        $this->assertErrorCode($response, 422, 'INVALID_TEMPLATE');
    }

    public function test_edit_sheet_requires_update_permission(): void
    {
        $this->actingAs($this->createTenantUser($this->org, [$this->viewerRole($this->org)]), 'web');

        $this->assertErrorCode($this->getJson('/api/locations/edit-sheet'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->getJson('/api/assets/edit-sheet'), 403, 'FORBIDDEN');
        $this->assertErrorCode($this->upload('/api/tenant-masters/categories/edit-sheet', $this->book([['ID'], ['x']])), 403, 'FORBIDDEN');
    }

    /** Workbooks hold large reference cycles (every styled cell of 1000 rows); free them between tests. */
    protected function tearDown(): void
    {
        foreach ($this->books as $book) {
            $book->disconnectWorksheets();
        }
        $this->books = [];
        gc_collect_cycles();
        parent::tearDown();
    }

    private function download(string $uri): Spreadsheet
    {
        $response = $this->get($uri)->assertOk();

        return $this->books[] = IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    /** @param list<list<mixed>> $rows */
    private function book(array $rows): Spreadsheet
    {
        $book = $this->books[] = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Data')->fromArray($rows, null, 'A1', true);

        return $book;
    }

    private function upload(string $uri, Spreadsheet $book): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'edit').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    /** @return array<string, list<mixed>> data rows keyed by their first column */
    private function rowsByKey(Worksheet $sheet): array
    {
        $rows = [];
        foreach (array_slice($sheet->toArray(), 1) as $row) {
            if ($row[0] !== null) {
                $rows[$row[0]] = $row;
            }
        }

        return $rows;
    }

    private function rowOf(Worksheet $sheet, string $key): int
    {
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            if ($sheet->getCell("A{$r}")->getValue() === $key) {
                return $r;
            }
        }
        $this->fail("Row {$key} not found");
    }
}
