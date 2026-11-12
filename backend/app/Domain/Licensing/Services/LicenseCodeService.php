<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Models\LicenseCode;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * License code management: generation, assignment, validation, and release.
 *
 * Codes follow the format: LIC-XXXX-XXXX-XXXX where each X is an alphanumeric character.
 * They are reusable - when a user resigns, the code is released and can be reassigned.
 */
final class LicenseCodeService
{
    private const CODE_PREFIX = 'LIC';

    private const CODE_PATTERN = '/^LIC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Generate a new unique license code.
     * Format: LIC-XXXX-XXXX-XXXX
     */
    public function generateCode(): string
    {
        do {
            $segment1 = strtoupper(Str::random(4));
            $segment2 = strtoupper(Str::random(4));
            $segment3 = strtoupper(Str::random(4));
            $code = sprintf('%s-%s-%s-%s', self::CODE_PREFIX, $segment1, $segment2, $segment3);
        } while (LicenseCode::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Create a new license code for the current organization.
     */
    public function create(string $notes = null, string $reference = null): LicenseCode
    {
        return DB::transaction(function () use ($notes, $reference) {
            $code = new LicenseCode;
            $code->forceFill([
                'organization_id' => $this->tenancy->organizationId(),
                'code' => $this->generateCode(),
                'status' => LicenseCode::STATUS_AVAILABLE,
                'notes' => $notes,
                'reference' => $reference,
            ]);
            $code->save();

            return $code;
        });
    }

    /**
     * Create a new license code for a specific organization (static method for system context).
     */
    public static function createForOrganization(\App\Domain\Organization\Models\Organization $organization, string $notes = null, string $reference = null): LicenseCode
    {
        return DB::transaction(function () use ($organization, $notes, $reference) {
            $code = new LicenseCode;
            $code->forceFill([
                'organization_id' => $organization->id,
                'code' => self::generateCodeStatic(),
                'status' => LicenseCode::STATUS_AVAILABLE,
                'notes' => $notes,
                'reference' => $reference,
            ]);
            $code->save();

            return $code;
        });
    }

    /**
     * Generate a new unique license code (static version for system context).
     * Format: LIC-XXXX-XXXX-XXXX
     */
    private static function generateCodeStatic(): string
    {
        do {
            $segment1 = strtoupper(Str::random(4));
            $segment2 = strtoupper(Str::random(4));
            $segment3 = strtoupper(Str::random(4));
            $code = sprintf('%s-%s-%s-%s', self::CODE_PREFIX, $segment1, $segment2, $segment3);
        } while (LicenseCode::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Create multiple license codes for the current organization.
     *
     * @param  int  $count  Number of codes to create
     * @return  array<int, LicenseCode>
     */
    public function createBatch(int $count, string $notes = null): array
    {
        return DB::transaction(function () use ($count, $notes) {
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $codes[] = $this->create($notes);
            }

            return $codes;
        });
    }

    /**
     * Assign a license code to a user. The user must be a member of the organization who does
     * not hold another code here yet (one code per person).
     *
     * @throws  ApiException  if code is not available, or the user cannot receive it
     */
    public function assign(string $codeId, string $userId): LicenseCode
    {
        return DB::transaction(function () use ($codeId, $userId) {
            $orgId = $this->tenancy->organizationId();
            $code = $this->lockCode($codeId);

            if (! $code->isAvailable()) {
                throw ApiException::conflict('CODE_NOT_AVAILABLE', 'Kode lisensi ini tidak tersedia untuk ditugaskan.');
            }

            $member = OrganizationMember::query()->where('organization_id', $orgId)->where('user_id', $userId)->first();
            if (! $member) {
                throw ApiException::unprocessable('USER_NOT_MEMBER', 'Pengguna ini bukan anggota organisasi.');
            }
            if ($member->status === User::STATUS_DEACTIVATED) {
                throw ApiException::conflict('USER_DEACTIVATED', 'Pengguna ini sudah dinonaktifkan. Aktifkan kembali sebelum menugaskan kode lisensi.');
            }

            $held = LicenseCode::query()->where('organization_id', $orgId)->assigned()->where('assigned_to_user_id', $userId)->value('code');
            if ($held !== null) {
                throw ApiException::conflict('USER_ALREADY_LICENSED', "Pengguna ini sudah memegang kode lisensi {$held}. Lepaskan kode tersebut terlebih dahulu.");
            }

            $code->assignTo($userId);
            $this->audit->record('license_code.assigned', $code,
                before: ['status' => LicenseCode::STATUS_AVAILABLE],
                after: ['status' => LicenseCode::STATUS_ASSIGNED, 'user_id' => $userId, 'user_name' => User::query()->whereKey($userId)->value('name')],
                metadata: ['code' => $code->code]);

            return $code;
        });
    }

    /**
     * Release a license code (when user resigns).
     *
     * @throws  ApiException  if code is not assigned
     */
    public function release(string $codeId): LicenseCode
    {
        return DB::transaction(function () use ($codeId) {
            $code = $this->lockCode($codeId);

            if (! $code->isAssigned()) {
                throw ApiException::conflict('CODE_NOT_ASSIGNED', 'Kode lisensi ini tidak sedang ditugaskan ke pengguna manapun.');
            }

            $before = $this->holder($code);
            $code->release();
            $this->audit->record('license_code.released', $code, before: $before, after: ['status' => LicenseCode::STATUS_AVAILABLE], metadata: ['code' => $code->code]);

            return $code;
        });
    }

    /** Releases the code a user holds, when there is one (the member was deactivated). Call inside a transaction. */
    public function releaseHeldBy(string $userId, string $reason): void
    {
        $code = LicenseCode::query()->where('organization_id', $this->tenancy->organizationId())
            ->assigned()->where('assigned_to_user_id', $userId)->lockForUpdate()->first();
        if ($code === null) {
            return;
        }

        $before = $this->holder($code);
        $code->release();
        $this->audit->record('license_code.released', $code, before: $before, after: ['status' => LicenseCode::STATUS_AVAILABLE], metadata: ['code' => $code->code, 'reason' => $reason]);
    }

    private function lockCode(string $codeId): LicenseCode
    {
        return LicenseCode::query()
            ->where('id', $codeId)
            ->where('organization_id', $this->tenancy->organizationId())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<string, mixed> status and holder of the code, for the audit trail */
    private function holder(LicenseCode $code): array
    {
        return array_filter([
            'status' => $code->status,
            'user_id' => $code->assigned_to_user_id,
            'user_name' => $code->assigned_to_user_id ? User::query()->whereKey($code->assigned_to_user_id)->value('name') : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Validate a license code format.
     */
    public function isValidFormat(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    /**
     * Find and validate a license code by its code string.
     * Returns the code if it exists and is available for the organization.
     *
     * @throws  ApiException  if code is invalid, not found, or not available
     */
    public function validateCode(string $code, string $organizationId): LicenseCode
    {
        if (! $this->isValidFormat($code)) {
            throw ApiException::badRequest('INVALID_CODE_FORMAT', 'Format kode lisensi tidak valid. Gunakan format: LIC-XXXX-XXXX-XXXX');
        }

        $licenseCode = LicenseCode::query()
            ->where('code', $code)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $licenseCode) {
            throw ApiException::notFound('CODE_NOT_FOUND', 'Kode lisensi tidak ditemukan untuk organisasi ini.');
        }

        if (! $licenseCode->isAvailable()) {
            throw ApiException::conflict('CODE_NOT_AVAILABLE', 'Kode lisensi ini tidak tersedia.');
        }

        return $licenseCode;
    }

    /**
     * Get available license codes for the current organization.
     *
     * @return  \Illuminate\Database\Eloquent\Collection<int, LicenseCode>
     */
    public function getAvailableCodes()
    {
        return LicenseCode::query()
            ->where('organization_id', $this->tenancy->organizationId())
            ->available()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all license codes for the current organization.
     *
     * @return  \Illuminate\Database\Eloquent\Collection<int, LicenseCode>
     */
    public function getAllCodes()
    {
        return LicenseCode::query()
            ->where('organization_id', $this->tenancy->organizationId())
            ->with('assignedUser')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get license code by ID.
     */
    public function getById(string $id): LicenseCode
    {
        return LicenseCode::query()
            ->where('id', $id)
            ->where('organization_id', $this->tenancy->organizationId())
            ->with('assignedUser')
            ->firstOrFail();
    }
}
