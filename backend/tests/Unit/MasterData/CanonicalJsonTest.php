<?php

namespace Tests\Unit\MasterData;

use App\Domain\TenantMaster\Schema\CanonicalJson;
use PHPUnit\Framework\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_object_key_order_does_not_change_the_hash(): void
    {
        $a = ['b' => 1, 'a' => ['y' => true, 'x' => null], 'list' => [3, 1, 2]];
        $b = ['list' => [3, 1, 2], 'a' => ['x' => null, 'y' => true], 'b' => 1];

        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
        $this->assertSame('{"a":{"x":null,"y":true},"b":1,"list":[3,1,2]}', CanonicalJson::encode($a));
    }

    public function test_list_order_and_values_change_the_hash(): void
    {
        $this->assertNotSame(CanonicalJson::hash(['list' => [1, 2]]), CanonicalJson::hash(['list' => [2, 1]]));
        $this->assertNotSame(CanonicalJson::hash(['v' => 1]), CanonicalJson::hash(['v' => 1.0]));
    }

    public function test_round_trip_through_json_keeps_the_hash(): void
    {
        $schema = ['attributes' => [['label' => 'Kapasitas', 'validation' => ['min' => 0.5, 'max' => 10], 'key' => 'k']], 'schema_version' => 1];

        $decoded = json_decode(json_encode($schema), true);

        $this->assertSame(CanonicalJson::hash($schema), CanonicalJson::hash($decoded));
    }
}
