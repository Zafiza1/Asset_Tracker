<?php

namespace Tests\Feature\MasterData;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Organization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Bulk .xlsx import of categories, locations and assets (all-or-nothing). */
class SpreadsheetImportTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->createMasterFixtures($this->org);
        $this->actingAs($this->admin, 'web');
    }

    public function test_categories_import_with_parent_from_an_earlier_row(): void
    {
        $this->upload('/api/tenant-masters/categories/import', [
            ['code', 'name', 'parent_code', 'description'],
            ['it', 'Perangkat IT', null, 'Teknologi'],
            [null, 'Laptop Kantor', 'IT', null],
            [null, null, null, null],
            [null, 'Mouse', 'cat1', null],
        ])->assertOk()->assertJsonPath('data.imported', 3);

        $laptop = TenantAssetCategory::query()->where('name', 'Laptop Kantor')->firstOrFail();
        $this->assertSame('LAPTOP-KANTOR', $laptop->code);
        $this->assertSame(TenantAssetCategory::query()->where('code', 'IT')->value('id'), $laptop->parent_id);
        $this->assertSame(TenantAssetCategory::query()->where('code', 'CAT1')->value('id'),
            TenantAssetCategory::query()->where('name', 'Mouse')->value('parent_id'));
    }

    public function test_locations_import_uses_built_in_location_types(): void
    {
        $this->upload('/api/locations/import', [
            ['name', 'code', 'location_type_code', 'parent_code', 'address'],
            ['Gedung Pusat', 'GP', 'gudang', null, 'Jl. Sudirman'],
            ['Rak 1', null, 'GUDANG', 'GP', null],
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $rack = Location::query()->where('name', 'Rak 1')->firstOrFail();
        $this->assertSame(Location::query()->where('code', 'GP')->value('id'), $rack->parent_id);
    }

    public function test_assets_import_resolves_codes_dates_and_status_labels(): void
    {
        $this->upload('/api/assets/import', [
            ['name', 'serial_number', 'category_code', 'location_code', 'status', 'acquisition_date', 'acquisition_cost', 'description'],
            // A DateTime becomes a real date cell (serial number + date format), as Excel saves it.
            ['Laptop Dell', 'SN-1', 'CAT1', 'LOC1', 'Digunakan', new DateTimeImmutable('2024-01-15'), 15000000, 'Staf IT'],
            ['Meja', null, 'cat1', null, null, '2024-02-01', null, null],
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $laptop = Asset::query()->where('name', 'Laptop Dell')->firstOrFail();
        $this->assertSame('in_use', $laptop->status);
        $this->assertSame('2024-01-15', $laptop->acquisition_date->format('Y-m-d'));
        $this->assertSame('available', Asset::query()->where('name', 'Meja')->value('status'));
    }

    public function test_any_invalid_row_rolls_back_the_whole_file_and_reports_every_row(): void
    {
        $response = $this->upload('/api/assets/import', [
            ['name', 'serial_number', 'category_code', 'location_code', 'status', 'acquisition_date', 'acquisition_cost', 'description'],
            ['Valid', null, 'CAT1', null, null, null, null, null],
            ['Salah kategori', null, 'TIDAK-ADA', null, null, null, null, null],
            ['Salah status', null, 'CAT1', null, 'on_loan', null, null, null],
            ['Salah tanggal', null, 'CAT1', null, null, '15/01/2024', null, null],
            [null, null, 'CAT1', null, null, null, null, 'Tanpa nama'],
        ]);

        $this->assertErrorCode($response, 422, 'IMPORT_FAILED');
        $errors = $response->json('error.details.errors');
        $this->assertCount(4, $errors);
        $this->assertSame("Baris 3: Kategori dengan kode 'TIDAK-ADA' tidak ditemukan.", $errors[0]);
        $this->assertSame('Baris 4: Status tidak valid; pilih dari daftar.', $errors[1]);
        $this->assertSame('Baris 5: Tanggal Perolehan harus berupa tanggal dengan format YYYY-MM-DD.', $errors[2]);
        $this->assertSame('Baris 6: Nama Aset wajib diisi.', $errors[3]);
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_file_with_wrong_columns_or_no_data_is_rejected(): void
    {
        $this->assertErrorCode($this->upload('/api/locations/import', [['nama', 'alamat'], ['Gudang', 'Jl. A']]), 422, 'INVALID_TEMPLATE');
        $this->assertErrorCode($this->upload('/api/locations/import', [['code', 'name', 'parent_code', 'location_type_code', 'address']]), 422, 'IMPORT_EMPTY');

        $notExcel = UploadedFile::fake()->createWithContent('data.xlsx', 'bukan excel');
        $this->assertErrorCode($this->post('/api/locations/import', ['file' => $notExcel], ['Accept' => 'application/json']), 422, 'INVALID_FILE');
    }

    public function test_template_has_labelled_headers_dropdowns_and_instructions(): void
    {
        $book = $this->template('/api/assets/template');

        $this->assertSame(['Data', 'Petunjuk', 'Daftar Pilihan'], $book->getSheetNames());
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Nama Aset *', 'Kategori *', 'Lokasi', 'Status'], array_slice($data->rangeToArray('A1:H1')[0], 0, 4));
        $this->assertSame('A2', $data->getFreezePane());

        $category = $data->getCell('B2')->getDataValidation();
        $this->assertSame(DataValidation::TYPE_LIST, $category->getType());
        $this->assertStringContainsString('Daftar Pilihan', $category->getFormula1());
        $this->assertSame(DataValidation::TYPE_DATE, $data->getCell('F500')->getDataValidation()->getType());

        $lists = $book->getSheetByName('Daftar Pilihan')->toArray();
        $this->assertSame(['Kategori', 'Lokasi', 'Status'], $lists[0]);
        $this->assertSame(['CAT1 — Kategori 1', 'LOC1 — Gudang 1', 'Tersedia'], $lists[1]);
        $this->assertStringContainsString('Template Import Pendataan Aset', (string) $book->getSheetByName('Petunjuk')->getCell('A1')->getValue());

        $this->template('/api/locations/template');
        $this->template('/api/tenant-masters/categories/template');
    }

    public function test_template_filled_with_dropdown_choices_imports(): void
    {
        $book = $this->template('/api/assets/template');
        $book->getSheetByName('Data')->fromArray(['Proyektor Epson', 'CAT1 — Kategori 1', 'LOC1 — Gudang 1', 'Dalam perbaikan'], null, 'A2');

        $this->send('/api/assets/import', $book)->assertOk()->assertJsonPath('data.imported', 1);

        $asset = Asset::query()->where('name', 'Proyektor Epson')->firstOrFail();
        $this->assertSame('under_maintenance', $asset->status);
        $this->assertSame(Location::query()->where('code', 'LOC1')->value('id'), $asset->location_id);
    }

    /** @param list<list<mixed>> $rows */
    private function upload(string $uri, array $rows): TestResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r + 1]);
                if ($value instanceof DateTimeInterface) {
                    $cell->setValue(Date::PHPToExcel($value))->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } else {
                    $cell->setValue($value);
                }
            }
        }

        return $this->send($uri, $book);
    }

    private function send(string $uri, Spreadsheet $book): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    private function template(string $uri): Spreadsheet
    {
        $response = $this->get($uri)->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }
}
