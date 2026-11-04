<?php

namespace App\Domain\Approvals\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change waiting for (or decided by) a Super Admin or Supervisor. Holds the original API
 * call (method, path, input, uploaded files) so approving it applies exactly what was submitted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $category
 * @property string $action
 * @property string $summary
 * @property string $method
 * @property string $path
 * @property array<string, mixed> $payload
 * @property array<string, array{path: string, name: string, mime: string|null}>|null $files
 * @property string $status
 * @property string $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon $created_at
 */
class ApprovalRequest extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'files' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
