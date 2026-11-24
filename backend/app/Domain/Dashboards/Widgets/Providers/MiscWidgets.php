<?php

namespace App\Domain\Dashboards\Widgets\Providers;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Dashboards\Widgets\WidgetContext;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * Widgets without an asset/transaction dataset: the audit-trail activity list, the free-text
 * note and the quick-link shortcuts of §4.3.
 */
final class MiscWidgets
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /** Asset changes from the audit trail, newest first. */
    public function assetChangeHistory(WidgetContext $ctx): array
    {
        $limit = max(1, min(50, $ctx->intConfig('limit') ?: 10));
        [$from, $to] = $ctx->period() ?? [null, null];

        $query = AuditLog::query()->forOrganization($this->tenancy->organizationId())
            ->where('entity_type', 'Asset')
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->whereIn('entity_id', $ctx->assets(includeArchived: true)->selectRaw('assets.id::text')); // entity_id is varchar

        $rows = $query->with('actor:id,name')->orderByDesc('created_at')->limit($limit)->get();
        $names = Asset::query()->whereIn('id', $rows->pluck('entity_id')->filter()->unique())->pluck('name', 'id');

        return [
            'count' => (clone $query)->count(),
            'items' => $rows->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->actor?->name,
                'asset' => $l->entity_id !== null && $names->has($l->entity_id)
                    ? ['id' => $l->entity_id, 'name' => $names[$l->entity_id]] : null,
                'at' => $l->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function note(WidgetContext $ctx): array
    {
        return ['text' => (string) ($ctx->config('text') ?? '')];
    }

    /**
     * Quick links. Each item names the permission the member must hold for the link to be
     * offered; the frontend renders the ones the member may use.
     *
     * @return array<string, mixed>
     */
    public function shortcuts(WidgetContext $ctx): array
    {
        $items = [
            ['key' => 'assets', 'label' => 'Daftar Aset', 'target' => '/aset', 'permission' => 'asset.view'],
            ['key' => 'transactions', 'label' => 'Transaksi', 'target' => '/transaksi', 'permission' => 'transaction.view'],
            ['key' => 'runs', 'label' => 'Perlu keputusan saya', 'target' => '/notifikasi?status=pending', 'permission' => 'transaction.view'],
            ['key' => 'reports', 'label' => 'Laporan', 'target' => '/laporan/template', 'permission' => 'report.view'],
        ];
        $templateId = $ctx->config('report_template_id');
        if (is_string($templateId) && $templateId !== '') {
            $items[] = ['key' => 'report_run', 'label' => 'Jalankan Laporan', 'target' => "/laporan/template/{$templateId}/jalankan", 'permission' => 'report.generate'];
        }

        return ['items' => $items];
    }
}
