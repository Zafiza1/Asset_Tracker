<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Home page card preferences (docs/factory/features/F-06 §3.2, D-19): each member's own order
 * of the home cards and the cards they hid, as {"order": [...], "hidden": [...]} of card keys
 * (HomeCards::CARDS). Kept on the existing per-member preference row, so the RLS "own" policy
 * already limits it to that member.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement("ALTER TABLE user_dashboard_preferences ADD COLUMN home_cards jsonb NOT NULL DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE user_dashboard_preferences ADD CONSTRAINT user_dashboard_preferences_home_cards_check
            CHECK (jsonb_typeof(home_cards) = 'object')");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE user_dashboard_preferences DROP CONSTRAINT IF EXISTS user_dashboard_preferences_home_cards_check');
        DB::statement('ALTER TABLE user_dashboard_preferences DROP COLUMN IF EXISTS home_cards');
    }
};
