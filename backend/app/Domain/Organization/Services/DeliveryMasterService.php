<?php

namespace App\Domain\Organization\Services;

use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Vehicle;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;

/** Employees, customers and vehicles. A record an open delivery still needs cannot be deactivated or archived. */
final class DeliveryMasterService
{
    private const EMPLOYEE_AUDIT = ['code', 'name', 'department_id', 'phone', 'license_number', 'license_expires_on', 'status'];

    private const CUSTOMER_AUDIT = ['code', 'name', 'address', 'contact_name', 'phone', 'status'];

    private const VEHICLE_AUDIT = ['code', 'name', 'plate_number', 'type', 'year', 'chassis_number', 'engine_number', 'fuel', 'capacity',
        'stnk_expires_on', 'kir_expires_on', 'status'];

    public function __construct(private readonly MasterWriter $writer) {}

    /** @param array<string, mixed> $data */
    public function createEmployee(array $data): Employee
    {
        $this->assertDivision($data['department_id']);
        // The code is the system's: never entered, never changed.
        $data['code'] = $this->nextEmployeeCode();

        return $this->writer->create(new Employee, $this->withoutLicenseOutsideCrew($data, $data['department_id']), 'employee.created', self::EMPLOYEE_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateEmployee(Employee $employee, array $data): Employee
    {
        $leavesDivision = isset($data['department_id']) && $data['department_id'] !== $employee->department_id;
        if ($leavesDivision) {
            $this->assertDivision($data['department_id']);
        }
        if ($leavesDivision || ($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertEmployeeFree($employee);
        }
        unset($data['code']);
        $data = $this->withoutLicenseOutsideCrew($data, $data['department_id'] ?? $employee->department_id);

        return $this->writer->update($employee, $data, 'employee.updated', self::EMPLOYEE_AUDIT);
    }

    /**
     * Next code of an employee: the numbering of the latest coded employee goes on (SIG-0010 →
     * SIG-0011), KRY-0001 for the first one. Codes are per organization (Tenancy scope).
     */
    private function nextEmployeeCode(): string
    {
        $latest = Employee::query()->whereRaw("code ~ '^[A-Z]+-[0-9]+$'")->latest()->value('code');
        $prefix = $latest === null ? 'KRY' : explode('-', $latest)[0];
        $width = $latest === null ? 4 : max(4, strlen(explode('-', $latest)[1]));
        $number = (int) Employee::query()->where('code', 'like', $prefix.'-%')->whereRaw("code ~ '^[A-Z]+-[0-9]+$'")
            ->selectRaw("max(cast(split_part(code, '-', 2) as bigint)) as n")->value('n');
        do {
            $code = $prefix.'-'.str_pad((string) ++$number, $width, '0', STR_PAD_LEFT);
        } while (Employee::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Only Supir and Kernet keep a SIM: for any other division the SIM fields are emptied.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutLicenseOutsideCrew(array $data, ?string $departmentId): array
    {
        $crew = $departmentId !== null && Department::query()->whereKey($departmentId)->whereNotNull('delivery_role')->exists();

        return $crew ? $data : [...$data, 'license_number' => null, 'license_expires_on' => null];
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

    /** @param array<string, mixed> $data */
    public function createVehicle(array $data): Vehicle
    {
        return $this->writer->create(new Vehicle, $data, 'vehicle.created', self::VEHICLE_AUDIT);
    }

    /** @param array<string, mixed> $data */
    public function updateVehicle(Vehicle $vehicle, array $data): Vehicle
    {
        if (($data['status'] ?? null) === MasterStatus::INACTIVE) {
            $this->assertVehicleFree($vehicle);
        }

        return $this->writer->update($vehicle, $data, 'vehicle.updated', self::VEHICLE_AUDIT);
    }

    public function archiveVehicle(Vehicle $vehicle, ?string $reason): Vehicle
    {
        $this->assertVehicleFree($vehicle);

        return $this->writer->archive($vehicle, 'vehicle.archived', $reason);
    }

    public function restoreVehicle(Vehicle $vehicle): Vehicle
    {
        return $this->writer->restore($vehicle, 'vehicle.restored');
    }

    private function assertVehicleFree(Vehicle $vehicle): void
    {
        $delivery = Delivery::query()->whereIn('status', Delivery::OPEN)->where('vehicle_id', $vehicle->id)->first();
        if ($delivery !== null) {
            throw ApiException::conflict('VEHICLE_IN_USE', "{$vehicle->name} masih dipakai di transaksi {$delivery->number} yang belum selesai.");
        }
    }

    private function assertEmployeeFree(Employee $employee): void
    {
        $delivery = Delivery::query()->whereIn('status', Delivery::OPEN)
            ->where(fn ($q) => $q->where('driver_id', $employee->id)->orWhere('helper_id', $employee->id))->first();
        if ($delivery !== null) {
            throw ApiException::conflict('EMPLOYEE_IN_USE', "{$employee->name} masih bertugas di transaksi {$delivery->number} yang belum selesai.");
        }
    }

    private function assertDivision(string $id): void
    {
        if (! Department::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Divisi tidak ditemukan atau tidak aktif.', ['fields' => ['department_id' => ['Divisi tidak ditemukan atau tidak aktif.']]]);
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
