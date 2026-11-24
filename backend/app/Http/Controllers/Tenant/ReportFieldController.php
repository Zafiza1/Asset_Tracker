<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use App\Domain\ReportDesigner\Catalog\TransactionReportFields;
use App\Domain\ReportDesigner\Models\ReportField;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportFieldResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The official field registry: what a report template may bind, per data source — plus the
 * organization's own transaction-type fields (F-07g), offered under the asset_transaction
 * source as unsaved registry-shaped entries.
 */
class ReportFieldController extends Controller
{
    public function __construct(private readonly TransactionReportFields $customFields) {}

    /** All sources with their active fields, keyed by data source, for the designer's data panel. */
    public function index(): JsonResponse
    {
        $fields = ReportField::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->groupBy('data_source')
            ->map(fn ($group) => ReportFieldResource::collection($group)->resolve())
            ->all();
        $fields['asset_transaction'] = [
            ...($fields['asset_transaction'] ?? []),
            ...$this->customFieldRows(),
        ];

        return response()->json(['data' => $fields]);
    }

    public function show(string $source): AnonymousResourceCollection|JsonResponse
    {
        if (! in_array($source, FieldCatalog::DATA_SOURCES, true)) {
            throw new ApiException('UNKNOWN_SOURCE', 'Sumber data laporan tidak dikenal.', 404);
        }
        if ($source !== 'asset_transaction') {
            return ReportFieldResource::collection(
                ReportField::query()->where('data_source', $source)->where('is_active', true)
                    ->orderBy('sort_order')->get());
        }

        $rows = ReportField::query()->where('data_source', $source)->where('is_active', true)
            ->orderBy('sort_order')->get()
            ->merge($this->customFieldModels());

        return ReportFieldResource::collection($rows);
    }

    /**
     * The organization's transaction-type fields as registry-shaped rows (never saved:
     * the registry table holds only the static catalog).
     *
     * @return list<ReportField>
     */
    private function customFieldModels(): array
    {
        $models = [];
        foreach ($this->customFields->fields() as $key => [$label, $type, $bind, , $formats, $permission]) {
            $model = new ReportField;
            $model->forceFill([
                'id' => 'custom:'.$key,
                'data_source' => 'asset_transaction',
                'field_key' => $key,
                'label' => $label,
                'data_type' => $type,
                'bind_path' => $bind,
                'relation' => null,
                'is_repeatable' => false,
                'formats' => $formats,
                'required_permission' => $permission,
                'sort_order' => 1000,
                'is_active' => true,
            ]);
            $models[] = $model;
        }

        return $models;
    }

    /** @return list<array<string, mixed>> resolved resource rows for the grouped index */
    private function customFieldRows(): array
    {
        return array_map(
            fn (ReportField $model) => (new ReportFieldResource($model))->toArray(request()),
            $this->customFieldModels());
    }
}
