<?php

namespace App\Domain\ReportDesigner\Binding;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\TransactionType;
use Illuminate\Support\Carbon;

/**
 * Turns a raw field value into the display string a report shows.
 *
 * Formatting comes only from the registry entry's `formats` map (date pattern,
 * currency, decimals, label map, empty fallback) and the field's data type —
 * template input never supplies format code, only these declarative hints.
 */
final class ValueFormatter
{
    /**
     * Formatted display value for a field on a row/context. Falls back to the
     * registry `fallback` (default '-') when the raw value is empty.
     *
     * @param  array<int, mixed>  $field  catalog entry: [label, type, bind, relation, formats, permission]
     */
    public function display(mixed $raw, array $field, ?string $fieldKey = null): string
    {
        $formats = $field[4] ?? [];
        $type = $field[1] ?? 'string';

        if ($this->isEmpty($raw)) {
            return (string) ($formats['fallback'] ?? '-');
        }

        if (($formats['labels'] ?? false) === true && is_string($raw)) {
            return $this->label($fieldKey ?? '', $raw);
        }
        if (is_string($formats['date'] ?? null) && $raw !== null) {
            return $this->date($raw, $formats['date']);
        }
        if (($formats['currency'] ?? null) !== null) {
            return $this->money($raw, $formats);
        }

        return match ($type) {
            'date' => $this->date($raw, 'd/m/Y'),
            'currency' => $this->money($raw, $formats),
            'number' => $this->number($raw, $formats),
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 'Ya' : 'Tidak',
            'array' => is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : (string) $raw,
            'image' => (string) $raw,
            default => $this->string($raw),
        };
    }

    /**
     * Scalar raw value for conditions: dates → Y-m-d, Carbon → datetime string,
     * everything else passes through (arrays become JSON so truthiness still works).
     */
    public function raw(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }
        if (is_array($value)) {
            return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /** Context field values (organization.*, report.*) resolved against the tenant. */
    public function contextValue(string $fieldKey, string $organizationName, string $organizationCode, string $reportTitle): mixed
    {
        return match ($fieldKey) {
            'organization.name' => $organizationName,
            'organization.code' => $organizationCode,
            'report.title' => $reportTitle,
            'report.generated_at' => now(),
            default => null,
        };
    }

    private function isEmpty(mixed $raw): bool
    {
        return $raw === null || $raw === '' || $raw === [];
    }

    private function date(mixed $raw, string $format): string
    {
        try {
            return Carbon::parse($raw)->format($format);
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    /** @param  array<string, mixed>  $formats */
    private function money(mixed $raw, array $formats): string
    {
        $decimals = (int) ($formats['decimals'] ?? 0);
        $number = number_format((float) $raw, $decimals, ',', '.');

        return ($formats['currency'] ?? 'IDR') === 'IDR' ? 'Rp '.$number : $number.' '.($formats['currency'] ?? '');
    }

    /** @param  array<string, mixed>  $formats */
    private function number(mixed $raw, array $formats): string
    {
        $decimals = (int) ($formats['decimals'] ?? 0);

        return number_format((float) $raw, $decimals, ',', '.');
    }

    private function label(string $fieldKey, string $value): string
    {
        $map = match (true) {
            str_ends_with($fieldKey, '.type') => TransactionType::LABELS,
            str_contains($fieldKey, 'status') => AssetStatus::LABELS,
            default => [],
        };

        return $map[$value] ?? $value;
    }

    private function string(mixed $raw): string
    {
        if ($raw instanceof Carbon) {
            return $raw->toDateTimeString();
        }
        if (is_bool($raw)) {
            return $raw ? 'Ya' : 'Tidak';
        }

        return (string) $raw;
    }
}
