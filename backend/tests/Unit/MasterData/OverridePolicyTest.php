<?php

namespace Tests\Unit\MasterData;

use App\Domain\GenericMaster\Attributes\AttributeDefinitionRules;
use App\Domain\GenericMaster\Attributes\AttributeValueValidator;
use App\Domain\GenericMaster\Attributes\OverridePolicy;
use PHPUnit\Framework\TestCase;

/** Overrides may only specialize a generic attribute, never loosen or retype it. */
class OverridePolicyTest extends TestCase
{
    private OverridePolicy $policy;

    protected function setUp(): void
    {
        $values = new AttributeValueValidator;
        $this->policy = new OverridePolicy(new AttributeDefinitionRules($values), $values);
    }

    /** @return array<string, mixed> */
    private function fuelType(array $overridable = ['label', 'help_text', 'required', 'options', 'default_value']): array
    {
        return [
            'key' => 'jenis_bahan_bakar', 'label' => 'Jenis Bahan Bakar', 'help_text' => null, 'data_type' => 'enum',
            'required' => false, 'validation' => [],
            'options' => [['value' => 'BENSIN', 'label' => 'Bensin'], ['value' => 'SOLAR', 'label' => 'Solar'], ['value' => 'LISTRIK', 'label' => 'Listrik']],
            'unit' => null, 'default_value' => null, 'searchable' => false, 'controlled' => false, 'overridable' => $overridable, 'sort_order' => 10,
        ];
    }

    /** @return array<string, mixed> */
    private function capacity(): array
    {
        return [
            'key' => 'kapasitas', 'label' => 'Kapasitas', 'help_text' => null, 'data_type' => 'decimal', 'required' => true,
            'validation' => ['decimals' => 2, 'max' => 1000, 'min' => 0], 'options' => null, 'unit' => null, 'default_value' => null,
            'searchable' => false, 'controlled' => false, 'overridable' => ['validation', 'default_value', 'required'], 'sort_order' => 20,
        ];
    }

    public function test_allowed_overrides_are_applied(): void
    {
        [$spec, $issues, $applied] = $this->policy->apply($this->fuelType(), [
            'label' => 'BBM Kendaraan Dinas',
            'required' => true,
            'options' => ['SOLAR', 'BENSIN'],
            'default_value' => 'SOLAR',
        ]);

        $this->assertSame([], $issues);
        $this->assertSame(['default_value', 'label', 'options', 'required'], $applied);
        $this->assertSame('BBM Kendaraan Dinas', $spec['label']);
        $this->assertTrue($spec['required']);
        // Subset keeps the generic labels and order.
        $this->assertSame([['value' => 'BENSIN', 'label' => 'Bensin'], ['value' => 'SOLAR', 'label' => 'Solar']], $spec['options']);
        $this->assertSame('enum', $spec['data_type']);
    }

    public function test_properties_outside_the_system_allowlist_are_rejected(): void
    {
        [$spec, $issues] = $this->policy->apply($this->fuelType(), ['data_type' => 'string', 'key' => 'x', 'unit' => 'KG']);

        $this->assertCount(3, $issues);
        $this->assertSame('enum', $spec['data_type']);
        $this->assertSame('jenis_bahan_bakar', $spec['key']);
    }

    public function test_properties_not_marked_overridable_by_the_generic_master_are_rejected(): void
    {
        [, $issues] = $this->policy->apply($this->fuelType(['label']), ['options' => ['SOLAR']]);

        $this->assertNotEmpty($issues);
    }

    public function test_required_can_only_be_tightened(): void
    {
        [, $issues] = $this->policy->apply($this->capacity(), ['required' => false]);
        $this->assertNotEmpty($issues);

        [, $issues] = $this->policy->apply($this->capacity(), ['required' => true]);
        $this->assertSame([], $issues);
    }

    public function test_options_must_be_a_subset_of_generic_values(): void
    {
        [, $issues] = $this->policy->apply($this->fuelType(), ['options' => ['SOLAR', 'AVTUR']]);
        $this->assertNotEmpty($issues);

        [, $issues] = $this->policy->apply($this->fuelType(), ['options' => []]);
        $this->assertNotEmpty($issues);
    }

    public function test_validation_can_only_be_narrowed(): void
    {
        [$spec, $issues] = $this->policy->apply($this->capacity(), ['validation' => ['min' => 1, 'max' => 50, 'decimals' => 1]]);
        $this->assertSame([], $issues);
        $this->assertEquals(['decimals' => 1, 'max' => 50, 'min' => 1], $spec['validation']);

        foreach ([['max' => 2000], ['min' => -1], ['decimals' => 3], ['min' => 60, 'max' => 50]] as $looser) {
            [, $issues] = $this->policy->apply($this->capacity(), ['validation' => $looser]);
            $this->assertNotEmpty($issues, 'Must reject '.json_encode($looser));
        }
    }

    public function test_pattern_can_never_be_overridden(): void
    {
        $base = [...$this->capacity(), 'data_type' => 'string', 'validation' => ['pattern' => '^[A-Z]+$']];

        [, $issues] = $this->policy->apply($base, ['validation' => ['pattern' => '.*']]);

        $this->assertNotEmpty($issues);
    }

    public function test_default_must_satisfy_the_effective_rules(): void
    {
        [, $issues] = $this->policy->apply($this->fuelType(), ['options' => ['SOLAR'], 'default_value' => 'BENSIN']);
        $this->assertNotEmpty($issues);

        // A generic default that the narrowed options exclude must be overridden too.
        $base = [...$this->fuelType(), 'default_value' => 'BENSIN'];
        [, $issues] = $this->policy->apply($base, ['options' => ['SOLAR']]);
        $this->assertNotEmpty($issues);
    }
}
