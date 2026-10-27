<?php

namespace App\Domain\Identity\Services;

use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The session guard's user provider. Accounts are protected by Row-Level Security (SEC-07), and
 * the guard resolves the user before any tenant or platform context exists, so each lookup and
 * write of the provider runs in the system context, and only that lookup or write.
 */
final class AccountUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private readonly Tenancy $tenancy)
    {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier)
    {
        return $this->tenancy->runAsSystem(fn () => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return $this->tenancy->runAsSystem(fn () => parent::retrieveByToken($identifier, $token));
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        $this->tenancy->runAsSystem(fn () => parent::updateRememberToken($user, $token));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        return $this->tenancy->runAsSystem(fn () => parent::retrieveByCredentials($credentials));
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        $this->tenancy->runAsSystem(fn () => parent::rehashPasswordIfRequired($user, $credentials, $force));
    }
}
