<?php

namespace Database\Seeders;

use App\Models\DeviceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeviceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $deviceTypes = [
            [
                'name' => 'RFID Tag',
                'slug' => 'rfid_tag',
                'description' => 'RFID tag device for asset identification and tracking',
                'capabilities' => ['identification', 'tracking'],
                'metadata' => [
                    'technology' => 'rfid',
                    'frequency' => 'uhf',
                ],
            ],
            [
                'name' => 'RFID Reader',
                'slug' => 'rfid_reader',
                'description' => 'RFID reader device for tag detection and location tracking',
                'capabilities' => ['reading', 'location_detection'],
                'metadata' => [
                    'technology' => 'rfid',
                    'frequency' => 'uhf',
                ],
            ],
            [
                'name' => 'GPS Tracker',
                'slug' => 'gps_tracker',
                'description' => 'GPS tracking device for real-time location monitoring',
                'capabilities' => ['location_tracking', 'telemetry'],
                'metadata' => [
                    'technology' => 'gps',
                ],
            ],
            [
                'name' => 'BLE Beacon',
                'slug' => 'ble_beacon',
                'description' => 'Bluetooth Low Energy beacon for proximity detection',
                'capabilities' => ['proximity_detection', 'identification'],
                'metadata' => [
                    'technology' => 'ble',
                ],
            ],
            [
                'name' => 'IoT Sensor',
                'slug' => 'iot_sensor',
                'description' => 'IoT sensor device for environmental monitoring',
                'capabilities' => ['sensing', 'telemetry'],
                'metadata' => [
                    'technology' => 'iot',
                ],
            ],
        ];

        foreach ($deviceTypes as $deviceType) {
            DeviceType::firstOrCreate(
                ['slug' => $deviceType['slug']],
                $deviceType
            );
        }
    }
}
