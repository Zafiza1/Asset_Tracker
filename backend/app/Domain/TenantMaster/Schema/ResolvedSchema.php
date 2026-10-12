<?php

namespace App\Domain\TenantMaster\Schema;

final readonly class ResolvedSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $issues  problems that block a release
     */
    public function __construct(
        public array $schema,
        public array $issues,
        public string $hash,
    ) {}

    public function isValid(): bool
    {
        return $this->issues === [];
    }
}
