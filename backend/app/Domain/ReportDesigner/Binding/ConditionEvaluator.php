<?php

namespace App\Domain\ReportDesigner\Binding;

/**
 * Evaluates the declarative `visibleWhen` expression of a design element.
 *
 * Grammar (deliberately tiny — no code, no functions):
 *   operand            → truthy check of the resolved value
 *   !operand           → negation
 *   operand == operand → equality after scalar normalization
 *   operand != operand → inequality
 *
 * An operand is either a {{field.key}} binding or a literal:
 * 'quoted', "double-quoted", number, true/false/null, or a bare word.
 *
 * $resolve receives a binding key and returns its raw (unformatted) value, or a
 * marker for literals via resolveLiteral — see ConditionEvaluator::parse for the
 * exact contract used by the preview resolver.
 */
final class ConditionEvaluator
{
    /**
     * Parse the expression into [negated, left, operator, right]. Operands stay raw
     * strings; the caller resolves them (bindings via callback, literals verbatim).
     *
     * @return array{0: bool, 1: string, 2: string|null, 3: string|null}|null null when malformed
     */
    public static function parse(string $expression): ?array
    {
        $expr = trim($expression);
        if ($expr === '') {
            return null;
        }

        $negated = false;
        if (str_starts_with($expr, '!') && ! str_starts_with($expr, '!=')) {
            $negated = true;
            $expr = trim(substr($expr, 1));
        }

        // Split on a top-level == or != (never inside {{…}} — bindings contain no operators).
        if (preg_match('/^(.*?)\s*(==|!=)\s*(.*)$/s', $expr, $m) === 1) {
            $left = trim($m[1]);
            $right = trim($m[3]);
            if (! self::operandWellFormed($left) || ! self::operandWellFormed($right)) {
                return null;
            }

            return [$negated, $left, $m[2], $right];
        }

        return self::operandWellFormed($expr) ? [$negated, $expr, null, null] : null;
    }

    /** An operand is exactly one {{key}} binding or a literal with no brace markup. */
    private static function operandWellFormed(string $operand): bool
    {
        if (preg_match('/^\{\{\s*[A-Za-z0-9_.]+\s*\}\}$/', $operand) === 1) {
            return true;
        }

        return ! str_contains($operand, '{{') && ! str_contains($operand, '}}');
    }

    /** True when the expression is syntactically valid (operands may still be unknown keys). */
    public static function isValid(string $expression): bool
    {
        return self::parse($expression) !== null;
    }

    /**
     * Evaluate a parsed expression. $resolveBinding($key) → raw value or null;
     * literals are normalized by literalValue().
     *
     * @param  array{0: bool, 1: string, 2: string|null, 3: string|null}  $parsed
     */
    public static function evaluate(array $parsed, callable $resolveBinding): bool
    {
        [$negated, $left, $op, $right] = $parsed;

        $result = match ($op) {
            null => self::truthy(self::operandValue($left, $resolveBinding)),
            '==' => self::looseEquals(self::operandValue($left, $resolveBinding), self::operandValue($right, $resolveBinding)),
            '!=' => ! self::looseEquals(self::operandValue($left, $resolveBinding), self::operandValue($right, $resolveBinding)),
            default => false,
        };

        return $negated ? ! $result : $result;
    }

    /** Evaluate directly from the expression string; false on malformed input. */
    public static function check(string $expression, callable $resolveBinding): bool
    {
        $parsed = self::parse($expression);

        return $parsed !== null && self::evaluate($parsed, $resolveBinding);
    }

    /** Operand → scalar: a {{key}} binding resolves, anything else is a literal. */
    private static function operandValue(string $operand, callable $resolveBinding): mixed
    {
        $operand = trim($operand);
        if (preg_match('/^\{\{\s*([A-Za-z0-9_.]+)\s*\}\}$/', $operand, $m) === 1) {
            return $resolveBinding($m[1]);
        }

        return self::literalValue($operand);
    }

    /** Literal operand normalization: quotes stripped, true/false/null, numbers. */
    private static function literalValue(string $operand): mixed
    {
        if (strlen($operand) >= 2 && ($operand[0] === "'" || $operand[0] === '"') && substr($operand, -1) === $operand[0]) {
            return substr($operand, 1, -1);
        }
        $lower = strtolower($operand);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($operand)) {
            return $operand + 0;
        }

        return $operand;
    }

    private static function truthy(mixed $value): bool
    {
        return ! in_array($value, [null, false, '', '0', 0, 0.0, []], true);
    }

    /** Loose scalar equality: numbers compare numerically, everything else as strings. */
    private static function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
