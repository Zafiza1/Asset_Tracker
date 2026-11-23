<?php

namespace App\Domain\AssetTransactions\Services;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual asset transactions. A transaction covers one or more assets; it is recorded and
 * applied to them in one database transaction while the asset rows are locked, so concurrent
 * transactions on the same asset are serialized and the history always matches the asset's
 * current state.
 *
 * Rules (kept deliberately small):
 *  - archived assets accept no transactions;
 *  - an asset on loan must be returned before it is moved or its status changes;
 *  - an asset outside the organization (off_site) must come back (incoming) first, likewise;
 *  - disposed assets cannot be moved or lent (a status change can still correct the status).
 *
 * Outgoing/incoming (docs/factory/features/F-02, APR-0006): an outgoing transaction sends active
 * or inactive assets to an outside party, temporarily (off_site, planned return date) or
 * permanently (disposed, needs asset.dispose); an incoming transaction brings an off_site asset
 * back and links to the outgoing row that sent it.
 *
 * Organization's own types (docs/factory/features/F-07a): type 'custom' with an active
 * TransactionTypeDefinition; the entered values are validated against its fields and stored on
 * the row. The asset must belong to one of the type's categories (or a subcategory). Such a
 * transaction records information only; changes to the asset come with flow actions (F-07b).
 *
 * Flows (F-07b): an asset held by a running transaction (transaction_run_assets.locked) takes no
 * other transaction until that one ends (D-29). Flow actions go through applyForRun(), which
 * applies the same core rules under the run's number, without the user's permission or scope
 * checks: the run was authorized when it started.
 */
final class AssetTransactionService
{
    /** Extra permission per type, on top of transaction.create (checked by the route). */
    private const PERMISSIONS = [
        TransactionType::TRANSFER => 'asset.transfer',
        TransactionType::STATUS_CHANGE => 'asset.status_change',
        TransactionType::LOAN => 'asset.assign',
        TransactionType::RETURN => 'asset.assign',
        TransactionType::OUTGOING => 'asset.outgoing',
        TransactionType::INCOMING => 'asset.incoming',
    ];

    /** The permission recording this built-in transaction needs (none for a note). */
    public static function permissionFor(string $type): ?string
    {
        return self::PERMISSIONS[$type] ?? null;
    }

    /** What the built-in form asks besides assets, date and notes; a system type's run keeps it (F-07f-2). */
    public const SYSTEM_INPUT = ['to_location_id', 'to_status', 'borrower_name', 'due_date', 'outgoing_nature', 'reason_code',
        'counterparty_name', 'counterparty_org', 'counterparty_contact', 'document_number'];

    /** Set while a flow action applies (applyForRun): permission and scope were checked at the start. */
    private bool $flowAction = false;

    /** Status an incoming asset may come back with. */
    private const INCOMING_STATUSES = [AssetStatus::AVAILABLE, AssetStatus::UNDER_MAINTENANCE];

    public function __construct(
        private readonly AssetScope $scope,
        private readonly TransactionLog $log,
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
        private readonly TransactionFieldTypes $fieldTypes,
    ) {}

    /**
     * One transaction covering every asset in $data['asset_ids']: each asset gets its own history
     * row and all rows share one transaction number. All or nothing: if one asset breaks a
     * rule, nothing is recorded and the error names that asset.
     *
     * @param  array<string, mixed>  $data  validated input
     * @return list<AssetTransaction>
     */
    public function create(array $data): array
    {
        $type = $data['type'];
        $this->authorize($type, $data);
        $definition = $type === TransactionType::CUSTOM ? $this->customType($data) : null;
        // A built-in type is its system type's row (F-07f): the fields the organization added to it
        // are asked too; without such fields nothing changes.
        $system = $definition === null ? TransactionTypeDefinition::system($type)?->load('fields') : null;
        $fields = ($definition ?? $system)?->fields;
        if ($definition !== null || $fields?->isNotEmpty()) {
            $data['field_values'] = $this->fieldTypes->values($fields, $data['field_values'] ?? [], $this->log->today());
        } else {
            unset($data['field_values']);
        }

        return DB::transaction(function () use ($type, $data, $definition, $system, $fields) {
            $assets = $this->lockAssets($data['asset_ids']);
            self::assertNotHeldByRun($assets, $definition ?? $system);
            $number = $this->log->nextNumber($type, $definition?->code);

            $rows = array_map(fn (Asset $asset) => $this->withAssetContext($asset, count($assets) > 1,
                fn () => $this->apply($asset, $type, $data, $number, $definition)), $assets);
            if (isset($data['field_values'])) {
                $this->fieldTypes->claimAttachments($fields, $data['field_values'], null, $number);
            }

            return $rows;
        });
    }

    /**
     * Before a system type's run starts (F-07f-2): the user may record this built-in type, and its
     * core rules hold for every asset now. Nothing is saved; the core action applies them later.
     *
     * @param  list<Asset>  $assets  locked
     * @param  array<string, mixed>  $data  the built-in input, transaction_date and notes
     */
    public function assertApplicable(string $type, array $assets, array $data): void
    {
        $this->authorize($type, $data);
        foreach ($assets as $asset) {
            $this->withAssetContext($asset, count($assets) > 1, fn () => $this->changes($asset, $type, $data));
        }
    }

    /**
     * The core action of a system type's run (F-07f-2): the built-in transaction with what the
     * form asked at the start, for every asset of the run, under the run's number. Permission and
     * scope were checked when the run started (assertApplicable); the core rules apply again now.
     *
     * @param  array<string, mixed>  $data  the run's system_values; field_values go on the rows
     * @return int number of assets recorded
     */
    public function applyCoreForRun(TransactionRun $run, string $type, array $data): int
    {
        if (! in_array($type, TransactionType::MANUAL, true)) {
            throw new \LogicException("{$type} is not a system transaction type.");
        }
        $this->flowAction = true;
        try {
            $assets = Asset::query()->whereIn('id', $run->assets()->select('assets.id'))->orderBy('id')->lockForUpdate()->get();
            foreach ($assets as $asset) {
                $this->withAssetContext($asset, $assets->count() > 1, fn () => $this->apply($asset, $type, [
                    ...$data, 'run_id' => $run->id, 'transaction_date' => $run->transaction_date->toDateString(), 'notes' => $run->notes,
                ], $run->transaction_number));
            }

            return $assets->count();
        } finally {
            $this->flowAction = false;
        }
    }

    /** @param array<string, mixed> $data */
    private function apply(Asset $asset, string $type, array $data, string $number, ?TransactionTypeDefinition $definition = null): AssetTransaction
    {
        [$assetChanges, $logChanges] = $this->changes($asset, $type, $data, $definition);

        if (isset($data['run_id'])) {
            $logChanges['run_id'] = $data['run_id'];
        }
        // Values of the fields an organization added to a system type (F-07f-2).
        if ($type !== TransactionType::CUSTOM && ($data['field_values'] ?? []) !== []) {
            $logChanges['field_values'] = $data['field_values'];
        }
        if ($assetChanges !== []) {
            $asset->forceFill([...$assetChanges, 'lock_version' => $asset->lock_version + 1, 'updated_by' => $this->tenancy->tenant()->userId()])->save();
        }
        $transaction = $this->log->record($asset, $type, $logChanges, $data['transaction_date'] ?? null, $data['notes'] ?? null, $number);
        $this->audit->record('asset_transaction.created', $transaction, after: [
            'transaction_number' => $transaction->transaction_number,
            'asset_name' => $asset->name,
            'type' => $type,
            ...($definition !== null ? ['type_name' => $definition->name] : []),
            ...$logChanges,
        ]);

        return $transaction;
    }

    /**
     * The core rules of one type for one asset: what would change on the asset and what the
     * history row records. Nothing is saved; a rule that is broken throws.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(Asset $asset, string $type, array $data, ?TransactionTypeDefinition $definition = null): array
    {
        if ($asset->isArchived()) {
            throw ApiException::conflict('ASSET_ARCHIVED', 'Aset nonaktif tidak dapat ditransaksikan. Aktifkan kembali terlebih dahulu.');
        }

        return match ($type) {
            TransactionType::TRANSFER => $this->transfer($asset, $data),
            TransactionType::STATUS_CHANGE => $this->statusChange($asset, $data),
            TransactionType::LOAN => $this->loan($asset, $data),
            TransactionType::RETURN => $this->return($asset, $data),
            TransactionType::NOTE => [[], []],
            TransactionType::OUTGOING => $this->outgoing($asset, $data),
            TransactionType::INCOMING => $this->incoming($asset, $data),
            TransactionType::CUSTOM => $this->custom($asset, $definition, $data),
        };
    }

    /**
     * In a multi-asset transaction, prefix rule failures with the asset they are about.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withAssetContext(Asset $asset, bool $many, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ApiException $e) {
            if (! $many) {
                throw $e;
            }
            $prefix = "{$asset->name}: ";
            $details = $e->details;
            foreach ($details['fields'] ?? [] as $field => $messages) {
                $details['fields'][$field] = array_map(fn ($m) => $prefix.$m, $messages);
            }

            throw new ApiException($e->errorCode, $prefix.$e->getMessage(), $e->status, [...$details, 'asset_id' => $asset->id]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function transfer(Asset $asset, array $data): array
    {
        $this->assertStatusNotIn($asset, [AssetStatus::ON_LOAN, AssetStatus::OFF_SITE, AssetStatus::LOST, AssetStatus::DISPOSED], 'dipindahkan');
        $to = $data['to_location_id'] ?? null;
        if ($to === null) {
            throw $this->fieldError('to_location_id', 'Lokasi tujuan wajib dipilih.');
        }
        if ($to === $asset->location_id) {
            throw $this->fieldError('to_location_id', 'Lokasi tujuan sama dengan lokasi saat ini.');
        }
        $this->assertTargetLocation($to, $asset);

        return [['location_id' => $to], ['from_location_id' => $asset->location_id, 'to_location_id' => $to]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function statusChange(Asset $asset, array $data): array
    {
        $this->assertStatusNotIn($asset, [AssetStatus::ON_LOAN, AssetStatus::OFF_SITE], 'diubah statusnya');
        $status = $data['to_status'] ?? null;
        if ($status === null || $status === $asset->status) {
            throw $this->fieldError('to_status', 'Pilih status baru yang berbeda dari saat ini.');
        }

        return [['status' => $status], ['from_status' => $asset->status, 'to_status' => $status]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function loan(Asset $asset, array $data): array
    {
        if (! in_array($asset->status, [AssetStatus::AVAILABLE, AssetStatus::IN_USE], true)) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Hanya aset berstatus Aktif atau Tidak Aktif yang dapat dipinjamkan (status saat ini: '.AssetStatus::LABELS[$asset->status].').');
        }
        $borrower = trim((string) ($data['borrower_name'] ?? ''));
        if ($borrower === '') {
            throw $this->fieldError('borrower_name', 'Nama peminjam wajib diisi.');
        }
        $due = $data['due_date'] ?? null;
        if ($due !== null && $due < ($data['transaction_date'] ?? $this->log->today())) {
            throw $this->fieldError('due_date', 'Rencana tanggal kembali tidak boleh sebelum tanggal peminjaman.');
        }

        return [
            ['status' => AssetStatus::ON_LOAN, 'borrower_name' => $borrower, 'loan_due_date' => $due],
            ['from_status' => $asset->status, 'to_status' => AssetStatus::ON_LOAN, 'borrower_name' => $borrower, 'due_date' => $due],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function return(Asset $asset, array $data): array
    {
        if ($asset->status !== AssetStatus::ON_LOAN) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Aset ini tidak sedang dipinjam.');
        }
        $assetChanges = ['status' => AssetStatus::AVAILABLE, 'borrower_name' => null, 'loan_due_date' => null];
        $logChanges = ['from_status' => AssetStatus::ON_LOAN, 'to_status' => AssetStatus::AVAILABLE, 'borrower_name' => $asset->borrower_name];

        $location = $data['to_location_id'] ?? null;
        if ($location !== null && $location !== $asset->location_id) {
            $this->assertTargetLocation($location, $asset);
            $assetChanges['location_id'] = $location;
            $logChanges += ['from_location_id' => $asset->location_id, 'to_location_id' => $location];
        }

        return [$assetChanges, $logChanges];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function outgoing(Asset $asset, array $data): array
    {
        if (! in_array($asset->status, [AssetStatus::AVAILABLE, AssetStatus::IN_USE], true)) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Hanya aset berstatus Aktif atau Tidak Aktif yang dapat dikeluarkan (status saat ini: '.AssetStatus::LABELS[$asset->status].').');
        }
        $nature = $data['outgoing_nature'] ?? null;
        if (! in_array($nature, OutgoingReason::NATURES, true)) {
            throw $this->fieldError('outgoing_nature', 'Pilih sifat transaksi keluar: sementara atau permanen.');
        }
        $reason = $data['reason_code'] ?? null;
        if ($reason === null || ! OutgoingReason::allows($nature, $reason)) {
            throw $this->fieldError('reason_code', 'Pilih alasan keluar yang sesuai dengan sifatnya.');
        }
        if ($reason === OutgoingReason::OTHER && trim((string) ($data['notes'] ?? '')) === '') {
            throw $this->fieldError('notes', 'Keterangan wajib diisi untuk alasan "Lainnya".');
        }
        $counterparty = trim((string) ($data['counterparty_name'] ?? ''));
        if ($counterparty === '') {
            throw $this->fieldError('counterparty_name', 'Nama pihak luar wajib diisi.');
        }

        $due = null;
        $status = AssetStatus::DISPOSED;
        if ($nature === OutgoingReason::TEMPORARY) {
            $due = $data['due_date'] ?? null;
            if ($due === null) {
                throw $this->fieldError('due_date', 'Rencana tanggal kembali wajib diisi untuk transaksi keluar sementara.');
            }
            if ($due < ($data['transaction_date'] ?? $this->log->today())) {
                throw $this->fieldError('due_date', 'Rencana tanggal kembali tidak boleh sebelum tanggal keluar.');
            }
            $status = AssetStatus::OFF_SITE;
        }

        return [['status' => $status], [
            'from_status' => $asset->status,
            'to_status' => $status,
            'outgoing_nature' => $nature,
            'reason_code' => $reason,
            'counterparty_name' => $counterparty,
            'counterparty_org' => self::trimmed($data['counterparty_org'] ?? null),
            'counterparty_contact' => self::trimmed($data['counterparty_contact'] ?? null),
            'document_number' => self::trimmed($data['document_number'] ?? null),
            'due_date' => $due,
        ]];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function incoming(Asset $asset, array $data): array
    {
        if ($asset->status !== AssetStatus::OFF_SITE) {
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Hanya aset berstatus Di Luar Organisasi yang dapat dicatat masuk kembali.');
        }
        $outgoing = AssetTransaction::query()->where('asset_id', $asset->id)
            ->where('type', TransactionType::OUTGOING)->orderByDesc('seq')->first();
        if ($outgoing === null || $outgoing->outgoing_nature !== OutgoingReason::TEMPORARY) {
            throw ApiException::conflict('OUTGOING_NOT_FOUND', 'Transaksi keluar sementara untuk aset ini tidak ditemukan.');
        }
        $date = $data['transaction_date'] ?? $this->log->today();
        if ($date < $outgoing->transaction_date->toDateString()) {
            throw $this->fieldError('transaction_date', 'Tanggal masuk tidak boleh sebelum tanggal keluar ('.$outgoing->transaction_date->toDateString().').');
        }
        $status = $data['to_status'] ?? AssetStatus::AVAILABLE;
        if (! in_array($status, self::INCOMING_STATUSES, true)) {
            throw $this->fieldError('to_status', 'Status saat kembali hanya Tidak Aktif atau Dalam Perbaikan.');
        }

        $assetChanges = ['status' => $status];
        $logChanges = [
            'from_status' => AssetStatus::OFF_SITE,
            'to_status' => $status,
            'outgoing_transaction_id' => $outgoing->id,
            'counterparty_name' => self::trimmed($data['counterparty_name'] ?? null) ?? $outgoing->counterparty_name,
            'counterparty_org' => self::trimmed($data['counterparty_org'] ?? null) ?? $outgoing->counterparty_org,
            'document_number' => self::trimmed($data['document_number'] ?? null),
        ];

        $location = $data['to_location_id'] ?? null;
        if ($location !== null && $location !== $asset->location_id) {
            $this->assertTargetLocation($location, $asset);
            $assetChanges['location_id'] = $location;
            $logChanges += ['from_location_id' => $asset->location_id, 'to_location_id' => $location];
        }

        return [$assetChanges, $logChanges];
    }

    private static function trimmed(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A flow action (F-07b): a status change, a move, a loan to a person or its return, for every
     * asset of the run, recorded under the run's number. Assets already in the wanted state are
     * left alone. Core rules still apply (an asset on loan is not lent again, and so on).
     *
     * @param  array<string, mixed>  $data  to_status, to_location_id, or borrower_name and due_date
     * @return int number of assets changed
     */
    public function applyForRun(TransactionRun $run, string $type, array $data): int
    {
        if (! in_array($type, [TransactionType::STATUS_CHANGE, TransactionType::TRANSFER, TransactionType::LOAN, TransactionType::RETURN], true)) {
            throw new \LogicException("Flow actions do not record {$type} transactions.");
        }
        $this->flowAction = true;
        try {
            $assets = Asset::query()->whereIn('id', $run->assets()->select('assets.id'))->orderBy('id')->lockForUpdate()->get();
            $changed = 0;
            foreach ($assets as $asset) {
                $unchanged = ($type === TransactionType::STATUS_CHANGE && $asset->status === ($data['to_status'] ?? null))
                    || ($type === TransactionType::TRANSFER && $asset->location_id === ($data['to_location_id'] ?? null))
                    || ($type === TransactionType::RETURN && $asset->status !== AssetStatus::ON_LOAN);
                if ($unchanged) {
                    continue;
                }
                $this->withAssetContext($asset, $assets->count() > 1, fn () => $this->apply($asset, $type, [
                    ...$data, 'run_id' => $run->id, 'transaction_date' => $run->transaction_date->toDateString(), 'notes' => $data['notes'] ?? null,
                ], $run->transaction_number));
                $changed++;
            }

            return $changed;
        } finally {
            $this->flowAction = false;
        }
    }

    /**
     * Assets held by a running transaction take no other transaction (D-29). One exception: a
     * transaction of the type that closes the run's type (closes_type_id), while the run waits
     * for it at a "wait for the paired transaction" step.
     *
     * @param  list<Asset>  $assets
     * @return list<string> ids of the runs the transaction closes (their assets stay theirs)
     */
    public static function assertNotHeldByRun(array $assets, ?TransactionTypeDefinition $closing = null): array
    {
        $held = DB::table('transaction_run_assets as ra')->join('transaction_runs as r', 'r.id', '=', 'ra.run_id')
            ->whereIn('ra.asset_id', array_map(fn (Asset $a) => $a->id, $assets))->where('ra.locked', true)
            ->orderBy('ra.asset_id')->get(['ra.asset_id', 'r.id as run_id', 'r.transaction_number', 'r.type_definition_id']);
        $closes = [];
        foreach ($held as $row) {
            $pairs = $closing !== null && $closing->closes_type_id === $row->type_definition_id
                && TransactionRun::query()->with('flowVersion')->find($row->run_id)?->awaitsPair();
            if ($pairs) {
                $closes[] = $row->run_id;

                continue;
            }
            $name = collect($assets)->firstWhere('id', $row->asset_id)?->name;
            throw ApiException::conflict('ASSET_LOCKED', "{$name} sedang dalam transaksi {$row->transaction_number} yang belum selesai.", ['asset_id' => $row->asset_id, 'transaction_number' => $row->transaction_number]);
        }

        return array_values(array_unique($closes));
    }

    /**
     * The active organization type a 'custom' transaction uses, with its fields and categories.
     *
     * @param  array<string, mixed>  $data
     */
    private function customType(array $data): TransactionTypeDefinition
    {
        // A system type is recorded as its built-in type, never as 'custom' (its core rules would be skipped).
        $definition = TransactionTypeDefinition::query()->organizationOwn()->with(['fields', 'categories:id'])->find($data['type_definition_id'] ?? null);
        if ($definition === null) {
            throw ValidationException::withMessages(['type_definition_id' => 'Jenis transaksi tidak ditemukan.']);
        }
        if (! $definition->isActive()) {
            throw ApiException::unprocessable('TYPE_INACTIVE', "Jenis \"{$definition->name}\" tidak aktif sehingga tidak dapat dipakai mencatat transaksi.");
        }
        // A copy of a system type (F-08) records only through its flow's core action: recorded at
        // once it would be information only and skip the core rules.
        if ($definition->core_type !== null) {
            throw ApiException::unprocessable('FLOW_REQUIRED', "Jenis \"{$definition->name}\" disalin dari jenis sistem; terbitkan alurnya terlebih dahulu.");
        }
        if (! $definition->multi_asset && count($data['asset_ids']) > 1) {
            throw ValidationException::withMessages(['asset_ids' => "Jenis \"{$definition->name}\" hanya untuk satu aset per transaksi."]);
        }

        return $definition;
    }

    /**
     * An organization's type records information only (F-07a): the asset must fall in one of the
     * type's categories or their subcategories; nothing on the asset changes.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function custom(Asset $asset, TransactionTypeDefinition $definition, array $data): array
    {
        self::assertCategoryAllowed($asset, $definition);

        return [[], ['type_definition_id' => $definition->id, 'field_values' => $data['field_values']]];
    }

    /** The asset falls in one of the type's categories or their subcategories (none: every category). */
    public static function assertCategoryAllowed(Asset $asset, TransactionTypeDefinition $definition): void
    {
        $allowed = $definition->categories->pluck('id')->all();
        if ($allowed !== []) {
            $path = (string) $asset->category()->value('path');
            $inside = array_filter($allowed, fn (string $id) => str_contains($path, '/'.$id.'/'));
            if ($inside === []) {
                throw ApiException::unprocessable('CATEGORY_NOT_ALLOWED', "Jenis \"{$definition->name}\" tidak berlaku untuk kategori aset {$asset->name}.");
            }
        }
    }

    private function authorize(string $type, array $data): void
    {
        $context = $this->tenancy->tenant();
        $permission = self::PERMISSIONS[$type] ?? null;
        if ($permission !== null && ! $context->can($permission)) {
            throw ApiException::forbidden();
        }
        $disposes = ($type === TransactionType::STATUS_CHANGE && ($data['to_status'] ?? null) === AssetStatus::DISPOSED)
            || ($type === TransactionType::OUTGOING && ($data['outgoing_nature'] ?? null) === OutgoingReason::PERMANENT);
        if ($disposes && ! $context->can('asset.dispose')) {
            throw ApiException::forbidden('FORBIDDEN', 'Anda tidak memiliki izin untuk menghapuskan aset.');
        }
    }

    /**
     * Lock the asset rows (in id order, so concurrent multi-asset transactions cannot deadlock);
     * assets outside the tenant or the user's data scope are 404. Returned in the given order.
     *
     * @param  list<string>  $ids
     * @return list<Asset>
     */
    private function lockAssets(array $ids): array
    {
        $assets = $this->scope->apply(Asset::query())->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($assets->count() !== count($ids)) {
            throw new ApiException('NOT_FOUND', 'Aset tidak ditemukan.', 404, ['fields' => ['asset_ids' => ['Aset tidak ditemukan.']]]);
        }

        return array_map(fn (string $id) => $assets[$id], $ids);
    }

    /** @param list<string> $statuses */
    private function assertStatusNotIn(Asset $asset, array $statuses, string $action): void
    {
        if (in_array($asset->status, $statuses, true)) {
            $hint = match ($asset->status) {
                AssetStatus::ON_LOAN => ' Catat pengembalian terlebih dahulu.',
                AssetStatus::OFF_SITE => ' Catat transaksi masuk terlebih dahulu.',
                default => '',
            };
            throw ApiException::conflict('INVALID_ASSET_STATUS', 'Aset berstatus '.AssetStatus::LABELS[$asset->status]." tidak dapat {$action}.{$hint}");
        }
    }

    /** An active location that keeps the asset within the user's scope (by location or by its department). */
    private function assertTargetLocation(string $id, Asset $asset): void
    {
        if (! Location::query()->whereKey($id)->where('status', MasterStatus::ACTIVE)->exists()) {
            throw $this->fieldError('to_location_id', 'Lokasi tidak ditemukan atau tidak aktif.');
        }
        if (! $this->flowAction && ! $this->scope->allowsAsset($id, $asset->department_id)) {
            throw $this->fieldError('to_location_id', 'Lokasi berada di luar cakupan data Anda.');
        }
    }

    private function fieldError(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('VALIDATION_FAILED', $message, ['fields' => [$field => [$message]]]);
    }
}
