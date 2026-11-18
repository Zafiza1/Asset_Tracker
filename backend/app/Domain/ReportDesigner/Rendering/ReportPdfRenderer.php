<?php

namespace App\Domain\ReportDesigner\Rendering;

use TCPDF;

/**
 * Draws a resolved report design (ReportPreviewResolver::preview with every row) as a PDF,
 * laid out like the designer canvas (docs/factory/features/F-05, D-12).
 *
 * - Geometry is in millimetres inside the page's content box (design.ts).
 * - Header, footer and page-number elements repeat on every page.
 * - Tables (and repeating sections, drawn as tables) grow with their rows and continue on the
 *   next page with the header row repeated; elements designed below a table move down by
 *   what it grew. A page break starts a new page.
 * - Images are only drawn from embedded data URIs (PNG/JPEG). Other URLs are not fetched by
 *   the server (no SSRF); a labelled box takes their place.
 */
final class ReportPdfRenderer
{
    private const PAGE_MM = ['A4' => [210, 297], 'A5' => [148, 210], 'LETTER' => [215.9, 279.4], 'LEGAL' => [215.9, 355.6]];

    private const FONTS = ['sans' => 'dejavusans', 'serif' => 'dejavuserif', 'mono' => 'dejavusansmono'];

    private const REPEATED = ['header', 'footer', 'page_number'];

    private const PT_TO_MM = 0.3528;

    private TCPDF $pdf;

    private float $left;

    private float $top;

    /** Height of the content box (page minus margins). */
    private float $content;

    /**
     * @param  array<string, mixed>  $page  design.page
     * @param  list<array<string, mixed>>  $elements  resolved elements
     */
    public function render(array $page, array $elements, string $title, string $author): string
    {
        [$width, $height] = self::pageSize($page);
        $this->left = (float) ($page['marginLeft'] ?? 15);
        $this->top = (float) ($page['marginTop'] ?? 15);
        $this->content = max(10.0, $height - $this->top - (float) ($page['marginBottom'] ?? 15));

        $this->pdf = new TCPDF($width > $height ? 'L' : 'P', 'mm', [$width, $height], true, 'UTF-8');
        $this->pdf->SetCreator('Asset Tracker');
        $this->pdf->SetAuthor($author);
        $this->pdf->SetTitle($title);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(0, 0, 0);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setCellPaddings(0, 0, 0, 0);
        $this->pdf->AddPage();

        $visible = array_values(array_filter($elements, fn ($e) => ($e['visible'] ?? true) !== false));
        $repeated = array_values(array_filter($visible, fn ($e) => in_array($e['type'] ?? '', self::REPEATED, true)));
        $flow = array_values(array_filter($visible, fn ($e) => ! in_array($e['type'] ?? '', self::REPEATED, true)));
        usort($flow, fn ($a, $b) => [(float) $a['y'], (float) $a['x'], (int) ($a['z'] ?? 0)] <=> [(float) $b['y'], (float) $b['x'], (int) ($b['z'] ?? 0)]);

        $this->flow($flow);
        $this->repeat($repeated, $page);

        return $this->pdf->Output('', 'S');
    }

    /** @param list<array<string, mixed>> $elements sorted by y */
    private function flow(array $elements): void
    {
        // [designed bottom, extra height]: elements designed at or below the bottom move down.
        $growth = [];
        foreach ($elements as $element) {
            $y = (float) $element['y'];
            $shift = array_sum(array_map(fn ($g) => $g[0] <= $y + 0.01 ? $g[1] : 0.0, $growth));
            $at = $y + $shift; // position on one continuous strip of pages
            $h = max(0.0, (float) $element['h']);

            if (($element['type'] ?? '') === 'page_break') {
                $next = (floor($at / $this->content) + 1) * $this->content;
                $growth[] = [$y + $h, $next - $at];

                continue;
            }
            // An element that does not fit in what is left of the page starts on the next one.
            $pageTop = floor($at / $this->content) * $this->content;
            if (! in_array($element['type'] ?? '', ['table', 'repeating_section'], true) && $at + $h > $pageTop + $this->content && $h <= $this->content) {
                $growth[] = [$y, $pageTop + $this->content - $at];
                $at = $pageTop + $this->content;
            }

            if (in_array($element['type'] ?? '', ['table', 'repeating_section'], true)) {
                $used = $this->table($element, $at);
                if ($used > $h) {
                    $growth[] = [$y + $h, $used - $h];
                }

                continue;
            }
            [$page, $localY] = $this->locate($at);
            $this->pdf->setPage($page);
            $this->draw($element, (float) $element['x'], $localY);
        }
    }

    /** @param list<array<string, mixed>> $elements */
    private function repeat(array $elements, array $page): void
    {
        $total = $this->pdf->getNumPages();
        for ($n = 1; $n <= $total; $n++) {
            $this->pdf->setPage($n);
            foreach ($elements as $element) {
                if (($element['type'] ?? '') === 'page_number') {
                    $element['text'] = ($element['format'] ?? 'number') === 'number_total' ? "{$n} / {$total}" : (string) $n;
                }
                $this->draw($element, (float) $element['x'], $this->top + (float) $element['y']);
            }
        }
    }

    /** Page number and y on that page (mm from the top edge) of a position on the strip. */
    private function locate(float $at): array
    {
        $page = (int) floor($at / $this->content) + 1;
        while ($this->pdf->getNumPages() < $page) {
            $this->pdf->AddPage();
        }

        return [$page, $this->top + ($at - ($page - 1) * $this->content)];
    }

    /** Draws the table from $at down the pages; returns the height it used on the strip. */
    private function table(array $element, float $at): float
    {
        $columns = $element['columns'] ?? [];
        if ($columns === []) {
            return 0.0;
        }
        $style = $element['style'] ?? [];
        $size = (float) ($style['fontSize'] ?? 9);
        $line = $size * self::PT_TO_MM * 1.25;
        $pad = 1.2;
        $widths = self::widths($columns, (float) $element['w']);
        $x0 = $this->left + (float) $element['x'];
        $this->font($style);

        $start = $at;
        $heading = array_map(fn ($c) => (string) ($c['label'] ?? ''), $columns);
        $at = $this->row($heading, $widths, $x0, $at, $line, $pad, true, $style);
        foreach ($element['rows'] ?? [] as $row) {
            $cells = array_map(fn ($c) => (string) ($row[$c['key'] ?? ''] ?? ''), $columns);
            $height = $this->rowHeight($cells, $widths, $line, $pad, $size, false);
            $pageTop = floor($at / $this->content) * $this->content;
            if ($at + $height > $pageTop + $this->content) {
                // Next page, with the header row again.
                $at = $this->row($heading, $widths, $x0, $pageTop + $this->content, $line, $pad, true, $style);
            }
            $at = $this->row($cells, $widths, $x0, $at, $line, $pad, false, $style);
        }

        return $at - $start;
    }

    /** @param list<string> $cells */
    private function row(array $cells, array $widths, float $x0, float $at, float $line, float $pad, bool $head, array $style): float
    {
        $size = (float) ($style['fontSize'] ?? 9);
        $height = $this->rowHeight($cells, $widths, $line, $pad, $size, $head);
        [$page, $y] = $this->locate($at);
        $this->pdf->setPage($page);
        $this->font($style, $head);
        $this->pdf->SetDrawColorArray(self::rgb($style['borderColor'] ?? '#cbd5e1'));
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->SetFillColorArray($head ? [241, 245, 249] : [255, 255, 255]);
        $this->pdf->SetTextColorArray(self::rgb($style['color'] ?? '#0f172a'));
        $this->pdf->setCellPaddings($pad, $pad * 0.6, $pad, $pad * 0.6);
        $x = $x0;
        foreach ($cells as $i => $text) {
            $this->pdf->MultiCell($widths[$i], $height, $text, 1, 'L', true, 0, $x, $y, true, 0, false, true, $height, 'T');
            $x += $widths[$i];
        }
        $this->pdf->setCellPaddings(0, 0, 0, 0);

        return $at + $height;
    }

    /** @param list<string> $cells */
    private function rowHeight(array $cells, array $widths, float $line, float $pad, float $size, bool $head): float
    {
        $this->pdf->SetFont($this->pdf->getFontFamily(), $head ? 'B' : '', $size);
        $lines = 1;
        foreach ($cells as $i => $text) {
            $lines = max($lines, $this->pdf->getNumLines($text, max(1.0, $widths[$i] - 2 * $pad)));
        }

        return $lines * $line + $pad * 1.2;
    }

    private function draw(array $element, float $x, float $y): void
    {
        $x += $this->left;
        $w = max(0.1, (float) $element['w']);
        $h = max(0.1, (float) $element['h']);
        $style = $element['style'] ?? [];
        $type = $element['type'] ?? '';

        if (in_array($type, ['text', 'dynamic_text', 'header', 'footer', 'page_number', 'signature_area', 'container', 'rectangle'], true)) {
            $this->box($x, $y, $w, $h, $style, $type === 'signature_area' && empty($style['borderWidth']) ? 0.3 : null);
        }
        match ($type) {
            'text', 'dynamic_text', 'header', 'footer', 'page_number' => $this->text((string) ($element['text'] ?? ''), $x, $y, $w, $h, $style),
            'signature_area' => $this->text((string) ($element['text'] ?? ''), $x, $y, $w, $h, $style + ['valign' => 'B']),
            'line' => $this->line($x, $y, $w, $style),
            'image', 'signature_image' => $this->image((string) ($element['src'] ?? ''), (string) ($element['alt'] ?? 'Gambar'), $x, $y, $w, $h),
            'qr_code' => $this->qr((string) ($element['qrValue'] ?? ''), $x, $y, $w, $h),
            default => null, // spacer
        };
    }

    private function box(float $x, float $y, float $w, float $h, array $style, ?float $border = null): void
    {
        $border ??= (float) ($style['borderWidth'] ?? 0);
        $fill = $style['background'] ?? null;
        if ($border <= 0 && ! $fill) {
            return;
        }
        $mode = ($border > 0 ? 'D' : '').($fill ? 'F' : '');
        $this->pdf->SetLineWidth(max(0.1, $border));
        $this->pdf->SetDrawColorArray(self::rgb($style['borderColor'] ?? '#94a3b8'));
        if ($fill) {
            $this->pdf->SetFillColorArray(self::rgb($fill));
        }
        $this->pdf->RoundedRect($x, $y, $w, $h, (float) ($style['radius'] ?? 0), '1111', $mode === 'DF' ? 'DF' : $mode);
    }

    private function text(string $text, float $x, float $y, float $w, float $h, array $style): void
    {
        if ($text === '') {
            return;
        }
        $pad = (float) ($style['padding'] ?? 0);
        $this->font($style, ($style['fontWeight'] ?? 'normal') === 'bold');
        $this->pdf->SetTextColorArray(self::rgb($style['color'] ?? '#0f172a'));
        $align = ['left' => 'L', 'center' => 'C', 'right' => 'R'][$style['align'] ?? 'left'] ?? 'L';
        $this->pdf->setCellPaddings($pad, $pad, $pad, $pad);
        $this->pdf->MultiCell($w, $h, $text, 0, $align, false, 0, $x, $y, true, 0, false, true, $h, $style['valign'] ?? 'T', true);
        $this->pdf->setCellPaddings(0, 0, 0, 0);
    }

    private function line(float $x, float $y, float $w, array $style): void
    {
        $this->pdf->SetLineWidth(max(0.1, (float) ($style['borderWidth'] ?? 0.3)));
        $this->pdf->SetDrawColorArray(self::rgb($style['borderColor'] ?? '#0f172a'));
        $this->pdf->Line($x, $y, $x + $w, $y);
    }

    private function image(string $src, string $alt, float $x, float $y, float $w, float $h): void
    {
        if (preg_match('#^data:image/(png|jpe?g);base64,([A-Za-z0-9+/=\s]+)$#', $src, $m)) {
            $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
            if ($binary !== false && @getimagesizefromstring($binary) !== false) {
                $this->pdf->Image('@'.$binary, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, 'CM');

                return;
            }
        }
        // Not embedded: a labelled placeholder instead of fetching a URL from the server.
        $this->box($x, $y, $w, $h, ['borderWidth' => 0.2, 'borderColor' => '#cbd5e1', 'background' => '#f8fafc']);
        $this->text($alt !== '' ? $alt : 'Gambar', $x, $y, $w, $h, ['fontSize' => 7, 'color' => '#64748b', 'align' => 'center', 'valign' => 'M']);
    }

    private function qr(string $value, float $x, float $y, float $w, float $h): void
    {
        if ($value === '') {
            return;
        }
        $this->pdf->write2DBarcode($value, 'QRCODE,M', $x, $y, $w, $h, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
    }

    private function font(array $style, bool $bold = false): void
    {
        $this->pdf->SetFont(self::FONTS[$style['fontFamily'] ?? 'sans'] ?? 'dejavusans', $bold ? 'B' : '', (float) ($style['fontSize'] ?? 10));
    }

    /** @return array{0: float, 1: float} page width and height in mm */
    public static function pageSize(array $page): array
    {
        [$w, $h] = ($page['size'] ?? 'A4') === 'CUSTOM'
            ? [(float) ($page['customWidth'] ?? 210), (float) ($page['customHeight'] ?? 297)]
            : (self::PAGE_MM[$page['size'] ?? 'A4'] ?? self::PAGE_MM['A4']);

        return ($page['orientation'] ?? 'portrait') === 'landscape' ? [$h, $w] : [$w, $h];
    }

    /** @return list<float> column widths in mm from the relative width hints (equal when absent) */
    private static function widths(array $columns, float $total): array
    {
        $hints = array_map(fn ($c) => is_numeric($c['width'] ?? null) && $c['width'] > 0 ? (float) $c['width'] : 1.0, $columns);
        $sum = array_sum($hints) ?: 1.0;

        return array_map(fn ($hint) => $total * $hint / $sum, $hints);
    }

    /** @return list<int> */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9a-f]{6}$/i', $hex) ? array_map('hexdec', str_split($hex, 2)) : [15, 23, 42];
    }
}
