<?php

namespace Tests\Unit\MasterData;

use App\Domain\GenericMaster\Attributes\AttributeValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeValueValidatorTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, mixed>, 1: mixed, 2: bool}> */
    public static function cases(): iterable
    {
        $enum = ['data_type' => 'enum', 'options' => [['value' => 'A', 'label' => 'A'], ['value' => 'B', 'label' => 'B']]];
        $multi = [...$enum, 'data_type' => 'multi_enum'];

        yield 'required missing' => [['data_type' => 'string', 'required' => true], null, false];
        yield 'optional missing' => [['data_type' => 'string'], '', true];
        yield 'string ok' => [['data_type' => 'string', 'validation' => ['max_length' => 5]], 'abcde', true];
        yield 'string too long' => [['data_type' => 'string', 'validation' => ['max_length' => 5]], 'abcdef', false];
        yield 'string default cap 255' => [['data_type' => 'string'], str_repeat('x', 256), false];
        yield 'string pattern ok' => [['data_type' => 'string', 'validation' => ['pattern' => '^[A-Z]{1,2} \d{1,4}$']], 'B 1234', true];
        yield 'string pattern fails' => [['data_type' => 'string', 'validation' => ['pattern' => '^[A-Z]{1,2} \d{1,4}$']], 'b1234', false];
        yield 'string not a string' => [['data_type' => 'string'], 12, false];
        yield 'integer ok' => [['data_type' => 'integer', 'validation' => ['min' => 1, 'max' => 10]], 10, true];
        yield 'integer above max' => [['data_type' => 'integer', 'validation' => ['max' => 10]], 11, false];
        yield 'integer rejects float' => [['data_type' => 'integer'], 1.5, false];
        yield 'integer rejects numeric string' => [['data_type' => 'integer'], '5', false];
        yield 'decimal ok' => [['data_type' => 'decimal', 'validation' => ['decimals' => 2]], 12.5, true];
        yield 'decimal string ok' => [['data_type' => 'decimal', 'validation' => ['decimals' => 2]], '12.50', true];
        yield 'decimal too many decimals' => [['data_type' => 'decimal', 'validation' => ['decimals' => 1]], 1.25, false];
        yield 'decimal below min' => [['data_type' => 'decimal', 'validation' => ['min' => 0]], -0.1, false];
        yield 'boolean ok' => [['data_type' => 'boolean'], false, true];
        yield 'boolean rejects string' => [['data_type' => 'boolean'], 'true', false];
        yield 'date ok' => [['data_type' => 'date', 'validation' => ['min' => '2020-01-01']], '2024-02-29', true];
        yield 'date impossible' => [['data_type' => 'date'], '2023-02-29', false];
        yield 'date wrong format' => [['data_type' => 'date'], '29/02/2024', false];
        yield 'date before min' => [['data_type' => 'date', 'validation' => ['min' => '2020-01-01']], '2019-12-31', false];
        yield 'enum ok' => [$enum, 'A', true];
        yield 'enum unknown' => [$enum, 'C', false];
        yield 'multi enum ok' => [$multi, ['A', 'B'], true];
        yield 'multi enum duplicate' => [$multi, ['A', 'A'], false];
        yield 'multi enum unknown' => [$multi, ['A', 'Z'], false];
        yield 'multi enum not a list' => [$multi, ['x' => 'A'], false];
    }

    /** @param array<string, mixed> $spec */
    #[DataProvider('cases')]
    public function test_validates_values_against_the_spec(array $spec, mixed $value, bool $valid): void
    {
        $error = (new AttributeValueValidator)->validate($spec, $value);

        $valid ? $this->assertNull($error) : $this->assertNotNull($error);
    }

    public function test_invalid_pattern_never_matches(): void
    {
        $this->assertNull(AttributeValueValidator::compilePattern('(unclosed'));
        $this->assertNotNull((new AttributeValueValidator)->validate(['data_type' => 'string', 'validation' => ['pattern' => '(unclosed']], 'x'));
    }
}
