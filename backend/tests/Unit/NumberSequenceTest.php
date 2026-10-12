<?php

namespace Tests\Unit;

use App\Domain\Shared\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class NumberSequenceTest extends TestCase
{
    public function test_format_tokens(): void
    {
        $date = CarbonImmutable::create(2026, 3, 9);

        $this->assertSame('AST-2026-000042', NumberSequence::format('AST-{YYYY}-{SEQ:6}', 42, $date));
        $this->assertSame('TRF-26-03-7', NumberSequence::format('{TYPE}-{YY}-{MM}-{SEQ}', 7, $date, 'TRF'));
        $this->assertSame('INV1234567', NumberSequence::format('INV{SEQ:3}', 1234567, $date));
    }
}
