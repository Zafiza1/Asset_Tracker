<?php

namespace App\Modules\Customer;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\User;
use App\Modules\Concerns\ChecksProjectLocations;
use App\Modules\Customer\Events\CustomerChanged;
use App\Modules\Customer\Models\Customer;
use App\Services\AuditService;

/**
 * Customer module business logic. References Core locations (the customer
 * site) without writing Core tables; changes go out as customer.* events.
 */
class CustomerService
{
    use ChecksProjectLocations;

    public const MODULE_SLUG = 'customer';

    public function __construct(protected AuditService $audit)
    {
    }

    public function create(Project $project, array $data, ?User $user = null): Customer
    {
        $this->assertCodeAvailable($project, $data['code']);
        $this->assertLocationInProject($project, $data['location_id'] ?? null);

        $customer = Customer::create(array_merge($data, [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'status' => $data['status'] ?? 'active',
        ]));

        $this->announce($customer, 'created', $user);

        return $customer;
    }

    public function update(Customer $customer, array $data, ?User $user = null): Customer
    {
        $project = $customer->project;

        if (isset($data['code']) && $data['code'] !== $customer->code) {
            $this->assertCodeAvailable($project, $data['code']);
        }

        if (array_key_exists('location_id', $data)) {
            $this->assertLocationInProject($project, $data['location_id']);
        }

        $customer->update($data);
        $this->announce($customer, 'updated', $user, ['changes' => array_keys($data)]);

        return $customer->fresh();
    }

    /**
     * Soft delete: history that references the customer (e.g. deliveries)
     * keeps resolving, and the code becomes free for reuse.
     */
    public function delete(Customer $customer, ?User $user = null): void
    {
        $customer->delete();
        $this->announce($customer, 'deleted', $user);
    }

    protected function assertCodeAvailable(Project $project, string $code): void
    {
        $taken = Customer::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->exists();

        if ($taken) {
            throw ApiException::invalid('Validation failed', ['code' => ['This code is already used by another customer in this project']]);
        }
    }

    protected function announce(Customer $customer, string $action, ?User $user, array $extra = []): void
    {
        CustomerChanged::dispatch($customer, $action);

        $this->audit->logActivity(
            "customer.{$action}",
            'customer',
            $customer->id,
            array_merge(['code' => $customer->code], $extra),
            $user?->id,
            $customer->organization_id,
            $customer->project_id,
        );
    }
}
