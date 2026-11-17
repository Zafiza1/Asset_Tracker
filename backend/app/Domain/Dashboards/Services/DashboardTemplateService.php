<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Organization templates. System templates are read-only here (and in the database); an
 * organization copies one to change it. A template starts empty, as a copy of another
 * template, or as a capture of a dashboard's widgets. Replacing a template's widgets raises
 * its version; dashboards made from it keep their widgets until a template update is applied.
 */
final class DashboardTemplateService
{
    private const AUDIT = ['name', 'description', 'dashboard_type', 'category_type_key', 'version', 'is_default', 'is_active'];

    private const CATEGORY_TYPE_KEY = ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'];

    public function __construct(
        private readonly DashboardBuilderService $builder,
        private readonly DashboardAccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'dashboard_type' => ['required', Rule::in(DashboardType::ALL)],
            'category_type_key' => self::CATEGORY_TYPE_KEY,
            'is_default' => ['sometimes', 'boolean'],
            'source_template_id' => ['nullable', 'uuid', 'prohibits:dashboard_id'],
            'dashboard_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_type_key' => ['sometimes', ...self::CATEGORY_TYPE_KEY],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'dashboard_id' => ['sometimes', 'uuid'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $user): DashboardTemplate
    {
        $this->assertCategoryKey($data['dashboard_type'], $data['category_type_key'] ?? null);
        $source = ! empty($data['source_template_id'])
            ? (DashboardTemplate::query()->whereKey($data['source_template_id'])->first()
                ?? throw ApiException::unprocessable('INVALID_TEMPLATE', 'Template sumber tidak ditemukan.'))
            : null;
        $dashboard = ! empty($data['dashboard_id']) ? $this->readableDashboard($data['dashboard_id']) : null;

        return DB::transaction(function () use ($data, $user, $source, $dashboard) {
            $template = new DashboardTemplate([
                'template_key' => $this->availableKey($data['name']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'dashboard_type' => $data['dashboard_type'],
                'category_type_key' => $data['category_type_key'] ?? null,
                'is_active' => true,
            ]);
            $template->scope = DashboardTemplate::SCOPE_ORGANIZATION;
            $template->version = 1;
            $template->created_by = $user->id;
            $template->source_template_id = $source?->id;
            $template->save();
            if (! empty($data['is_default'])) {
                $this->makeDefault($template);
            }

            if ($source !== null) {
                $this->builder->copyTemplateWidgets($source, $template);
            } elseif ($dashboard !== null) {
                $this->builder->captureDashboard($dashboard, $template);
            }
            $this->audit->record('dashboard_template.created', $template, $template->id, after: $template->only(self::AUDIT),
                metadata: array_filter(['source_template_id' => $source?->id, 'dashboard_id' => $dashboard?->id]));

            return $template->fresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(DashboardTemplate $template, array $data): DashboardTemplate
    {
        $this->assertOwned($template);
        if (array_key_exists('category_type_key', $data)) {
            $this->assertCategoryKey($template->dashboard_type, $data['category_type_key']);
        }
        $dashboard = ! empty($data['dashboard_id']) ? $this->readableDashboard($data['dashboard_id']) : null;
        $active = $data['is_active'] ?? $template->is_active;
        if (! empty($data['is_default']) && ! $active) {
            throw ApiException::unprocessable('TEMPLATE_INACTIVE', 'Template nonaktif tidak dapat menjadi template default.');
        }

        return DB::transaction(function () use ($template, $data, $dashboard, $active) {
            $before = $template->only(self::AUDIT);
            $wantDefault = $active && (array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $template->is_default);
            $template->fill(collect($data)->only(['name', 'description', 'category_type_key', 'is_active'])->all());
            // Cleared first: the (type, category key) slot may belong to another default until makeDefault.
            $template->is_default = false;
            if ($dashboard !== null) {
                $this->builder->captureDashboard($dashboard, $template);
                $template->version++;
            }
            $template->save();
            if ($wantDefault) {
                $this->makeDefault($template);
            }
            $this->audit->record('dashboard_template.updated', $template, $template->id, $before, $template->only(self::AUDIT));

            return $template;
        });
    }

    /** Dashboards made from it keep their widgets (template_id becomes NULL). */
    public function delete(DashboardTemplate $template): void
    {
        $this->assertOwned($template);
        DB::transaction(function () use ($template) {
            $template->delete();
            $this->audit->record('dashboard_template.deleted', null, $template->id, after: $template->only(['name', 'dashboard_type', 'category_type_key']));
        });
    }

    private function assertOwned(DashboardTemplate $template): void
    {
        if ($template->isSystem()) {
            throw ApiException::forbidden('SYSTEM_TEMPLATE_READ_ONLY', 'Template sistem tidak dapat diubah. Salin template ini untuk menyesuaikannya.');
        }
    }

    private function assertCategoryKey(string $type, ?string $key): void
    {
        if ($key !== null && $type !== DashboardType::CATEGORY) {
            throw ApiException::unprocessable('INVALID_CATEGORY_TYPE_KEY', 'Kunci tipe kategori hanya berlaku untuk template dashboard kategori.');
        }
    }

    /** Captured dashboards must be ones the member can open (personal ones only by their owner). */
    private function readableDashboard(string $id): Dashboard
    {
        $dashboard = Dashboard::query()->find($id);
        if ($dashboard === null || $this->access->level($dashboard) === null) {
            throw ApiException::unprocessable('INVALID_DASHBOARD', 'Dashboard sumber tidak ditemukan.');
        }

        return $dashboard;
    }

    /** One active default per dashboard type and category type key in the organization. */
    private function makeDefault(DashboardTemplate $template): void
    {
        DashboardTemplate::query()->whereNotNull('organization_id')
            ->where('dashboard_type', $template->dashboard_type)
            ->where(fn ($q) => $template->category_type_key === null
                ? $q->whereNull('category_type_key')
                : $q->where('category_type_key', $template->category_type_key))
            ->whereKeyNot($template->id)->where('is_default', true)
            ->update(['is_default' => false]);
        $template->forceFill(['is_default' => true])->save();
    }

    private function availableKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'template', 50, '');
        $candidate = $base;
        for ($i = 2; DashboardTemplate::query()->whereNotNull('organization_id')->where('template_key', $candidate)->exists(); $i++) {
            $candidate = $base.'_'.$i;
        }

        return $candidate;
    }
}
