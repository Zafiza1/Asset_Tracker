<?php

namespace App\Domain\ReportDesigner\Services;

use App\Domain\ReportDesigner\Binding\BindingParser;
use App\Domain\ReportDesigner\Binding\ConditionEvaluator;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use Illuminate\Support\Str;

/**
 * Static validation of a designer document against the Field Registry.
 *
 * The designer UI already restricts choices to registry fields, but the backend
 * re-checks everything: a template is untrusted input. Issues are returned, not
 * thrown — the validate endpoint reports them all at once and the preview endpoint
 * attaches them to its payload.
 *
 * Issue shape: ['severity' => 'error'|'warning', 'element_id' => ?string,
 *               'path' => string, 'code' => string, 'message' => string]
 */
final class DesignValidator
{
    private const ELEMENT_TYPES = [
        'text', 'dynamic_text', 'image', 'table', 'repeating_section', 'line', 'rectangle',
        'container', 'spacer', 'header', 'footer', 'page_number', 'page_break',
        'signature_image', 'signature_area', 'qr_code',
    ];

    private const MAX_ELEMENTS = 200;

    private const MAX_COLUMNS = 30;

    private const PAGE_SIZES = ['A4', 'A5', 'LETTER', 'LEGAL', 'CUSTOM'];

    private const ORIENTATIONS = ['portrait', 'landscape'];

    /** Props that may carry {{…}} bindings, per element type. */
    private const BINDING_PROPS = [
        'text' => ['text'], 'dynamic_text' => ['text'], 'header' => ['text'],
        'footer' => ['text'], 'signature_area' => ['text'], 'qr_code' => ['qrValue'],
    ];

    /**
     * Validate a design document for a template's row source.
     *
     * @param  array<string, mixed>  $design
     * @return array{valid: bool, issues: list<array<string, mixed>>, bindings: list<string>}
     */
    public function validate(array $design, string $dataSource): array
    {
        $issues = [];
        $bindings = [];

        if (($design['schema'] ?? null) !== 1) {
            $issues[] = $this->issue('error', null, 'schema', 'UNSUPPORTED_SCHEMA', 'Versi skema desain tidak didukung.');
        }

        $this->validatePage($design['page'] ?? null, $issues);

        $elements = $design['elements'] ?? null;
        if (! is_array($elements)) {
            $issues[] = $this->issue('error', null, 'elements', 'INVALID_ELEMENTS', 'Daftar elemen desain tidak valid.');

            return ['valid' => false, 'issues' => $issues, 'bindings' => []];
        }
        if (count($elements) > self::MAX_ELEMENTS) {
            $issues[] = $this->issue('error', null, 'elements', 'TOO_MANY_ELEMENTS', 'Dokumen melebihi '.self::MAX_ELEMENTS.' elemen.');
        }

        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                $issues[] = $this->issue('error', null, "elements.{$index}", 'INVALID_ELEMENT', 'Elemen desain tidak valid.');

                continue;
            }
            $this->validateElement($element, $index, $dataSource, $issues, $bindings);
        }

        return [
            'valid' => ! collect($issues)->contains('severity', 'error'),
            'issues' => $issues,
            'bindings' => array_values(array_unique($bindings)),
        ];
    }

    /**
     * All registry field keys (source + context) the design binds, deduplicated.
     * Used by the preview resolver to fetch exactly the needed columns.
     *
     * @param  array<string, mixed>  $design
     * @return list<string>
     */
    public function bindings(array $design, string $dataSource): array
    {
        return $this->validate($design, $dataSource)['bindings'];
    }

    /** @param  list<array<string, mixed>>  $issues */
    private function validatePage(mixed $page, array &$issues): void
    {
        if (! is_array($page)) {
            $issues[] = $this->issue('error', null, 'page', 'INVALID_PAGE', 'Pengaturan halaman tidak valid.');

            return;
        }
        if (! in_array($page['size'] ?? null, self::PAGE_SIZES, true)) {
            $issues[] = $this->issue('error', null, 'page.size', 'INVALID_PAGE_SIZE', 'Ukuran halaman tidak dikenal.');
        }
        if (! in_array($page['orientation'] ?? null, self::ORIENTATIONS, true)) {
            $issues[] = $this->issue('error', null, 'page.orientation', 'INVALID_ORIENTATION', 'Orientasi halaman tidak dikenal.');
        }
        foreach (['marginTop', 'marginRight', 'marginBottom', 'marginLeft'] as $margin) {
            $value = $page[$margin] ?? null;
            if (! is_numeric($value) || $value < 0 || $value > 100) {
                $issues[] = $this->issue('error', null, "page.{$margin}", 'INVALID_MARGIN', 'Margin halaman tidak valid.');
            }
        }
        if (($page['size'] ?? null) === 'CUSTOM') {
            foreach (['customWidth', 'customHeight'] as $dim) {
                $value = $page[$dim] ?? null;
                if (! is_numeric($value) || $value < 50 || $value > 2000) {
                    $issues[] = $this->issue('error', null, "page.{$dim}", 'INVALID_PAGE_SIZE', 'Ukuran halaman kustom tidak valid.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $bindings
     */
    private function validateElement(array $element, int $index, string $dataSource, array &$issues, array &$bindings): void
    {
        $path = "elements.{$index}";
        $id = is_string($element['id'] ?? null) ? $element['id'] : null;
        $elementPath = $id !== null ? "elements[id={$id}]" : $path;

        $type = $element['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::ELEMENT_TYPES, true)) {
            $issues[] = $this->issue('error', $id, "{$elementPath}.type", 'UNKNOWN_ELEMENT_TYPE', 'Tipe elemen tidak dikenal: '.Str::limit((string) $type, 40));

            return;
        }

        foreach (['x', 'y', 'w', 'h'] as $geo) {
            $value = $element[$geo] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                $issues[] = $this->issue('error', $id, "{$elementPath}.{$geo}", 'INVALID_GEOMETRY', 'Geometri elemen tidak valid.');
            }
        }
        if (is_numeric($element['w'] ?? null) && (float) $element['w'] <= 0) {
            $issues[] = $this->issue('error', $id, "{$elementPath}.w", 'INVALID_GEOMETRY', 'Lebar elemen harus lebih dari nol.');
        }
        if (is_numeric($element['h'] ?? null) && (float) $element['h'] <= 0 && ! in_array($type, ['line', 'spacer', 'page_break'], true)) {
            $issues[] = $this->issue('error', $id, "{$elementPath}.h", 'INVALID_GEOMETRY', 'Tinggi elemen harus lebih dari nol.');
        }

        $props = $element['props'] ?? [];
        if (! is_array($props)) {
            $issues[] = $this->issue('error', $id, "{$elementPath}.props", 'INVALID_PROPS', 'Properti elemen tidak valid.');

            return;
        }

        $this->validateBindings($type, $props, $dataSource, $elementPath, $id, $issues, $bindings);
        $this->validateColumns($type, $props, $dataSource, $elementPath, $id, $issues, $bindings);
        $this->validateDataPath($type, $props, $elementPath, $id, $issues);
    }

    /** @param  array<string, mixed>  $props */
    private function validateBindings(string $type, array $props, string $dataSource, string $path, ?string $id, array &$issues, array &$bindings): void
    {
        $texts = [];
        foreach (self::BINDING_PROPS[$type] ?? [] as $prop) {
            if (isset($props[$prop]) && is_string($props[$prop])) {
                $texts[$prop] = $props[$prop];
            }
        }

        foreach ($texts as $prop => $text) {
            if (! BindingParser::wellFormed($text)) {
                $issues[] = $this->issue('error', $id, "{$path}.props.{$prop}", 'MALFORMED_BINDING', 'Tanda kurung ikat binding tidak valid.');

                continue;
            }
            foreach (BindingParser::tokens($text) as $key) {
                $bindings[] = $key;
                $this->checkFieldKey($key, $dataSource, "{$path}.props.{$prop}", $id, $issues, allowContext: true);
            }
        }

        if (isset($props['visibleWhen']) && is_string($props['visibleWhen']) && trim($props['visibleWhen']) !== '') {
            $expr = $props['visibleWhen'];
            if (! ConditionEvaluator::isValid($expr)) {
                $issues[] = $this->issue('error', $id, "{$path}.props.visibleWhen", 'INVALID_CONDITION', 'Ekspresi kondisi tidak valid.');
            } else {
                foreach ($this->conditionKeys($expr) as $key) {
                    $bindings[] = $key;
                    $this->checkFieldKey($key, $dataSource, "{$path}.props.visibleWhen", $id, $issues, allowContext: true);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $props */
    private function validateColumns(string $type, array $props, string $dataSource, string $path, ?string $id, array &$issues, array &$bindings): void
    {
        if (! in_array($type, ['table', 'repeating_section'], true)) {
            return;
        }
        $columns = $props['columns'] ?? [];
        if (! is_array($columns)) {
            $issues[] = $this->issue('error', $id, "{$path}.props.columns", 'INVALID_COLUMNS', 'Daftar kolom tidak valid.');

            return;
        }
        if ($columns === []) {
            $issues[] = $this->issue('warning', $id, "{$path}.props.columns", 'EMPTY_COLUMNS', 'Tabel tidak memiliki kolom.');

            return;
        }
        if (count($columns) > self::MAX_COLUMNS) {
            $issues[] = $this->issue('error', $id, "{$path}.props.columns", 'TOO_MANY_COLUMNS', 'Tabel melebihi '.self::MAX_COLUMNS.' kolom.');

            return;
        }
        foreach ($columns as $i => $column) {
            if (! is_array($column) || ! is_string($column['key'] ?? null) || ! is_string($column['label'] ?? null)) {
                $issues[] = $this->issue('error', $id, "{$path}.props.columns.{$i}", 'INVALID_COLUMN', 'Definisi kolom tidak valid.');

                continue;
            }
            // Table cells are row-bound: only the template's own source fields (never context).
            $bindings[] = $column['key'];
            $this->checkFieldKey($column['key'], $dataSource, "{$path}.props.columns.{$i}.key", $id, $issues, allowContext: false);
        }
    }

    /** @param  array<string, mixed>  $props */
    private function validateDataPath(string $type, array $props, string $path, ?string $id, array &$issues): void
    {
        if ($type !== 'repeating_section') {
            return;
        }
        $dataPath = $props['dataPath'] ?? '';
        if (! is_string($dataPath)) {
            $issues[] = $this->issue('error', $id, "{$path}.props.dataPath", 'INVALID_DATAPATH', 'Jalur data tidak valid.');

            return;
        }
        if (trim($dataPath) === '') {
            return; // empty = repeat over the report's main row collection
        }
        // A custom path must name a repeatable registry field; V1 has none.
        $issues[] = $this->issue('error', $id, "{$path}.props.dataPath", 'UNSUPPORTED_DATAPATH',
            'Jalur data kustom belum didukung; biarkan kosong untuk mengulang per baris laporan.');
    }

    private function checkFieldKey(string $key, string $dataSource, string $path, ?string $id, array &$issues, bool $allowContext): void
    {
        if (FieldCatalog::has($dataSource, $key)) {
            return;
        }
        if ($allowContext && FieldCatalog::has('context', $key)) {
            return;
        }
        $issues[] = $this->issue('error', $id, $path, 'UNKNOWN_BINDING',
            'Field "'.$key.'" tidak ada di registry untuk sumber ini.');
    }

    /** Binding keys referenced by a visibleWhen expression (operands that are {{…}}). */
    private function conditionKeys(string $expression): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $expression, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string, mixed> */
    private function issue(string $severity, ?string $elementId, string $path, string $code, string $message): array
    {
        return [
            'severity' => $severity,
            'element_id' => $elementId,
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }
}
