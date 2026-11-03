<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The organization's optional modules (OrganizationModule::CATALOG), switched on and off. */
class OrganizationModuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->modules()]);
    }

    public function update(Request $request, string $module): JsonResponse
    {
        if (! isset(OrganizationModule::CATALOG[$module])) {
            throw new ApiException('MODULE_NOT_FOUND', 'Modul tidak ditemukan.', 404);
        }
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        DB::transaction(function () use ($module, $enabled) {
            $row = OrganizationModule::query()->where('module', $module)->lockForUpdate()->first();
            if ($enabled === ($row !== null)) {
                return;
            }
            $enabled ? OrganizationModule::query()->create(['module' => $module]) : $row->delete();
            $this->audit->record($enabled ? 'module.enabled' : 'module.disabled', metadata: ['module' => $module]);
        });

        return response()->json(['data' => $this->modules()]);
    }

    /** @return list<array{key: string, name: string, description: string, enabled: bool}> */
    private function modules(): array
    {
        $enabled = OrganizationModule::enabledKeys();

        return array_map(
            fn (string $key) => ['key' => $key, ...OrganizationModule::CATALOG[$key], 'enabled' => in_array($key, $enabled, true)],
            array_keys(OrganizationModule::CATALOG),
        );
    }
}
