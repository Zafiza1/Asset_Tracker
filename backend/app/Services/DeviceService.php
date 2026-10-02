<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Asset;
use App\Models\DeviceBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DeviceService
{
    public function createDevice(array $data): Device
    {
        return DB::transaction(function () use ($data) {
            $device = Device::create([
                'organization_id' => $data['organization_id'],
                'project_id' => $data['project_id'],
                'device_type_id' => $data['device_type_id'],
                'integration_id' => $data['integration_id'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'name' => $data['name'],
                'status' => $data['status'] ?? 'offline',
                'metadata' => $data['metadata'] ?? [],
            ]);

            return $device;
        });
    }

    public function updateDevice(Device $device, array $data): Device
    {
        $device->update([
            'serial_number' => $data['serial_number'] ?? $device->serial_number,
            'name' => $data['name'] ?? $device->name,
            'status' => $data['status'] ?? $device->status,
            'metadata' => $data['metadata'] ?? $device->metadata,
        ]);

        return $device->fresh();
    }

    public function updateDeviceStatus(Device $device, string $status): Device
    {
        $device->update([
            'status' => $status,
            'last_seen_at' => now(),
        ]);

        return $device->fresh();
    }

    public function deleteDevice(Device $device): bool
    {
        return DB::transaction(function () use ($device) {
            // Unbind device from any asset
            $device->currentBinding()->update([
                'unbound_at' => now(),
            ]);

            return $device->delete();
        });
    }

    /**
     * Bind a device to an asset (Device → Device Binding → Asset).
     *
     * A device bound to a different asset is never silently moved: the caller
     * must unbind it first or pass $replace = true (an explicit hardware swap),
     * which closes the old binding and records why.
     */
    public function bindDevice(Device $device, Asset $asset, bool $replace = false, ?string $reason = null): DeviceBinding
    {
        if ($device->project_id !== $asset->project_id) {
            throw ApiException::invalid('Validation failed', [
                'asset_id' => ['Device and asset must belong to the same project'],
            ]);
        }

        return DB::transaction(function () use ($device, $asset, $replace, $reason) {
            // Serialize concurrent bind attempts on the same device.
            Device::withoutGlobalScopes()->whereKey($device->id)->lockForUpdate()->first();

            $current = $device->currentBinding()->first();

            if ($current && $current->asset_id === $asset->id) {
                return $current;
            }

            if ($current && !$replace) {
                throw ApiException::conflict(
                    'Device is already bound to another asset. Unbind it first or pass "replace": true.'
                );
            }

            if ($current) {
                $current->update([
                    'unbound_at' => now(),
                    'metadata' => array_merge($current->metadata ?? [], [
                        'unbind_reason' => $reason ?? 'replaced',
                        'replaced_by_asset_id' => $asset->id,
                    ]),
                ]);
            }

            return DeviceBinding::create([
                'device_id' => $device->id,
                'asset_id' => $asset->id,
                'project_id' => $device->project_id,
                'bound_at' => now(),
                'metadata' => $reason ? ['reason' => $reason] : null,
            ]);
        });
    }

    public function unbindDevice(Device $device): bool
    {
        $binding = $device->currentBinding()->first();

        if (!$binding) {
            return false;
        }

        $binding->update([
            'unbound_at' => now(),
        ]);

        return true;
    }

    public function getDeviceBindings(Device $device, bool $activeOnly = true)
    {
        $query = $device->bindings();

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderByDesc('bound_at')->get();
    }

    public function createDeviceType(array $data): DeviceType
    {
        return DeviceType::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'capabilities' => $data['capabilities'] ?? [],
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    public function updateDeviceType(DeviceType $deviceType, array $data): DeviceType
    {
        $deviceType->update([
            'name' => $data['name'] ?? $deviceType->name,
            'slug' => $data['slug'] ?? $deviceType->slug,
            'description' => $data['description'] ?? $deviceType->description,
            'capabilities' => $data['capabilities'] ?? $deviceType->capabilities,
            'metadata' => $data['metadata'] ?? $deviceType->metadata,
        ]);

        return $deviceType->fresh();
    }

    public function deleteDeviceType(DeviceType $deviceType): bool
    {
        // Check if there are devices using this type
        if ($deviceType->devices()->exists()) {
            throw new Exception('Cannot delete device type with associated devices');
        }

        return $deviceType->delete();
    }

    public function getDevicesByAsset(Asset $asset)
    {
        return Device::whereHas('currentBinding', function ($query) use ($asset) {
            $query->where('asset_id', $asset->id);
        })->get();
    }

    public function getDeviceHistory(Device $device)
    {
        return $device->bindings()
            ->with('asset')
            ->orderByDesc('bound_at')
            ->get();
    }
}
