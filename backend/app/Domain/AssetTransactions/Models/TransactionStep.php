<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened at one step of a run (append-only): a decision, entered data, a condition's
 * answer, an action applied.
 *
 * @property string $run_id
 * @property string $node_id
 * @property string $node_type
 * @property string|null $outcome
 * @property string|null $actor_id
 * @property string|null $note
 * @property array<string, mixed> $details
 */
class TransactionStep extends Model
{
    use BelongsToOrganization, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected function details(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : (json_decode($value, true) ?: []),
            set: fn (?array $value) => json_encode((object) ($value ?? [])),
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
