<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Open work for people at a step of a run: fill in data or approve. Assigned to a role (any
 * member holding it), to one user, or (approvals) to the location supervisors of the run's
 * assets. An approval may need several approvers (required_approvals); approved_by lists who
 * approved so far. Whose task it is: see Flows\RunAccess.
 *
 * @property string $run_id
 * @property string $node_id
 * @property string $kind
 * @property string $assignee_type role|user|location_supervisor|asset_custodian|department_supervisor
 * @property string|null $assignee_id
 * @property string $status
 * @property int $required_approvals
 * @property list<string> $approved_by
 * @property array<string, mixed> $details who approves as custodian and why a task fell back to another approver (F-08i)
 */
class TransactionTask extends Model
{
    use BelongsToOrganization, HasUuids;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'required_approvals' => 'integer',
            'details' => 'array',
        ];
    }

    /** Always a JSON array (the column requires one). */
    protected function approvedBy(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : array_values(json_decode($value, true) ?: []),
            set: fn (?array $value) => json_encode(array_values($value ?? [])),
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TransactionRun::class, 'run_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
