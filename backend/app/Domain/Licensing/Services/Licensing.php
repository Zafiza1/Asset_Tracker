<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\LicenseStatus;
use App\Domain\Licensing\Models\OrganizationLicense;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Organization licenses and seats.
 *
 *  - An organization works only while a base (Asset Tracker) license is in force; otherwise it
 *    is read-only (EnsureLicensed middleware).
 *  - Seats are a pool, not tied to people. Everything that makes somebody use one (inviting,
 *    re-sending an expired invitation, accepting, re-activating a member) calls assertSeats()
 *    inside its transaction; the organization row lock serializes those checks.
 *  - Only the platform (or the system, for trials) records and revokes licenses.
 */
final class Licensing
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    public function status(string $organizationId): LicenseStatus
    {
        $valid = OrganizationLicense::query()->where('organization_id', $organizationId)->valid()->get();
        $base = $valid->where('kind', OrganizationLicense::KIND_BASE);
        $active = $base->isNotEmpty();

        return new LicenseStatus(
            active: $active,
            expiresAt: $active ? $base->max('expires_at') : null,
            capacity: $active ? (int) $valid->sum('seats') : 0,
            activeMembers: OrganizationMember::query()->where('organization_id', $organizationId)->where('status', User::STATUS_ACTIVE)->count(),
            pendingInvitations: OrganizationInvitation::query()->where('organization_id', $organizationId)
                ->where('status', OrganizationInvitation::STATUS_PENDING)->where('expires_at', '>', now())->count(),
        );
    }

    /**
     * Checks that $additional more seats fit in the license (0: the seat is already counted, as
     * when a pending invitation is accepted). Call inside the transaction that uses the seat.
     */
    public function assertSeats(string $organizationId, int $additional = 1): void
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
        $status = $this->status($organizationId);

        if (! $status->active) {
            throw self::inactive();
        }
        if ($status->used() + $additional > $status->capacity) {
            throw ApiException::conflict('SEAT_LIMIT_REACHED',
                "Semua seat lisensi sudah terpakai ({$status->used()} dari {$status->capacity}). Nonaktifkan anggota atau batalkan undangan yang tidak diperlukan, atau tambah seat lisensi.",
                ['seats' => $status->toArray()['seats']]);
        }
    }

    public static function inactive(): ApiException
    {
        return ApiException::forbidden('LICENSE_INACTIVE',
            'Lisensi Asset Tracker organisasi ini tidak aktif atau sudah kedaluwarsa. Organisasi hanya dapat dilihat sampai lisensi diperpanjang.');
    }

    /** Records a purchase (platform console). */
    public function grant(Organization $organization, string $kind, int $seats, Carbon $startsAt, Carbon $expiresAt,
        ?string $reference = null, ?string $notes = null, string $source = OrganizationLicense::SOURCE_PURCHASE): OrganizationLicense
    {
        if (! $this->tenancy->isUnrestricted()) {
            throw ApiException::forbidden();
        }

        return DB::transaction(function () use ($organization, $kind, $seats, $startsAt, $expiresAt, $reference, $notes, $source) {
            $license = new OrganizationLicense;
            $license->forceFill([
                'organization_id' => $organization->id,
                'kind' => $kind,
                'seats' => $kind === OrganizationLicense::KIND_BASE ? 1 : $seats,
                'source' => $source,
                'reference' => $reference,
                'notes' => $notes,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'granted_by' => $this->tenancy->platform()?->user->id,
            ])->save();

            $this->audit->record('license.granted', $license, after: [
                'kind' => $license->kind,
                'seats' => $license->seats,
                'source' => $license->source,
                'reference' => $license->reference,
                'starts_at' => $license->starts_at->toIso8601String(),
                'expires_at' => $license->expires_at->toIso8601String(),
            ], organizationId: $organization->id);

            return $license;
        });
    }

    /** The trial of a self-service organization (config licensing.trial); none when disabled. */
    public function grantTrial(Organization $organization): void
    {
        $days = (int) config('licensing.trial.days');
        if ($days <= 0) {
            return;
        }
        $start = now();
        $end = $start->copy()->addDays($days);
        $this->grant($organization, OrganizationLicense::KIND_BASE, 1, $start, $end, source: OrganizationLicense::SOURCE_TRIAL);
        $extra = (int) config('licensing.trial.seats') - 1;
        if ($extra > 0) {
            $this->grant($organization, OrganizationLicense::KIND_SEATS, $extra, $start, $end, source: OrganizationLicense::SOURCE_TRIAL);
        }
    }

    /**
     * Ends a license early (refund, mistake). Members and invitations stay as they are; if the
     * organization is now over its seats, it is read-only until seats are freed or added.
     */
    public function revoke(OrganizationLicense $license, string $reason): OrganizationLicense
    {
        if (! $this->tenancy->isUnrestricted()) {
            throw ApiException::forbidden();
        }

        return DB::transaction(function () use ($license, $reason) {
            $locked = OrganizationLicense::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw ApiException::conflict('LICENSE_REVOKED', 'Lisensi ini sudah dicabut.');
            }
            $before = $locked->status();
            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $this->tenancy->platform()?->user->id,
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->record('license.revoked', $locked, before: ['status' => $before], after: ['status' => OrganizationLicense::STATUS_REVOKED],
                metadata: ['reason' => $reason], organizationId: $locked->organization_id);

            return $locked;
        });
    }
}
