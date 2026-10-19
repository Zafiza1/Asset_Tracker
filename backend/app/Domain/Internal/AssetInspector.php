<?php

namespace App\Domain\Internal;

use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Generic Data internal container (developers only, console access; never exposed over HTTP).
 *
 * Shows the internal identity of an asset and every technical reference behind the
 * user-facing data, and verifies that replaying its transactions reproduces the asset's
 * current state. Callers run it in system context.
 */
final class AssetInspector
{
    /** @return array<string, mixed> */
    public function inspect(Asset $asset): array
    {
        $asset->loadMissing(['category.genericCategory', 'location', 'organization']);
        $transactions = $this->transactions($asset);

        return [
            'identity' => [
                'id' => $asset->id,
                'organization' => $asset->organization->code.' ('.$asset->organization_id.')',
                'asset_number' => $asset->asset_number,
                'lock_version' => $asset->lock_version,
                'created_at' => $asset->created_at?->toIso8601String(),
                'archived_at' => $asset->archived_at?->toIso8601String(),
            ],
            'references' => [
                'category' => $asset->category ? "{$asset->category->code} ({$asset->category_id})" : $asset->category_id,
                'category_path' => $asset->category?->path,
                'generic_category' => $asset->category?->genericCategory ? "{$asset->category->genericCategory->code} ({$asset->category->generic_category_id})" : null,
                'location' => $asset->location ? "{$asset->location->code} ({$asset->location_id})" : null,
                'status' => $asset->status,
            ],
            'transactions' => $transactions->map(fn (AssetTransaction $t) => [
                'number' => $t->transaction_number,
                'type' => $t->type,
                'date' => $t->transaction_date->toDateString(),
                'recorded_at' => $t->created_at->toIso8601String(),
                'id' => $t->id,
            ])->all(),
            'audit_entries' => AuditLog::query()->where('entity_id', $asset->id)->count(),
            'problems' => $this->verify($asset, $transactions->all()),
        ];
    }

    /**
     * Problems found when replaying the history; empty = consistent.
     *
     * @param  list<AssetTransaction>|null  $transactions
     * @return list<string>
     */
    public function verify(Asset $asset, ?array $transactions = null): array
    {
        $transactions ??= $this->transactions($asset)->all();
        $problems = [];

        if ($transactions === [] || $transactions[0]->type !== TransactionType::REGISTRATION) {
            $problems[] = 'History does not start with a registration transaction.';
        }
        $registrations = count(array_filter($transactions, fn ($t) => $t->type === TransactionType::REGISTRATION));
        if ($registrations !== 1) {
            $problems[] = "Expected exactly 1 registration transaction, found {$registrations}.";
        }

        $state = ['location_id' => null, 'status' => null, 'archived' => false];
        foreach ($transactions as $t) {
            $state['location_id'] = $t->to_location_id ?? $state['location_id'];
            $state['status'] = $t->to_status ?? $state['status'];
            $state['archived'] = match ($t->type) {
                TransactionType::ARCHIVE => true,
                TransactionType::RESTORE => false,
                default => $state['archived'],
            };
        }

        $actual = ['location_id' => $asset->location_id, 'status' => $asset->status, 'archived' => $asset->isArchived()];
        foreach ($actual as $field => $value) {
            if ($state[$field] !== $value) {
                $problems[] = sprintf('%s is %s but the history replays to %s.', $field, var_export($value, true), var_export($state[$field], true));
            }
        }

        return $problems;
    }

    /** @return Collection<int, AssetTransaction> */
    private function transactions(Asset $asset)
    {
        return AssetTransaction::query()->where('asset_id', $asset->id)->orderBy('seq')->get();
    }
}
