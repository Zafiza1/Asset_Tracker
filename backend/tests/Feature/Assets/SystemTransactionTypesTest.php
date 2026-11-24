<?php

namespace Tests\Feature\Assets;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\SystemTransactionTypes;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F-07f-1 (D-26): the seven built-in transaction types are rows of every organization. They keep
 * their name, code and rules, are never usable as an organization type, and without a flow
 * recording them behaves exactly as before (AssetTransactionTest stays unchanged).
 */
class SystemTransactionTypesTest extends TestCase
{
    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['organization' => $this->org, 'admin' => $this->admin] = $this->createOrganization('ALPHA');
        $this->actingAs($this->admin, 'web');
    }

    public function test_a_new_organization_has_the_seven_system_types(): void
    {
        $types = $this->systemTypes();

        $this->assertEqualsCanonicalizing(TransactionType::MANUAL, $types->keys()->all());
        foreach ($types as $type => $row) {
            $this->assertSame(TransactionType::PREFIXES[$type], $row->code, $type);
            $this->assertSame(TransactionType::LABELS[$type], $row->name, $type);
            $this->assertSame(TransactionTypeDefinition::ACTIVE, $row->status, $type);
        }
        // A note never holds its assets (D-29); returns close loans, incomings close outgoings.
        $this->assertFalse($types[TransactionType::NOTE]->locks_assets);
        $this->assertSame($types[TransactionType::LOAN]->id, $types[TransactionType::RETURN]->closes_type_id);
        $this->assertSame($types[TransactionType::OUTGOING]->id, $types[TransactionType::INCOMING]->closes_type_id);

        // Another organization has its own set.
        ['organization' => $orgB] = $this->createOrganization('BETA');
        $this->assertSame(7, DB::table('transaction_type_definitions')->where('organization_id', $orgB->id)->whereNotNull('system_type')->count());
    }

    public function test_install_adds_only_what_is_missing(): void
    {
        $this->tenancy()->runAsSystem(function () {
            DB::table('transaction_type_definitions')->where('organization_id', $this->org->id)->where('system_type', TransactionType::INCOMING)->delete();
            SystemTransactionTypes::install($this->org->id);
            SystemTransactionTypes::install($this->org->id);
        });

        $types = $this->systemTypes();
        $this->assertCount(7, $types);
        $this->assertSame($types[TransactionType::OUTGOING]->id, $types[TransactionType::INCOMING]->closes_type_id);
    }

    public function test_the_type_list_shows_system_types_only_when_asked(): void
    {
        $this->assertSame([], array_filter(array_column($this->getJson('/api/transaction-types')->assertOk()->json('data'), 'system_type')));

        $all = $this->getJson('/api/transaction-types?include_system=1')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(TransactionType::MANUAL, array_values(array_filter(array_column($all, 'system_type'))));
    }

    public function test_system_types_are_read_only_and_never_recorded_as_custom(): void
    {
        $loan = $this->systemTypes()[TransactionType::LOAN];

        // Only the fields and the flow of a system type change (F-07f-2, SystemTypeFlowTest).
        $this->assertErrorCode($this->putJson("/api/transaction-types/{$loan->id}", ['name' => 'Pinjam']), 409, 'SYSTEM_TYPE_READONLY');
        $this->assertErrorCode($this->putJson("/api/transaction-types/{$loan->id}", ['name' => 'Pinjam', 'fields' => []]), 409, 'SYSTEM_TYPE_READONLY');
        $this->assertErrorCode($this->deleteJson("/api/transaction-types/{$loan->id}"), 409, 'SYSTEM_TYPE_READONLY');

        // Recording a system type as an organization type would skip its core rules.
        ['asset' => $asset] = $this->createAssetFixture($this->org, $this->createMasterFixtures($this->org), 'A1');
        $this->assertErrorCode($this->postJson('/api/transactions', [
            'asset_id' => $asset->id, 'type' => TransactionType::CUSTOM, 'type_definition_id' => $loan->id, 'field_values' => [],
        ])->assertJsonValidationErrors(['type_definition_id'], 'error.details.fields'), 422, 'VALIDATION_FAILED');

        // An organization type cannot be paired with a system type either.
        $this->postJson('/api/transaction-types', ['code' => 'KMBX', 'name' => 'Kembali X', 'fields' => [], 'closes_type_id' => $loan->id])
            ->assertJsonValidationErrors(['closes_type_id'], 'error.details.fields');

        // The built-in type still records at once, without a definition.
        $this->postJson('/api/transactions', ['asset_id' => $asset->id, 'type' => TransactionType::LOAN, 'borrower_name' => 'Budi'])
            ->assertCreated()->assertJsonPath('data.type', TransactionType::LOAN);
    }

    public function test_the_database_keeps_kind_and_code_of_a_system_type(): void
    {
        $loan = $this->systemTypes()[TransactionType::LOAN];

        $this->expectException(QueryException::class);
        $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => DB::table('transaction_type_definitions')->where('id', $loan->id)->update(['code' => 'PJMX']));
    }

    /** @return Collection<string, object> */
    private function systemTypes()
    {
        return $this->tenancy()->runAsTenant(new TenantContext($this->org, $this->admin),
            fn () => TransactionTypeDefinition::query()->whereNotNull('system_type')->get()->keyBy('system_type'));
    }
}
