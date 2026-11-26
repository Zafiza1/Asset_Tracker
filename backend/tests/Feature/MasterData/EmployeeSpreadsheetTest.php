<?php

namespace Tests\Feature\MasterData;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Master Data › Karyawan from Excel: adding many employees at once, and Multiple Edit. */
class EmployeeSpreadsheetTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private Department $drivers;

    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->drivers = $this->division('SUPIR', 'Supir', Department::DRIVER);
        $this->office = $this->division('ADMIN', 'Admin', null);
        $this->actingAs($this->admin, 'web');
    }

    public function test_employees_are_imported_with_system_codes_and_a_sim_only_for_the_crew(): void
    {
        $header = array_values(array_filter($this->download('/api/employees/template')->getSheetByName('Data')->rangeToArray('A1:F1')[0]));
        $this->assertSame(['Nama *', 'Divisi *', 'No HP', 'No SIM', 'SIM Berlaku Sampai'], $header);

        $book = $this->book([
            $header,
            ['Budi', 'SUPIR — Supir', '0812', 'SIM-1', '2027-05-01'],
            ['Siti', 'ADMIN', null, 'SIM-2', '2027-05-01'],
        ]);
        $this->upload('/api/employees/import', $book)->assertOk()->assertJsonPath('data.imported', 2);

        $budi = Employee::query()->where('name', 'Budi')->firstOrFail();
        $this->assertSame(['KRY-0001', $this->drivers->id, 'SIM-1', '2027-05-01'], [$budi->code, $budi->department_id, $budi->license_number, $budi->license_expires_on?->toDateString()]);
        $siti = Employee::query()->where('name', 'Siti')->firstOrFail();
        $this->assertSame(['KRY-0002', null], [$siti->code, $siti->license_number]);
    }

    public function test_multiple_edit_changes_the_data_but_never_the_code(): void
    {
        $budi = $this->employee('SIG-0001', 'Budi', $this->drivers);
        $this->employee('SIG-0002', 'Siti', $this->office);

        $book = $this->download('/api/employees/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Kode (info)', 'Nama *', 'Divisi *', 'No HP', 'No SIM', 'SIM Berlaku Sampai', 'Status *'], $data->rangeToArray('A1:G1')[0]);
        $row = 1 + array_search('Budi', array_column($data->toArray(), 1), true);
        $data->setCellValue("A{$row}", 'DIUBAH')->setCellValue("B{$row}", 'Budi Santoso')->setCellValue("E{$row}", 'SIM-9');
        $this->upload('/api/employees/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $budi->refresh();
        $this->assertSame(['SIG-0001', 'Budi Santoso', 'SIM-9'], [$budi->code, $budi->name, $budi->license_number]);
        // The next one continues the numbering.
        $this->postJson('/api/employees', ['name' => 'Joko', 'department_id' => $this->drivers->id])->assertCreated()->assertJsonPath('data.code', 'SIG-0003');
    }

    private function division(string $code, string $name, ?string $role): Department
    {
        $division = (new Department)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name, 'delivery_role' => $role]);
        $division->save();

        return $division;
    }

    private function employee(string $code, string $name, Department $division): Employee
    {
        $employee = (new Employee)->forceFill(['organization_id' => $this->org->id, 'code' => $code, 'name' => $name, 'department_id' => $division->id]);
        $employee->save();

        return $employee;
    }

    private function download(string $uri): Spreadsheet
    {
        return IOFactory::load($this->get($uri)->assertOk()->baseResponse->getFile()->getPathname());
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
        $path = tempnam(sys_get_temp_dir(), 'emp').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }
}
