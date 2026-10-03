<?php

namespace App\Http\Controllers;

use App\Http\Resources\ModuleResource;
use App\Models\Module;
use Illuminate\Http\Request;

/**
 * The platform module catalog (Control Plane). Catalog entries are platform
 * data, not tenant data, so any authenticated user may browse them; what a
 * project has installed is served by ProjectModuleController.
 */
class ModuleController extends Controller
{
    public function index(Request $request)
    {
        $query = Module::query()->available()->with('versions');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $sort = (string) $request->query('sort', 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'slug', 'category', 'created_at'], true)) {
            $query->orderBy($column, $direction);
        }

        $perPage = $this->perPage($request);
        $modules = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ModuleResource::collection($modules->items()),
            'meta' => [
                'current_page' => $modules->currentPage(),
                'per_page' => $modules->perPage(),
                'total' => $modules->total(),
                'last_page' => $modules->lastPage(),
            ],
        ]);
    }

    public function show(Module $module)
    {
        $module->load('versions');

        return response()->json([
            'success' => true,
            'data' => new ModuleResource($module),
        ]);
    }
}
