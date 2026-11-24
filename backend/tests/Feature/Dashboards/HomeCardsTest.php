<?php

namespace Tests\Feature\Dashboards;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Dashboards\Home\HomeCards;
use App\Domain\Dashboards\Models\UserDashboardPreference;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Home page cards (F-06 §3): chosen by permission, counted within the user's data scope and
 * organization, cached for a minute.
 */
class HomeCardsTest extends TestCase
{
    private Organization $org;

    private User $admin;

    /** @var array<string, mixed> */
    private array $masters;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->masters = $this->createMasterFixtures($this->org);
        $this->relaxDefaultCondition();
        $this->actingAs($this->admin, 'web');
    }

    public function test_cards_follow_the_permissions_of_the_user(): void
    {
        $keys = array_column($this->cards(), 'key');
        // Super Admin: everything, in order, except the own requests (an approver's changes apply directly).
        $this->assertSame(array_values(array_diff(array_keys(HomeCards::CARDS), ['my_requests'])), $keys);

        $this->actingAs($this->member(['transaction.view', 'asset.view']), 'web');
        $this->assertSame(['decisions', 'my_requests', 'my_assets', 'overdue_outgoing', 'overdue_loans', 'due_soon', 'date_reminders'], array_column($this->cards(), 'key'));
        // Without approval.manage the inbox card holds only the steps of transactions.
        $this->assertNull($this->card('decisions')['approvals']);
        $this->assertSame(0, $this->card('decisions')['tasks']['count']);

        $this->actingAs($this->member([]), 'web');
        $this->assertSame(['my_requests'], array_column($this->cards(), 'key'));
    }

    public function test_approvals_to_decide_leave_out_own_requests_and_my_requests_show_the_outcome(): void
    {
        $requester = $this->member(['asset.view']);
        $theirs = $this->approval($requester, 'Tambah lokasi "Pos Jaga 2"');
        $this->approval($this->admin, 'Ubah kategori milik admin');
        $rejected = $this->approval($requester, 'Ubah lokasi Laptop', ApprovalRequest::REJECTED, 'Lokasi tujuan salah.');

        $card = $this->card('decisions')['approvals'];
        $this->assertSame(1, $card['count']);
        $this->assertSame([$theirs], array_column($card['items'], 'id'));
        $this->assertSame($requester->name, $card['items'][0]['requested_by']);

        $this->actingAs($requester, 'web');
        $mine = $this->card('my_requests');
        $this->assertSame(1, $mine['counts']['pending']);
        $this->assertSame(1, $mine['counts']['rejected']);
        $this->assertSame('Lokasi tujuan salah.', collect($mine['items'])->firstWhere('id', $rejected)['decision_note']);
    }

    public function test_overdue_and_due_soon_count_only_assets_in_the_users_scope(): void
    {
        // Transactions are append-only: let time pass instead of editing due dates.
        $this->outgoing($this->asset('Proyektor', $this->masters['location']->id), now()->addDays(5));
        $this->outgoing($this->asset('Genset', $this->otherLocation()), now()->addDays(6));
        $this->travel(13)->days();
        $this->outgoing($this->asset('Kamera', $this->masters['location']->id), now()->addDays(3));

        $overdue = $this->card('overdue_outgoing');
        $this->assertSame(2, $overdue['count']);
        $this->assertSame('Proyektor', $overdue['items'][0]['asset']['name']);   // most overdue first
        $this->assertSame(8, $overdue['items'][0]['days_late']);
        $due = $this->card('due_soon');
        $this->assertSame(1, $due['count']);
        $this->assertSame(['outgoing', 'Kamera'], [$due['items'][0]['kind'], $due['items'][0]['asset']['name']]);

        $this->actingAs($this->member(['transaction.view', 'asset.view'], location: $this->masters['location']->id), 'web');
        $this->assertSame(['Proyektor'], array_column(array_column($this->card('overdue_outgoing')['items'], 'asset'), 'name'));
    }

    public function test_overdue_loans_are_internal_loans_past_their_return_date_in_scope(): void
    {
        $late = $this->asset('Laptop', $this->masters['location']->id);
        $later = $this->asset('Kamera', $this->masters['location']->id);
        $elsewhere = $this->asset('Proyektor', $this->otherLocation());
        $onTime = $this->asset('Printer', $this->masters['location']->id);
        $this->loan($late, now()->subDays(10), now()->subDays(4), 'Budi');
        $this->loan($later, now()->subDays(5), now()->subDays(1), 'Sari');
        $this->loan($elsewhere, now()->subDays(10), now()->subDays(2), 'Andi');
        $this->loan($onTime, now()->subDay(), now()->addDays(3), 'Dewi');
        // Temporary outgoing past its date is a different card.
        $this->outgoing($this->asset('Genset', $this->masters['location']->id), now()->addDay());
        $this->travel(2)->days();

        $card = $this->card('overdue_loans');
        $this->assertSame(3, $card['count']);
        $this->assertSame(['Laptop', 'Proyektor', 'Kamera'], array_column(array_column($card['items'], 'asset'), 'name'));   // most overdue first
        $this->assertSame(['Budi', 6], [$card['items'][0]['borrower_name'], $card['items'][0]['days_late']]);
        // "Lihat semua yang lewat batas": the asset list with overdue=1 has the same loans, not the one on time.
        $this->assertEqualsCanonicalizing(['Laptop', 'Kamera', 'Proyektor'], array_column($this->getJson('/api/assets?overdue=1')->assertOk()->json('data'), 'name'));

        $this->actingAs($this->member(['transaction.view', 'asset.view'], location: $this->masters['location']->id), 'web');
        $this->assertSame(['Laptop', 'Kamera'], array_column(array_column($this->card('overdue_loans')['items'], 'asset'), 'name'));
        $this->assertEqualsCanonicalizing(['Laptop', 'Kamera'], array_column($this->getJson('/api/assets?overdue=1')->assertOk()->json('data'), 'name'));
    }

    public function test_date_reminders_and_incomplete_assets(): void
    {
        $soon = $this->asset('Avanza', $this->masters['location']->id, ['pajak_stnk' => now()->addDays(6)->toDateString(), 'nomor_polisi' => 'D 1234 AB']);
        $this->asset('Beat', $this->masters['location']->id, ['pajak_stnk' => now()->addDays(90)->toDateString(), 'nomor_polisi' => 'D 5678 CD']);
        $noSerial = $this->asset('L300', $this->masters['location']->id, ['pajak_stnk' => 'bukan tanggal']);
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => DB::table('assets')->where('id', $noSerial)->update(['serial_number' => null]));

        // Attributes added after the assets: a required one the assets do not fill counts as missing.
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () {
            foreach ([['pajak_stnk', 'Pajak STNK', 'date', false], ['nomor_polisi', 'Nomor Polisi', 'string', true]] as [$key, $label, $type, $required]) {
                (new TenantCategoryAttribute)->forceFill(['organization_id' => $this->org->id, 'category_id' => $this->masters['category']->id,
                    'key' => $key, 'label' => $label, 'data_type' => $type, 'is_required' => $required])->save();
            }
        });
        $reminders = $this->card('date_reminders');
        $this->assertSame(1, $reminders['count']);
        $this->assertSame([$soon, 'Pajak STNK', now()->addDays(6)->toDateString()],
            [$reminders['items'][0]['asset']['id'], $reminders['items'][0]['label'], $reminders['items'][0]['due_date']]);

        $incomplete = $this->card('incomplete_assets');
        $this->assertSame(['no_serial_number' => 1, 'missing_required_attribute' => 1, 'no_location' => 0], $incomplete['counts']);
        $this->assertSame(1, $incomplete['count']);   // the same asset lacks both
    }

    public function test_another_organization_sees_none_of_this_and_cards_are_cached_for_a_minute(): void
    {
        $this->approval($this->member([]), 'Pengajuan ALPHA');
        $this->assertSame(1, $this->card('decisions')['count']);

        ['admin' => $other] = $this->createOrganization('BETA');
        $this->actingAs($other, 'web');
        $this->assertSame(0, $this->card('decisions')['count']);
        $this->assertNotContains('Pengajuan ALPHA', array_column($this->card('decisions')['approvals']['items'], 'summary'));

        // A new request shows after the cache expires, not before.
        $this->actingAs($this->admin, 'web');
        $this->approval($this->member([]), 'Pengajuan kedua');
        $this->assertSame(1, $this->card('decisions')['count']);
        $this->travel(HomeCards::CACHE_SECONDS + 1)->seconds();
        $this->assertSame(2, $this->card('decisions')['count']);
    }

    public function test_an_arrangement_saved_with_the_former_inbox_cards_moves_to_the_merged_card(): void
    {
        $saved = fn (array $cards) => $this->tenancy()->runAsSystem(fn () => (UserDashboardPreference::query()->firstWhere('user_id', $this->admin->id) ?? new UserDashboardPreference)
            ->forceFill(['organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'home_cards' => $cards])->save());
        $preferences = fn () => $this->getJson('/api/home/cards')->json('meta.preferences');

        // It takes the place of the first of them, once.
        $saved(['order' => ['my_requests', 'transaction_tasks', 'approvals_to_decide', 'license'], 'hidden' => []]);
        $this->assertSame(['order' => ['my_requests', 'decisions', 'license'], 'hidden' => []], $preferences());

        // Hidden only when every one of them was hidden.
        $saved(['order' => [], 'hidden' => ['transaction_tasks']]);
        $this->travel(HomeCards::CACHE_SECONDS + 1)->seconds();
        $this->assertSame(['order' => [], 'hidden' => ['decisions']], $preferences());
        $saved(['order' => ['approvals_to_decide'], 'hidden' => ['transaction_tasks']]);
        $this->assertSame(['order' => ['decisions'], 'hidden' => []], $preferences());
    }

    public function test_each_member_keeps_their_own_card_order_and_hidden_cards(): void
    {
        $this->assertSame(['order' => [], 'hidden' => []], $this->getJson('/api/home/cards')->json('meta.preferences'));

        $this->putJson('/api/home/preferences', ['order' => ['my_requests', 'decisions'], 'hidden' => ['recent_activity']])
            ->assertOk()->assertJsonPath('data.hidden', ['recent_activity']);
        $this->assertSame(['order' => ['my_requests', 'decisions'], 'hidden' => ['recent_activity']],
            $this->getJson('/api/home/cards')->json('meta.preferences'));

        // Only card keys, each once.
        $this->putJson('/api/home/preferences', ['order' => ['bukan_kartu'], 'hidden' => []])->assertUnprocessable();
        $this->putJson('/api/home/preferences', ['order' => ['my_requests', 'my_requests'], 'hidden' => []])->assertUnprocessable();
        $this->putJson('/api/home/preferences', ['order' => []])->assertUnprocessable();

        // A member without any dashboard permission has their own, untouched preferences.
        $this->actingAs($this->member([]), 'web');
        $this->assertSame(['order' => [], 'hidden' => []], $this->getJson('/api/home/cards')->json('meta.preferences'));
        $this->putJson('/api/home/preferences', ['order' => [], 'hidden' => ['my_requests']])->assertOk();
        $this->actingAs($this->admin, 'web');
        $this->assertSame(['recent_activity'], $this->getJson('/api/home/cards')->json('meta.preferences.hidden'));
    }

    /** @return list<array<string, mixed>> */
    private function cards(): array
    {
        return $this->getJson('/api/home/cards')->assertOk()->assertJsonPath('meta.refresh_seconds', HomeCards::CACHE_SECONDS)->json('data');
    }

    /** @return array<string, mixed> */
    private function card(string $key): array
    {
        return collect($this->cards())->firstWhere('key', $key) ?? $this->fail("Card {$key} missing");
    }

    /** @param list<string> $permissions */
    private function member(array $permissions, ?string $location = null): User
    {
        static $n = 0;
        $role = $this->createRole($this->org, 'HOME_ROLE_'.(++$n), ['organization.view', ...$permissions])->code;
        $scopes = $location === null ? [['scope_type' => 'organization']] : [['scope_type' => 'location', 'ref_id' => $location]];

        return $this->createTenantUser($this->org, [$role], $scopes);
    }

    private function approval(User $requester, string $summary, string $status = ApprovalRequest::PENDING, ?string $note = null): string
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () use ($requester, $summary, $status, $note) {
            $approval = (new ApprovalRequest)->forceFill([
                'organization_id' => $this->org->id, 'category' => 'master', 'action' => 'location.create', 'summary' => $summary,
                'method' => 'POST', 'path' => 'api/locations', 'payload' => ['name' => 'X'], 'requested_by' => $requester->id,
                'status' => $status,
                ...($status === ApprovalRequest::PENDING ? [] : ['decided_by' => $this->admin->id, 'decided_at' => now(), 'decision_note' => $note]),
            ]);
            $approval->save();

            return $approval->id;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function asset(string $name, string $location, array $attributes = []): string
    {
        $id = $this->postJson('/api/assets', [
            'name' => $name, 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $location,
        ])->assertCreated()->json('data.id');
        if ($attributes !== []) {
            $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => DB::table('assets')->where('id', $id)
                ->update(['attribute_values' => DB::raw('attribute_values || '.DB::getPdo()->quote(json_encode($attributes)).'::jsonb')]));
        }

        return $id;
    }

    private function otherLocation(): string
    {
        return $this->postJson('/api/locations', ['name' => 'Gudang Lain '.Str::random(4)])->assertCreated()->json('data.id');
    }

    private function loan(string $assetId, \DateTimeInterface $on, \DateTimeInterface $due, string $borrower): void
    {
        $this->postJson('/api/transactions', [
            'asset_id' => $assetId, 'type' => 'loan', 'borrower_name' => $borrower,
            'transaction_date' => $on->format('Y-m-d'), 'due_date' => $due->format('Y-m-d'),
        ])->assertCreated();
    }

    private function outgoing(string $assetId, \DateTimeInterface $due): void
    {
        $this->postJson('/api/transactions', [
            'asset_id' => $assetId, 'type' => 'outgoing', 'outgoing_nature' => 'temporary', 'reason_code' => 'external_repair',
            'counterparty_name' => 'CV Servis', 'due_date' => $due->format('Y-m-d'),
        ])->assertCreated();
    }
}
