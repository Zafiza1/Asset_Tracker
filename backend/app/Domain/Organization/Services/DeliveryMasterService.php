<?php

namespace App\Domain\Organization\Services;

use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Employee;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;

/** Employees and customers. A record an open delivery still needs cannot be deactivated or archived. */
final class DeliveryMasterService
{
    private const EMPLOYEE_AUDIT = ['code', 'name', 'position', 'phone', 'license_number', 'license_expires_on', 'status'];

    private const CUSTOMER_AUDIT = ['code', 'name', 'address', 'contact_name', 'phone', 'status'];

    public function __construct(private readonly MasterWriter $writer) {}

    /** @param array<string, mixed> $data */
    public function createEmployee(array $data): Employee
    {
        return $this->writer->create(new Employee, $data, 'employee.created', self::EMPLOYEE_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateEmployee(Employee $employee, array $data): Employee
    {
        $leavesPosition = isset($data['position']) && $data['position'] !== $employee->position;
        if ($leavesPosition || ($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertEmployeeFree($employee);
        }

        return $this->writer->update($employee, $data, 'employee.updated', self::EMPLOYEE_AUDIT);
    }

    public function archiveEmployee(Employee $employee, ?string $reason): Employee
    {
        $this->assertEmployeeFree($employee);

        return $this->writer->archive($employee, 'employee.archived', $reason);
    }

    public function restoreEmployee(Employee $employee): Employee
    {
        return $this->writer->restore($employee, 'employee.restored');
    }

    /** @param array<string, mixed> $data */
    public function createCustomer(array $data): Customer
    {
        return $this->writer->create(new Customer, $data, 'customer.created', self::CUSTOMER_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateCustomer(Customer $customer, array $data): Customer
    {
        if (($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertCustomerFree($customer);
        }

        return $this->writer->update($customer, $data, 'customer.updated', self::CUSTOMER_AUDIT);
    }

    public function archiveCustomer(Customer $customer, ?string $reason): Customer
    {
        $this->assertCustomerFree($customer);

        return $this->writer->archive($customer, 'customer.archived', $reason);
    }

    public function restoreCustomer(Customer $customer): Customer
    {
        return $this->writer->restore($customer, 'customer.restored');
    }

    private function assertEmployeeFree(Employee $employee): void
    {
        $delivery = Delivery::query()->whereIn('status', Delivery::OPEN)
            ->where(fn ($q) => $q->where('driver_id', $employee->id)->orWhere('helper_id', $employee->id))->first();
        if ($delivery !== null) {
            throw ApiException::conflict('EMPLOYEE_IN_USE', "{$employee->name} masih bertugas di transaksi {$delivery->number} yang belum selesai.");
        }
    }

    private function assertCustomerFree(Customer $customer): void
    {
        $delivery = Delivery::query()->whereIn('status', Delivery::OPEN)
            ->whereHas('stops', fn ($q) => $q->where('customer_id', $customer->id))->first();
        if ($delivery !== null) {
            throw ApiException::conflict('CUSTOMER_IN_USE', "{$customer->name} masih menjadi tujuan transaksi {$delivery->number} yang belum selesai.");
        }
    }
}
