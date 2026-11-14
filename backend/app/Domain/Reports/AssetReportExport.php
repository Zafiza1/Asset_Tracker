<?php

namespace App\Domain\Reports;

use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders the asset report as an Excel workbook or a PDF document: a heading with the
 * organization, print time and applied filters, one row per asset, and the totals.
 */
final class AssetReportExport
{
    /** Row limits per format; larger selections must be narrowed with filters. */
    public const LIMITS = ['xlsx' => 50000, 'pdf' => 5000];

    /** Column key => [header, Excel width]. */
    private const COLUMNS = [
        'no' => ['No', 6],
        'name' => ['Nama Aset', 34],
        'serial_number' => ['No Seri', 20],
        'category' => ['Kategori', 24],
        'location' => ['Lokasi', 24],
        'status' => ['Status', 18],
        'acquisition_date' => ['Tgl Perolehan', 15],
        'acquisition_cost' => ['Nilai Perolehan (Rp)', 20],
        'description' => ['Keterangan', 36],
    ];

    /**
     * @param  list<string>  $filterLines
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly Builder $query,
        private readonly array $filterLines,
        private readonly array $summary,
    ) {}

    /** Path of a temporary .xlsx file. */
    public function xlsx(): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator($this->organization->name)->setTitle('Laporan Aset');
        $sheet = $book->getActiveSheet()->setTitle('Laporan Aset');
        $lastCol = Coordinate::stringFromColumnIndex(count(self::COLUMNS));

        $sheet->setCellValue('A1', 'LAPORAN ASET');
        $sheet->setCellValue('A2', $this->organization->name);
        $sheet->setCellValue('A3', 'Dicetak: '.$this->printedAt());
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $row = 4;
        foreach ($this->filterLines as $line) {
            $sheet->setCellValue("A{$row}", $line);
            $sheet->getStyle("A{$row}")->getFont()->getColor()->setRGB('475569');
            $row++;
        }

        $headerRow = $row + 1;
        $col = 1;
        foreach (self::COLUMNS as [$header, $width]) {
            $letter = Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}{$headerRow}", $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $row = $headerRow;
        foreach ($this->rows() as $i => $asset) {
            $row++;
            $sheet->fromArray([
                $i + 1,
                $asset->name,
                $asset->serial_number,
                $asset->category?->name,
                $asset->location?->name ?? 'Tanpa lokasi',
                $this->status($asset),
                $asset->acquisition_date?->toDateString(),
                $asset->acquisition_cost === null ? null : (float) $asset->acquisition_cost,
                $asset->description,
            ], null, "A{$row}", true);
            // Serials are text, never numbers (keeps leading zeros).
            if ($asset->serial_number !== null) {
                $sheet->setCellValueExplicit("C{$row}", $asset->serial_number, DataType::TYPE_STRING);
            }
        }

        $totalRow = $row + 1;
        $sheet->setCellValue("A{$totalRow}", 'Total: '.$this->summary['total'].' aset');
        $sheet->mergeCells("A{$totalRow}:G{$totalRow}");
        $sheet->setCellValue("H{$totalRow}", (float) $this->summary['total_value']);
        $sheet->getStyle("A{$totalRow}:{$lastCol}{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
        ]);

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$totalRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle('H'.($headerRow + 1).":H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('G'.($headerRow + 1).":G{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.($headerRow + 1).":{$lastCol}{$totalRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$row}");

        $this->summarySheet($book);
        $book->setActiveSheetIndex(0);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $path = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** The PDF document as a string. */
    public function pdf(): string
    {
        $pdf = new AssetReportPdf($this->printedAt());
        $pdf->heading($this->organization->name, $this->filterLines, $this->summary);
        foreach ($this->rows() as $i => $asset) {
            $pdf->row([
                (string) ($i + 1),
                $asset->name,
                $asset->serial_number,
                $asset->category?->name,
                $asset->location?->name ?? 'Tanpa lokasi',
                $this->status($asset),
                $asset->acquisition_date?->format('d/m/Y'),
                $asset->acquisition_cost === null ? null : number_format((float) $asset->acquisition_cost, 0, ',', '.'),
            ]);
        }
        $pdf->totals($this->summary['total'], $this->summary['total_value']);

        return $pdf->Output('laporan_aset.pdf', 'S');
    }

    /** @return iterable<int, Asset> */
    private function rows(): iterable
    {
        return $this->query->lazy(500)->values();
    }

    private function status(Asset $asset): string
    {
        $label = AssetStatus::LABELS[$asset->status] ?? $asset->status;

        return $asset->archived_at !== null ? $label.' (nonaktif)' : $label;
    }

    private function printedAt(): string
    {
        return CarbonImmutable::now($this->organization->timezone ?: 'UTC')->format('d/m/Y H:i');
    }

    private function summarySheet(Spreadsheet $book): void
    {
        $sheet = $book->createSheet()->setTitle('Ringkasan');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(22);

        $rows = [
            ['RINGKASAN LAPORAN ASET', null, null],
            ['Jumlah aset', $this->summary['total'], null],
            ['Total nilai perolehan (Rp)', null, (float) $this->summary['total_value']],
            ['Aset dengan nilai perolehan', $this->summary['valued'], null],
            ['Aset tanpa nilai perolehan (tidak dihitung)', $this->summary['total'] - $this->summary['valued'], null],
            [null, null, null],
            ['Per status', 'Jumlah', null],
            ...array_map(fn ($s) => [$s['label'], $s['count'], null], $this->summary['by_status']),
            [null, null, null],
            ['Per kategori', 'Jumlah', 'Nilai Perolehan (Rp)'],
            ...array_map(fn ($c) => [$c['name'], $c['count'], (float) $c['value']], $this->summary['by_category']),
        ];
        $sheet->fromArray($rows, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $statusHeader = 7;
        $categoryHeader = $statusHeader + count($this->summary['by_status']) + 2;
        foreach ([$statusHeader, $categoryHeader] as $r) {
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            ]);
        }
        $sheet->getStyle('C1:C'.count($rows))->getNumberFormat()->setFormatCode('#,##0');
    }
}
