<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues and verifies API keys. Format: "atk_<prefix>_<secret>". The prefix
 * is a public lookup handle; only SHA-256 of the full key is persisted.
 */
class ApiKeyService
{
    public const TOKEN_PREFIX = 'atk_';

    /**
     * @return array{0: ApiKey, 1: string} the model and the plaintext key (shown once)
     */
    public function issue(Project $project, User $creator, array $data): array
    {
        $prefix = Str::lower(Str::random(12));
        $plain = self::TOKEN_PREFIX . $prefix . '_' . Str::random(40);

        $key = ApiKey::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'integration_id' => $data['integration_id'] ?? null,
            'created_by' => $creator->id,
            'name' => $data['name'],
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'scopes' => array_values(array_unique($data['scopes'])),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return [$key, $plain];
    }

    /**
     * Resolve a presented key, or null when unknown, revoked or expired.
     * Runs before any tenant context exists, so the tenant scope is bypassed
     * explicitly — the key itself defines the tenant.
     */
    public function authenticate(string $plain): ?ApiKey
    {
        if (!preg_match('/^' . self::TOKEN_PREFIX . '([a-z0-9]{12})_[A-Za-z0-9]{40}$/', $plain, $matches)) {
            return null;
        }

        $key = ApiKey::withoutGlobalScope(TenantScope::class)->where('prefix', $matches[1])->first();

        if (!$key || !hash_equals($key->key_hash, hash('sha256', $plain)) || !$key->isUsable()) {
            return null;
        }

        // Throttle last_used_at writes to once a minute per key.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $key;
    }

    public function revoke(ApiKey $key): void
    {
        $key->forceFill(['revoked_at' => now()])->save();
    }
}
