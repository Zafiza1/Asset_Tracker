<?php

namespace App\Domain\ReportDesigner\Rendering;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The data of a resolved report as one Excel sheet (docs/factory/features/F-05, D-15): the
 * columns of the design's first table (or every field the design uses when it has none),
 * under a heading with the template, organization, filters and who generated it.
 *
 * Every value is written as an explicit string cell, so text such as "=HYPERLINK(...)" from
 * the data is never evaluated as a formula when the file is opened (formula injection).
 */
final class ReportExcelRenderer
{
    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, string>>  $rows  cells keyed by column key
     * @param  list<string>  $heading  lines above the table
     * @return string path of a temporary .xlsx file
     */
    public function render(string $title, array $heading, array $columns, array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('#[\\\\/?*:\[\]]#', ' ', $title) ?: 'Laporan', 0, 31));

        $this->text($sheet, 'A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $line = 2;
        foreach ($heading as $text) {
            $this->text($sheet, "A{$line}", $text);
            $line++;
        }

        $headerRow = $line + 1;
        foreach (array_values($columns) as $i => $column) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).$headerRow, $column['label'] !== '' ? $column['label'] : $column['key']);
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($columns)));
        $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F1F5F9');

        $r = $headerRow + 1;
        foreach ($rows as $row) {
            foreach (array_values($columns) as $i => $column) {
                $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).$r, (string) ($row[$column['key']] ?? ''));
            }
            $r++;
        }

        foreach (range(1, max(1, count($columns))) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));

        $path = (string) tempnam(sys_get_temp_dir(), 'rpt');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function text($sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}
