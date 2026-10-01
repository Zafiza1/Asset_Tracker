<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal semver constraint matcher for module dependencies.
 *
 * Supported: "*", "1.2.0" / "=1.2.0", ">=1.0.0", ">1.0.0", "<=2.0.0",
 * "<2.0.0", "^1.2" (same major, at least 1.2), and comma/space separated
 * combinations which must all hold (">=1.0.0, <2.0.0").
 */
class VersionConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*,\s*|\s+/', $constraint) as $part) {
            if (!self::satisfiesSingle($version, $part)) {
                return false;
            }
        }

        return true;
    }

    protected static function satisfiesSingle(string $version, string $part): bool
    {
        if (!preg_match('/^(\^|>=|<=|>|<|=)?\s*v?(\d+(?:\.\d+){0,2})$/', $part, $matches)) {
            throw new InvalidArgumentException("Unsupported version constraint [{$part}]");
        }

        $operator = $matches[1] ?: '=';
        $target = self::normalize($matches[2]);
        $version = self::normalize($version);

        if ($operator === '^') {
            $major = (int) explode('.', $target)[0];

            return version_compare($version, $target, '>=')
                && version_compare($version, ($major + 1) . '.0.0', '<');
        }

        return version_compare($version, $target, $operator === '=' ? '==' : $operator);
    }

    /**
     * Pad "1" / "1.2" to "1.0.0" / "1.2.0" so version_compare treats them as
     * equal to their full forms.
     */
    public static function normalize(string $version): string
    {
        $parts = explode('.', ltrim($version, 'v'));

        return implode('.', array_pad($parts, 3, '0'));
    }
}
