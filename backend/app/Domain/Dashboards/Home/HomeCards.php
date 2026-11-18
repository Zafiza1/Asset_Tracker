<?php

namespace App\Domain\Dashboards\Home;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Licensing\Services\Licensing;
use App\Domain\ReportDesigner\Models\ReportExport;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cards of the home page (docs/factory/features/F-06 §3): what the signed-in user has to look
 * at or do. A card is included only when the user holds its permission (D-18), so each role
 * gets its own set and custom roles are covered too. Every count respects the user's data
 * scope. The whole set is cached per user for CACHE_SECONDS (D-20).
 */
final class HomeCards
{
    public const CACHE_SECONDS = 60;

    /** Rows listed on a card; the card's link opens the full list. */
    public const ITEMS = 5;

    public const DUE_SOON_DAYS = 7;

    public const REMINDER_DAYS = 30;

    public const RECENT_DAYS = 30;

    /** Card key => permission needed (null: every member). Order is the display order. */
    public const CARDS = [
        'approvals_to_decide' => 'approval.manage',
        'my_requests' => null,
        'overdue_outgoing' => 'transaction.view',
        'due_soon' => 'transaction.view',
        'date_reminders' => 'asset.view',
        'incomplete_assets' => 'asset.update',
        'license' => 'license.manage',
        'invitations' => 'user.create',
        'users' => 'user.view',
        'my_reports' => 'report.generate',
        'recent_activity' => 'audit.view',
    ];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
        private readonly Licensing $licensing,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forCurrentUser(): array
    {
        $tenant = $this->tenancy->tenant();

        return Cache::remember($this->cacheKey($tenant), self::CACHE_SECONDS, function () use ($tenant) {
            $today = CarbonImmutable::now($tenant->organization->timezone ?: 'UTC')->startOfDay();
            $cards = [];
            foreach (self::CARDS as $key => $permission) {
                if ($permission === null || $tenant->can($permission)) {
                    $cards[] = ['key' => $key, ...$this->card($key, $today)];
                }
            }

            return $cards;
        });
    }

    /**
     * Per user, and per set of permissions and scope, so a role or scope change shows at once.
     */
    private function cacheKey(TenantContext $tenant): string
    {
        $permissions = $tenant->permissions();
        sort($permissions);

        return sprintf('home-cards:%s:%s:%s', $tenant->organizationId(), $tenant->userId(),
            md5(serialize([$permissions, $tenant->scope()])));
    }

    /** @return array<string, mixed> */
    private function card(string $key, CarbonImmutable $today): array
    {
        return match ($key) {
            'approvals_to_decide' => $this->approvalsToDecide(),
            'my_requests' => $this->myRequests($today),
            'overdue_outgoing' => $this->overdueOutgoing($today),
            'due_soon' => $this->dueSoon($today),
            'date_reminders' => $this->dateReminders($today),
            'incomplete_assets' => $this->incompleteAssets(),
            'license' => $this->license($today),
            'invitations' => $this->invitations(),
            'users' => $this->users($today),
            'my_reports' => $this->myReports(),
            'recent_activity' => $this->recentActivity(),
        };
    }

    /** Pending requests from other users, oldest first: they have waited longest. */
    private function approvalsToDecide(): array
    {
        $query = ApprovalRequest::query()->where('status', ApprovalRequest::PENDING)
            ->where('requested_by', '!=', $this->userId());

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('requester:id,name')->orderBy('created_at')->limit(self::ITEMS)->get()
                ->map(fn (ApprovalRequest $a) => [
                    'id' => $a->id, 'summary' => $a->summary, 'action' => $a->action,
                    'requested_by' => $a->requester?->name, 'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /** The user's own requests of the last RECENT_DAYS days, by status, newest first. */
    private function myRequests(CarbonImmutable $today): array
    {
        $query = ApprovalRequest::query()->where('requested_by', $this->userId())
            ->where('created_at', '>=', $today->subDays(self::RECENT_DAYS));
        $byStatus = (clone $query)->toBase()->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');

        return [
            'days' => self::RECENT_DAYS,
            'counts' => array_map(fn (string $status) => (int) ($byStatus[$status] ?? 0), array_combine(ApprovalRequest::STATUSES, ApprovalRequest::STATUSES)),
            'items' => $query->with('decider:id,name')->orderByDesc('created_at')->limit(self::ITEMS)->get()
                ->map(fn (ApprovalRequest $a) => [
                    'id' => $a->id, 'summary' => $a->summary, 'status' => $a->status,
                    'decision_note' => $a->decision_note, 'decided_by' => $a->decider?->name,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /** Temporary outgoing assets past their planned return date, most overdue first. */
    private function overdueOutgoing(CarbonImmutable $today): array
    {
        $query = $this->openOutgoing()->where('due_date', '<', $today->toDateString());

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('asset:id,name')->orderBy('due_date')->limit(self::ITEMS)->get()
                ->map(fn (AssetTransaction $t) => [
                    'transaction_number' => $t->transaction_number,
                    'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name],
                    'counterparty_name' => $t->counterparty_name,
                    'due_date' => $t->due_date?->toDateString(),
                    'days_late' => $this->daysBetween($t->due_date?->toDateString(), $today),
                ])->all(),
        ];
    }

    /** Loans and temporary outgoing assets due today or within DUE_SOON_DAYS days, earliest first. */
    private function dueSoon(CarbonImmutable $today): array
    {
        $from = $today->toDateString();
        $to = $today->addDays(self::DUE_SOON_DAYS)->toDateString();

        $loans = $this->assets()->where('status', AssetStatus::ON_LOAN)->whereBetween('loan_due_date', [$from, $to]);
        $outgoing = $this->openOutgoing()->whereBetween('due_date', [$from, $to]);

        $items = collect()
            ->concat((clone $loans)->orderBy('loan_due_date')->limit(self::ITEMS)->get(['id', 'name', 'borrower_name', 'loan_due_date'])
                ->map(fn (Asset $a) => ['kind' => 'loan', 'asset' => ['id' => $a->id, 'name' => $a->name], 'party' => $a->borrower_name, 'due_date' => $a->loan_due_date?->toDateString()]))
            ->concat((clone $outgoing)->with('asset:id,name')->orderBy('due_date')->limit(self::ITEMS)->get()
                ->map(fn (AssetTransaction $t) => ['kind' => 'outgoing', 'asset' => ['id' => $t->asset_id, 'name' => $t->asset?->name], 'party' => $t->counterparty_name, 'due_date' => $t->due_date?->toDateString()]))
            ->sortBy('due_date')->take(self::ITEMS)->values()->all();

        return ['days' => self::DUE_SOON_DAYS, 'count' => $loans->count() + $outgoing->count(), 'items' => $items];
    }

    /**
     * Date attributes of the asset's category (warranty, vehicle tax, calibration) falling
     * today or within REMINDER_DAYS days, earliest first.
     */
    private function dateReminders(CarbonImmutable $today): array
    {
        $from = $today->toDateString();
        $to = $today->addDays(self::REMINDER_DAYS)->toDateString();
        // Only well-formed dates are cast, so one odd value never breaks the card.
        $value = "CASE WHEN assets.attribute_values ->> a.key ~ '^\\d{4}-\\d{2}-\\d{2}$' THEN (assets.attribute_values ->> a.key)::date END";

        $query = $this->assets()->toBase()
            ->join('tenant_category_attributes as a', function ($join) {
                $join->on('a.category_id', '=', 'assets.category_id')->where('a.data_type', 'date');
            })
            ->whereRaw("{$value} BETWEEN ?::date AND ?::date", [$from, $to]);

        return [
            'days' => self::REMINDER_DAYS,
            'count' => (clone $query)->count(),
            'items' => $query->selectRaw("assets.id, assets.name, a.label, {$value} AS due_date")
                ->orderBy('due_date')->limit(self::ITEMS)->get()
                ->map(fn ($r) => ['asset' => ['id' => $r->id, 'name' => $r->name], 'label' => $r->label, 'due_date' => substr((string) $r->due_date, 0, 10)])->all(),
        ];
    }

    /** Active assets missing data the organization relies on. */
    private function incompleteAssets(): array
    {
        $required = DB::table('tenant_category_attributes as a')
            ->whereColumn('a.category_id', 'assets.category_id')->where('a.is_required', true)
            ->whereRaw("coalesce(assets.attribute_values ->> a.key, '') = ''");

        $counts = [
            'no_serial_number' => $this->assets()->where(fn ($q) => $q->whereNull('serial_number')->orWhere('serial_number', ''))->count(),
            'missing_required_attribute' => $this->assets()->whereExists($required)->count(),
            'no_location' => $this->assets()->whereNull('location_id')->where('status', '!=', AssetStatus::DISPOSED)->count(),
        ];

        return ['count' => $this->assets()->where(fn ($q) => $q
            ->whereNull('serial_number')->orWhere('serial_number', '')
            ->orWhereExists($required)
            ->orWhere(fn ($w) => $w->whereNull('location_id')->where('status', '!=', AssetStatus::DISPOSED)))->count(),
            'counts' => $counts];
    }

    private function license(CarbonImmutable $today): array
    {
        $orgId = $this->tenancy->organizationId();
        $status = $this->tenancy->runAsSystem(fn () => $this->licensing->status($orgId));

        return [
            'active' => $status->active,
            'expires_at' => $status->expiresAt?->toIso8601String(),
            'days_left' => $status->expiresAt ? -$this->daysBetween($status->expiresAt->setTimezone($today->getTimezone())->toDateString(), $today) : null,
            'capacity' => $status->capacity,
            'used' => $status->used(),
            'over_limit' => $status->overLimit(),
        ];
    }

    private function invitations(): array
    {
        $orgId = $this->tenancy->organizationId();
        $count = fn (string $status) => OrganizationInvitation::query()->where('organization_id', $orgId)->withEffectiveStatus($status)->count();

        return ['pending' => $count(OrganizationInvitation::STATUS_PENDING), 'expired' => $count(OrganizationInvitation::STATUS_EXPIRED)];
    }

    private function users(CarbonImmutable $today): array
    {
        $members = fn () => OrganizationMember::query()->where('organization_id', $this->tenancy->organizationId());

        return [
            'days' => self::RECENT_DAYS,
            'new' => $members()->where('status', User::STATUS_ACTIVE)->where('created_at', '>=', $today->subDays(self::RECENT_DAYS))->count(),
            'deactivated' => $members()->where('status', User::STATUS_DEACTIVATED)->count(),
        ];
    }

    /** The user's latest report files (F-05); expired ones cannot be downloaded again. */
    private function myReports(): array
    {
        return ['items' => ReportExport::query()->where('created_by', $this->userId())->with('template:id,name')
            ->orderByDesc('created_at')->limit(self::ITEMS)->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id, 'export_number' => $e->export_number, 'format' => $e->format,
                'template' => $e->template?->name,
                'downloadable' => $e->status === 'completed' && ! ($e->expires_at?->isPast() ?? false),
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all()];
    }

    /** What happened in the organization in the last 24 hours. */
    private function recentActivity(): array
    {
        $query = AuditLog::query()->forOrganization($this->tenancy->organizationId())->where('created_at', '>=', now()->subDay());

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('actor:id,name')->orderByDesc('created_at')->limit(self::ITEMS)->get()
                ->map(fn (AuditLog $l) => ['action' => $l->action, 'actor' => $l->actor?->name, 'created_at' => $l->created_at?->toIso8601String()])->all(),
        ];
    }

    /** Active assets in the user's data scope. */
    private function assets(): Builder
    {
        return $this->scope->apply(Asset::query(), 'assets.location_id')->whereNull('assets.archived_at');
    }

    /** Temporary outgoing rows of in-scope assets still outside the organization. */
    private function openOutgoing(): Builder
    {
        return AssetTransaction::query()
            ->whereIn('asset_id', $this->assets()->where('assets.status', AssetStatus::OFF_SITE)->select('assets.id'))
            ->where('type', TransactionType::OUTGOING)->where('outgoing_nature', OutgoingReason::TEMPORARY)
            ->whereDoesntHave('incomingTransaction');
    }

    /** Calendar days from $date to $today (negative when $date is later), whatever the time zones. */
    private function daysBetween(?string $date, CarbonImmutable $today): int
    {
        if ($date === null) {
            return 0;
        }

        return (int) CarbonImmutable::parse($date, 'UTC')->diffInDays(CarbonImmutable::parse($today->toDateString(), 'UTC'), false);
    }

    private function userId(): string
    {
        return $this->tenancy->tenant()->userId();
    }
}
