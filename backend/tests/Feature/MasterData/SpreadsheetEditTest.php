<?php

namespace Tests\Feature\MasterData;

use App\Domain\Assets\Models\Asset;
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
        // The code cannot be changed: it is shown read-only to tell rows apart; rows are linked by the hidden ID column.
        $this->assertSame(['Kode (info)', 'Nama Kategori *', 'Kategori Induk', 'Deskripsi', 'Status *'], $data->rangeToArray('A1:E1')[0]);
        $this->assertSame('ID *', $data->getCell('F1')->getValue());
        $this->assertFalse($data->getColumnDimension('F')->getVisible());
        $rows = $this->rowsByKey($data, 'B');
        $this->assertEqualsCanonicalizing(['Kategori 1', 'Perangkat IT', 'Laptop'], array_keys($rows));
        $this->assertSame('IT', $rows['Perangkat IT'][0]);
        $this->assertSame('Aktif', $rows['Perangkat IT'][4]);

        // Laptop moves under IT and gets a description; IT is renamed; Kategori 1 is left as is.
        // Editing the read-only code is ignored.
        $it = $this->rowOf($data, 'Perangkat IT', 'B');
        $laptop = $this->rowOf($data, 'Laptop', 'B');
        $data->setCellValue("B{$it}", 'Perangkat Teknologi')->setCellValue("A{$it}", 'DIUBAH');
        $data->setCellValue("C{$laptop}", 'IT — Perangkat Teknologi')->setCellValue("D{$laptop}", 'Notebook kantor');

        $this->upload('/api/tenant-masters/categories/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 2);

        $it = TenantAssetCategory::query()->where('code', 'IT')->firstOrFail();
        $this->assertSame('Perangkat Teknologi', $it->name);
        $moved = TenantAssetCategory::query()->where('code', 'LAPTOP')->firstOrFail();
        $this->assertSame($it->id, $moved->parent_id);
        $this->assertSame('Notebook kantor', $moved->description);
        $this->assertStringStartsWith($it->path, $moved->path);
    }

    public function test_category_sheet_can_be_limited_to_checked_categories(): void
    {
        $it = $this->postJson('/api/tenant-masters/categories', ['code' => 'IT', 'name' => 'Perangkat IT'])->assertCreated()->json('data.id');
        $laptop = $this->postJson('/api/tenant-masters/categories', ['code' => 'LAPTOP', 'name' => 'Laptop'])->assertCreated()->json('data.id');

        $data = $this->download("/api/tenant-masters/categories/edit-sheet?ids={$it},{$laptop}")->getSheetByName('Data');
        $this->assertEqualsCanonicalizing(['Perangkat IT', 'Laptop'], array_keys($this->rowsByKey($data, 'B')));

        $this->getJson('/api/tenant-masters/categories/edit-sheet?ids=bukan-uuid')->assertUnprocessable();
    }

    public function test_location_sheet_clears_emptied_values_and_deactivates(): void
    {
        $this->masters['location']->forceFill(['address' => 'Jl. Lama'])->save();
        $book = $this->download('/api/locations/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Kode (info)', 'Nama Lokasi *', 'Lokasi Induk', 'Alamat', 'Status *'], $data->rangeToArray('A1:E1')[0]);
        $row = $this->rowOf($data, 'Gudang 1', 'B');
        $this->assertSame('LOC1', $data->getCell("A{$row}")->getValue());
        $this->assertSame('Jl. Lama', $data->getCell("D{$row}")->getValue());

        $data->setCellValue("D{$row}", null)->setCellValue("E{$row}", 'Nonaktif');
        $this->upload('/api/locations/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $location = Location::query()->where('code', 'LOC1')->firstOrFail();
        $this->assertNull($location->address);
        $this->assertSame('inactive', $location->status);
    }

    public function test_location_sheet_can_be_limited_to_checked_locations(): void
    {
        $this->postJson('/api/locations', ['code' => 'GDG-B', 'name' => 'Gudang B'])->assertCreated();
        $picked = $this->masters['location']->id;

        $data = $this->download("/api/locations/edit-sheet?ids={$picked}")->getSheetByName('Data');
        $this->assertSame(['Gudang 1'], array_keys($this->rowsByKey($data, 'B')));

        $this->getJson('/api/locations/edit-sheet?ids=bukan-uuid')->assertUnprocessable();
    }

    public function test_asset_sheet_follows_the_list_filters_and_updates_descriptive_fields(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $other = $this->createAssetFixture($this->org, $this->masters, '2')['asset'];
        $this->postJson('/api/tenant-masters/categories', ['code' => 'MEJA', 'name' => 'Meja'])->assertCreated();
        $this->relaxDefaultCondition();
        $versions = [$asset->refresh()->lock_version, $other->refresh()->lock_version];

        $filtered = $this->download('/api/assets/edit-sheet?search=FIX-2')->getSheetByName('Data');
        $this->assertSame(['FIX-2'], array_keys($this->rowsByKey($filtered)));
        // Picked assets replace the list filters.
        $picked = $this->download("/api/assets/edit-sheet?search=FIX-2&ids={$asset->id}")->getSheetByName('Data');
        $this->assertSame(['FIX-1'], array_keys($this->rowsByKey($picked)));
        $this->getJson('/api/assets/edit-sheet?ids=bukan-uuid')->assertUnprocessable();

        $book = $this->download('/api/assets/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['No Aset *', 'Nama Aset *', 'Kategori *'], $data->rangeToArray('A1:C1')[0]);
        $this->assertSame('Lokasi (info)', $data->getCell('H1')->getValue());
        // Rows are matched by the asset number and checked by the visible last-changed time: no hidden columns.
        $this->assertSame('Terakhir Diubah (info)', $data->getCell('J1')->getValue());
        $this->assertNull($data->getCell('K1')->getValue());
        foreach (range('A', 'J') as $column) {
            $this->assertTrue($data->getColumnDimension($column)->getVisible(), "Column {$column} is visible.");
        }
        $row = $this->rowOf($data, 'FIX-1');
        $this->assertSame('LOC1 — Gudang 1', $data->getCell("H{$row}")->getValue());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data->getCell("J{$row}")->getValue());

        $data->setCellValue("B{$row}", 'Laptop Dell')
            ->setCellValue("C{$row}", 'MEJA — Meja')
            ->setCellValue("D{$row}", '00123')
            ->setCellValue("F{$row}", 15000000)
            // Reference columns (location) are ignored, even when edited.
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

    public function test_asset_row_with_an_edited_last_changed_time_is_rejected(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $book = $this->download('/api/assets/edit-sheet');
        $data = $book->getSheetByName('Data');
        $row = $this->rowOf($data, 'FIX-1');
        $data->setCellValue("B{$row}", 'Nama dari file')->setCellValue("J{$row}", '');

        $response = $this->upload('/api/assets/edit-sheet', $book);

        $this->assertErrorCode($response, 422, 'IMPORT_FAILED');
        $this->assertStringContainsString('telah diubah setelah file diunduh', $response->json('error.details.errors.0'));
        $this->assertNotSame('Nama dari file', $asset->refresh()->name);
    }

    public function test_asset_row_with_an_unknown_or_repeated_asset_number_is_rejected(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $this->createAssetFixture($this->org, $this->masters, '2');
        $book = $this->download('/api/assets/edit-sheet');
        $data = $book->getSheetByName('Data');
        $data->setCellValue('A'.$this->rowOf($data, 'FIX-1'), 'NOMOR-LAIN');
        $data->setCellValue('A'.$this->rowOf($data, 'FIX-2'), 'NOMOR-LAIN');

        $response = $this->upload('/api/assets/edit-sheet', $book);

        $this->assertErrorCode($response, 422, 'IMPORT_FAILED');
        $errors = implode(' ', $response->json('error.details.errors'));
        $this->assertStringContainsString('tidak ditemukan', $errors);
        $this->assertStringContainsString('salinan dari baris lain', $errors);
        $this->assertSame('FIX-1', $asset->refresh()->asset_number);
    }

    public function test_asset_changed_after_download_is_rejected(): void
    {
        $asset = $this->createAssetFixture($this->org, $this->masters)['asset'];
        $book = $this->download('/api/assets/edit-sheet');
        $this->travel(1)->seconds();
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

    public function test_edit_sheet_uploaded_to_import_is_refused_without_adding_anything(): void
    {
        $this->createAssetFixture($this->org, $this->masters);
        $before = Asset::query()->count();
        $book = $this->download('/api/assets/edit-sheet');

        $response = $this->upload('/api/assets/import', $book);

        $this->assertErrorCode($response, 422, 'WRONG_FILE_KIND');
        $this->assertStringContainsString('bukan template Import', $response->json('error.message'));
        $this->assertSame($before, Asset::query()->count());
    }

    public function test_import_template_uploaded_to_multiple_edit_is_refused(): void
    {
        $book = $this->download('/api/assets/template');
        $this->assertStringStartsWith('JENIS FILE: IMPORT', (string) $book->getSheetByName('Petunjuk')->getCell('A2')->getValue());

        $response = $this->upload('/api/assets/edit-sheet', $book);

        $this->assertErrorCode($response, 422, 'WRONG_FILE_KIND');
        $this->assertStringContainsString('bukan file Multiple Edit', $response->json('error.message'));
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
    private function rowsByKey(Worksheet $sheet, string $column = 'A'): array
    {
        $index = ord($column) - ord('A');
        $rows = [];
        foreach (array_slice($sheet->toArray(), 1) as $row) {
            if ($row[$index] !== null) {
                $rows[$row[$index]] = $row;
            }
        }

        return $rows;
    }

    private function rowOf(Worksheet $sheet, string $key, string $column = 'A'): int
    {
        for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
            if ($sheet->getCell("{$column}{$r}")->getValue() === $key) {
                return $r;
            }
        }
        $this->fail("Row {$key} not found");
    }
}
