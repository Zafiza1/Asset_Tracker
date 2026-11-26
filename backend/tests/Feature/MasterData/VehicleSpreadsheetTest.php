<?php

namespace Tests\Feature\MasterData;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Master Data › Kendaraan from Excel: adding many vehicles at once, and Multiple Edit. */
class VehicleSpreadsheetTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->actingAs($this->admin, 'web');
    }

    public function test_vehicles_are_imported_with_kinds_and_fuels_by_their_labels(): void
    {
        $template = $this->download('/api/vehicles/template')->getSheetByName('Data');
        $header = array_values(array_filter($template->rangeToArray('A1:K1')[0]));
        $this->assertSame(['Nama / Merk *', 'Kode', 'Nomor Polisi *', 'Jenis *'], array_slice($header, 0, 4));

        $book = $this->book([
            $header,
            ['Truk Hino Dutro', null, ' l  9001 aa ', 'Truk', 2022, 'MHR-1', 'ENG-1', 'Solar', 4000, '2027-08-17', '2027-02-01'],
            ['Pick Up Traga', 'PU-1', 'L 9002 AB', 'pick up', null, null, null, null, null, null, null],
        ]);
        $this->upload('/api/vehicles/import', $book)->assertOk()->assertJsonPath('data.imported', 2);

        $truck = Vehicle::query()->where('plate_number', 'L 9001 AA')->firstOrFail();
        $this->assertSame(['truk', 'solar', 2022, '2027-08-17', 'TRUK-HINO-DUTRO'], [$truck->type, $truck->fuel, $truck->year, $truck->stnk_expires_on?->toDateString(), $truck->code]);
        $this->assertSame('pickup', Vehicle::query()->where('code', 'PU-1')->value('type'));

        // A kind that is no longer offered is refused, and nothing of the file is saved.
        $bad = $this->book([$header, ['Avanza', null, 'L 9003 AC', 'Mobil', null, null, null, null, null, null, null]]);
        $this->upload('/api/vehicles/import', $bad)->assertUnprocessable();
        $this->assertSame(2, Vehicle::query()->count());
    }

    public function test_multiple_edit_changes_the_data_but_not_the_status(): void
    {
        $truck = $this->vehicle('Truk Lama', 'L 1 AA', 'truk');
        $this->vehicle('Pick Up', 'L 2 BB', 'pickup');

        $book = $this->download('/api/vehicles/edit-sheet');
        $data = $book->getSheetByName('Data');
        $header = $data->rangeToArray('A1:M1')[0];
        $this->assertSame('Kode (info)', $header[0]);
        $this->assertContains('Status (info)', $header);
        $rows = [];
        foreach (array_slice($data->toArray(), 1) as $i => $row) {
            $rows[$row[1]] = $i + 2;
        }
        $this->assertSame('Tersedia', $data->getCell('L'.$rows['Truk Lama'])->getValue());

        $data->setCellValue('B'.$rows['Truk Lama'], 'Truk Baru')->setCellValue('D'.$rows['Truk Lama'], 'Pick Up')
            ->setCellValue('L'.$rows['Truk Lama'], 'Sedang Digunakan');
        $this->upload('/api/vehicles/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $truck->refresh();
        $this->assertSame(['Truk Baru', 'pickup', 'active'], [$truck->name, $truck->type, $truck->status]);
    }

    public function test_the_edit_sheet_can_be_limited_to_checked_vehicles(): void
    {
        $one = $this->vehicle('Satu', 'L 1 AA', 'truk');
        $this->vehicle('Dua', 'L 2 BB', 'truk');

        $data = $this->download("/api/vehicles/edit-sheet?ids={$one->id}")->getSheetByName('Data');
        $this->assertSame(['Satu'], array_values(array_filter(array_column(array_slice($data->toArray(), 1), 1))));
    }

    private function vehicle(string $name, string $plate, string $type): Vehicle
    {
        $vehicle = (new Vehicle)->forceFill(['organization_id' => $this->org->id, 'code' => strtoupper(str_replace(' ', '-', $name)), 'name' => $name, 'plate_number' => $plate, 'type' => $type]);
        $vehicle->save();

        return $vehicle;
    }

    private function download(string $uri): Spreadsheet
    {
        $response = $this->get($uri)->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    /** @param list<list<mixed>> $rows */
    private function book(array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Data')->fromArray($rows, null, 'A1', true);

        return $book;
    }

    private function upload(string $uri, Spreadsheet $book): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'veh').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }
}
