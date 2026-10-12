<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-owned unit of measure (kg, bar, kW, ...), read-only for tenants.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property string $status
 */
class Unit extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'symbol', 'quantity', 'status'];

    /** @return array{id: string, code: string, symbol: string} */
    public function toSpec(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'symbol' => $this->symbol];
    }
}
