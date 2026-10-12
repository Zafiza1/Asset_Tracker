<?php

namespace App\Http\Controllers\Platform;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Services\GenericCatalogService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenericCategoryResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GenericCategoryController extends Controller
{
    public function __construct(private readonly GenericCatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = GenericAssetCategory::query()->with('parent')->withCount('definitions');

        return GenericCategoryResource::collection(MasterRequests::list($query, $request,
            ['tree' => 'path', 'sort_order' => 'sort_order', 'code' => 'code', 'name' => 'name'], 'sort_order'));
    }

    public function store(Request $request): GenericCategoryResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(), Rule::unique('generic_asset_categories', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        return new GenericCategoryResource($this->catalog->createCategory($data)->load('parent'));
    }

    public function update(Request $request, GenericAssetCategory $genericCategory): GenericCategoryResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new GenericCategoryResource($this->catalog->updateCategory($genericCategory, $data)->load('parent'));
    }

    public function archive(Request $request, GenericAssetCategory $genericCategory): GenericCategoryResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new GenericCategoryResource($this->catalog->archiveCategory($genericCategory, $data['reason'] ?? null));
    }

    public function restore(GenericAssetCategory $genericCategory, MasterWriter $writer): GenericCategoryResource
    {
        return new GenericCategoryResource($writer->restore($genericCategory, 'generic_category.restored'));
    }
}
