<?php

namespace App\Domain\Shared\Tenancy;

use LogicException;

/**
 * Thrown when tenant-owned data is accessed without an established tenant context.
 * This is a programming error, never a user error: it keeps queries fail-closed.
 */
final class MissingTenantContext extends LogicException
{
    public function __construct(string $message = 'Tenant context is required for this operation.')
    {
        parent::__construct($message);
    }
}
