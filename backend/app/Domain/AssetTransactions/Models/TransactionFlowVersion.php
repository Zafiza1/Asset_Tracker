<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of a transaction type's flow (F-07 §4): the diagram as nodes and edges. A draft is
 * edited; a published version is frozen (database trigger) and used by the runs that started
 * with it.
 *
 * @property string $id
 * @property string $type_definition_id
 * @property int $version_number
 * @property string $status
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property string|null $change_note
 */
class TransactionFlowVersion extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = ['graph', 'change_note'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** Always a JSON object with node and edge lists. */
    protected function graph(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => ['nodes' => [], 'edges' => [], ...(json_decode($value ?? '{}', true) ?: [])],
            set: fn (?array $value) => json_encode(['nodes' => array_values($value['nodes'] ?? []), 'edges' => array_values($value['edges'] ?? [])]),
        );
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TransactionTypeDefinition::class, 'type_definition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
