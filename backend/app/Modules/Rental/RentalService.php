<?php

namespace App\Modules\Rental;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Modules\Concerns\ChecksProjectLocations;
use App\Modules\Customer\Models\Customer;
use App\Modules\ModuleSettings;
use App\Modules\Rental\Events\RentalChanged;
use App\Modules\Rental\Models\Rental;
use App\Services\AuditService;
use App\Services\MovementService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rental module business logic.
 *
 *   reserved ──checkout──► active ──return──► returned
 *       └──cancel──► cancelled          (extend: move due_at)
 *
 * The asset moves only through Core's MovementService (source "rental").
 * Amounts (rental and late fee) are computed on return for reporting and
 * external billing; the platform does not bill.
 */
class RentalService
{
    use ChecksProjectLocations;

    public const MODULE_SLUG = 'rental';

    public const MOVEMENT_SOURCE = 'rental';

    public function __construct(
        protected MovementService $movements,
        protected ModuleSettings $settings,
        protected AuditService $audit,
    ) {
    }

    public function create(Project $project, Customer $customer, Asset $asset, array $data, ?User $user = null): Rental
    {
        if ($customer->project_id !== $project->id) {
            throw ApiException::invalid('Validation failed', ['customer_id' => ['The customer does not belong to this project']]);
        }

        if ($customer->status !== 'active') {
            throw ApiException::invalid('Validation failed', ['customer_id' => ['Assets can only be rented to active customers']]);
        }

        if ($asset->project_id !== $project->id) {
            throw ApiException::invalid('Validation failed', ['asset_id' => ['The asset does not belong to this project']]);
        }

        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        $dueAt = isset($data['due_at'])
            ? Carbon::parse($data['due_at'])
            : $startsAt->copy()->addDays((int) $this->settings->get($project, self::MODULE_SLUG, 'default_rental_period_days', 7));
        $this->assertDueAfterStart($startsAt, $dueAt);

        $destinationId = $data['destination_location_id'] ?? $customer->location_id;
        $this->assertLocationInProject($project, $destinationId, 'destination_location_id');

        return DB::transaction(function () use ($project, $customer, $asset, $data, $startsAt, $dueAt, $destinationId, $user) {
            // Lock the asset so two concurrent rentals cannot both take it.
            Asset::withoutGlobalScopes()->whereKey($asset->id)->lockForUpdate()->first();

            $busy = Rental::withoutGlobalScopes()
                ->where('asset_id', $asset->id)
                ->whereIn('status', Rental::OPEN_STATUSES)
                ->value('id');

            if ($busy) {
                throw ApiException::conflict("Asset {$asset->system_id} is already in an open rental");
            }

            $rental = Rental::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'customer_id' => $customer->id,
                'asset_id' => $asset->id,
                'reference' => $data['reference'] ?? null,
                'status' => 'reserved',
                'starts_at' => $startsAt,
                'due_at' => $dueAt,
                'destination_location_id' => $destinationId,
                'daily_rate' => $data['daily_rate'] ?? null,
                'late_fee_per_day' => $data['late_fee_per_day'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $user?->id,
            ]);

            $this->announce($rental, 'created', $user);

            return $rental;
        });
    }

    /** The asset goes to the customer: it moves to the destination. */
    public function checkout(Rental $rental, array $data = [], ?User $user = null): Rental
    {
        $this->assertStatus($rental, ['reserved'], 'checked out');

        if (!$rental->destination_location_id) {
            throw ApiException::invalid('Validation failed', [
                'destination_location_id' => ['Set a destination (or the customer\'s site) before checking out'],
            ]);
        }

        return DB::transaction(function () use ($rental, $data, $user) {
            $checkedOutAt = isset($data['checked_out_at']) ? Carbon::parse($data['checked_out_at']) : now();
            $this->move($rental, $rental->destination_location_id, $checkedOutAt, 'checked_out', $user);

            $rental->update(['status' => 'active', 'checked_out_at' => $checkedOutAt]);
            $this->announce($rental, 'checked_out', $user);

            return $rental;
        });
    }

    /** Create and hand over in one step (a walk-in rental). */
    public function createAndCheckout(Project $project, Customer $customer, Asset $asset, array $data, ?User $user = null): Rental
    {
        return DB::transaction(fn () => $this->checkout($this->create($project, $customer, $asset, $data, $user), [], $user));
    }

    public function extend(Rental $rental, CarbonInterface $dueAt, ?User $user = null): Rental
    {
        $this->assertStatus($rental, Rental::OPEN_STATUSES, 'extended');
        $this->assertDueAfterStart($rental->starts_at, $dueAt);

        $rental->update(['due_at' => $dueAt]);
        $this->announce($rental, 'extended', $user);

        return $rental;
    }

    /**
     * The asset comes back to $toLocationId. Rented days count from checkout
     * (at least 1, part days round up); days late count past due_at. The
     * late fee uses late_fee_per_day (or the daily rate) and is only charged
     * when the project enables late_fee_enabled.
     */
    public function returnAsset(Rental $rental, int $toLocationId, array $data = [], ?User $user = null): Rental
    {
        $this->assertStatus($rental, ['active'], 'returned');
        $this->assertLocationInProject($rental->project, $toLocationId, 'to_location_id');

        $returnedAt = isset($data['returned_at']) ? Carbon::parse($data['returned_at']) : now();
        if ($returnedAt->lessThan($rental->checked_out_at)) {
            throw ApiException::invalid('Validation failed', ['returned_at' => ['The return cannot be before the checkout']]);
        }

        return DB::transaction(function () use ($rental, $toLocationId, $returnedAt, $data, $user) {
            $this->move($rental, $toLocationId, $returnedAt, 'returned', $user);

            $rentedDays = max(1, $this->wholeDays($rental->checked_out_at, $returnedAt));
            $daysLate = $returnedAt->greaterThan($rental->due_at) ? $this->wholeDays($rental->due_at, $returnedAt) : 0;
            $lateFeeEnabled = (bool) $this->settings->get($rental->project, self::MODULE_SLUG, 'late_fee_enabled', false);
            $lateRate = $rental->late_fee_per_day ?? $rental->daily_rate;

            $rental->update([
                'status' => 'returned',
                'returned_at' => $returnedAt,
                'returned_to_location_id' => $toLocationId,
                'rented_days' => $rentedDays,
                'days_late' => $daysLate,
                'rental_amount' => $rental->daily_rate !== null ? round((float) $rental->daily_rate * $rentedDays, 2) : null,
                'late_fee' => $lateFeeEnabled ? round((float) ($lateRate ?? 0) * $daysLate, 2) : null,
                'notes' => $data['notes'] ?? $rental->notes,
            ]);
            $this->announce($rental, 'returned', $user);

            return $rental;
        });
    }

    /** Only a reservation can be cancelled; an active rental is returned. */
    public function cancel(Rental $rental, ?string $reason = null, ?User $user = null): Rental
    {
        $this->assertStatus($rental, ['reserved'], 'cancelled');

        $rental->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => $reason ? trim(($rental->notes ? $rental->notes . "\n" : '') . "Cancelled: {$reason}") : $rental->notes,
        ]);
        $this->announce($rental, 'cancelled', $user);

        return $rental;
    }

    /** Whole days between two moments, part days rounded up. */
    protected function wholeDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) ceil(max(0, $to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    protected function assertDueAfterStart(CarbonInterface $startsAt, CarbonInterface $dueAt): void
    {
        if (!$dueAt->greaterThan($startsAt)) {
            throw ApiException::invalid('Validation failed', ['due_at' => ['The due date must be after the start']]);
        }
    }

    protected function assertStatus(Rental $rental, array $allowed, string $action): void
    {
        if (!in_array($rental->status, $allowed, true)) {
            throw ApiException::conflict("A {$rental->status} rental cannot be {$action}");
        }
    }

    protected function move(Rental $rental, int $locationId, CarbonInterface $at, string $step, ?User $user): void
    {
        $this->movements->recordMovement($rental->asset, [
            'to_location_id' => $locationId,
            'source' => self::MOVEMENT_SOURCE,
            'occurred_at' => $at,
            'recorded_by' => $user?->id,
            'metadata' => [
                'rental_id' => $rental->id,
                'rental_reference' => $rental->reference,
                'customer_id' => $rental->customer_id,
                'step' => $step,
            ],
        ]);
    }

    protected function announce(Rental $rental, string $action, ?User $user): void
    {
        $rental->load('customer', 'asset');
        RentalChanged::dispatch($rental, $action);

        $this->audit->logActivity(
            "rental.{$action}",
            'rental',
            $rental->id,
            ['status' => $rental->status, 'asset_id' => $rental->asset_id, 'customer_id' => $rental->customer_id],
            $user?->id,
            $rental->organization_id,
            $rental->project_id,
        );
    }
}
