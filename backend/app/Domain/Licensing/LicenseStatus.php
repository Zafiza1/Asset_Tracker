<?php

namespace App\Domain\Licensing;

use Illuminate\Support\Carbon;

/**
 * Licensing state of one organization at a point in time.
 *
 * Seats only count while the base license is in force. Used seats are active members plus
 * open (pending, unexpired) invitations; deactivated members and revoked/expired
 * invitations free theirs.
 */
final readonly class LicenseStatus
{
    public function __construct(
        public bool $active,
        public ?Carbon $expiresAt,
        public int $capacity,
        public int $activeMembers,
        public int $pendingInvitations,
    ) {}

    public function used(): int
    {
        return $this->activeMembers + $this->pendingInvitations;
    }

    public function available(): int
    {
        return max(0, $this->capacity - $this->used());
    }

    public function overLimit(): bool
    {
        return $this->used() > $this->capacity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'seats' => [
                'capacity' => $this->capacity,
                'used' => $this->used(),
                'active_members' => $this->activeMembers,
                'pending_invitations' => $this->pendingInvitations,
                'available' => $this->available(),
            ],
            'over_limit' => $this->overLimit(),
        ];
    }
}
