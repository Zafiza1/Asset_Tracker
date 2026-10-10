<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * The only writer of audit_logs. Organization is taken from the tenant context when one
 * exists (a caller cannot log into another tenant); outside a tenant context the caller
 * states it explicitly (e.g. login events, platform actions on an organization).
 */
final class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY = '/pass(word)?|secret|token|remember|api[_-]?key|authorization|cookie/i';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        Model|string|null $entity = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?string $organizationId = null,
        ?User $actor = null,
    ): AuditLog {
        if ($this->tenancy->hasTenant()) {
            $tenantOrg = $this->tenancy->organizationId();
            if ($organizationId !== null && $organizationId !== $tenantOrg) {
                throw new LogicException('Cannot write audit entries for another organization.');
            }
            $organizationId = $tenantOrg;
        }

        $actor ??= Auth::user();
        [$entityType, $entityId] = $this->entity($entity, $entityId);
        $fromConsole = app()->runningInConsole() && ! app()->runningUnitTests();

        $attributes = [
            'organization_id' => $organizationId,
            'actor_type' => $actor ? 'user' : ($fromConsole ? 'system' : 'anonymous'),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before === null ? null : $this->redact($before),
            'after' => $after === null ? null : $this->redact($after),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip' => $fromConsole ? null : $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'request_id' => $this->request->attributes->get('request_id'),
        ];

        // Outside a tenant context the insert must still pass RLS for the stated organization.
        if ($this->tenancy->hasTenant() || $this->tenancy->isUnrestricted()) {
            return AuditLog::query()->create($attributes);
        }

        return $this->tenancy->runAsSystem(fn () => AuditLog::query()->create($attributes));
    }

    /**
     * Only changed keys, for update events.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] != $value) {
                $changedBefore[$key] = $before[$key] ?? null;
                $changedAfter[$key] = $value;
            }
        }

        return [$changedBefore, $changedAfter];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function entity(Model|string|null $entity, ?string $entityId): array
    {
        if ($entity instanceof Model) {
            return [class_basename($entity), (string) $entity->getKey()];
        }

        return [$entity, $entityId];
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
