<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryAttributeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Custom fields of asset categories ("atribut kategori"). */
class CategoryAttributeController extends Controller
{
    public function __construct(private readonly CategoryAttributeService $attributes) {}

    /** Fields that apply to assets of the category, inherited ones included (see `category`). */
    public function index(TenantAssetCategory $tenantCategory): AnonymousResourceCollection
    {
        return CategoryAttributeResource::collection($this->attributes->effective($tenantCategory->id));
    }

    public function store(Request $request, TenantAssetCategory $tenantCategory): CategoryAttributeResource
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'data_type' => ['required', Rule::in(CategoryAttributeService::TYPES)],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array'],
            'section' => ['nullable', 'string', 'in:basic_information,location_ownership,status_condition'],
        ]);

        return new CategoryAttributeResource($this->attributes->create($tenantCategory, $data)->load('category'));
    }

    /** Saves the category's own fields as one list (create, update, reorder, remove). */
    public function sync(Request $request, TenantAssetCategory $tenantCategory): AnonymousResourceCollection
    {
        $data = $request->validate([
            'attributes' => ['present', 'array', 'list', 'max:50'],
            'attributes.*.id' => ['nullable', 'uuid'],
            'attributes.*.label' => ['required', 'string', 'max:100'],
            'attributes.*.data_type' => ['required', Rule::in(CategoryAttributeService::TYPES)],
            'attributes.*.is_required' => ['sometimes', 'boolean'],
            'attributes.*.options' => ['nullable', 'array'],
            'attributes.*.section' => ['nullable', 'string', 'in:basic_information,location_ownership,status_condition'],
        ]);

        return CategoryAttributeResource::collection($this->attributes->sync($tenantCategory, $data['attributes']));
    }

    public function update(Request $request, TenantCategoryAttribute $categoryAttribute): CategoryAttributeResource
    {
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);

        return new CategoryAttributeResource($this->attributes->update($categoryAttribute, $data)->load('category'));
    }

    public function destroy(TenantCategoryAttribute $categoryAttribute): CategoryAttributeResource
    {
        return new CategoryAttributeResource($this->attributes->archive($categoryAttribute)->load('category'));
    }
}
