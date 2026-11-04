<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\ApprovalActions;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Approval of transactions, asset changes and master data changes.
 *
 * A change by a user without approval.manage is not applied: the API call (method, path, input,
 * uploaded files) is stored as a pending request (RequireApproval middleware). Approving replays
 * that call through its controller as the requester, so the rules, data scope and permissions
 * of the requester apply exactly as if the change were made directly, and the history shows
 * the requester as its author. Rules are checked again at approval time: if the data changed
 * in the meantime and the change no longer fits, approval fails and the request stays pending.
 */
final class ApprovalService
{
    public const DISK = 'local';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
        private readonly Router $router,
        private readonly Container $container,
    ) {}

    /** True when the current user's changes apply directly and they decide on requests. */
    public function canDecide(): bool
    {
        return $this->tenancy->tenant()->can(ApprovalActions::PERMISSION);
    }

    /**
     * The API call of $request as an (unsaved) approval request. Taken before the call is tried
     * out: trying it may change the input (normalization) and the bound records in memory.
     */
    public function draft(Request $request, string $action): ApprovalRequest
    {
        $payload = $request->except(array_keys($request->allFiles()));

        $approval = new ApprovalRequest;
        $approval->forceFill([
            'id' => $approval->newUniqueId(),
            'category' => ApprovalActions::category($action),
            'action' => $action,
            'summary' => mb_substr($this->summary($request, $action, $payload), 0, 255),
            'method' => $request->method(),
            'path' => $request->path(),
            'payload' => $payload,
            'status' => ApprovalRequest::PENDING,
            'requested_by' => $this->tenancy->tenant()->userId(),
        ]);

        return $approval;
    }

    /** Stores a draft() with the uploaded files of its request as a pending request. */
    public function submit(ApprovalRequest $approval, Request $request): ApprovalRequest
    {
        $files = array_filter($request->allFiles(), fn ($file) => $file instanceof UploadedFile);
        $stored = [];
        foreach ($files as $field => $file) {
            $path = $file->storeAs("approvals/{$this->tenancy->organizationId()}/{$approval->id}", $field.'.'.($file->getClientOriginalExtension() ?: 'bin'), self::DISK);
            if ($path === false) {
                throw ApiException::unprocessable('UPLOAD_FAILED', 'File gagal disimpan. Coba lagi.');
            }
            $stored[$field] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType()];
        }
        $approval->forceFill(['files' => $stored === [] ? null : $stored]);

        try {
            DB::transaction(function () use ($approval) {
                $approval->save();
                $this->audit->record('approval.submitted', $approval, after: $this->snapshot($approval));
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($approval);
            throw $e;
        }

        return $approval;
    }

    public function approve(ApprovalRequest $approval, ?string $note): ApprovalRequest
    {
        $this->assertDecider();
        $requester = $this->tenancy->runAsSystem(fn () => User::query()->find($approval->requested_by));
        $organization = $requester === null ? null : $this->access->find($requester, $approval->organization_id);
        if ($organization === null) {
            throw ApiException::conflict('REQUESTER_INACTIVE', 'Pengaju sudah tidak aktif di organisasi ini, sehingga pengajuan tidak dapat diterapkan. Tolak pengajuan ini.');
        }

        $approval = DB::transaction(function () use ($approval, $requester, $organization, $note) {
            $locked = $this->lockPending($approval);
            try {
                $this->tenancy->runAsTenant(new TenantContext($organization, $requester), fn () => $this->replay($locked));
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first() ?? 'Data tidak valid.';
                throw ApiException::unprocessable('APPROVAL_FAILED', "Pengajuan tidak dapat diterapkan: {$message}", ['fields' => $e->errors()]);
            } catch (ApiException $e) {
                throw new ApiException($e->errorCode, "Pengajuan tidak dapat diterapkan: {$e->getMessage()}", $e->status, $e->details);
            } catch (ModelNotFoundException) {
                throw ApiException::conflict('APPROVAL_FAILED', 'Pengajuan tidak dapat diterapkan: data yang diajukan sudah tidak ditemukan.');
            }

            return $this->decide($locked, ApprovalRequest::APPROVED, $note);
        });
        $this->deleteFiles($approval);

        return $approval;
    }

    public function reject(ApprovalRequest $approval, ?string $note): ApprovalRequest
    {
        $this->assertDecider();
        $approval = DB::transaction(fn () => $this->decide($this->lockPending($approval), ApprovalRequest::REJECTED, $note));
        $this->deleteFiles($approval);

        return $approval;
    }

    /** The requester withdraws their own pending request. */
    public function cancel(ApprovalRequest $approval): ApprovalRequest
    {
        if ($approval->requested_by !== $this->tenancy->tenant()->userId()) {
            throw ApiException::forbidden('FORBIDDEN', 'Hanya pengaju yang dapat membatalkan pengajuan ini.');
        }
        $approval = DB::transaction(fn () => $this->decide($this->lockPending($approval), ApprovalRequest::CANCELLED, null));
        $this->deleteFiles($approval);

        return $approval;
    }

    private function assertDecider(): void
    {
        if (! $this->canDecide()) {
            throw ApiException::forbidden('FORBIDDEN', 'Hanya Super Admin dan Supervisor yang dapat menyetujui atau menolak pengajuan.');
        }
    }

    private function lockPending(ApprovalRequest $approval): ApprovalRequest
    {
        $locked = ApprovalRequest::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isPending()) {
            throw ApiException::conflict('APPROVAL_DECIDED', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        return $locked;
    }

    private function decide(ApprovalRequest $approval, string $status, ?string $note): ApprovalRequest
    {
        $approval->forceFill([
            'status' => $status,
            'decided_by' => $this->tenancy->tenant()->userId(),
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();
        $this->audit->record("approval.{$status}", $approval, after: $this->snapshot($approval));

        return $approval;
    }

    /**
     * Runs the stored API call through its route's controller (without the route middleware:
     * the approval gate must not intercept it again). Route model binding applies the current
     * (requester's) tenant context and data scope.
     */
    private function replay(ApprovalRequest $approval): void
    {
        $files = [];
        foreach ($approval->files ?? [] as $field => $file) {
            $files[$field] = new UploadedFile(Storage::disk(self::DISK)->path($file['path']), $file['name'], $file['mime'], null, true);
        }
        $request = Request::create('/'.$approval->path, $approval->method, $approval->payload, [], $files, ['HTTP_ACCEPT' => 'application/json']);
        $route = $this->router->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        $previous = $this->container->make('request');
        $this->container->instance('request', $request);
        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
            $route->run();
        } finally {
            $this->container->instance('request', $previous);
        }
    }

    /** One line naming what the request changes, e.g. "AST-2026-000001 · Laptop Dell". */
    private function summary(Request $request, string $action, array $payload): string
    {
        $subjects = [];
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                $subjects[] = $this->describe($value);
            }
        }
        if ($subjects !== []) {
            return implode(' · ', $subjects);
        }

        $assetIds = array_values(array_filter((array) ($payload['asset_ids'] ?? [$payload['asset_id'] ?? null]), 'is_string'));
        $file = $request->file('file');

        return match (true) {
            $action === 'transaction.create' => (TransactionType::LABELS[$payload['type'] ?? ''] ?? 'Transaksi').' · '.$this->assetList($assetIds),
            $assetIds !== [] => $this->assetList($assetIds),
            $file instanceof UploadedFile => $file->getClientOriginalName(),
            default => (string) ($payload['name'] ?? $payload['label'] ?? ApprovalActions::label($action)),
        };
    }

    private function describe(Model $model): string
    {
        $a = $model->getAttributes();

        return match (true) {
            isset($a['asset_number']) => "{$a['asset_number']} · {$a['name']}",
            isset($a['code'], $a['name']) => "{$a['name']} ({$a['code']})",
            default => (string) ($a['name'] ?? $a['label'] ?? $model->getKey()),
        };
    }

    /** @param list<string> $ids */
    private function assetList(array $ids): string
    {
        $numbers = Asset::query()->whereIn('id', array_slice($ids, 0, 3))->orderBy('asset_number')->pluck('asset_number')->all();
        $more = count($ids) - count($numbers);

        return implode(', ', $numbers).($more > 0 ? " dan {$more} aset lain" : '');
    }

    /** @return array<string, mixed> */
    private function snapshot(ApprovalRequest $approval): array
    {
        return [
            'action' => $approval->action,
            'summary' => $approval->summary,
            'status' => $approval->status,
            'requested_by' => $approval->requested_by,
            'decision_note' => $approval->decision_note,
        ];
    }

    private function deleteFiles(ApprovalRequest $approval): void
    {
        foreach ($approval->files ?? [] as $file) {
            Storage::disk(self::DISK)->delete($file['path']);
        }
    }
}
