<?php

namespace App\Domain\Shared\Tenancy;

final readonly class TenancyState
{
    public function __construct(
        public ?TenantContext $tenant,
        public ?PlatformContext $platform,
        public bool $system,
    ) {}

    public static function system(): self
    {
        return new self(null, null, true);
    }
}
