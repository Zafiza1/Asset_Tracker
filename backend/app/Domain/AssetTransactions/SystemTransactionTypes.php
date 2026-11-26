<?php

namespace App\Domain\AssetTransactions;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seven built-in transaction types as rows of every organization (F-07f, D-26): code =
 * the built-in number prefix (reserved, so never taken by an organization type), name = the
 * built-in label. A system type without a published flow records at once, exactly like the
 * built-in type always did; flows for system types come with F-07f-2.
 */
final class SystemTransactionTypes
{
    /** system_type => [description, locks_assets, closes]. A NOTE never holds its assets (D-29). */
    public const TYPES = [
        TransactionType::TRANSFER => ['Memindah aset ke lokasi lain dalam business unit.', true, null],
        TransactionType::STATUS_CHANGE => ['Mengubah status aset.', true, null],
        TransactionType::LOAN => ['Meminjamkan aset kepada seseorang dengan rencana tanggal kembali.', true, null],
        TransactionType::RETURN => ['Mencatat aset pinjaman yang sudah kembali.', true, TransactionType::LOAN],
        TransactionType::NOTE => ['Mencatat aktivitas tanpa mengubah aset.', false, null],
        TransactionType::OUTGOING => ['Aset keluar business unit, sementara atau permanen.', true, null],
        TransactionType::INCOMING => ['Aset yang keluar sementara masuk kembali.', true, TransactionType::OUTGOING],
    ];

    /** Adds the missing system types of one organization; existing rows are left as they are. */
    public static function install(string $organizationId): void
    {
        $existing = DB::table('transaction_type_definitions')->where('organization_id', $organizationId)
            ->whereNotNull('system_type')->pluck('id', 'system_type')->all();
        $now = now();
        foreach (self::TYPES as $type => [$description, $locks]) {
            if (isset($existing[$type])) {
                continue;
            }
            $existing[$type] = (string) Str::uuid7();
            DB::table('transaction_type_definitions')->insert([
                'id' => $existing[$type],
                'organization_id' => $organizationId,
                'system_type' => $type,
                'code' => TransactionType::PREFIXES[$type],
                'name' => TransactionType::LABELS[$type],
                'description' => $description,
                'multi_asset' => true,
                'locks_assets' => $locks,
                'status' => TransactionTypeDefinition::ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        // Pairs (Berpasangan): a return closes a loan, an incoming closes an outgoing.
        foreach (self::TYPES as $type => [, , $closes]) {
            if ($closes !== null) {
                DB::table('transaction_type_definitions')->where('id', $existing[$type])->whereNull('closes_type_id')
                    ->update(['closes_type_id' => $existing[$closes]]);
            }
        }
    }
}
