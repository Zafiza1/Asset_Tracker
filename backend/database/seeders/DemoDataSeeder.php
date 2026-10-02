<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Location;
use App\Models\Project;
use App\Services\DeviceService;
use App\Services\IntegrationService;
use App\Services\MovementService;
use Illuminate\Database\Seeder;

/**
 * Demo data: locations, assets, integrations, devices, bindings, movements
 * and custom fields for the seeded demo projects. Everything here is project
 * configuration and runtime data created through Core services — the same
 * thing a customer does through the API — never platform code.
 */
class DemoDataSeeder extends Seeder
{
    public function run(IntegrationService $integrations, DeviceService $devices, MovementService $movements): void
    {
        foreach ($this->plan() as $projectSlug => $plan) {
            $project = Project::where('slug', $projectSlug)->first();

            if (!$project || Asset::where('project_id', $project->id)->exists()) {
                continue;
            }

            $scope = ['organization_id' => $project->organization_id, 'project_id' => $project->id];

            foreach ($plan['fields'] as $order => $field) {
                CustomField::create($scope + $field + ['entity_type' => 'asset', 'sort_order' => $order, 'active' => true, 'required' => false]);
            }

            $locations = [];
            foreach ($plan['locations'] as [$name, $type, $address, $lat, $lng]) {
                $locations[] = Location::create($scope + ['name' => $name, 'type' => $type, 'address' => $address, 'latitude' => $lat, 'longitude' => $lng]);
            }

            $integrationIds = [];
            foreach ($plan['integrations'] as $type) {
                $integration = $integrations->createIntegration($scope + [
                    'name' => strtoupper($type) . ' Gateway',
                    'type' => $type,
                    'provider' => "demo-{$type}-gateway",
                    'config' => ['endpoint' => "https://{$type}-gateway.example.com/api", 'api_key' => 'demo-' . bin2hex(random_bytes(8)), 'poll_interval' => 60],
                ]);
                // Demo only: shown as connected without contacting the fake endpoint.
                $integration->update(['status' => 'connected', 'last_connected_at' => now()]);
                $integrationIds[$type] = $integration->id;
            }

            foreach ($plan['assets'] as $index => [$serial, $name, $type, $status, $metadata]) {
                $asset = Asset::create($scope + compact('name', 'status', 'metadata') + ['serial_number' => $serial, 'asset_type' => $type]);

                // Two hops of history, ending at a location chosen per asset.
                $first = $locations[0];
                $last = $locations[($index % (count($locations) - 1)) + 1];
                $movements->recordMovement($asset, ['to_location_id' => $first->id, 'source' => 'manual', 'occurred_at' => now()->subDays(10 - $index)]);
                $movements->recordMovement($asset, ['to_location_id' => $last->id, 'source' => isset($integrationIds['gps']) ? 'gps' : 'rfid', 'occurred_at' => now()->subDays(3)->addHours($index)]);

                $number = str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT);
                foreach (['rfid' => ['rfid_tag', 'TAG'], 'gps' => ['gps_tracker', 'GPS']] as $integrationType => [$deviceType, $prefix]) {
                    if (!isset($integrationIds[$integrationType])) {
                        continue;
                    }
                    $device = Device::create($scope + [
                        'device_type_id' => DeviceType::where('slug', $deviceType)->value('id'),
                        'integration_id' => $integrationIds[$integrationType],
                        'serial_number' => "{$prefix}-{$number}",
                        'name' => "{$prefix} {$number}",
                        'status' => 'online',
                        'last_seen_at' => now(),
                    ]);
                    $devices->bindDevice($device, $asset, false, 'Initial demo binding');
                }

                // The last asset has not been seen for a day: shows as offline.
                $asset->forceFill(['last_seen_at' => $index === count($plan['assets']) - 1 ? now()->subDay() : now()])->saveQuietly();
            }

            // A spare reader not bound to any asset.
            if (isset($integrationIds['rfid'])) {
                Device::create($scope + [
                    'device_type_id' => DeviceType::where('slug', 'rfid_reader')->value('id'),
                    'integration_id' => $integrationIds['rfid'],
                    'serial_number' => 'RDR-00001',
                    'name' => 'Gate Reader 1',
                    'status' => 'online',
                    'last_seen_at' => now(),
                ]);
            }
        }

        $this->command?->info('Demo data seeded successfully.');
    }

    /**
     * @return array<string, array{fields: array, locations: array, integrations: string[], assets: array}>
     */
    protected function plan(): array
    {
        return [
            'cylinder-asset-tracker' => [
                'fields' => [
                    ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'select', 'options' => ['3 KG', '6 KG', '12 KG']],
                    ['key' => 'last_refill', 'label' => 'Last refill', 'type' => 'date'],
                ],
                'locations' => [
                    ['Main Warehouse', 'warehouse', 'Cilegon, Banten', -6.0025, 106.0110],
                    ['Filling Plant', 'factory', 'Serang, Banten', -6.1200, 106.1500],
                    ['Customer Site A', 'customer_site', 'Jakarta Barat', -6.1683, 106.7588],
                    ['Delivery Truck 01', 'vehicle', null, null, null],
                ],
                'integrations' => ['rfid', 'gps'],
                'assets' => [
                    ['C2H2-3KG-00001', 'Acetylene Cylinder 3KG #1', 'C2H2', 'active', ['capacity' => '3 KG', 'last_refill' => '2026-09-20']],
                    ['C2H2-3KG-00002', 'Acetylene Cylinder 3KG #2', 'C2H2', 'active', ['capacity' => '3 KG', 'last_refill' => '2026-09-21']],
                    ['O2-6KG-00001', 'Oxygen Cylinder 6KG #1', 'O2', 'active', ['capacity' => '6 KG', 'last_refill' => '2026-09-18']],
                    ['O2-6KG-00002', 'Oxygen Cylinder 6KG #2', 'O2', 'maintenance', ['capacity' => '6 KG']],
                    ['N2-12KG-00001', 'Nitrogen Cylinder 12KG #1', 'N2', 'active', ['capacity' => '12 KG']],
                ],
            ],
            'vehicle-tracker' => [
                'fields' => [
                    ['key' => 'plate_number', 'label' => 'Plate number', 'type' => 'text'],
                    ['key' => 'odometer_km', 'label' => 'Odometer (km)', 'type' => 'number'],
                ],
                'locations' => [
                    ['Jakarta Depot', 'depot', 'Cakung, Jakarta Timur', -6.1820, 106.9380],
                    ['Surabaya Depot', 'depot', 'Rungkut, Surabaya', -7.3290, 112.7810],
                    ['Bandung Hub', 'hub', 'Gedebage, Bandung', -6.9450, 107.6900],
                ],
                'integrations' => ['gps'],
                'assets' => [
                    ['CAR-B-001', 'Box Truck 01', 'truck', 'active', ['plate_number' => 'B 9123 KXA', 'odometer_km' => 120450]],
                    ['CAR-B-002', 'Box Truck 02', 'truck', 'active', ['plate_number' => 'B 9124 KXA', 'odometer_km' => 98012]],
                    ['CAR-B-003', 'Delivery Van 01', 'van', 'maintenance', ['plate_number' => 'B 1771 PQR', 'odometer_km' => 45210]],
                    ['CAR-B-004', 'Delivery Van 02', 'van', 'active', ['plate_number' => 'B 1772 PQR', 'odometer_km' => 30877]],
                ],
            ],
            'factory-equipment-tracker' => [
                'fields' => [
                    ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                    ['key' => 'inspection_interval', 'label' => 'Inspection interval (days)', 'type' => 'number'],
                ],
                'locations' => [
                    ['Production Line 1', 'factory', 'Cikarang, Bekasi', -6.3000, 107.1500],
                    ['Production Line 2', 'factory', 'Cikarang, Bekasi', -6.3010, 107.1510],
                    ['Maintenance Workshop', 'workshop', 'Cikarang, Bekasi', -6.3020, 107.1520],
                ],
                'integrations' => ['rfid', 'gps'],
                'assets' => [
                    ['MACHINE-001', 'CNC Lathe', 'cnc', 'active', ['department' => 'Machining', 'inspection_interval' => 30]],
                    ['MACHINE-002', 'Hydraulic Press', 'press', 'active', ['department' => 'Forming', 'inspection_interval' => 14]],
                    ['MACHINE-003', 'Forklift 01', 'forklift', 'maintenance', ['department' => 'Logistics', 'inspection_interval' => 7]],
                ],
            ],
            'warehouse-tracker' => [
                'fields' => [
                    ['key' => 'zone', 'label' => 'Zone', 'type' => 'select', 'options' => ['A', 'B', 'C']],
                ],
                'locations' => [
                    ['Receiving Dock', 'dock', null, null, null],
                    ['Rack Zone A', 'storage', null, null, null],
                    ['Shipping Dock', 'dock', null, null, null],
                ],
                'integrations' => ['rfid'],
                'assets' => [
                    ['PLT-0001', 'Pallet 0001', 'pallet', 'active', ['zone' => 'A']],
                    ['PLT-0002', 'Pallet 0002', 'pallet', 'active', ['zone' => 'B']],
                    ['RACK-01', 'Mobile Rack 01', 'rack', 'active', ['zone' => 'C']],
                ],
            ],
        ];
    }
}
