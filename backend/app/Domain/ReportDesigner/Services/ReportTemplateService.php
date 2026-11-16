<?php

namespace App\Domain\ReportDesigner\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\Models\ReportTemplate;
use App\Domain\ReportDesigner\Models\ReportTemplateVersion;
use App\Domain\ReportDesigner\TemplateStatus;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Template lifecycle of the Custom Report Designer.
 *
 * A template owns a chain of versions. At most one draft version exists at a time: saving
 * design changes updates that draft, or starts a new draft (version_number + 1) when the
 * latest version is already published — published versions are immutable (DB trigger).
 * Publishing a draft freezes it and points the template's active_version at it; exports
 * pin the version they render, so later edits never rewrite history.
 */
final class ReportTemplateService
{
    /** Minimal valid designer document stored when the client sends no design yet. */
    public const DEFAULT_DESIGN = [
        'schema' => 1,
        'page' => ['size' => 'A4', 'orientation' => 'portrait'],
        'elements' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Validation rules shared by store and update. */
    public static function rules(bool $creating = false): array
    {
        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:60'],
            'data_source' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['asset', 'asset_transaction'])],
            'design' => ['sometimes', 'array'],
            'excel_config' => ['sometimes', 'array'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $user): ReportTemplate
    {
        $this->assertCodeAvailable($data['code']);

        return DB::transaction(function () use ($data, $user) {
            $template = new ReportTemplate([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'data_source' => $data['data_source'],
                'status' => TemplateStatus::DRAFT,
            ]);
            $template->created_by = $user->id;
            $template->save();

            $this->newDraftVersion($template, $user, $data['design'] ?? self::DEFAULT_DESIGN, $data['excel_config'] ?? [], $data['change_note'] ?? null);
            $this->audit->record('report_template.created', $template, $template->id, after: [
                'code' => $template->code, 'name' => $template->name, 'data_source' => $template->data_source,
            ]);

            return $template->fresh()->loadCount('versions');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ReportTemplate $template, array $data, User $user): ReportTemplate
    {
        return DB::transaction(function () use ($template, $data, $user) {
            $before = $template->only(['code', 'name', 'description', 'category', 'data_source', 'status']);
            if (isset($data['code']) && $data['code'] !== $template->code) {
                $this->assertCodeAvailable($data['code'], $template->id);
            }
            $template->fill(collect($data)->only(['code', 'name', 'description', 'category'])->all());
            // The row source is fixed at creation: a published layout is meaningless against
            // another source's fields.
            $template->save();

            if (array_key_exists('design', $data) || array_key_exists('excel_config', $data) || array_key_exists('change_note', $data)) {
                $this->saveDraft($template, $user, $data);
            }

            $this->audit->record('report_template.updated', $template, $template->id, $before, $template->only(array_keys($before)));

            return $template->fresh()->loadCount('versions');
        });
    }

    /** Copy of a template (meta + latest design) as a new draft owned by the same organization. */
    public function duplicate(ReportTemplate $template, User $user): ReportTemplate
    {
        return DB::transaction(function () use ($template, $user) {
            $source = $template->versions()->orderByDesc('version_number')->first()
                ?? $template->versions()->where('version_number', $template->active_version)->first();
            $copy = new ReportTemplate([
                'code' => $this->availableCode($template->code),
                'name' => $template->name.' (Salinan)',
                'description' => $template->description,
                'category' => $template->category,
                'data_source' => $template->data_source,
                'status' => TemplateStatus::DRAFT,
            ]);
            $copy->created_by = $user->id;
            $copy->save();

            $this->newDraftVersion($copy, $user, $source?->design ?? self::DEFAULT_DESIGN, $source?->excel_config ?? [], 'Disalin dari '.$template->code);
            $this->audit->record('report_template.duplicated', $copy, $copy->id, metadata: [
                'source_template_id' => $template->id, 'source_code' => $template->code,
            ]);

            return $copy->fresh()->loadCount('versions');
        });
    }

    /** Freeze a draft version and make it the template's active version. */
    public function publish(ReportTemplate $template, ?int $versionNumber, User $user): ReportTemplate
    {
        return DB::transaction(function () use ($template, $versionNumber, $user) {
            $draft = $template->versions()
                ->where('status', TemplateStatus::DRAFT)
                ->when($versionNumber, fn ($q) => $q->where('version_number', $versionNumber))
                ->orderByDesc('version_number')
                ->first();

            if ($draft === null) {
                throw ApiException::unprocessable('NO_DRAFT_VERSION', 'Template tidak memiliki versi draft untuk dipublikasikan.');
            }

            $draft->status = TemplateStatus::PUBLISHED;
            $draft->published_at = now();
            $draft->published_by = $user->id;
            $draft->save();

            $template->status = TemplateStatus::PUBLISHED;
            $template->active_version = $draft->version_number;
            $template->save();

            $this->audit->record('report_template.published', $template, $template->id, after: [
                'version' => $draft->version_number,
            ]);

            return $template->fresh()->loadCount('versions');
        });
    }

    public function archive(ReportTemplate $template): ReportTemplate
    {
        if ($template->status === TemplateStatus::ARCHIVED) {
            return $template;
        }
        $template->status = TemplateStatus::ARCHIVED;
        $template->save();
        $this->audit->record('report_template.archived', $template, $template->id);

        return $template;
    }

    /** Soft delete: exports keep their pinned version, the row stays for history. */
    public function delete(ReportTemplate $template): void
    {
        $template->delete();
        $this->audit->record('report_template.deleted', null, $template->id, after: [
            'code' => $template->code, 'name' => $template->name,
        ]);
    }

    /** Update the working draft, or open the next version when the latest is published. */
    private function saveDraft(ReportTemplate $template, User $user, array $data): void
    {
        $draft = $template->versions()->where('status', TemplateStatus::DRAFT)->orderByDesc('version_number')->first();
        if ($draft !== null) {
            $draft->fill([
                'design' => $data['design'] ?? $draft->design,
                'excel_config' => $data['excel_config'] ?? $draft->excel_config,
                'change_note' => $data['change_note'] ?? $draft->change_note,
            ])->save();

            return;
        }

        $latest = $template->versions()->orderByDesc('version_number')->first();
        $this->newDraftVersion($template, $user, $data['design'] ?? $latest?->design ?? self::DEFAULT_DESIGN,
            $data['excel_config'] ?? $latest?->excel_config ?? [], $data['change_note'] ?? null);
    }

    /** @param  array<string, mixed>  $design */
    private function newDraftVersion(ReportTemplate $template, User $user, array $design, array $excelConfig, ?string $note): ReportTemplateVersion
    {
        $next = ((int) $template->versions()->max('version_number')) + 1;
        $version = new ReportTemplateVersion([
            'template_id' => $template->id,
            'version_number' => $next,
            'status' => TemplateStatus::DRAFT,
            'design' => $design,
            'excel_config' => $excelConfig,
            'change_note' => $note,
            'created_by' => $user->id,
        ]);
        $version->save();

        return $version;
    }

    private function assertCodeAvailable(string $code, ?string $exceptId = null): void
    {
        $taken = ReportTemplate::withTrashed()->where('code', $code)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ApiException::conflict('TEMPLATE_CODE_TAKEN', 'Kode template sudah digunakan. Pilih kode lain.');
        }
    }

    private function availableCode(string $base): string
    {
        $candidate = Str::limit($base, 60 - 6, '').'-COPY';
        for ($i = 2; ReportTemplate::withTrashed()->where('code', $candidate)->exists(); $i++) {
            $candidate = Str::limit($base, 60 - strlen((string) $i) - 6, '').'-COPY-'.$i;
        }

        return $candidate;
    }
}
