<?php

namespace App\Domain\Authorization;

/**
 * Union of a user's data scope entries (docs/05-rbac-matrix.md §3).
 * No entries at all means the user sees no scoped data (fail-closed).
 */
final readonly class DataScope
{
    /**
     * @param  list<string>  $branchIds
     * @param  list<string>  $departmentIds
     * @param  list<string>  $locationIds
     */
    public function __construct(
        public bool $organizationWide,
        public array $branchIds = [],
        public array $departmentIds = [],
        public array $locationIds = [],
    ) {}

    public function isEmpty(): bool
    {
        return ! $this->organizationWide && $this->branchIds === [] && $this->departmentIds === [] && $this->locationIds === [];
    }

    /** @return array{organization_wide: bool, branch_ids: list<string>, department_ids: list<string>, location_ids: list<string>} */
    public function toArray(): array
    {
        return [
            'organization_wide' => $this->organizationWide,
            'branch_ids' => $this->branchIds,
            'department_ids' => $this->departmentIds,
            'location_ids' => $this->locationIds,
        ];
    }
}
