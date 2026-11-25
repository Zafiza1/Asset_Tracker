<?php

namespace App\Domain\Deliveries;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Models\DeliveryItem;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Domain\TenantMaster\StandardCategories;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The assets deliveries are about: cylinders (category Tabung) and vehicles (category Kendaraan),
 * each with its subcategories, and where a cylinder is according to its deliveries.
 */
final class Fleet
{
    public const PLATE_LABEL = 'Nomor Polisi';

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

    /** Active vehicles the user may see that can be used now. */
    public function vehicles(): Builder
    {
        return $this->inCategory(StandardCategories::VEHICLE)->whereIn('status', [AssetStatus::IN_USE, AssetStatus::AVAILABLE]);
    }

    /** Statuses a cylinder may be loaded with. */
    public static function loadableStatuses(string $direction): array
    {
        return $direction === Delivery::OUTGOING ? [AssetStatus::AVAILABLE, AssetStatus::IN_USE] : [AssetStatus::OFF_SITE];
    }

    public function plate(?Asset $vehicle): ?string
    {
        if ($vehicle === null) {
            return null;
        }
        $key = $this->fieldsOf($vehicle->category_id)[self::PLATE_LABEL]['key'] ?? null;

        return ($key === null ? null : ($vehicle->attribute_values[$key] ?? null)) ?: $vehicle->serial_number;
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
            ->selectRaw('DISTINCT ON (di.asset_id) di.asset_id, di.state, di.active, d.id AS delivery_id, d.number, d.direction, c.name AS customer, a.status AS asset_status')
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
                'delivery_id' => $row->delivery_id, 'delivery_number' => $row->number,
            ];
        }

        return $out;
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
