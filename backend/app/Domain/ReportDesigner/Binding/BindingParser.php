<?php

namespace App\Domain\ReportDesigner\Binding;

/**
 * Extracts and interpolates `{{field.key}}` binding tokens from design strings.
 *
 * Tokens are plain registry field keys — never expressions. Anything that looks like a
 * binding but does not match the strict pattern is reported as malformed by the design
 * validator, so template input can never smuggle logic into a report.
 */
final class BindingParser
{
    /** A well-formed token: {{key}} with optional inner whitespace. */
    private const TOKEN = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /**
     * All field keys bound in a string, in order of appearance.
     *
     * @return list<string>
     */
    public static function tokens(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        preg_match_all(self::TOKEN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** True when the string contains `{{`/`}}` markup at all. */
    public static function hasMarkup(?string $text): bool
    {
        return $text !== null && str_contains($text, '{{');
    }

    /**
     * True when every `{{…}}` span is a well-formed token. A stray `{{` or `}}`, an
     * unclosed brace, or characters outside [A-Za-z0-9_.] inside the braces fail.
     */
    public static function wellFormed(?string $text): bool
    {
        if (! self::hasMarkup($text)) {
            return true;
        }
        // Remove valid tokens; anything left containing braces is malformed.
        $rest = preg_replace(self::TOKEN, '', (string) $text) ?? '';

        return ! str_contains($rest, '{{') && ! str_contains($rest, '}}');
    }

    /**
     * Replace each token with $resolve($key). Unresolvable keys are left to the
     * resolver's fallback — this method only does textual substitution.
     */
    public static function interpolate(?string $text, callable $resolve): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return (string) preg_replace_callback(self::TOKEN, fn ($m) => (string) $resolve($m[1]), $text);
    }
}
