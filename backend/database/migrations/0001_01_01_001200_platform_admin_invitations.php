<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first Organization Administrator of an organization created on the platform console is
 * invited, never assigned: the owner of the address accepts the invitation (or signs up through
 * it) before any membership exists.
 *
 *  - invited_by (a member of the organization) becomes optional; such an invitation is issued by
 *    invited_by_platform_user_id instead, a platform account. Exactly one of the two is set.
 *  - Existing rows all have invited_by, so nothing needs to be copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE organization_invitations ALTER COLUMN invited_by DROP NOT NULL');
        DB::statement('ALTER TABLE organization_invitations ADD COLUMN invited_by_platform_user_id uuid NULL
            CONSTRAINT organization_invitations_platform_inviter_fk REFERENCES users (id)');
        DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_inviter_check
            CHECK ((invited_by IS NULL) <> (invited_by_platform_user_id IS NULL))');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_invitation_platform_inviter() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.invited_by_platform_user_id IS NOT NULL
                    AND (SELECT user_type FROM users WHERE id = NEW.invited_by_platform_user_id) IS DISTINCT FROM 'platform' THEN
                    RAISE EXCEPTION 'Only platform accounts can issue platform invitations' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER organization_invitations_platform_inviter BEFORE INSERT OR UPDATE ON organization_invitations
            FOR EACH ROW EXECUTE FUNCTION ate_invitation_platform_inviter()');
    }

    /** Fails while platform-issued invitations exist (they have no member inviter to fall back to). */
    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP TRIGGER organization_invitations_platform_inviter ON organization_invitations');
        DB::statement('DROP FUNCTION IF EXISTS ate_invitation_platform_inviter()');
        DB::statement('ALTER TABLE organization_invitations DROP CONSTRAINT organization_invitations_inviter_check');
        DB::statement('ALTER TABLE organization_invitations DROP COLUMN invited_by_platform_user_id');
        DB::statement('ALTER TABLE organization_invitations ALTER COLUMN invited_by SET NOT NULL');
    }
};
