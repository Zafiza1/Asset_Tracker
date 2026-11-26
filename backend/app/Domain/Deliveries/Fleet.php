<?php

namespace App\Domain\Deliveries;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Models\DeliveryItem;
use App\Domain\Organization\Models\Vehicle;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What deliveries are about: cylinders (assets of category Tabung and its subcategories), the vehicles
 * they travel in (Master Data › Kendaraan), and where a cylinder is according to its deliveries.
 */
final class Fleet
{
    /**
     * Lokasi Status of a cylinder, set by what happens to it: in the warehouse while no transaction
     * carries it, on the way while it is on a vehicle that has left, at a customer once delivered.
     */
    public const WAREHOUSE = 'warehouse';

    public const IN_TRANSIT = 'in_transit';

    public const AT_CUSTOMER = 'at_customer';

    public const LOCATION_STATUSES = [
        self::WAREHOUSE => 'Di Gudang',
        self::IN_TRANSIT => 'Dalam Perjalanan',
        self::AT_CUSTOMER => 'Di Customer',
    ];

    /** Fields of a cylinder shown next to its name when choosing or scanning it. */
    private const CYLINDER_LABELS = ['Business Unit', 'Jenis Gas', 'Grade Gas', 'Jenis Tabung', 'Warna', 'Volume Tabung', 'Satuan Volume'];

    /** @var array<string, array<string, array{key: string, options: array<string, string>}>> category id => label => field */
    private array $fields = [];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly CategoryAttributeService $attributes,
    ) {}

    /** Active cylinders the user may see. */
    public function cylinders(): Builder
    {
        return $this->inCategory(StandardCategories::CYLINDER);
    }

    /** Whether a category is Tabung or one of its subcategories. */
    public static function isCylinderCategory(string $categoryId): bool
    {
        $path = TenantAssetCategory::query()->where('code', StandardCategories::CYLINDER)->value('path');

        return $path !== null && TenantAssetCategory::query()->whereKey($categoryId)->where('path', 'like', $path.'%')->exists();
    }

    /** Active vehicles. */
    public function vehicles(): Builder
    {
        return Vehicle::query()->where('status', MasterStatus::ACTIVE);
    }

    /** Statuses a cylinder may be loaded with. */
    public static function loadableStatuses(string $direction): array
    {
        return $direction === Delivery::OUTGOING ? [AssetStatus::AVAILABLE, AssetStatus::IN_USE] : [AssetStatus::OFF_SITE];
    }

    public function plate(?Vehicle $vehicle): ?string
    {
        return $vehicle?->plate_number;
    }

    /** @return array<string, string> label => value as shown */
    public function cylinderDetails(Asset $asset): array
    {
        $fields = $this->fieldsOf($asset->category_id);
        $out = [];
        foreach (self::CYLINDER_LABELS as $label) {
            $field = $fields[$label] ?? null;
            $value = $field === null ? null : ($asset->attribute_values[$field['key']] ?? null);
            if ($value !== null && $value !== '') {
                $out[$label] = $field['options'][$value] ?? (string) $value;
            }
        }

        return $out;
    }

    /**
     * Where these assets are according to their deliveries; assets that never were on one are
     * left out. One query for the whole list.
     *
     * @param  list<string>  $assetIds
     * @return array<string, array{state: string, label: string, delivery_id: string, delivery_number: string, customer: string}>
     */
    public static function positions(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $rows = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->join('delivery_stops as s', 's.id', '=', 'di.stop_id')
            ->join('customers as c', 'c.id', '=', 's.customer_id')
            ->join('assets as a', 'a.id', '=', 'di.asset_id')
            ->whereIn('di.asset_id', $assetIds)
            ->whereIn('di.state', [DeliveryItem::PLANNED, DeliveryItem::LOADED, DeliveryItem::UNLOADED, DeliveryItem::RETURNED, DeliveryItem::MISSING])
            ->orderBy('di.asset_id')->orderByDesc('di.active')->orderByDesc('di.updated_at')
            ->selectRaw('DISTINCT ON (di.asset_id) di.asset_id, di.state, di.active, d.id AS delivery_id, d.number, d.direction, d.status AS delivery_status, c.name AS customer, a.status AS asset_status')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $off = $row->asset_status === AssetStatus::OFF_SITE;
            [$state, $label] = match (true) {
                $row->state === DeliveryItem::MISSING && $off => ['missing', 'Selisih, perlu dicek'],
                $row->state === DeliveryItem::LOADED && (bool) $row->active => ['in_transit', $row->direction === Delivery::OUTGOING ? "Dikirim ke {$row->customer}" : "Dijemput dari {$row->customer}"],
                $row->state === DeliveryItem::PLANNED && $off => ['at_customer', "Di {$row->customer}, akan dijemput"],
                $row->state === DeliveryItem::PLANNED => ['planned', "Di gudang, akan dikirim ke {$row->customer}"],
                $row->state === DeliveryItem::UNLOADED && $row->direction === Delivery::INCOMING => ['warehouse', 'Di gudang'],
                $off => ['at_customer', "Di {$row->customer}"],
                default => ['warehouse', 'Di gudang'],
            };
            $out[$row->asset_id] = [
                'state' => $state, 'label' => $label, 'customer' => $row->customer,
                'delivery_id' => $row->delivery_id, 'delivery_number' => $row->number, 'delivery_status' => $row->delivery_status,
            ];
        }

        return $out;
    }

    /**
     * The Lokasi Status of an asset from its status and its place in the deliveries (positions()).
     *
     * @param  array{state: string, label: string, customer: string, delivery_id: string, delivery_number: string, delivery_status: string}|null  $position
     * @return array{key: string, label: string, detail: string|null, delivery_id: string|null, delivery_number: string|null}
     */
    public static function locationStatus(string $status, ?array $position): array
    {
        $onTheWay = $position !== null && $position['state'] === 'in_transit' && $position['delivery_status'] === Delivery::IN_TRANSIT;
        $key = $onTheWay ? self::IN_TRANSIT : ($status === AssetStatus::OFF_SITE ? self::AT_CUSTOMER : self::WAREHOUSE);
        $detail = match (true) {
            $position === null => null,
            $key === self::IN_TRANSIT => $position['label'],
            $key === self::AT_CUSTOMER => $position['state'] === 'missing' ? "{$position['customer']} · selisih, perlu dicek" : $position['customer'],
            $position['state'] === 'planned' => "Akan dikirim ke {$position['customer']}",
            $position['state'] === 'in_transit' => "Sedang dimuat untuk {$position['customer']}",
            default => null,
        };
        $linked = $detail !== null;

        return [
            'key' => $key, 'label' => self::LOCATION_STATUSES[$key], 'detail' => $detail,
            'delivery_id' => $linked ? $position['delivery_id'] : null, 'delivery_number' => $linked ? $position['delivery_number'] : null,
        ];
    }

    /** The same Lokasi Status as locationStatus(), as SQL on `assets`, for filtering and sorting lists. */
    public static function locationStatusSql(): string
    {
        $loaded = DeliveryItem::LOADED;
        $onTrip = Delivery::IN_TRANSIT;
        $off = AssetStatus::OFF_SITE;

        return "CASE WHEN EXISTS (SELECT 1 FROM delivery_items di JOIN deliveries d ON d.id = di.delivery_id
                WHERE di.asset_id = assets.id AND di.active AND di.state = '{$loaded}' AND d.status = '{$onTrip}') THEN '".self::IN_TRANSIT."'
            WHEN assets.status = '{$off}' THEN '".self::AT_CUSTOMER."' ELSE '".self::WAREHOUSE."' END";
    }

    private function inCategory(string $code): Builder
    {
        $path = TenantAssetCategory::query()->where('code', $code)->value('path');
        $query = $this->scope->apply(Asset::query())->whereNull('archived_at');
        if ($path === null) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('category_id', TenantAssetCategory::query()->select('id')->where('path', 'like', $path.'%'));
    }

    /** @return array<string, array{key: string, options: array<string, string>}> */
    private function fieldsOf(string $categoryId): array
    {
        return $this->fields[$categoryId] ??= $this->attributes->effective($categoryId)->mapWithKeys(fn ($a) => [$a->label => [
            'key' => $a->key,
            'options' => array_column($a->options ?? [], 'label', 'value'),
        ]])->all();
    }
}
