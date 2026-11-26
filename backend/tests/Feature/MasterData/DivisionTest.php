<?php

namespace Tests\Feature\MasterData;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** PT SIG / PT CDO: Master Data › Divisi, whose code and delivery role come from the system. */
class DivisionTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edition.custodians' => false]);
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->actingAs($this->admin, 'web');
    }

    public function test_the_code_and_delivery_role_follow_the_name(): void
    {
        $this->postJson('/api/departments', ['code' => 'PILIHAN', 'name' => ' supir ', 'delivery_role' => 'helper'])->assertCreated()
            ->assertJsonPath('data.code', 'SUPIR')->assertJsonPath('data.delivery_role', 'driver');
        $id = $this->postJson('/api/departments', ['name' => 'Petugas Gudang', 'delivery_role' => 'driver'])->assertCreated()
            ->assertJsonPath('data.code', 'PETUGAS-GUDANG')->assertJsonPath('data.delivery_role', null)->json('data.id');

        $this->patchJson("/api/departments/{$id}", ['delivery_role' => 'driver'])->assertOk()->assertJsonPath('data.delivery_role', null);
        $this->patchJson("/api/departments/{$id}", ['name' => 'Kernet', 'code' => 'LAIN'])->assertOk()
            ->assertJsonPath('data.delivery_role', 'helper')->assertJsonPath('data.code', 'PETUGAS-GUDANG');
    }

    public function test_divisions_are_imported_and_edited_from_excel(): void
    {
        $header = array_values(array_filter($this->download('/api/departments/template')->getSheetByName('Data')->rangeToArray('A1:C1')[0]));
        $this->assertSame(['Nama Divisi *'], $header);
        $this->upload('/api/departments/import', $this->book([$header, ['Supir'], ['Admin']]))->assertOk()->assertJsonPath('data.imported', 2);
        $this->assertSame('driver', Department::query()->where('code', 'SUPIR')->value('delivery_role'));

        $book = $this->download('/api/departments/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Kode (info)', 'Nama Divisi *', 'Status *'], $data->rangeToArray('A1:C1')[0]);
        $row = 1 + array_search('Admin', array_column($data->toArray(), 1), true);
        $data->setCellValue("B{$row}", 'Kernet')->setCellValue("A{$row}", 'DIUBAH');
        $this->upload('/api/departments/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $admin = Department::query()->where('code', 'ADMIN')->firstOrFail();
        $this->assertSame(['Kernet', 'helper'], [$admin->name, $admin->delivery_role]);
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
        $path = tempnam(sys_get_temp_dir(), 'div').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }
}
