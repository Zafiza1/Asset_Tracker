<?php

namespace App\Domain\AssetTransactions\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Flows\RunAccess;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Models\TransactionAttachment;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Files of attachment fields (F-07 §3.2). A file is uploaded for one field of one type, checked
 * against that field's accepted kinds and size (never above the organization's max_upload_mb),
 * and referenced by id in the form. Saving the form claims it for the run or transaction; only
 * the uploader sees an unclaimed file, afterwards whoever sees the transaction.
 */
final class TransactionAttachments
{
    public const DISK = 'local';

    /** Kinds a field may accept => file extensions. No SVG or HTML: they can carry script. */
    public const KINDS = [
        'pdf' => ['pdf'],
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'office' => ['doc', 'docx', 'xls', 'xlsx'],
    ];

    public const KIND_LABELS = ['pdf' => 'PDF', 'image' => 'gambar (JPG, PNG, WEBP)', 'office' => 'Word/Excel'];

    /** Unclaimed uploads older than this are removed (scheduled). */
    public const PRUNE_AFTER_HOURS = 24;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
        private readonly RunAccess $runs,
    ) {}

    public function upload(TransactionTypeDefinition $definition, string $fieldKey, UploadedFile $file): TransactionAttachment
    {
        $field = $definition->fields()->where('key', $fieldKey)->where('data_type', 'attachment')->first()
            ?? throw ValidationException::withMessages(['field_key' => 'Field lampiran tidak ada di jenis ini.']);
        $accept = self::accepted($field);
        $maxMb = $this->maxMb($field);

        // Both the content (mimes) and the name (extensions) must be an accepted kind.
        $allowed = implode(',', array_merge(...array_map(fn (string $k) => self::KINDS[$k], $accept)));
        $labels = implode(', ', array_map(fn (string $k) => self::KIND_LABELS[$k], $accept));
        Validator::make(['file' => $file], ['file' => ['required', 'file', "mimes:{$allowed}", "extensions:{$allowed}", 'max:'.($maxMb * 1024)]], [
            'file.mimes' => "File harus berformat {$labels}.",
            'file.extensions' => "File harus berformat {$labels}.",
            'file.max' => "Ukuran file maksimal {$maxMb} MB.",
        ])->validate();

        $orgId = $this->tenancy->organizationId();
        $path = $file->store("transactions/{$orgId}/attachments", self::DISK);
        if ($path === false) {
            throw ApiException::unprocessable('UPLOAD_FAILED', 'File gagal disimpan. Coba lagi.');
        }
        $attachment = new TransactionAttachment;
        $attachment->forceFill([
            'type_definition_id' => $definition->id, 'field_key' => $field->key, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 120), 'size' => (int) $file->getSize(),
            'uploaded_by' => $this->tenancy->tenant()->userId(),
        ])->save();

        return $attachment;
    }

    /**
     * May the current user use this attachment as the value of the field? Unclaimed files only by
     * their uploader; a file already claimed by $runId stays usable in that run.
     */
    public function usable(string $id, TransactionTypeField $field, ?string $runId): bool
    {
        return TransactionAttachment::query()->whereKey($id)
            ->where('type_definition_id', $field->type_definition_id)->where('field_key', $field->key)
            ->where(fn ($w) => $w->where(fn ($u) => $u->whereNull('claimed_at')->where('uploaded_by', $this->tenancy->tenant()->userId()))
                ->when($runId !== null, fn ($q) => $q->orWhere('run_id', $runId)))
            ->exists();
    }

    /**
     * Claim the files referenced by the values for a run or a transaction number.
     *
     * @param  iterable<TransactionTypeField>  $fields
     * @param  array<string, mixed>  $values
     */
    public function claim(iterable $fields, array $values, ?string $runId, ?string $transactionNumber): void
    {
        $ids = [];
        foreach ($fields as $field) {
            if ($field->data_type === 'attachment' && is_string($values[$field->key] ?? null)) {
                $ids[] = $values[$field->key];
            }
        }
        if ($ids === []) {
            return;
        }
        TransactionAttachment::query()->whereKey($ids)->whereNull('claimed_at')
            ->update(['run_id' => $runId, 'transaction_number' => $runId === null ? $transactionNumber : null, 'claimed_at' => now()]);
    }

    /**
     * Summaries of the files referenced by values, keyed by id, for display.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, name: string, size: int, mime_type: string}>
     */
    public static function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TransactionAttachment::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (TransactionAttachment $a) => [$a->id => $a->summary()])->all();
    }

    /** The uploader, or anyone who sees the run or the transaction that holds it. */
    public function assertReadable(TransactionAttachment $attachment): void
    {
        $userId = $this->tenancy->tenant()->userId();
        $readable = $attachment->uploaded_by === $userId
            || ($attachment->run_id !== null && $this->runs->visible()->whereKey($attachment->run_id)->exists())
            || ($attachment->transaction_number !== null && AssetTransaction::query()->where('transaction_number', $attachment->transaction_number)
                ->whereIn('asset_id', $this->scope->apply(Asset::query())->select('id'))->exists());
        if (! $readable) {
            throw new ApiException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
    }

    /** Removes unclaimed uploads past PRUNE_AFTER_HOURS (across organizations; run as system). */
    public static function prune(Tenancy $tenancy): int
    {
        return $tenancy->runAsSystem(function () {
            $old = TransactionAttachment::query()->withoutGlobalScopes()->whereNull('claimed_at')
                ->where('created_at', '<', now()->subHours(self::PRUNE_AFTER_HOURS))->get(['id', 'path']);
            foreach ($old as $attachment) {
                Storage::disk(self::DISK)->delete($attachment->path);
            }

            return TransactionAttachment::query()->withoutGlobalScopes()->whereKey($old->pluck('id'))->delete();
        });
    }

    /** @return list<string> */
    public static function accepted(TransactionTypeField $field): array
    {
        $accept = array_values(array_intersect(array_keys(self::KINDS), (array) ($field->settings['accept'] ?? [])));

        return $accept === [] ? array_keys(self::KINDS) : $accept;
    }

    private function maxMb(TransactionTypeField $field): int
    {
        $org = (int) (OrganizationSetting::query()->where('organization_id', $this->tenancy->organizationId())->value('max_upload_mb') ?: 20);
        $own = (int) ($field->settings['max_mb'] ?? $org);

        return max(1, min($own, $org));
    }
}
