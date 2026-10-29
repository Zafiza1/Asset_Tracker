<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Services\AssetService;
use App\Domain\Organization\Models\Location;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetTransactionResource;
use App\Http\Resources\CategoryAttributeResource;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Asset Management container: list/search, detail, register, edit, deactivate, reactivate. */
class AssetController extends Controller
{
    private const RELATIONS = ['category', 'location'];

    private const SORTS = ['asset_number' => 'asset_number', 'name' => 'name', 'created_at' => 'created_at',
        'acquisition_date' => 'acquisition_date', 'status' => 'status'];

    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetScope $scope,
        private readonly CategoryAttributeService $attributes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // Comma-separated; a category also matches its subcategories.
            'category_ids' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(AssetStatus::ALL)],
            'archived' => ['nullable', Rule::in(['only', 'all'])],
        ]);
        $categoryIds = array_values(array_filter(explode(',', (string) $request->query('category_ids'))));
        $request->merge(['category_id_list' => $categoryIds])->validate(['category_id_list.*' => ['uuid']]);

        $query = $this->scope->apply(Asset::query())->with(self::RELATIONS)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('asset_number', 'ilike', ListQuery::like($term))
                ->orWhere('serial_number', 'ilike', ListQuery::like($term))))
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $this->subtree(TenantAssetCategory::query(), $categoryIds)))
            ->when($request->query('location_id'), fn ($q, $id) => $q->whereIn('location_id', $this->subtree(Location::query(), [$id])))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        match ($request->query('archived')) {
            'only' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
        ListQuery::sort($query, $request, self::SORTS, '-created_at');

        return AssetResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    /** Includes the category's fields, so the values can be shown with their labels. */
    public function show(Asset $asset): AssetResource
    {
        return (new AssetResource($asset->load([...self::RELATIONS, 'creator'])))
            ->additional(['attribute_fields' => CategoryAttributeResource::collection($this->attributes->effective($asset->category_id))]);
    }

    public function store(Request $request): AssetResource
    {
        $data = $request->validate([
            ...$this->descriptiveRules(creating: true),
            'location_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(AssetStatus::INITIAL)],
            'registered_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1999-12-31'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return new AssetResource($this->assets->create($data)->load(self::RELATIONS));
    }

    public function update(Request $request, Asset $asset): AssetResource
    {
        $data = $request->validate([
            ...$this->descriptiveRules(creating: false),
            'lock_version' => ['sometimes', 'integer'],
        ]);

        return new AssetResource($this->assets->update($asset, $data)->load(self::RELATIONS));
    }

    public function archive(Request $request, Asset $asset): AssetResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return new AssetResource($this->assets->archive($asset, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(Asset $asset): AssetResource
    {
        return new AssetResource($this->assets->restore($asset)->load(self::RELATIONS));
    }

    /** Full history of one asset, newest first. */
    public function transactions(Request $request, Asset $asset): AnonymousResourceCollection
    {
        $history = $asset->transactions()->with(['fromLocation', 'toLocation', 'creator'])
            ->orderByDesc('seq')
            ->paginate(ListQuery::perPage($request, 10));

        return AssetTransactionResource::collection($history);
    }

    /** @return array<string, list<mixed>> */
    private function descriptiveRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'serial_number' => [...$optional, 'string', 'max:100'],
            'category_id' => [...$required, 'uuid'],
            'acquisition_date' => [...$optional, 'date_format:Y-m-d', 'before_or_equal:today'],
            'acquisition_cost' => [...$optional, 'numeric', 'min:0', 'max:9999999999999999'],
            'description' => [...$optional, 'string', 'max:2000'],
            // Category fields, {key: value}; validated against the category by the service.
            'attributes' => [...$optional, 'array'],
        ];
    }

    /**
     * Ids of the given tree nodes and all their descendants (materialized path).
     *
     * @param  list<string>  $ids
     */
    private function subtree(Builder $query, array $ids): Builder
    {
        return $query->select('id')->where(function ($w) use ($ids) {
            foreach ($ids as $id) {
                $w->orWhere('path', 'like', '%/'.$id.'/%');
            }
        });
    }
}
