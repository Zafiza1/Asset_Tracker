<?php

namespace App\Domain\ReportDesigner\Services;

use App\Domain\ReportDesigner\Binding\BindingParser;
use App\Domain\ReportDesigner\Binding\ConditionEvaluator;
use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Models\ReportTemplateVersion;
use App\Domain\ReportDesigner\TemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns a stored design document plus live tenant data into the resolved preview
 * payload consumed by the designer preview, and later by the PDF/Excel renderers.
 *
 * Resolution rules (the contract Phase 4/5 build on):
 *  - {{context.key}} binds organization/report values — same on every row.
 *  - {{source.key}} inside plain text binds the FIRST data row (header-style fields);
 *    row values live in tables and repeating sections.
 *  - visibleWhen evaluates against context + first row; row-less reports hide
 *    row-bound conditions (falsy).
 *  - Fields the user may not read (required_permission) resolve to their fallback
 *    and are listed in `restricted_fields` — never leaked.
 */
final class ReportPreviewResolver
{
    /** Rows embedded inside table/repeating_section preview elements. */
    private const ELEMENT_ROWS = 25;

    public function __construct(
        private readonly DesignValidator $validator,
        private readonly ReportDataResolver $data,
    ) {}

    /**
     * The version a preview renders: an explicit number, else the working draft,
     * else the published active version, else the newest version.
     */
    public function versionFor(ReportTemplate $template, ?int $versionNumber): ?ReportTemplateVersion
    {
        if ($versionNumber !== null) {
            return $template->versions()->where('version_number', $versionNumber)->first();
        }

        return $template->versions()->where('status', TemplateStatus::DRAFT)->orderByDesc('version_number')->first()
            ?? $template->activeVersion()->orderByDesc('version_number')->first()
            ?? $template->versions()->orderByDesc('version_number')->first();
    }

    /**
     * Resolve a version's design against filtered tenant data.
     *
     * @param  array<string, mixed>  $filters  already validated by ReportDataResolver::filterRules
     * @return array<string, mixed>
     */
    public function preview(ReportTemplate $template, ReportTemplateVersion $version, array $filters): array
    {
        $design = is_array($version->design) ? $version->design : [];
        $source = $template->data_source;
        $check = $this->validator->validate($design, $source);
        $split = $this->data->splitBindings($check['bindings'], $source);

        $context = $this->data->context($split['context'], $template->name);
        $result = $this->data->rows($source, $filters);
        /** @var Collection<int, Model> $rows */
        $rows = $result['rows'];

        // Resolve each row once: display strings for the payload, raw values kept
        // for the first row (text fallback + conditions).
        $resolvedRows = [];
        $restricted = [];
        foreach ($rows as $row) {
            $display = $this->data->rowDisplay($row, $source, $split['source']);
            $restricted = array_values(array_unique([...$restricted, ...$display['restricted']]));
            $resolvedRows[] = [
                'display' => $display['values'],
                'raw' => $this->data->rowRaw($row, $source, $split['source']),
                'model' => $row,
            ];
        }

        $first = $resolvedRows[0] ?? null;
        $elements = $this->resolveElements(
            is_array($design['elements'] ?? null) ? $design['elements'] : [],
            $source, $split['source'], $context, $first, $resolvedRows,
        );

        return [
            'template' => [
                'id' => $template->id, 'code' => $template->code,
                'name' => $template->name, 'data_source' => $source,
            ],
            'version' => [
                'id' => $version->id, 'version_number' => $version->version_number,
                'status' => $version->status,
            ],
            'valid' => $check['valid'],
            'issues' => $check['issues'],
            'context' => $context['display'],
            'rows' => array_map(fn (array $r) => $r['display'], $resolvedRows),
            'total' => $result['total'],
            'restricted_fields' => $restricted,
            'elements' => $elements,
        ];
    }

    /**
     * @param  list<mixed>  $elements
     * @param  list<string>  $sourceKeys
     * @param  array{display: array<string, string>, raw: array<string, mixed>}  $context
     * @param  array{display: array<string, string>, raw: array<string, mixed>, model: Model}|null  $first
     * @param  list<array{display: array<string, string>, raw: array<string, mixed>, model: Model}>  $resolvedRows
     * @return list<array<string, mixed>>
     */
    private function resolveElements(array $elements, string $source, array $sourceKeys, array $context, ?array $first, array $resolvedRows): array
    {
        $out = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $resolved = [
                'id' => $element['id'] ?? null,
                'type' => $element['type'] ?? null,
                'x' => $element['x'] ?? 0, 'y' => $element['y'] ?? 0,
                'w' => $element['w'] ?? 0, 'h' => $element['h'] ?? 0,
                'z' => $element['z'] ?? 0,
                'style' => is_array($element['style'] ?? null) ? $element['style'] : [],
                'visible' => $this->visible($element['props']['visibleWhen'] ?? null, $sourceKeys, $context, $first),
            ];

            $props = is_array($element['props'] ?? null) ? $element['props'] : [];
            $type = $element['type'] ?? '';

            if (in_array($type, ['text', 'dynamic_text', 'header', 'footer', 'signature_area'], true)) {
                $resolved['text'] = $this->interpolate($props['text'] ?? '', $source, $sourceKeys, $context, $first);
            }
            if (in_array($type, ['image', 'signature_image'], true)) {
                $resolved['src'] = $this->interpolate($props['src'] ?? '', $source, $sourceKeys, $context, $first);
                $resolved['alt'] = $props['alt'] ?? null;
            }
            if ($type === 'qr_code') {
                $resolved['qrValue'] = $this->interpolate($props['qrValue'] ?? '', $source, $sourceKeys, $context, $first);
            }
            if ($type === 'page_number') {
                // Page totals are a render-time concern; the preview shows a sample.
                $resolved['format'] = $props['format'] ?? 'number';
                $resolved['text'] = '1'.($resolved['format'] === 'number_total' ? ' / 1' : '');
            }
            if (in_array($type, ['table', 'repeating_section'], true)) {
                $columns = is_array($props['columns'] ?? null) ? $props['columns'] : [];
                $resolved['columns'] = array_values(array_map(fn ($c) => [
                    'key' => is_array($c) ? ($c['key'] ?? '') : '',
                    'label' => is_array($c) ? ($c['label'] ?? '') : '',
                    'width' => is_array($c) ? ($c['width'] ?? null) : null,
                    'format' => is_array($c) ? ($c['format'] ?? null) : null,
                ], $columns));
                $resolved['rows'] = $this->elementRows($resolved['columns'], array_slice($resolvedRows, 0, self::ELEMENT_ROWS));
            }

            $out[] = $resolved;
        }

        return $out;
    }

    /** @param  list<array{key: string, label: string, width: mixed, format: mixed}>  $columns */
    private function elementRows(array $columns, array $resolvedRows): array
    {
        $keys = array_column($columns, 'key');
        $rows = [];
        foreach ($resolvedRows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                // Column keys are registry fields of the row source; unknown keys stay empty.
                $cells[$key] = $row['display'][$key] ?? '';
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** Token interpolation: context keys → context, source keys → first row, else fallback. */
    private function interpolate(?string $text, string $source, array $sourceKeys, array $context, ?array $first): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return BindingParser::interpolate($text, function (string $key) use ($source, $sourceKeys, $context, $first) {
            if (isset($context['display'][$key])) {
                return $context['display'][$key];
            }
            if (in_array($key, $sourceKeys, true)) {
                if (isset($first['display'][$key])) {
                    return $first['display'][$key];
                }
                // Row field with no rows (or restricted): the registry fallback.
                $field = FieldCatalog::fields($source)[$key] ?? null;

                return $field !== null ? (string) (($field[4] ?? [])['fallback'] ?? '-') : '';
            }

            return '';
        });
    }

    /** visibleWhen against context + first row; absent/empty expression = visible. */
    private function visible(mixed $expression, array $sourceKeys, array $context, ?array $first): bool
    {
        if (! is_string($expression) || trim($expression) === '') {
            return true;
        }
        if (! ConditionEvaluator::isValid($expression)) {
            return true; // validator reports the error; don't silently hide
        }

        return ConditionEvaluator::check($expression, function (string $key) use ($sourceKeys, $context, $first) {
            if (array_key_exists($key, $context['raw'])) {
                return $context['raw'][$key];
            }
            if (in_array($key, $sourceKeys, true)) {
                return $first['raw'][$key] ?? null;
            }

            return null;
        });
    }
}
