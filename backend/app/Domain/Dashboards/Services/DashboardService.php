<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\UserDashboardPreference;
use App\Domain\Dashboards\SharePermission;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Dashboard lifecycle: the Overview and category dashboards (created by the system on first
 * use, from TemplateResolver), the member's asset board (a personal copy of their role's
 * template), custom dashboards (from a template or empty), settings, soft deletion,
 * explicit template updates and each member's default dashboard.
 *
 * Callers authorize through DashboardPolicy first; the checks here are business rules.
 */
final class DashboardService
{
    private const AUDIT = ['name', 'description', 'visibility', 'status', 'is_default'];

    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly DashboardBuilderService $builder,
        private readonly DashboardAccessService $access,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', Rule::in(DashboardType::VISIBILITIES)],
            'template_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', Rule::in(DashboardType::VISIBILITIES)],
            'status' => ['sometimes', Rule::in([Dashboard::STATUS_ACTIVE, Dashboard::STATUS_ARCHIVED])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** The organization's Overview, created from the Overview template the first time it is needed. */
    public function overview(): Dashboard
    {
        return $this->findOrCreate(
            fn () => Dashboard::query()->where('dashboard_type', DashboardType::OVERVIEW)->first(),
            function () {
                $dashboard = $this->newSystemDashboard(DashboardType::OVERVIEW, 'Overview', 'Ringkasan seluruh aset business unit.');
                // The Overview is the organization's default unless another dashboard already is.
                $dashboard->is_default = ! Dashboard::query()->where('is_default', true)->exists();
                $dashboard->save();
                $this->fillFromTemplate($dashboard, $this->resolver->forOverview());

                return $dashboard;
            },
        );
    }

    /** The category's dashboard, created from the best matching template on first access. */
    public function forCategory(TenantAssetCategory $category): Dashboard
    {
        return $this->findOrCreate(
            fn () => Dashboard::query()->where('dashboard_type', DashboardType::CATEGORY)->where('category_id', $category->id)->first(),
            function () use ($category) {
                if ($category->status === MasterStatus::ARCHIVED) {
                    throw ApiException::unprocessable('CATEGORY_ARCHIVED', 'Kategori sudah diarsipkan; dashboard-nya tidak dapat dibuat.');
                }
                $dashboard = $this->newSystemDashboard(DashboardType::CATEGORY, $category->name, "Dashboard kategori {$category->name}.");
                $dashboard->category_id = $category->id;
                $dashboard->save();
                $this->fillFromTemplate($dashboard, $this->resolver->forCategory($category));

                return $dashboard;
            },
        );
    }

    /**
     * The member's asset board (F-06 §4.1, D-21): a personal dashboard provisioned on first
     * open from the organization template mapped to their role. Their edits stay personal;
     * applying the template again starts a fresh copy.
     */
    public function assetBoard(User $user): Dashboard
    {
        return $this->findOrCreate(
            fn () => Dashboard::query()->where('dashboard_type', DashboardType::ASSET_BOARD)
                ->where('created_by', $user->id)->where('status', Dashboard::STATUS_ACTIVE)->first(),
            function () use ($user) {
                $dashboard = new Dashboard(['name' => 'Dashboard Aset', 'description' => 'Dashboard aset Anda, dari template role Anda.', 'visibility' => DashboardType::PERSONAL, 'status' => Dashboard::STATUS_ACTIVE]);
                $dashboard->dashboard_type = DashboardType::ASSET_BOARD;
                $dashboard->created_by = $user->id;
                $dashboard->save();
                $this->fillFromTemplate($dashboard, $this->resolver->forRole($user));

                return $dashboard;
            },
        );
    }

    /** @param  array<string, mixed>  $data */
    public function createCustom(array $data, User $user): Dashboard
    {
        $template = null;
        if (! empty($data['template_id'])) {
            $template = DashboardTemplate::query()->whereKey($data['template_id'])->where('is_active', true)->first()
                ?? throw ApiException::unprocessable('INVALID_TEMPLATE', 'Template tidak ditemukan atau tidak aktif.');
        }

        return DB::transaction(function () use ($data, $user, $template) {
            $dashboard = new Dashboard([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'],
                'status' => Dashboard::STATUS_ACTIVE,
            ]);
            $dashboard->dashboard_type = DashboardType::CUSTOM;
            $dashboard->created_by = $user->id;
            $dashboard->save();
            if ($template !== null) {
                $this->builder->applyTemplate($dashboard, $template);
            }
            $this->audit->record('dashboard.created', $dashboard, $dashboard->id, after: $dashboard->only(self::AUDIT) + [
                'dashboard_type' => $dashboard->dashboard_type, 'template_id' => $template?->id,
            ]);

            return $dashboard->fresh();
        });
    }

    /**
     * Settings (the caller holds the manage level). Organization-wide effects — publishing a
     * personal dashboard to the organization, choosing the organization default — also need
     * dashboard.manage.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Dashboard $dashboard, array $data): Dashboard
    {
        $manager = $this->tenancy->tenant()->can('dashboard.manage');

        if (array_key_exists('visibility', $data) && $data['visibility'] !== $dashboard->visibility) {
            if (! $dashboard->isCustom() || $data['visibility'] === DashboardType::PERSONAL) {
                // Members may already rely on an organization dashboard (shares, defaults).
                throw ApiException::unprocessable('VISIBILITY_LOCKED', 'Dashboard business unit tidak dapat dijadikan dashboard pribadi.');
            }
            if (! $manager) {
                throw ApiException::forbidden();
            }
        }
        $status = $data['status'] ?? $dashboard->status;
        if ($status === Dashboard::STATUS_ARCHIVED && ($dashboard->isOverview() || $dashboard->isAssetBoard() || ($data['is_default'] ?? $dashboard->is_default))) {
            throw ApiException::unprocessable('DASHBOARD_NOT_ARCHIVABLE', 'Overview, dashboard aset, dan dashboard default business unit tidak dapat diarsipkan.');
        }
        if (array_key_exists('is_default', $data) && (bool) $data['is_default'] !== $dashboard->is_default) {
            if (! $manager) {
                throw ApiException::forbidden();
            }
            if ($data['is_default'] && ($data['visibility'] ?? $dashboard->visibility) !== DashboardType::ORGANIZATION) {
                throw ApiException::unprocessable('INVALID_DEFAULT_DASHBOARD', 'Hanya dashboard business unit yang dapat menjadi default business unit.');
            }
        }

        return DB::transaction(function () use ($dashboard, $data) {
            $before = $dashboard->only(self::AUDIT);
            $dashboard->fill(collect($data)->only(['name', 'description', 'visibility', 'status'])->all());
            if (array_key_exists('is_default', $data)) {
                if ($data['is_default']) {
                    Dashboard::query()->where('is_default', true)->whereKeyNot($dashboard->id)->update(['is_default' => false]);
                }
                $dashboard->is_default = (bool) $data['is_default'];
            }
            $dashboard->save();
            $this->audit->record('dashboard.updated', $dashboard, $dashboard->id, $before, $dashboard->only(self::AUDIT));

            return $dashboard;
        });
    }

    /** Soft delete of a custom dashboard; members who chose it as default fall back automatically. */
    public function delete(Dashboard $dashboard): void
    {
        if (! $dashboard->isCustom()) {
            throw ApiException::unprocessable('DASHBOARD_NOT_DELETABLE', 'Overview, dashboard kategori, dan dashboard aset tidak dapat dihapus.');
        }
        DB::transaction(function () use ($dashboard) {
            $dashboard->is_default = false;
            $dashboard->save();
            $dashboard->delete();
            $this->audit->record('dashboard.deleted', null, $dashboard->id, after: ['name' => $dashboard->name, 'visibility' => $dashboard->visibility]);
        });
    }

    /**
     * Explicit template update: replace the widgets with a fresh copy of a template — the given
     * one, else the dashboard's own template, else (system dashboards) the resolved one.
     */
    public function applyTemplate(Dashboard $dashboard, ?string $templateId, ?User $user = null): Dashboard
    {
        if ($templateId !== null) {
            $template = DashboardTemplate::query()->whereKey($templateId)->where('is_active', true)->first();
            if ($template === null || (! $dashboard->isCustom() && ! $dashboard->isAssetBoard() && $template->dashboard_type !== $dashboard->dashboard_type)) {
                throw ApiException::unprocessable('INVALID_TEMPLATE', 'Template tidak ditemukan, tidak aktif, atau tidak sesuai jenis dashboard.');
            }
            if ($dashboard->isAssetBoard() && $template->dashboard_type !== DashboardType::ASSET_BOARD) {
                throw ApiException::unprocessable('INVALID_TEMPLATE', 'Template tidak sesuai jenis dashboard.');
            }
        } else {
            $template = $dashboard->template()->where('is_active', true)->first() ?? match ($dashboard->dashboard_type) {
                DashboardType::OVERVIEW => $this->resolver->forOverview(),
                DashboardType::CATEGORY => $this->resolver->forCategory($dashboard->category()->firstOrFail()),
                DashboardType::ASSET_BOARD => $user !== null ? $this->resolver->forRole($user) : null,
                default => null,
            };
            if ($template === null) {
                throw ApiException::unprocessable('NO_TEMPLATE', 'Dashboard ini tidak memiliki template untuk diterapkan.');
            }
        }

        return DB::transaction(function () use ($dashboard, $template) {
            $before = ['template_id' => $dashboard->template_id, 'template_version' => $dashboard->template_version];
            $this->builder->applyTemplate($dashboard, $template);
            $this->audit->record('dashboard.template_applied', $dashboard, $dashboard->id, $before,
                ['template_id' => $template->id, 'template_version' => $template->version]);

            return $dashboard;
        });
    }

    /**
     * The dashboard the member lands on: their own choice while they can still open it, else
     * the organization default, else the Overview.
     */
    public function defaultFor(User $user): Dashboard
    {
        $preferred = $this->preference($user)?->default_dashboard_id;
        foreach ([$preferred, null] as $candidate) {
            $dashboard = $candidate !== null
                ? Dashboard::query()->whereKey($candidate)->where('status', Dashboard::STATUS_ACTIVE)->first()
                : Dashboard::query()->where('is_default', true)->where('status', Dashboard::STATUS_ACTIVE)->first();
            if ($dashboard !== null && $this->access->level($dashboard) !== null) {
                return $dashboard;
            }
        }

        return $this->overview();
    }

    public function preference(User $user): ?UserDashboardPreference
    {
        return UserDashboardPreference::query()->where('user_id', $user->id)->first();
    }

    /** @return array<string, list<mixed>> */
    public static function preferenceRules(): array
    {
        return [
            'default_dashboard_id' => ['sometimes', 'nullable', 'uuid'],
            'sidebar_preferences' => ['sometimes', 'array:collapsed_sections'],
            'sidebar_preferences.collapsed_sections' => ['sometimes', 'array', 'max:4'],
            'sidebar_preferences.collapsed_sections.*' => ['string', 'distinct', Rule::in(['overview', 'categories', 'mine', 'shared'])],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function savePreference(User $user, array $data): UserDashboardPreference
    {
        if (! empty($data['default_dashboard_id'])) {
            $dashboard = Dashboard::query()->whereKey($data['default_dashboard_id'])->where('status', Dashboard::STATUS_ACTIVE)->first();
            if ($dashboard === null || $this->access->level($dashboard) === null) {
                throw ApiException::unprocessable('INVALID_DEFAULT_DASHBOARD', 'Dashboard default harus dashboard aktif yang dapat Anda buka.');
            }
        }

        $preference = $this->preference($user) ?? new UserDashboardPreference;
        $preference->user_id = $user->id;
        if (array_key_exists('default_dashboard_id', $data)) {
            $preference->default_dashboard_id = $data['default_dashboard_id'] ?: null;
        }
        if (array_key_exists('sidebar_preferences', $data)) {
            $preference->sidebar_preferences = $data['sidebar_preferences'] ?? [];
        }
        $preference->save();

        return $preference;
    }

    /** Level helper for resources and controllers. */
    public function level(Dashboard $dashboard): ?string
    {
        return $this->access->level($dashboard);
    }

    public function can(Dashboard $dashboard, string $level = SharePermission::VIEW): bool
    {
        return $this->access->allows($dashboard, $level);
    }

    private function newSystemDashboard(string $type, string $name, string $description): Dashboard
    {
        $dashboard = new Dashboard(['name' => $name, 'description' => $description, 'status' => Dashboard::STATUS_ACTIVE]);
        $dashboard->dashboard_type = $type;
        $dashboard->visibility = DashboardType::ORGANIZATION;

        return $dashboard;
    }

    private function fillFromTemplate(Dashboard $dashboard, ?DashboardTemplate $template): void
    {
        if ($template !== null) {
            $this->builder->applyTemplate($dashboard, $template);
        }
    }

    /**
     * Find, else create in a transaction. Two first visits at once both try to create; the unique
     * index keeps one and the loser reads the winner's row.
     *
     * @param  Closure(): ?Dashboard  $find
     * @param  Closure(): Dashboard  $create
     */
    private function findOrCreate(Closure $find, Closure $create): Dashboard
    {
        $existing = $find();
        if ($existing !== null) {
            return $existing;
        }
        try {
            // Re-read: the new row's database defaults are not on the model that inserted it.
            return DB::transaction($create)->fresh();
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \RuntimeException('Dashboard creation raced and left no row.');
        }
    }
}
