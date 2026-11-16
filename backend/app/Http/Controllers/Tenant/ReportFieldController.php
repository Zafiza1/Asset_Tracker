<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\ReportDesigner\Models\ReportField;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportFieldResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The official field registry: what a report template may bind, per data source. */
class ReportFieldController extends Controller
{
    /** All sources with their active fields, keyed by data source, for the designer's data panel. */
    public function index(): JsonResponse
    {
        $fields = ReportField::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->groupBy('data_source')
            ->map(fn ($group) => ReportFieldResource::collection($group)->resolve())
            ->all();

        return response()->json(['data' => $fields]);
    }

    public function show(string $source): AnonymousResourceCollection
    {
        if (! in_array($source, FieldCatalog::DATA_SOURCES, true)) {
            throw new ApiException('UNKNOWN_SOURCE', 'Sumber data laporan tidak dikenal.', 404);
        }

        return ReportFieldResource::collection(
            ReportField::query()->where('data_source', $source)->where('is_active', true)
                ->orderBy('sort_order')->get());
    }
}
