<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Integration;
use App\Models\EventLog;
use App\Models\Asset;
use App\Events\AssetDetected;
use App\Integrations\Contracts\IngestsReadings;
use App\Integrations\RFID\RFIDIntegration;
use Illuminate\Support\Facades\Log;
use Exception;

class RFIDService implements IngestsReadings
{
    protected RFIDIntegration $rfidIntegration;

    public function __construct(RFIDIntegration $rfidIntegration)
    {
        $this->rfidIntegration = $rfidIntegration;
    }

    /**
     * Register an RFID tag as a device
     */
    public function registerTag(array $data, Integration $integration): Device
    {
        // Get or create RFID tag device type
        $deviceType = DeviceType::firstOrCreate(
            ['slug' => 'rfid_tag'],
            [
                'name' => 'RFID Tag',
                'description' => 'RFID tag device for asset tracking',
                'capabilities' => ['identification', 'tracking'],
                'metadata' => [
                    'technology' => 'rfid',
                    'frequency' => 'uhf',
                ],
            ]
        );

        return Device::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'device_type_id' => $deviceType->id,
            'integration_id' => $integration->id,
            'serial_number' => $data['tag_id'],
            'name' => $data['name'] ?? "RFID Tag {$data['tag_id']}",
            'status' => 'online',
            'metadata' => [
                'tag_type' => $data['tag_type'] ?? 'passive',
                'frequency' => $data['frequency'] ?? 'uhf',
                'manufacturer' => $data['manufacturer'] ?? null,
                'memory_size' => $data['memory_size'] ?? null,
            ],
        ]);
    }

    /**
     * Register an RFID reader as a device
     */
    public function registerReader(array $data, Integration $integration): Device
    {
        $deviceType = DeviceType::firstOrCreate(
            ['slug' => 'rfid_reader'],
            [
                'name' => 'RFID Reader',
                'description' => 'RFID reader device for tag detection',
                'capabilities' => ['reading', 'location_detection'],
                'metadata' => [
                    'technology' => 'rfid',
                    'frequency' => 'uhf',
                ],
            ]
        );

        return Device::create([
            'organization_id' => $integration->organization_id,
            'project_id' => $integration->project_id,
            'device_type_id' => $deviceType->id,
            'integration_id' => $integration->id,
            'serial_number' => $data['reader_id'],
            'name' => $data['name'] ?? "RFID Reader {$data['reader_id']}",
            'status' => 'online',
            'metadata' => [
                'reader_type' => $data['reader_type'] ?? 'fixed',
                'antenna_count' => $data['antenna_count'] ?? 1,
                'read_power' => $data['read_power'] ?? null,
                'location' => $data['location'] ?? null,
            ],
        ]);
    }

    public function ingest(Integration $integration, array $reading): EventLog
    {
        return $this->processTagRead($integration, $reading);
    }

    /**
     * Process RFID tag read event
     */
    public function processTagRead(Integration $integration, array $tagData): EventLog
    {
        // Validate required fields
        if (empty($tagData['tag_id'])) {
            throw new Exception('tag_id is required');
        }

        // Ingest the event
        $eventLog = $this->rfidIntegration->ingestTagRead($integration, $tagData);

        // Resolve tag → device → active binding → asset
        $device = Device::where('serial_number', $tagData['tag_id'])
            ->where('project_id', $integration->project_id)
            ->first();
        $asset = $device?->boundAsset();

        $device?->update(['status' => 'online', 'last_seen_at' => now()]);

        // An unresolved read stays processed with a null asset_id.
        $eventLog->update([
            'device_id' => $device?->id,
            'asset_id' => $asset?->id,
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        if ($asset) {
            $asset->touchLastSeen();
            AssetDetected::dispatch($asset, $device, 'rfid', $eventLog->id);
        } else {
            Log::warning('RFID tag read could not resolve to asset', [
                'event_log_id' => $eventLog->id,
                'tag_id' => $tagData['tag_id'],
            ]);
        }

        return $eventLog;
    }

    /**
     * Validate RFID tag ID format
     */
    public function validateTagId(string $tagId): bool
    {
        // Basic validation: hex string, 8-24 characters
        return preg_match('/^[A-F0-9]{8,24}$/i', $tagId) === 1;
    }

    /**
     * Validate RFID reader ID format
     */
    public function validateReaderId(string $readerId): bool
    {
        // Basic validation: alphanumeric with hyphens/underscores
        return preg_match('/^[A-Z0-9\-_]{4,32}$/i', $readerId) === 1;
    }

    /**
     * Get RFID devices for an integration
     */
    public function getRFIDDevices(Integration $integration, ?string $deviceType = null)
    {
        $query = Device::where('integration_id', $integration->id)
            ->where('project_id', $integration->project_id);

        if ($deviceType === 'tag') {
            $query->whereHas('deviceType', fn($q) => $q->where('slug', 'rfid_tag'));
        } elseif ($deviceType === 'reader') {
            $query->whereHas('deviceType', fn($q) => $q->where('slug', 'rfid_reader'));
        }

        return $query->with('deviceType', 'currentBinding.asset')->get();
    }

    /**
     * Bulk register RFID tags
     */
    public function bulkRegisterTags(array $tags, Integration $integration): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($tags as $tagData) {
            try {
                if (!$this->validateTagId($tagData['tag_id'])) {
                    throw new Exception("Invalid tag ID format: {$tagData['tag_id']}");
                }

                $this->registerTag($tagData, $integration);
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'tag_id' => $tagData['tag_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get tag read statistics
     */
    public function getTagReadStats(Integration $integration, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $totalReads = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.detected')
            ->where('occurred_at', '>=', $since)
            ->count();

        $uniqueTags = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.detected')
            ->where('occurred_at', '>=', $since)
            ->distinct()
            ->pluck('payload->tag_id')
            ->count();

        $resolvedAssets = EventLog::where('integration_id', $integration->id)
            ->where('event_type', 'asset.detected')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('asset_id')
            ->distinct()
            ->count('asset_id');

        return [
            'total_reads' => $totalReads,
            'unique_tags' => $uniqueTags,
            'resolved_assets' => $resolvedAssets,
            'resolution_rate' => $totalReads > 0 ? round(($resolvedAssets / $totalReads) * 100, 2) : 0,
            'period_hours' => $hours,
        ];
    }
}
