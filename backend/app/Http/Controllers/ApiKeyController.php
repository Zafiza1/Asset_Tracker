<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Models\ApiKey;
use App\Models\Integration;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project API keys for gateways and external systems. The plaintext key is
 * returned exactly once, on creation.
 */
class ApiKeyController extends Controller
{
    use ResolvesProject;

    public function __construct(
        protected ApiKeyService $keys
    ) {}

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorizePermission($request, 'api-key.view', $project->organization_id, $project->id);

        $keys = ApiKey::where('project_id', $project->id)->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $keys->map(fn (ApiKey $key) => $this->present($key)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorizePermission($request, 'api-key.manage', $project->organization_id, $project->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKey::SCOPES)],
            'integration_id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if (!empty($validated['integration_id'])
            && !Integration::where('project_id', $project->id)->whereKey($validated['integration_id'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['integration_id' => ['The selected integration is invalid.']],
            ], 422);
        }

        [$key, $plain] = $this->keys->issue($project, $request->user(), $validated);

        return response()->json([
            'success' => true,
            'data' => array_merge($this->present($key), ['key' => $plain]),
            'message' => 'API key created. Store it now — it will not be shown again.',
        ], 201);
    }

    public function destroy(Request $request, int $apiKey): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorizePermission($request, 'api-key.manage', $project->organization_id, $project->id);

        $key = ApiKey::where('project_id', $project->id)->findOrFail($apiKey);
        $this->keys->revoke($key);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked',
        ]);
    }

    protected function authorizePermission(Request $request, string $permission, int $organizationId, int $projectId): void
    {
        $user = $request->user();

        abort_unless(
            $user->isPlatformAdmin() || $user->hasPermission($permission, $organizationId, $projectId),
            403,
            'This action is unauthorized.'
        );
    }

    protected function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => ApiKeyService::TOKEN_PREFIX . $key->prefix,
            'scopes' => $key->scopes,
            'integration_id' => $key->integration_id,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}
