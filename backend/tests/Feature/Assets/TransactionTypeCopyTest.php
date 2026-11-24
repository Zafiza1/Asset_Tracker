<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-08h (D-47): "Salin jenis". A copy is a new draft organization type with the source's fields
 * and flow. A copy of a system type keeps its core action (core_type): the core rules and
 * permission still apply, and it records only through its published flow.
 */
class TransactionTypeCopyTest extends TestCase
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

    public function test_a_copy_of_a_system_type_keeps_its_core_action_and_fields(): void
    {
        $loan = $this->systemType(TransactionType::LOAN);
        $this->putJson("/api/transaction-types/{$loan}", ['fields' => [
            ['key' => 'no_bast', 'label' => 'No. BAST', 'data_type' => 'text', 'is_required' => false],
        ]])->assertOk();

        $copy = $this->postJson("/api/transaction-types/{$loan}/duplicate", ['code' => 'PJP', 'name' => 'Peminjaman proyek'])
            ->assertCreated()
            ->assertJsonPath('data.system_type', null)
            ->assertJsonPath('data.core_type', TransactionType::LOAN)
            ->assertJsonPath('data.status', TransactionTypeDefinition::DRAFT)
            ->assertJsonPath('data.fields.0.key', 'no_bast')
            ->json('data');

        // Without a flow of its own the copy starts from Mulai → Aksi inti → Selesai, as a draft.
        $flow = $this->getJson("/api/transaction-types/{$copy['id']}/flow")->assertOk()->json('data');
        $draft = $flow['draft']['graph'] ?? $flow['graph'] ?? null;
        $this->assertNotNull($draft, 'the copy has a draft flow');
        $this->assertSame(['start', 'action', 'end'], array_column($draft['nodes'], 'type'));
        $this->assertSame([['kind' => 'core']], $draft['nodes'][1]['config']['actions']);

        // The system type itself is unchanged.
        $this->getJson("/api/transaction-types/{$loan}")->assertOk()
            ->assertJsonPath('data.system_type', TransactionType::LOAN)
            ->assertJsonPath('data.core_type', null);
        $this->assertTrue($this->audited('transaction_type.duplicated'));
    }

    public function test_a_copy_records_only_through_its_published_flow_and_keeps_the_core_rules(): void
    {
        $copy = $this->activeCopy(TransactionType::LOAN, 'PJP');
        $asset = $this->asset();

        // Recorded at once it would be information only and skip the core rules.
        $this->assertErrorCode($this->record($copy, $asset, ['borrower_name' => 'Budi']), 422, 'FLOW_REQUIRED');

        $this->publish($copy);
        // The core input and rules of a loan.
        $this->record($copy, $asset)->assertStatus(422)->assertJsonValidationErrors(['borrower_name'], 'error.details.fields');
        $this->createRole($this->org, 'PENCATAT', ['asset.view', 'transaction.view', 'transaction.create']);
        $this->actingAs($this->createTenantUser($this->org, ['PENCATAT']), 'web');
        $this->assertErrorCode($this->record($copy, $asset, ['borrower_name' => 'Budi']), 403, 'FORBIDDEN');
        $this->actingAs($this->admin, 'web');

        // Mulai → Aksi inti → Selesai: completed at once, as the built-in loan under the run's number.
        $run = $this->record($copy, $asset, ['borrower_name' => 'Budi', 'due_date' => now()->addWeek()->toDateString()])
            ->assertCreated()->json('data');
        $this->assertSame('completed', $run['state']);
        $this->getJson("/api/assets/{$asset}")->assertJsonPath('data.status', 'on_loan')->assertJsonPath('data.borrower_name', 'Budi');
        $rows = $this->rows($asset);
        $this->assertSame([['registration', null], ['loan', $run['id']]], array_map(fn ($r) => [$r->type, $r->run_id], $rows));
        $this->assertSame($run['transaction_number'], $rows[1]->transaction_number);
    }

    public function test_a_copy_of_a_copy_keeps_the_core_type_and_an_own_type_copy_has_none(): void
    {
        $copy = $this->activeCopy(TransactionType::TRANSFER, 'MTX');
        $this->postJson("/api/transaction-types/{$copy}/duplicate", ['code' => 'MTY', 'name' => 'Mutasi kedua'])
            ->assertCreated()->assertJsonPath('data.core_type', TransactionType::TRANSFER);

        $own = $this->postJson('/api/transaction-types', ['code' => 'KAL', 'name' => 'Kalibrasi', 'status' => 'active', 'fields' => [
            ['key' => 'vendor', 'label' => 'Vendor', 'data_type' => 'text', 'is_required' => true],
        ]])->assertCreated()->json('data.id');
        $this->postJson("/api/transaction-types/{$own}/duplicate", ['code' => 'KALB', 'name' => 'Kalibrasi luar'])
            ->assertCreated()
            ->assertJsonPath('data.core_type', null)
            ->assertJsonPath('data.fields.0.key', 'vendor');

        // The code stays unique per organization.
        $this->postJson("/api/transaction-types/{$own}/duplicate", ['code' => 'KAL', 'name' => 'Lagi'])->assertStatus(422);
    }

    public function test_copying_needs_both_type_and_flow_rights(): void
    {
        $loan = $this->systemType(TransactionType::LOAN);
        $this->createRole($this->org, 'JENIS', ['asset.view', 'transaction.view', 'transaction_type.manage']);
        $this->actingAs($this->createTenantUser($this->org, ['JENIS']), 'web');

        $this->assertErrorCode($this->postJson("/api/transaction-types/{$loan}/duplicate", ['code' => 'PJP', 'name' => 'Salinan']), 403, 'FORBIDDEN');
    }

    public function test_the_core_type_is_fixed_and_never_on_a_system_type(): void
    {
        $copy = $this->activeCopy(TransactionType::LOAN, 'PJP');
        $loan = $this->systemType(TransactionType::LOAN);

        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), function () use ($copy, $loan) {
            foreach ([
                fn () => DB::table('transaction_type_definitions')->where('id', $copy)->update(['core_type' => TransactionType::TRANSFER]),
                fn () => DB::table('transaction_type_definitions')->where('id', $copy)->update(['core_type' => null]),
                fn () => DB::table('transaction_type_definitions')->where('id', $loan)->update(['core_type' => TransactionType::LOAN]),
            ] as $change) {
                try {
                    DB::transaction($change);
                    $this->fail('the change should be refused');
                } catch (QueryException) {
                    // refused by the guard trigger or the check constraint
                }
            }
        });
        $this->getJson("/api/transaction-types/{$copy}")->assertJsonPath('data.core_type', TransactionType::LOAN);
    }

    /** An active copy of a system type, with its starter draft flow. */
    private function activeCopy(string $type, string $code): string
    {
        $id = $this->postJson("/api/transaction-types/{$this->systemType($type)}/duplicate", ['code' => $code, 'name' => "Salinan {$code}"])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/transaction-types/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');

        return $id;
    }

    private function publish(string $typeId): void
    {
        $this->postJson("/api/transaction-types/{$typeId}/flow/publish")->assertOk()->assertJsonPath('data.status', 'published');
    }

    /** @param array<string, mixed> $input */
    private function record(string $typeId, string $assetId, array $input = [])
    {
        return $this->postJson('/api/transactions', ['asset_id' => $assetId, 'type' => 'custom', 'type_definition_id' => $typeId, ...$input]);
    }

    private function systemType(string $type): string
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin), fn () => TransactionTypeDefinition::system($type)->id);
    }

    private function asset(): string
    {
        return $this->postJson('/api/assets', [
            'name' => 'Laptop '.Str::random(4), 'serial_number' => 'SN-'.Str::uuid(), 'status' => 'available',
            'category_id' => $this->masters['category']->id, 'location_id' => $this->masters['location']->id,
        ])->assertCreated()->json('data.id');
    }

    /** @return list<object> the asset's history rows, oldest first */
    private function rows(string $assetId): array
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('asset_transactions')->where('asset_id', $assetId)->orderBy('seq')->get()->all());
    }

    private function audited(string $action): bool
    {
        return DB::table('audit_logs')->where('organization_id', $this->org->id)->where('action', $action)->exists();
    }
}
