<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\WidgetCatalogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The widgets the system offers (read-only reference data). Every active widget is listed —
 * `required_permission` tells the frontend which ones the member can actually use; the
 * server enforces it again when widget data is requested.
 */
class WidgetCatalogController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WidgetCatalogResource::collection(
            WidgetCatalog::query()->where('is_active', true)->orderBy('sort_order')->get());
    }
}
