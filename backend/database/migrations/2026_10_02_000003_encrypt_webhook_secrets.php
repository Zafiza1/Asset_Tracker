<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook signing secrets are now encrypted at rest (Webhook::$casts).
 * Step 1 widens the column (ciphertext exceeds 255 chars); step 2 backfills
 * existing plaintext rows. Rows that already decrypt are left alone, so the
 * migration is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
        });

        DB::table('webhooks')->whereNotNull('secret')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                try {
                    Crypt::decryptString($row->secret);
                    continue;
                } catch (DecryptException) {
                    // plaintext — encrypt below
                }

                DB::table('webhooks')->where('id', $row->id)->update([
                    'secret' => Crypt::encryptString($row->secret),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible by design: secrets are not written back as plaintext.
    }
};
