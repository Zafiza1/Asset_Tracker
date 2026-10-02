<?php

use App\Models\IntegrationConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: encrypt secret integration config values that were stored in
 * plaintext before IntegrationConfig started encrypting on save. Rows are
 * marked with the "enc:" prefix, so the migration is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_configs')
            ->where('is_secret', true)
            ->whereNotNull('value')
            ->where('value', 'not like', IntegrationConfig::ENCRYPTED_PREFIX . '%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('integration_configs')->where('id', $row->id)->update([
                        'value' => IntegrationConfig::ENCRYPTED_PREFIX . Crypt::encryptString($row->value),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: decrypting secrets back to plaintext
        // would weaken storage. Encrypted rows remain readable by the model.
    }
};
