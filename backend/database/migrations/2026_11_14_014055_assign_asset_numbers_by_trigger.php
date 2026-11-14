<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The asset number becomes an internal identifier assigned by the database itself: a BEFORE INSERT
 * trigger always overwrites whatever the application sent with the next value of the
 * organization's "asset" sequence, formatted like NumberSequence::format() (organization format
 * and time zone, counters per year or month). It is unique per organization (existing unique
 * index), never changes (assets_immutable) and is no longer exposed by the API.
 *
 * Existing assets keep their numbers and the counters continue from where they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_assign_asset_number() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                fmt text;
                tz text;
                local_now timestamp;
                period text;
                seq bigint;
                num text;
                token text[];
            BEGIN
                SELECT s.asset_number_format INTO fmt FROM organization_settings s WHERE s.organization_id = NEW.organization_id;
                fmt := COALESCE(NULLIF(fmt, ''), 'AST-{YYYY}-{SEQ:6}');
                SELECT o.timezone INTO tz FROM organizations o WHERE o.id = NEW.organization_id;
                local_now := now() AT TIME ZONE COALESCE(NULLIF(tz, ''), 'UTC');

                period := CASE
                    WHEN strpos(fmt, '{MM}') > 0 THEN to_char(local_now, 'YYYYMM')
                    WHEN strpos(fmt, '{YYYY}') > 0 OR strpos(fmt, '{YY}') > 0 THEN to_char(local_now, 'YYYY')
                    ELSE ''
                END;

                INSERT INTO number_sequences (organization_id, sequence_key, period_key, next_value, created_at, updated_at)
                VALUES (NEW.organization_id, 'asset', period, 2, now(), now())
                ON CONFLICT (organization_id, sequence_key, period_key)
                DO UPDATE SET next_value = number_sequences.next_value + 1, updated_at = now()
                RETURNING next_value - 1 INTO seq;

                num := replace(replace(replace(replace(fmt,
                    '{YYYY}', to_char(local_now, 'YYYY')),
                    '{YY}', to_char(local_now, 'YY')),
                    '{MM}', to_char(local_now, 'MM')),
                    '{TYPE}', '');
                LOOP
                    token := regexp_match(num, '\{SEQ(?::(\d))?\}');
                    EXIT WHEN token IS NULL;
                    num := regexp_replace(num, '\{SEQ(?::\d)?\}',
                        lpad(seq::text, GREATEST(COALESCE(token[1]::int, 1), length(seq::text)), '0'));
                END LOOP;

                NEW.asset_number := num;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER assets_assign_number BEFORE INSERT ON assets
            FOR EACH ROW EXECUTE FUNCTION ate_assign_asset_number()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS assets_assign_number ON assets');
        DB::statement('DROP FUNCTION IF EXISTS ate_assign_asset_number()');
    }
};
