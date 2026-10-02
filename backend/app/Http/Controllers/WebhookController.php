<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebhookRequest;
use App\Http\Requests\UpdateWebhookRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookResource;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Webhook::class);

        // Use the context TenantMiddleware validated (headers or the user's
        // default organization/project), never raw request input.
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'An organization context is required',
            ], 422);
        }

        $query = Webhook::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where(function ($q) use ($projectId) {
                $q->where('project_id', $projectId)
                  ->orWhereNull('project_id');
            });
        }

        $webhooks = $query->paginate($request->input('per_page', 25));

        return WebhookResource::collection($webhooks);
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $this->authorize('create', Webhook::class);

        $organizationId = $request->header('X-Organization-Id');
        $projectId = $request->header('X-Project-Id');

        $data = $request->validated();
        $data['organization_id'] = $organizationId;
        $data['project_id'] = $projectId;

        $webhook = $this->webhookService->createWebhook($data);

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook),
            'message' => 'Webhook created successfully',
        ], 201);
    }

    public function show(Webhook $webhook): JsonResponse
    {
        $this->authorize('view', $webhook);

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook),
        ]);
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook): JsonResponse
    {
        $this->authorize('update', $webhook);

        $webhook = $this->webhookService->updateWebhook($webhook, $request->validated());

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook),
            'message' => 'Webhook updated successfully',
        ]);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $this->authorize('delete', $webhook);

        $this->webhookService->deleteWebhook($webhook);

        return response()->json([
            'success' => true,
            'message' => 'Webhook deleted successfully',
        ]);
    }

    public function deliveries(Request $request, Webhook $webhook): AnonymousResourceCollection
    {
        $this->authorize('viewDeliveries', $webhook);

        $query = $webhook->deliveries();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        $deliveries = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return WebhookDeliveryResource::collection($deliveries);
    }

    public function stats(Webhook $webhook): JsonResponse
    {
        $this->authorize('view', $webhook);

        $stats = $this->webhookService->getWebhookDeliveryStats($webhook);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function test(Request $request, Webhook $webhook): JsonResponse
    {
        $this->authorize('test', $webhook);

        $testPayload = $request->input('payload');

        $result = $this->webhookService->testWebhook($webhook, $testPayload);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => $result['success'] ? 'Webhook test successful' : 'Webhook test failed',
        ]);
    }

    public function regenerateSecret(Webhook $webhook): JsonResponse
    {
        $this->authorize('regenerateSecret', $webhook);

        $newSecret = $this->webhookService->regenerateSecret($webhook);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $newSecret,
            ],
            'message' => 'Webhook secret regenerated successfully',
        ]);
    }

    public function retryDeliveries(Request $request, Webhook $webhook): JsonResponse
    {
        $this->authorize('retryDeliveries', $webhook);

        dispatch(new \App\Jobs\RetryFailedWebhookDeliveriesJob($webhook->id));

        return response()->json([
            'success' => true,
            'message' => 'Webhook delivery retry initiated',
        ]);
    }

    public function toggleActive(Request $request, Webhook $webhook): JsonResponse
    {
        $this->authorize('toggleActive', $webhook);

        $webhook->update(['active' => !$webhook->active]);

        return response()->json([
            'success' => true,
            'data' => new WebhookResource($webhook),
            'message' => $webhook->active ? 'Webhook activated' : 'Webhook deactivated',
        ]);
    }
}
