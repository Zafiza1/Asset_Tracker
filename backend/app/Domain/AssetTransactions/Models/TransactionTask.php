<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Open work for people at a step of a run: fill in data or approve. Assigned to a role (any
 * member holding it) or to one user.
 *
 * @property string $run_id
 * @property string $node_id
 * @property string $kind
 * @property string $assignee_type
 * @property string $assignee_id
 * @property string $status
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
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TransactionRun::class, 'run_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Open tasks a user may work on: assigned to them or to one of their roles.
     *
     * @param  list<string>  $roleIds
     */
    public function scopeOpenFor(Builder $query, string $userId, array $roleIds): Builder
    {
        return $query->where('status', self::OPEN)->where(fn ($w) => $w
            ->where(fn ($u) => $u->where('assignee_type', 'user')->where('assignee_id', $userId))
            ->orWhere(fn ($r) => $r->where('assignee_type', 'role')->whereIn('assignee_id', $roleIds)));
    }
}
