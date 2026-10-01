<?php

namespace Tests\Unit;

use App\Support\VersionConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionConstraintTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['1.0.0', '*', true],
            ['1.0.0', '', true],
            ['1.2.3', '1.2.3', true],
            ['1.2.3', '=1.2.3', true],
            ['1.2.4', '1.2.3', false],
            ['1.0.0', '>=1.0.0', true],
            ['0.9.0', '>=1.0.0', false],
            ['2.0.0', '>1.0', true],
            ['1.0.0', '>1.0', false],
            ['1.9.9', '<2.0.0', true],
            ['2.0.0', '<2.0.0', false],
            ['2.0.0', '<=2', true],
            ['1.5.0', '^1.2', true],
            ['1.1.0', '^1.2', false],
            ['2.0.0', '^1.2', false],
            ['1.5.0', '>=1.0.0, <2.0.0', true],
            ['2.1.0', '>=1.0.0 <2.0.0', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_constraint_matching(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, VersionConstraint::satisfies($version, $constraint));
    }

    public function test_unsupported_constraint_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VersionConstraint::satisfies('1.0.0', '~>1.0');
    }
}
