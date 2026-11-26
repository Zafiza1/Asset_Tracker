<?php

namespace Tests\Feature\MasterData;

use App\Domain\Organization\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Master Data › Customer from Excel: adding many customers at once, and Multiple Edit. */
class CustomerSpreadsheetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ['admin' => $admin] = $this->createOrganization('ALPHA');
        $this->actingAs($admin, 'web');
    }

    public function test_customers_are_imported_and_edited_from_excel(): void
    {
        $header = array_values(array_filter($this->download('/api/customers/template')->getSheetByName('Data')->rangeToArray('A1:E1')[0]));
        $this->assertSame(['Nama Customer *', 'Kode', 'Alamat', 'Nama Kontak', 'No HP'], $header);
        $this->upload('/api/customers/import', $this->book([$header, ['RS Sehat', null, 'Jl. Sehat 1', 'Bu Ani', '0812'], ['Bengkel Las', 'BL-1', null, null, null]]))
            ->assertOk()->assertJsonPath('data.imported', 2);
        $this->assertSame(['RS-SEHAT', 'Jl. Sehat 1'], [Customer::query()->where('name', 'RS Sehat')->value('code'), Customer::query()->where('name', 'RS Sehat')->value('address')]);

        $book = $this->download('/api/customers/edit-sheet');
        $data = $book->getSheetByName('Data');
        $this->assertSame(['Kode (info)', 'Nama Customer *', 'Alamat', 'Nama Kontak', 'No HP', 'Status *'], $data->rangeToArray('A1:F1')[0]);
        $row = 1 + array_search('Bengkel Las', array_column($data->toArray(), 1), true);
        $data->setCellValue("A{$row}", 'DIUBAH')->setCellValue("C{$row}", 'Jl. Las 2')->setCellValue("F{$row}", 'Nonaktif');
        $this->upload('/api/customers/edit-sheet', $book)->assertOk()->assertJsonPath('data.updated', 1);

        $bengkel = Customer::query()->where('code', 'BL-1')->firstOrFail();
        $this->assertSame(['Jl. Las 2', 'inactive'], [$bengkel->address, $bengkel->status]);
    }

    public function test_the_edit_sheet_can_be_limited_to_checked_customers(): void
    {
        $one = $this->postJson('/api/customers', ['name' => 'Satu'])->assertCreated()->json('data.id');
        $this->postJson('/api/customers', ['name' => 'Dua'])->assertCreated();

        $data = $this->download("/api/customers/edit-sheet?ids={$one}")->getSheetByName('Data');
        $this->assertSame(['Satu'], array_values(array_filter(array_column(array_slice($data->toArray(), 1), 1))));
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
        $path = tempnam(sys_get_temp_dir(), 'cus').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->post($uri, ['file' => new UploadedFile($path, 'data.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }
}
