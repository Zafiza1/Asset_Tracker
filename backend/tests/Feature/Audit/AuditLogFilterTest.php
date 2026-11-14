<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use Tests\TestCase;

class AuditLogFilterTest extends TestCase
{
    private Organization $org;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('AUDT');
        $this->other = $this->createTenantUser($this->org, ['ADMIN'], attributes: ['name' => 'Budi Pencatat', 'email' => 'budi@audit.test']);

        $this->log('asset.image_updated', $this->other, 'Asset', '11111111-1111-4111-8111-111111111111', after: ['name' => 'Laptop Kantor', 'asset_number' => 'AST-0099']);
        $this->log('user.updated', $this->admin, 'User', $this->other->id, before: ['name' => 'Budi'], after: ['name' => 'Budi Pencatat']);
        $this->log('location.archived', $this->other, 'Location', '22222222-2222-4222-8222-222222222222');
    }

    private function log(string $action, User $actor, string $type, string $id, ?array $before = null, ?array $after = null): void
    {
        $this->tenancy()->runAsSystem(fn () => $this->app->make(AuditLogger::class)->record(
            $action, $type, $id, before: $before, after: $after, organizationId: $this->org->id, actor: $actor,
        ));
    }

    /** @return list<string> */
    private function actions(string $query): array
    {
        $this->actingAs($this->admin, 'web');

        return collect($this->getJson('/api/audit-logs?per_page=100&'.$query)->assertOk()->json('data'))
            ->pluck('action')->filter(fn ($a) => in_array($a, ['asset.image_updated', 'user.updated', 'location.archived'], true))
            ->sort()->values()->all();
    }

    public function test_filters_by_actor(): void
    {
        $this->assertSame(['asset.image_updated', 'location.archived'], $this->actions('actor_user_id='.$this->other->id));
    }

    public function test_filters_by_module_prefixes_and_action_list(): void
    {
        $this->assertSame(['asset.image_updated', 'user.updated'], $this->actions('module=asset,user'));
        $this->assertSame(['location.archived'], $this->actions('actions=location.archived,role.deleted'));
    }

    public function test_search_matches_actor_snapshot_and_readable_label_codes(): void
    {
        $this->assertSame(['asset.image_updated', 'location.archived'], $this->actions('search=budi@audit'));
        $this->assertSame(['asset.image_updated'], $this->actions('search=Laptop%20Kantor'));
        // Older entries still hold the internal asset number; it is neither searchable nor shown.
        $this->assertSame([], $this->actions('search=AST-0099'));
        $this->assertSame(['location.archived'], $this->actions('search=arsip&search_actions=location.archived'));
    }

    public function test_rejects_malformed_code_lists(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->getJson("/api/audit-logs?module=asset'--")->assertUnprocessable();
        $this->getJson('/api/audit-logs?actions=a%25')->assertUnprocessable();
    }

    public function test_date_filter_includes_the_whole_last_day(): void
    {
        $today = now()->toDateString();

        $this->assertCount(3, $this->actions("from={$today}&to={$today}"));
    }

    public function test_rows_carry_object_label_from_snapshot_and_actor_email(): void
    {
        $this->actingAs($this->admin, 'web');

        $row = collect($this->getJson('/api/audit-logs?action=asset.image_updated')->assertOk()->json('data'))->first();

        $this->assertSame(['code' => null, 'name' => 'Laptop Kantor'], $row['entity_label']);
        $this->assertSame(['name' => 'Laptop Kantor'], $row['after']);
        $this->assertSame('budi@audit.test', $row['actor']['email']);

        $user = collect($this->getJson('/api/audit-logs?action=user.updated')->json('data'))->first();
        $this->assertSame(['code' => 'budi@audit.test', 'name' => 'Budi Pencatat'], $user['entity_label']);
    }

    public function test_actor_list_contains_only_actors_of_this_organization(): void
    {
        ['admin' => $foreign] = $this->createOrganization('LAIN');
        $this->actingAs($this->admin, 'web');

        $ids = collect($this->getJson('/api/audit-logs/actors')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($this->other->id, $ids);
        $this->assertContains($this->admin->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_actor_list_needs_audit_permission(): void
    {
        $viewer = $this->createTenantUser($this->org);
        $this->actingAs($viewer, 'web');

        $this->assertErrorCode($this->getJson('/api/audit-logs/actors'), 403, 'FORBIDDEN');
    }
}
