<?php

namespace App\Domain\Deliveries\Services;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Deliveries\Fleet;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Models\DeliveryEvent;
use App\Domain\Deliveries\Models\DeliveryItem;
use App\Domain\Deliveries\Models\DeliveryStop;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deliveries of cylinders, from planning to the last unloaded cylinder. Every operation locks the
 * delivery row, so the phone and the web cannot overtake each other.
 *
 * Outgoing: plan → load at the warehouse (scan) → depart → per customer: arrive, unload (scan),
 * done → complete. Departing records the outgoing asset transaction (status off_site) of every
 * loaded cylinder; cylinders a customer did not take come back with an incoming one on completion.
 *
 * Incoming: plan → depart → per customer: arrive, load (scan), done → back at the warehouse,
 * unload (scan) → complete. Completing records the incoming asset transaction of every unloaded
 * cylinder; one that was loaded but not unloaded stays off_site and is marked missing.
 *
 * Between those scans the phone reports departures, positions, problems and arrivals (report()).
 */
final class DeliveryService
{
    private const AUDIT = ['number', 'direction', 'status', 'vehicle_id', 'driver_id', 'helper_id', 'planned_date', 'expected_return_date', 'notes'];

    /** A report may reach the server this much later than it happened (no signal on the road). */
    private const MAX_DELAY_HOURS = 24;

    public function __construct(
        private readonly Fleet $fleet,
        private readonly AssetTransactionService $transactions,
        private readonly TransactionLog $log,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
    ) {}

    // ------------------------------------------------------------------ planning

    /** @param array<string, mixed> $data validated input */
    public function create(array $data): Delivery
    {
        $direction = $data['direction'];
        $this->assertMayRecord($direction);

        return DB::transaction(function () use ($data, $direction) {
            $delivery = new Delivery;
            $delivery->forceFill([
                ...$this->planned($direction, $data),
                'direction' => $direction,
                'number' => $this->log->nextNumber($direction === Delivery::OUTGOING ? TransactionType::OUTGOING : TransactionType::INCOMING),
                'created_by' => $this->userId(),
            ])->save();
            $delivery->refresh();
            $this->syncStops($delivery, $data['stops']);
            $this->event($delivery, 'created');
            $this->audit->record('delivery.created', $delivery, after: $this->auditable($delivery));

            return $delivery;
        });
    }

    /** @param array<string, mixed> $data validated input */
    public function update(Delivery $delivery, array $data): Delivery
    {
        $this->assertMayRecord($delivery->direction);

        return $this->locked($delivery, function (Delivery $delivery) use ($data) {
            if ($delivery->status !== Delivery::DRAFT) {
                throw ApiException::conflict('DELIVERY_STARTED', 'Transaksi yang sudah mulai muat atau berangkat tidak dapat diubah.');
            }
            $before = $this->auditable($delivery);
            $delivery->forceFill($this->planned($delivery->direction, $data))->save();
            $this->syncStops($delivery, $data['stops']);
            $this->event($delivery, 'updated');
            [$b, $a] = AuditLogger::diff($before, $this->auditable($delivery));
            $this->audit->record('delivery.updated', $delivery, before: $b, after: $a);

            return $delivery;
        });
    }

    public function cancel(Delivery $delivery, string $reason): Delivery
    {
        $this->assertMayRecord($delivery->direction);

        return $this->locked($delivery, function (Delivery $delivery) use ($reason) {
            $this->assertOpen($delivery);
            $leftEmpty = ! $delivery->isOutgoing() && ! $delivery->items()->where('state', DeliveryItem::LOADED)->exists();
            if ($delivery->status === Delivery::IN_TRANSIT && ! $leftEmpty) {
                throw ApiException::conflict('DELIVERY_ON_THE_WAY', 'Kendaraan sudah membawa tabung; selesaikan perjalanannya, tidak dapat dibatalkan.');
            }
            $this->each($delivery->items()->where('active', true)->get(), ['state' => DeliveryItem::CANCELLED, 'active' => false]);
            $delivery->forceFill(['status' => Delivery::CANCELLED, 'ended_at' => now(), 'end_note' => $reason])->save();
            $this->event($delivery, 'cancelled', ['note' => $reason]);
            $this->audit->record('delivery.cancelled', $delivery, metadata: ['reason' => $reason]);

            return $delivery;
        });
    }

    // ------------------------------------------------------------------ on the way (phone)

    /**
     * The vehicle, driver and helper, entered on the phone before leaving.
     *
     * @param  array<string, mixed>  $data  vehicle_id?, driver_id, helper_id?, and where/when (meta)
     */
    public function setCrew(Delivery $delivery, array $data): Delivery
    {
        return $this->locked($delivery, function (Delivery $delivery) use ($data) {
            if ($this->alreadyReported($delivery, $data)) {
                return $delivery;
            }
            if (! in_array($delivery->status, [Delivery::DRAFT, Delivery::LOADING], true)) {
                throw ApiException::conflict('DELIVERY_STARTED', 'Kendaraan, supir, dan kernet hanya dapat diisi sebelum berangkat.');
            }
            $changes = ['driver_id' => $data['driver_id'], 'helper_id' => $data['helper_id'] ?? null];
            if (($data['vehicle_id'] ?? null) !== null) {
                $changes['vehicle_id'] = $data['vehicle_id'];
            }
            $this->assertCrew($delivery, $changes);
            $before = $this->auditable($delivery);
            $delivery->forceFill($changes)->save();
            $delivery->load(['driver', 'helper', 'vehicle']);
            $this->event($delivery, 'crew', [...$data, 'note' => implode(', ', array_filter([
                'Supir '.$delivery->driver?->name,
                $delivery->helper ? 'Kernet '.$delivery->helper->name : null,
                $this->fleet->plate($delivery->vehicle),
            ]))]);
            [$b, $a] = AuditLogger::diff($before, $this->auditable($delivery));
            if ($a !== []) {
                $this->audit->record('delivery.crew_set', $delivery, before: $b, after: $a);
            }

            return $delivery;
        });
    }

    /**
     * A scanned (or typed) cylinder is loaded or unloaded.
     *
     * @param  array<string, mixed>  $data  phase (load|unload), code, stop_id?, and where/when (meta)
     * @return array{item: DeliveryItem, already: bool} already: it had been scanned before
     */
    public function scan(Delivery $delivery, array $data): array
    {
        return $this->locked($delivery, function (Delivery $delivery) use ($data) {
            $this->assertOpen($delivery);
            $asset = $this->cylinder($delivery, $data['code']);

            return $data['phase'] === 'load'
                ? $this->load($delivery, $asset, $data)
                : $this->unload($delivery, $asset, $data);
        });
    }

    /** Takes back a load scan made by mistake, while the cylinder has not moved yet. */
    public function unscan(Delivery $delivery, array $data): Delivery
    {
        return $this->locked($delivery, function (Delivery $delivery) use ($data) {
            $item = $delivery->items()->with('stop')->where('asset_id', $data['asset_id'])->where('state', DeliveryItem::LOADED)->first()
                ?? throw ApiException::unprocessable('NOT_LOADED', 'Tabung ini belum di-scan muat.');
            $mayUndo = $delivery->isOutgoing() ? $delivery->status === Delivery::LOADING : ! $item->stop->isClosed();
            if (! $mayUndo) {
                throw ApiException::conflict('DELIVERY_STARTED', 'Tabung sudah dibawa; scan muatnya tidak dapat dibatalkan.');
            }
            if ($item->planned) {
                $item->forceFill(['state' => DeliveryItem::PLANNED, 'loaded_at' => null, 'loaded_by' => null])->save();
            } else {
                $item->delete();
            }
            $this->event($delivery, 'unscan', [...$data, 'stop_id' => $item->stop_id]);

            return $delivery;
        });
    }

    /**
     * What the driver reports between loading and unloading (DeliveryEvent::REPORTS).
     *
     * @param  array<string, mixed>  $data  type, stop_id?, note?, receiver_name?, and where/when (meta)
     */
    public function report(Delivery $delivery, array $data): Delivery
    {
        return $this->locked($delivery, function (Delivery $delivery) use ($data) {
            if ($this->alreadyReported($delivery, $data)) {
                return $delivery;
            }
            $this->assertOpen($delivery);
            $type = $data['type'];
            $data['note'] = trim((string) ($data['note'] ?? '')) ?: null;
            if ($type === 'depart') {
                $this->depart($delivery, $data);

                return $delivery;
            }
            if ($delivery->status !== Delivery::IN_TRANSIT) {
                throw ApiException::conflict('DELIVERY_NOT_STARTED', 'Perjalanan belum dimulai. Tekan Berangkat terlebih dahulu.');
            }
            match ($type) {
                'arrive' => $this->arrive($delivery, $this->stop($delivery, $data), $data),
                'stop_done' => $this->finishStop($delivery, $this->stop($delivery, $data), $data),
                'return' => $this->backAtWarehouse($delivery, $data),
                'completed' => $this->complete($delivery, $data),
                'issue' => $data['note'] === null ? throw self::fieldError('note', 'Jelaskan kendalanya.') : $this->event($delivery, 'issue', $data),
                'position' => ($data['latitude'] ?? null) === null ? throw self::fieldError('latitude', 'Posisi tidak tersedia. Aktifkan lokasi di HP.') : $this->event($delivery, 'position', $data),
                'resume' => $this->event($delivery, 'resume', $data),
            };

            return $delivery;
        });
    }

    // ------------------------------------------------------------------ steps

    /** @return array{item: DeliveryItem, already: bool} */
    private function load(Delivery $delivery, Asset $asset, array $data): array
    {
        $outgoing = $delivery->isOutgoing();
        if ($outgoing && $delivery->status === Delivery::IN_TRANSIT) {
            throw ApiException::conflict('DELIVERY_STARTED', 'Kendaraan sudah berangkat; tabung tidak dapat dimuat lagi.');
        }
        if (! $outgoing && $delivery->status !== Delivery::IN_TRANSIT) {
            throw ApiException::conflict('DELIVERY_NOT_STARTED', 'Tekan Berangkat terlebih dahulu, lalu scan muat di customer.');
        }
        if (! $outgoing && $delivery->returned_at !== null) {
            throw ApiException::conflict('DELIVERY_RETURNED', 'Kendaraan sudah tiba di gudang; tabung tidak dapat dimuat lagi.');
        }

        $item = $delivery->items()->where('asset_id', $asset->id)->first();
        if ($item !== null && $item->state === DeliveryItem::LOADED) {
            return ['item' => $item, 'already' => true];
        }
        if ($item !== null && $item->state !== DeliveryItem::PLANNED) {
            throw ApiException::conflict('ALREADY_UNLOADED', "{$asset->name} sudah dibongkar di transaksi ini.");
        }
        if ($item === null && $outgoing) {
            $this->assertChosen($delivery, $asset);
        }
        // A cylinder chosen beforehand keeps its customer; at a customer it counts for where it is scanned.
        $stop = $item !== null && $outgoing ? $item->stop : $this->stop($delivery, $data);
        if ($item === null && ! $outgoing) {
            $this->assertChosen($delivery, $asset, $stop);
        }
        if ($item !== null && ! $outgoing && $item->planned && $item->stop_id !== $stop->id) {
            throw ApiException::unprocessable('NOT_IN_DELIVERY', "{$asset->name} ({$asset->serial_number}) tidak termasuk tabung yang dipilih untuk {$stop->customer->name} di transaksi ini.");
        }
        $this->assertLoadable($delivery->direction, $asset);
        if ($stop->isClosed()) {
            throw ApiException::conflict('STOP_CLOSED', "{$stop->customer->name} sudah selesai.");
        }

        if ($item === null) {
            AssetTransactionService::assertNotHeldByRun([$asset]);
            $item = (new DeliveryItem)->forceFill(['delivery_id' => $delivery->id, 'asset_id' => $asset->id, 'planned' => false]);
        }
        $item->forceFill([
            'stop_id' => $stop->id, 'state' => DeliveryItem::LOADED, 'active' => true,
            'loaded_at' => $this->happenedAt($data), 'loaded_by' => $this->userId(),
        ])->save();
        if ($outgoing && $delivery->status === Delivery::DRAFT) {
            $delivery->forceFill(['status' => Delivery::LOADING])->save();
        }
        if (! $outgoing) {
            $this->markArrived($delivery, $stop, $data);
        }
        $this->event($delivery, 'load', [...$data, 'asset_id' => $asset->id, 'stop_id' => $stop->id]);

        return ['item' => $item, 'already' => false];
    }

    /** @return array{item: DeliveryItem, already: bool} */
    private function unload(Delivery $delivery, Asset $asset, array $data): array
    {
        if ($delivery->status !== Delivery::IN_TRANSIT) {
            throw ApiException::conflict('DELIVERY_NOT_STARTED', 'Perjalanan belum dimulai. Tekan Berangkat terlebih dahulu.');
        }
        $item = $delivery->items()->with('stop.customer')->where('asset_id', $asset->id)->first();
        if ($item !== null && $item->state === DeliveryItem::UNLOADED) {
            return ['item' => $item, 'already' => true];
        }
        if ($item === null || $item->state !== DeliveryItem::LOADED) {
            throw ApiException::unprocessable('NOT_LOADED', "{$asset->name} ({$asset->serial_number}) tidak ada di muatan kendaraan ini.");
        }

        if ($delivery->isOutgoing()) {
            $stop = $this->stop($delivery, $data);
            if ($stop->id !== $item->stop_id) {
                throw ApiException::conflict('OTHER_CUSTOMER', "{$asset->name} ({$asset->serial_number}) untuk {$item->stop->customer->name}, bukan {$stop->customer->name}.");
            }
            if ($stop->isClosed()) {
                throw ApiException::conflict('STOP_CLOSED', "{$stop->customer->name} sudah selesai.");
            }
            $this->markArrived($delivery, $stop, $data);
        } else {
            $this->assertStopsClosed($delivery);
            $this->markReturned($delivery, $data);
        }
        $item->forceFill([
            'state' => DeliveryItem::UNLOADED,
            // Incoming: held until the completed trip records it as back.
            'active' => ! $delivery->isOutgoing(),
            'unloaded_at' => $this->happenedAt($data), 'unloaded_by' => $this->userId(),
        ])->save();
        $this->event($delivery, 'unload', [...$data, 'asset_id' => $asset->id, 'stop_id' => $delivery->isOutgoing() ? $item->stop_id : null]);

        return ['item' => $item, 'already' => false];
    }

    private function depart(Delivery $delivery, array $data): void
    {
        if ($delivery->status === Delivery::IN_TRANSIT) {
            throw ApiException::conflict('ALREADY_DEPARTED', 'Perjalanan ini sudah berangkat.');
        }
        if ($delivery->vehicle_id === null || $delivery->driver_id === null) {
            throw ApiException::unprocessable('CREW_REQUIRED', 'Isi kendaraan dan supir sebelum berangkat.');
        }
        $this->assertCrew($delivery, ['vehicle_id' => $delivery->vehicle_id, 'driver_id' => $delivery->driver_id, 'helper_id' => $delivery->helper_id]);
        $this->assertCrewFree($delivery);

        if ($delivery->isOutgoing()) {
            $loaded = $delivery->items()->with('stop.customer')->where('state', DeliveryItem::LOADED)->get();
            if ($loaded->isEmpty()) {
                throw ApiException::unprocessable('NOTHING_LOADED', 'Scan muat minimal satu tabung sebelum berangkat.');
            }
            $this->each($delivery->items()->where('state', DeliveryItem::PLANNED)->get(), ['state' => DeliveryItem::NOT_LOADED, 'active' => false]);
            $delivery->stops()->whereNotIn('id', $loaded->pluck('stop_id')->unique())->update(['status' => DeliveryStop::SKIPPED]);
            foreach ($loaded->groupBy('stop_id') as $items) {
                $customer = $items->first()->stop->customer;
                $this->transactions->recordForDelivery(TransactionType::OUTGOING, $items->pluck('asset_id')->all(), $delivery->number, [
                    'outgoing_nature' => OutgoingReason::TEMPORARY,
                    'reason_code' => 'sent_to_project',
                    'counterparty_name' => $customer->name,
                    'counterparty_contact' => $customer->phone,
                    'document_number' => $delivery->number,
                    'due_date' => max($this->log->today(), ($delivery->expected_return_date ?? $delivery->planned_date)->toDateString()),
                    'notes' => $delivery->notes,
                ]);
            }
        }
        try {
            $delivery->forceFill(['status' => Delivery::IN_TRANSIT, 'departed_at' => $this->happenedAt($data)])->save();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('CREW_BUSY', 'Kendaraan, supir, atau kernet masih dalam perjalanan lain.');
        }
        $this->event($delivery, 'depart', $data);
        $this->audit->record('delivery.departed', $delivery, after: $this->auditable($delivery));
    }

    private function arrive(Delivery $delivery, DeliveryStop $stop, array $data): void
    {
        if ($stop->status !== DeliveryStop::PENDING) {
            throw ApiException::conflict('ALREADY_ARRIVED', "Kedatangan di {$stop->customer->name} sudah tercatat.");
        }
        $this->markArrived($delivery, $stop, $data, $data['note']);
    }

    private function finishStop(Delivery $delivery, DeliveryStop $stop, array $data): void
    {
        if ($stop->isClosed()) {
            throw ApiException::conflict('STOP_CLOSED', "{$stop->customer->name} sudah selesai.");
        }
        $left = $delivery->isOutgoing() ? $stop->items()->where('state', DeliveryItem::LOADED)->count() : 0;
        if ($left > 0 && $data['note'] === null) {
            throw self::fieldError('note', "{$left} tabung belum di-scan bongkar. Scan tabungnya, atau isi alasan bila dibawa kembali.");
        }
        $this->markArrived($delivery, $stop, $data);
        $stop->forceFill([
            'status' => DeliveryStop::DONE, 'done_at' => $this->happenedAt($data),
            'receiver_name' => trim((string) ($data['receiver_name'] ?? '')) ?: null, 'note' => $data['note'],
        ])->save();
        if (! $delivery->isOutgoing()) {
            // Chosen beforehand but not handed over by the customer.
            $this->each($stop->items()->where('state', DeliveryItem::PLANNED)->get(), ['state' => DeliveryItem::NOT_LOADED, 'active' => false]);
        }
        $this->event($delivery, 'stop_done', [...$data, 'stop_id' => $stop->id]);
    }

    private function backAtWarehouse(Delivery $delivery, array $data): void
    {
        if ($delivery->returned_at !== null) {
            throw ApiException::conflict('ALREADY_RETURNED', 'Kedatangan di gudang sudah tercatat.');
        }
        $this->assertStopsClosed($delivery);
        $this->markReturned($delivery, $data);
    }

    private function complete(Delivery $delivery, array $data): void
    {
        $this->assertStopsClosed($delivery);
        $left = $delivery->items()->where('state', DeliveryItem::LOADED)->get();
        if ($left->isNotEmpty() && $data['note'] === null) {
            throw self::fieldError('note', $delivery->isOutgoing()
                ? "{$left->count()} tabung dibawa kembali. Isi keterangannya."
                : "{$left->count()} tabung belum di-scan bongkar di gudang. Scan tabungnya, atau isi alasan selisihnya.");
        }

        if ($delivery->isOutgoing()) {
            if ($left->isNotEmpty()) {
                $this->markReturned($delivery, $data);
                $this->transactions->recordForDelivery(TransactionType::INCOMING, $left->pluck('asset_id')->all(), null, [
                    'document_number' => $delivery->number, 'notes' => $data['note'],
                ]);
            }
            $this->each($left, ['state' => DeliveryItem::RETURNED, 'active' => false]);
        } else {
            $back = $delivery->items()->where('state', DeliveryItem::UNLOADED)->get();
            if ($back->isNotEmpty()) {
                $this->transactions->recordForDelivery(TransactionType::INCOMING, $back->pluck('asset_id')->all(), $delivery->number, [
                    'document_number' => $delivery->number, 'notes' => $delivery->notes,
                ]);
            }
            $this->each($back, ['active' => false]);
            $this->each($left, ['state' => DeliveryItem::MISSING, 'active' => false]);
        }
        $delivery->forceFill(['status' => Delivery::COMPLETED, 'ended_at' => $this->happenedAt($data), 'end_note' => $data['note']])->save();
        $this->event($delivery, 'completed', $data);
        $this->audit->record('delivery.completed', $delivery, after: [...$this->auditable($delivery), 'not_unloaded' => $left->count()]);
    }

    private function markArrived(Delivery $delivery, DeliveryStop $stop, array $data, ?string $note = null): void
    {
        if ($stop->status !== DeliveryStop::PENDING) {
            return;
        }
        $stop->forceFill(['status' => DeliveryStop::ARRIVED, 'arrived_at' => $this->happenedAt($data)])->save();
        $this->event($delivery, 'arrive', [...$data, 'stop_id' => $stop->id, 'note' => $note], own: $data['type'] ?? null);
    }

    private function markReturned(Delivery $delivery, array $data): void
    {
        if ($delivery->returned_at === null) {
            $delivery->forceFill(['returned_at' => $this->happenedAt($data)])->save();
            $this->event($delivery, 'return', $data, own: $data['type'] ?? null);
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> the columns entered while planning */
    private function planned(string $direction, array $data): array
    {
        $return = null;
        if ($direction === Delivery::OUTGOING) {
            $return = $data['expected_return_date'] ?? CarbonImmutable::parse($data['planned_date'])->addDays((int) config('edition.return_days'))->toDateString();
            if ($return < $data['planned_date']) {
                throw self::fieldError('expected_return_date', 'Perkiraan tabung kembali tidak boleh sebelum tanggal kirim.');
            }
        }
        $vehicle = $data['vehicle_id'] ?? null;
        if ($vehicle !== null) {
            $this->assertVehicle($vehicle);
        }

        return ['planned_date' => $data['planned_date'], 'expected_return_date' => $return, 'vehicle_id' => $vehicle, 'notes' => $data['notes'] ?? null];
    }

    /**
     * Replaces the stops and planned cylinders of a delivery that has not started.
     *
     * @param  list<array{customer_id: string, asset_ids?: list<string>}>  $stops
     */
    private function syncStops(Delivery $delivery, array $stops): void
    {
        $customers = Customer::query()->whereKey(array_column($stops, 'customer_id'))->where('status', MasterStatus::ACTIVE)->pluck('id')->flip();
        foreach ($stops as $i => $stop) {
            if (! $customers->has($stop['customer_id'])) {
                throw self::fieldError("stops.{$i}.customer_id", 'Customer tidak ditemukan atau tidak aktif.');
            }
        }
        $assetIds = array_merge(...array_map(fn (array $s) => $s['asset_ids'] ?? [], $stops));
        if (count($assetIds) !== count(array_unique($assetIds))) {
            throw self::fieldError('stops', 'Satu tabung tidak dapat dipilih untuk dua customer.');
        }
        if ($delivery->isOutgoing() && $assetIds === []) {
            throw self::fieldError('stops', 'Pilih minimal satu tabung.');
        }

        // Locked in id order, so two plans with the same cylinders cannot deadlock.
        $assets = $this->fleet->cylinders()->whereKey($assetIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($stops as $i => $stop) {
            foreach ($stop['asset_ids'] ?? [] as $id) {
                $asset = $assets[$id] ?? throw self::fieldError("stops.{$i}.asset_ids", 'Tabung tidak ditemukan.');
                $this->assertLoadable($delivery->direction, $asset, "stops.{$i}.asset_ids");
            }
        }

        $delivery->items()->delete();
        $delivery->stops()->delete();
        AssetTransactionService::assertNotHeldByRun($assets->values()->all());
        try {
            foreach (array_values($stops) as $i => $row) {
                $stop = (new DeliveryStop)->forceFill(['delivery_id' => $delivery->id, 'sequence' => $i + 1, 'customer_id' => $row['customer_id']]);
                $stop->save();
                foreach ($row['asset_ids'] ?? [] as $assetId) {
                    (new DeliveryItem)->forceFill(['delivery_id' => $delivery->id, 'stop_id' => $stop->id, 'asset_id' => $assetId])->save();
                }
            }
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('ASSET_LOCKED', 'Salah satu tabung baru saja dipakai transaksi lain. Muat ulang lalu pilih lagi.');
        }
        $delivery->unsetRelations();
    }

    /** The cylinder a label or a typed No Manufaktur names. */
    private function cylinder(Delivery $delivery, string $code): Asset
    {
        $code = trim($code);
        // What is already on this delivery may be scanned whatever the user's data scope.
        $own = Asset::query()->whereIn('id', $delivery->items()->select('asset_id'));
        foreach ([$own, $this->fleet->cylinders()] as $query) {
            $asset = (Str::isUuid($code) ? (clone $query)->whereKey(strtolower($code))->first() : null)
                ?? (clone $query)->whereRaw('lower(serial_number) = ?', [mb_strtolower($code)])->first();
            if ($asset !== null) {
                return Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            }
        }

        throw new ApiException('CYLINDER_NOT_FOUND', 'Tabung dengan kode ini tidak ditemukan.', 404);
    }

    private function assertLoadable(string $direction, Asset $asset, ?string $field = null): void
    {
        $message = match (true) {
            $asset->isArchived() => 'nonaktif',
            in_array($asset->status, Fleet::loadableStatuses($direction), true) => null,
            $direction === Delivery::OUTGOING => 'berstatus '.$asset->statusLabel().' sehingga tidak dapat dikirim',
            default => 'tidak sedang berada di customer',
        };
        if ($message !== null) {
            $message = "{$asset->name} ({$asset->serial_number}) {$message}.";
            throw $field === null ? ApiException::conflict('INVALID_ASSET_STATUS', $message) : self::fieldError($field, $message);
        }
    }

    /** The stop an operation is about; optional when there is only one. */
    private function stop(Delivery $delivery, array $data): DeliveryStop
    {
        $stops = $delivery->stops()->with('customer')->get();
        $id = $data['stop_id'] ?? null;
        $stop = $id === null ? ($stops->count() === 1 ? $stops->first() : null) : $stops->firstWhere('id', $id);

        return $stop ?? throw self::fieldError('stop_id', 'Pilih customer tujuan.');
    }

    /**
     * Cylinders chosen when the transaction was made on the web are the only ones it takes; one
     * that was not chosen is refused, so a wrong label shows at once instead of joining the load.
     * Without chosen cylinders (an incoming pick-up, or a customer without any), whatever is scanned joins.
     */
    private function assertChosen(Delivery $delivery, Asset $asset, ?DeliveryStop $stop = null): void
    {
        $chosen = $delivery->items()->where('planned', true)
            ->when($stop !== null, fn ($query) => $query->where('stop_id', $stop->id))
            ->exists();
        if ($chosen) {
            $where = $stop !== null ? " untuk {$stop->customer->name}" : '';
            throw ApiException::unprocessable('NOT_IN_DELIVERY', "{$asset->name} ({$asset->serial_number}) tidak termasuk tabung yang dipilih{$where} di transaksi ini.");
        }
    }

    private function assertStopsClosed(Delivery $delivery): void
    {
        $open = $delivery->stops()->with('customer')->get()->first(fn (DeliveryStop $s) => ! $s->isClosed());
        if ($open !== null) {
            throw ApiException::conflict('STOP_OPEN', "{$open->customer->name} belum selesai.");
        }
    }

    private function assertOpen(Delivery $delivery): void
    {
        if (! $delivery->isOpen()) {
            throw ApiException::conflict('DELIVERY_ENDED', 'Transaksi ini sudah '.mb_strtolower(Delivery::STATUSES[$delivery->status]).'.');
        }
    }

    private function assertVehicle(string $id): void
    {
        if (! $this->fleet->vehicles()->whereKey($id)->exists()) {
            throw self::fieldError('vehicle_id', 'Kendaraan tidak ditemukan atau tidak dapat dipakai.');
        }
    }

    /** @param array<string, mixed> $crew */
    private function assertCrew(Delivery $delivery, array $crew): void
    {
        $vehicle = $crew['vehicle_id'] ?? $delivery->vehicle_id;
        if ($vehicle === null) {
            throw self::fieldError('vehicle_id', 'Kendaraan wajib dipilih.');
        }
        // One chosen on the web stays usable for a driver with a narrower data scope.
        if ($vehicle !== $delivery->vehicle_id) {
            $this->assertVehicle($vehicle);
        }
        $employee = fn (?string $id, array $roles) => Employee::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->withDeliveryRole($roles)->exists();
        // Supir and Kernet swap: either may drive or help on a trip.
        if (! $employee($crew['driver_id'], [Department::DRIVER, Department::HELPER])) {
            throw self::fieldError('driver_id', 'Supir tidak ditemukan atau tidak aktif.');
        }
        if ($crew['helper_id'] !== null && ! $employee($crew['helper_id'], [Department::HELPER, Department::DRIVER])) {
            throw self::fieldError('helper_id', 'Kernet tidak ditemukan atau tidak aktif.');
        }
        if ($crew['helper_id'] === $crew['driver_id']) {
            throw self::fieldError('helper_id', 'Supir dan kernet harus orang yang berbeda.');
        }
    }

    /** A vehicle, a driver and a helper are on one trip at a time. */
    private function assertCrewFree(Delivery $delivery): void
    {
        $people = array_filter([$delivery->driver_id, $delivery->helper_id]);
        $other = Delivery::query()->where('status', Delivery::IN_TRANSIT)->whereKeyNot($delivery->id)
            ->where(fn ($q) => $q->where('vehicle_id', $delivery->vehicle_id)->orWhereIn('driver_id', $people)->orWhereIn('helper_id', $people))
            ->first();
        if ($other !== null) {
            $what = $other->vehicle_id === $delivery->vehicle_id ? 'Kendaraan' : 'Supir atau kernet';
            throw ApiException::conflict('CREW_BUSY', "{$what} masih dalam perjalanan {$other->number}.");
        }
    }

    private function assertMayRecord(string $direction): void
    {
        if (! $this->tenancy->tenant()->can($direction === Delivery::OUTGOING ? 'asset.outgoing' : 'asset.incoming')) {
            throw ApiException::forbidden();
        }
    }

    /**
     * @template T
     *
     * @param  callable(Delivery): T  $fn
     * @return T
     */
    private function locked(Delivery $delivery, callable $fn): mixed
    {
        return DB::transaction(fn () => $fn(Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail()));
    }

    /** The phone sends a report again when it got no answer. */
    private function alreadyReported(Delivery $delivery, array $data): bool
    {
        return ($data['client_id'] ?? null) !== null && $delivery->events()->where('client_id', $data['client_id'])->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|false|null  $own  type the phone reported; another type recorded along with it does not take its client_id
     */
    private function event(Delivery $delivery, string $type, array $data = [], string|false|null $own = false): void
    {
        $event = new DeliveryEvent;
        $event->forceFill([
            'delivery_id' => $delivery->id,
            'stop_id' => $data['stop_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'type' => $type,
            'note' => $data['note'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy' => isset($data['accuracy']) ? min(999999, round((float) $data['accuracy'], 1)) : null,
            'client_id' => $own === false || $own === $type ? ($data['client_id'] ?? null) : null,
            'actor_id' => $this->userId(),
            'recorded_at' => $this->happenedAt($data),
        ])->save();
    }

    /** The phone's time, unless it is implausible (wrong clock). */
    private function happenedAt(array $data): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $at = isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at']) : $now;

        return $at > $now || $at < $now->subHours(self::MAX_DELAY_HOURS) ? $now : $at;
    }

    /**
     * @param  Collection<int, DeliveryItem>  $items
     * @param  array<string, mixed>  $changes
     */
    private function each(Collection $items, array $changes): void
    {
        foreach ($items as $item) {
            $item->forceFill($changes)->save();
        }
    }

    private function userId(): string
    {
        return $this->tenancy->tenant()->userId();
    }

    /** @return array<string, mixed> */
    private function auditable(Delivery $delivery): array
    {
        return [
            ...$delivery->only(self::AUDIT),
            'planned_date' => $delivery->planned_date?->toDateString(),
            'expected_return_date' => $delivery->expected_return_date?->toDateString(),
        ];
    }

    private static function fieldError(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('VALIDATION_FAILED', $message, ['fields' => [$field => [$message]]]);
    }
}
