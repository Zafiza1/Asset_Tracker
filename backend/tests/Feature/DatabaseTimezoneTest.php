<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Timestamps written by Laravel (UTC, without offset) must be stored as the correct
 * instant regardless of the PostgreSQL server's default timezone.
 */
class DatabaseTimezoneTest extends TestCase
{
    public function test_connections_use_utc_sessions(): void
    {
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
        $this->assertSame('UTC', DB::connection('pgsql_owner')->selectOne('SHOW timezone')->TimeZone);
    }

    public function test_application_timestamps_are_stored_as_the_current_instant(): void
    {
        ['organization' => $org] = $this->createOrganization('ALPHA');

        $drift = DB::selectOne('SELECT abs(extract(epoch FROM now() - created_at)) AS seconds FROM organizations WHERE id = ?', [$org->id])->seconds;

        $this->assertLessThan(60, (float) $drift);
    }
}
