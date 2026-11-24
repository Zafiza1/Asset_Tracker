<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A transaction of an organization's type while it goes through its flow (F-07 §5).
 *
 * @property string $id
 * @property string $type_definition_id
 * @property string $flow_version_id
 * @property string $transaction_number
 * @property string $state
 * @property string|null $current_node_id
 * @property array<string, mixed> $field_values
 * @property array<string, mixed>|null $system_values what the built-in form asked, for a system type's core action (F-07f-2)
 * @property string|null $notes
 * @property string $created_by
 */
class TransactionRun extends Model
{
    use BelongsToOrganization, HasUuids;

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATES = [self::RUNNING, self::COMPLETED, self::REJECTED, self::CANCELLED];

    public const STATE_LABELS = [
        self::RUNNING => 'Berjalan',
        self::COMPLETED => 'Selesai',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'ended_at' => 'datetime',
            'system_values' => 'array',
        ];
    }

    /** Stored as a JSON object even when empty (the column requires an object). */
    protected function fieldValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : (json_decode($value, true) ?: []),
            set: fn (?array $value) => json_encode((object) ($value ?? [])),
        );
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TransactionTypeDefinition::class, 'type_definition_id');
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(TransactionFlowVersion::class, 'flow_version_id');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'transaction_run_assets', 'run_id', 'asset_id')->withPivot('organization_id', 'locked');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TransactionStep::class, 'run_id')->orderBy('created_at')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TransactionTask::class, 'run_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AssetTransaction::class, 'run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    /** Waiting at a step for the transaction of the type that closes this one (F-07 §4.1 Tunggu). */
    public function awaitsPair(): bool
    {
        $node = $this->current_node_id === null ? null : FlowGraph::node($this->flowVersion->graph, $this->current_node_id);

        return $this->isRunning() && ($node['type'] ?? null) === 'wait' && ($node['config']['until'] ?? null) === 'paired';
    }
}
