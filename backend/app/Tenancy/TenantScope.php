<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Auth;

/**
 * Static facade over the current request's TenantContext.
 *
 * Backed by a container singleton rather than the session, so it works for
 * stateless, token-authenticated API requests (Sanctum bearer tokens) and not
 * just cookie-based sessions.
 */
class TenantScope
{
    public static function getCurrentOrganizationId(): ?int
    {
        return app(TenantContext::class)->organizationId();
    }

    public static function getCurrentProjectId(): ?int
    {
        return app(TenantContext::class)->projectId();
    }

    public static function setOrganizationId(?int $organizationId): void
    {
        app(TenantContext::class)->setOrganization($organizationId);
    }

    public static function setProjectId(?int $projectId): void
    {
        app(TenantContext::class)->setProject($projectId);
    }

    public static function clearContext(): void
    {
        app(TenantContext::class)->clear();
    }

    /**
     * Check if user has access to organization
     */
    public static function userHasAccessToOrganization(int $organizationId): bool
    {
        if (!Auth::check()) {
            return false;
        }

        return Auth::user()->canAccessOrganization($organizationId);
    }

    /**
     * Check if user has access to project
     */
    public static function userHasAccessToProject(int $projectId): bool
    {
        if (!Auth::check()) {
            return false;
        }

        return Auth::user()->canAccessProject($projectId);
    }
}
