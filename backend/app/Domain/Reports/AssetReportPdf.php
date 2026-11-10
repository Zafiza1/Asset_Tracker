<?php

namespace App\Domain\Reports;

use TCPDF;

/**
 * The asset report as an A4 landscape PDF, drawn cell by cell (no HTML layout), so memory
 * stays flat with the number of rows. The table header repeats on every page; the footer
 * carries the print time and page numbers.
 */
final class AssetReportPdf extends TCPDF
{
    private const MARGIN = 10;

    private const LINE = 3.4;

    /** Column header => [width mm, alignment]; widths add up to the printable width (277 mm). */
    private const COLUMNS = [
        'No' => [9, 'C'],
        'No Aset' => [30, 'L'],
        'Nama Aset' => [52, 'L'],
        'No Seri' => [30, 'L'],
        'Kategori' => [36, 'L'],
        'Lokasi' => [36, 'L'],
        'Status' => [26, 'L'],
        'Tgl Perolehan' => [24, 'C'],
        'Nilai Perolehan (Rp)' => [34, 'R'],
    ];

    private bool $inTable = false;

    private int $rowIndex = 0;

    public function __construct(private readonly string $printedAt)
    {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');
        $this->setPrintHeader(false);
        $this->tcpdflink = false;
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->SetAutoPageBreak(false);
        $this->SetFont('dejavusans', '', 7);
        $this->SetCreator('Asset Tracker');
        $this->SetTitle('Laporan Aset');
    }

    /**
     * @param  list<string>  $filterLines
     * @param  array<string, mixed>  $summary
     */
    public function heading(string $organization, array $filterLines, array $summary): void
    {
        $this->AddPage();
        $this->SetTextColor(15, 23, 42);
        $this->SetFont('', 'B', 14);
        $this->Cell(0, 7, 'LAPORAN ASET', 0, 1);
        $this->SetFont('', 'B', 9);
        $this->Cell(0, 5, $organization, 0, 1);
        $this->SetFont('', '', 7.5);
        $this->SetTextColor(71, 85, 105);
        foreach (['Dicetak: '.$this->printedAt, ...$filterLines] as $line) {
            $this->MultiCell(0, 3.8, $line, 0, 'L', false, 1);
        }
        $this->Ln(2);

        $statuses = implode('   ·   ', array_map(fn ($s) => $s['label'].': '.$s['count'], $summary['by_status']));
        $this->summaryBox(45, 'JUMLAH ASET', number_format($summary['total'], 0, ',', '.'));
        $this->summaryBox(70, 'TOTAL NILAI PEROLEHAN', 'Rp '.number_format((float) $summary['total_value'], 0, ',', '.'));
        $this->summaryBox(0, 'PER STATUS', $statuses, small: true);
        $this->Ln(16);

        $this->tableHeader();
        $this->inTable = true;
    }

    /** @param list<string|null> $cells one value per column; null prints a dash */
    public function row(array $cells): void
    {
        $widths = array_column(self::COLUMNS, 0);
        $this->SetFont('', '', 7);
        $height = self::LINE;
        foreach ($cells as $i => $value) {
            $height = max($height, $this->getNumLines($value ?? '—', $widths[$i]) * self::LINE);
        }
        $height += 1.6;
        $this->ensureSpace($height);
        // A page break draws the (bold) table header; rows are regular weight.
        $this->SetFont('', '', 7);

        $fill = $this->rowIndex++ % 2 === 1;
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(226, 232, 240);
        $this->SetTextColor(15, 23, 42);
        $aligns = array_column(self::COLUMNS, 1);
        foreach ($cells as $i => $value) {
            $this->SetTextColor(...($value === null ? [148, 163, 184] : [15, 23, 42]));
            $this->MultiCell($widths[$i], $height, $value ?? '—', 'B', $aligns[$i], $fill, 0, '', '', true, 0, false, true, $height, 'M');
        }
        $this->Ln();
    }

    public function totals(int $count, string $value): void
    {
        $this->ensureSpace(7);
        $this->inTable = false;
        $widths = array_column(self::COLUMNS, 0);
        $this->SetFont('', 'B', 7.5);
        $this->SetFillColor(226, 232, 240);
        $this->SetTextColor(15, 23, 42);
        if ($count === 0) {
            $this->SetFont('', '', 7.5);
            $this->Cell(array_sum($widths), 10, 'Tidak ada aset yang sesuai dengan filter laporan.', 0, 1, 'C');
            $this->SetFont('', 'B', 7.5);
        }
        $this->Cell(array_sum($widths) - end($widths), 6, 'Total: '.number_format($count, 0, ',', '.').' aset', 0, 0, 'L', true);
        $this->Cell(end($widths), 6, number_format((float) $value, 0, ',', '.'), 0, 1, 'R', true);
    }

    public function Footer(): void
    {
        $this->SetY(-8);
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'Laporan Aset · dicetak '.$this->printedAt, 0, 0, 'L');
        $this->SetX(self::MARGIN);
        // The page-number aliases are replaced after layout; the shift keeps them right-aligned.
        $this->Cell(0, 4, $this->getAliasRightShift().'Halaman '.$this->getAliasNumPage().' dari '.$this->getAliasNbPages(), 0, 0, 'R');
    }

    /** Starts a new page (repeating the table header) when the next block does not fit. */
    private function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height <= $this->getPageHeight() - 12) {
            return;
        }
        $this->AddPage();
        if ($this->inTable) {
            $this->tableHeader();
        }
    }

    private function tableHeader(): void
    {
        $this->SetFont('', 'B', 7);
        $this->SetFillColor(30, 64, 175);
        $this->SetTextColor(255, 255, 255);
        foreach (self::COLUMNS as $header => [$width, $align]) {
            $this->Cell($width, 7, $header, 0, 0, $align, true);
        }
        $this->Ln();
    }

    private function summaryBox(float $width, string $label, string $value, bool $small = false): void
    {
        $x = $this->GetX();
        $y = $this->GetY();
        $width = $width ?: $this->getPageWidth() - self::MARGIN - $x;
        $this->SetFillColor(241, 245, 249);
        $this->SetDrawColor(226, 232, 240);
        $this->Rect($x, $y, $width - 2, 13, 'DF');
        $this->SetXY($x + 2.5, $y + 1.8);
        $this->SetFont('', '', 6);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($width - 5, 3, $label, 0, 2);
        $this->SetFont('', 'B', $small ? 7.5 : 11);
        $this->SetTextColor(15, 23, 42);
        $this->Cell($width - 5, $small ? 6 : 6.5, $value, 0, 0, 'L', false, '', 1);
        $this->SetXY($x + $width, $y);
    }
}
