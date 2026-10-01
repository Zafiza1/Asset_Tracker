<?php

namespace App\Services;

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

    public function bindDevice(Device $device, Asset $asset): DeviceBinding
    {
        // Check if device is already bound to this asset
        $existingBinding = $device->currentBinding()->where('asset_id', $asset->id)->first();

        if ($existingBinding) {
            return $existingBinding;
        }

        // Unbind from any other asset
        $device->currentBinding()->update([
            'unbound_at' => now(),
        ]);

        return DeviceBinding::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'project_id' => $device->project_id,
            'bound_at' => now(),
        ]);
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
