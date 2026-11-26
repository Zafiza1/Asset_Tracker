<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Departemen becomes Divisi, and an employee's position is their division:
 *  - departments.delivery_role: driver/helper marks the divisions whose employees can be the crew of a
 *    delivery (Supir, Kernet). The fixed employees.position list is replaced by employees.department_id;
 *    the positions in use become divisions of the same name.
 *
 * Vehicles leave the asset register for their own master (Master Data › Kendaraan):
 *  - vehicles: one row per vehicle with its own fields. Every asset of the Kendaraan category is copied
 *    with the same id, so deliveries.vehicle_asset_id (renamed vehicle_id) keeps its values.
 *  - In an edition without the Kendaraan category (EDITION_CATEGORIES) those assets are archived and the
 *    category with them; assets are never deleted (assets_no_delete).
 */
return new class extends Migration
{
    private const VEHICLE_CATEGORY = 'AST_KENDARAAN';

    /** Former employees.position => [division name, code, delivery role]. */
    private const POSITIONS = [
        'driver' => ['Supir', 'SUPIR', 'driver'],
        'helper' => ['Kernet', 'KERNET', 'helper'],
        'warehouse' => ['Petugas Gudang', 'GUDANG', null],
        'admin' => ['Admin', 'ADMIN', null],
        'other' => ['Lainnya', 'LAINNYA', null],
    ];

    /** Kendaraan field label => vehicles column. */
    private const FIELDS = [
        'Nomor Polisi' => 'plate_number',
        'Nomor Rangka' => 'chassis_number',
        'Nomor Mesin' => 'engine_number',
        'Jenis Kendaraan' => 'type',
        'Tahun Pembuatan' => 'year',
        'Jenis Bahan Bakar' => 'fuel',
        'Kapasitas Muatan' => 'capacity',
        'Tanggal STNK Berakhir' => 'stnk_expires_on',
        'Tanggal KIR Berakhir' => 'kir_expires_on',
    ];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        $this->divisions();

        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('plate_number', 20);
            $table->string('type', 20)->default('lainnya');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('chassis_number', 50)->nullable();
            $table->string('engine_number', 50)->nullable();
            $table->string('fuel', 20)->nullable();
            $table->decimal('capacity', 12, 2)->nullable();
            $table->date('stnk_expires_on')->nullable();
            $table->date('kir_expires_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('vehicles');
        DB::statement('CREATE UNIQUE INDEX vehicles_plate_unique ON vehicles (organization_id, upper(plate_number))');
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check CHECK (type IN ('truk','pickup','mobil','motor','bus','forklift','alat_berat','lainnya'))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_fuel_check CHECK (fuel IS NULL OR fuel IN ('bensin','solar','listrik','hybrid','gas','lainnya'))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_check CHECK (status IN ('active','inactive','archived'))");
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_capacity_check CHECK (capacity IS NULL OR capacity >= 0)');
        TenantSchema::enableRls('vehicles');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON vehicles TO {$app}");

        $moved = $this->copyVehicleAssets();

        // The departure check and the one-trip index follow the renamed column.
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_vehicle_asset_id_tfk');
        DB::statement('ALTER TABLE deliveries RENAME COLUMN vehicle_asset_id TO vehicle_id');
        TenantSchema::tenantForeign('deliveries', 'vehicle_id', 'vehicles');

        if (! in_array(self::VEHICLE_CATEGORY, $this->editionCategories(), true) && $this->editionCategories() !== []) {
            $this->retireVehicleAssets($moved);
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_vehicle_id_tfk');
        DB::statement('ALTER TABLE deliveries RENAME COLUMN vehicle_id TO vehicle_asset_id');
        // Vehicles added after the move have no asset; their trips lose the vehicle.
        DB::statement('UPDATE deliveries d SET vehicle_asset_id = NULL WHERE vehicle_asset_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.id = d.vehicle_asset_id)');
        TenantSchema::tenantForeign('deliveries', 'vehicle_asset_id', 'assets');
        Schema::dropIfExists('vehicles');

        Schema::table('employees', fn (Blueprint $table) => $table->string('position', 20)->default('other'));
        DB::statement("UPDATE employees e SET position = COALESCE(
            (SELECT d.delivery_role FROM departments d WHERE d.id = e.department_id), 'other')");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_position_check CHECK (position IN ('driver','helper','warehouse','admin','other'))");
        DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_department_id_tfk');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('department_id'));
        DB::statement('ALTER TABLE departments DROP CONSTRAINT departments_delivery_role_check');
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('delivery_role'));
    }

    private function divisions(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->string('delivery_role', 10)->nullable());
        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_delivery_role_check CHECK (delivery_role IS NULL OR delivery_role IN ('driver','helper'))");
        Schema::table('employees', fn (Blueprint $table) => $table->uuid('department_id')->nullable());
        TenantSchema::tenantForeign('employees', 'department_id', 'departments');

        $used = DB::table('employees')->select('organization_id', 'position')->distinct()->get();
        foreach ($used as $row) {
            [$name, $code, $role] = self::POSITIONS[$row->position] ?? self::POSITIONS['other'];
            $division = DB::table('departments')->where('organization_id', $row->organization_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->where('status', '!=', 'archived')->first();
            if ($division === null) {
                $id = (string) Str::uuid();
                DB::table('departments')->insert([
                    'id' => $id, 'organization_id' => $row->organization_id, 'name' => $name, 'status' => 'active',
                    'code' => $this->freeCode('departments', $row->organization_id, $code), 'delivery_role' => $role,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $id = $division->id;
                if ($role !== null && $division->delivery_role === null) {
                    DB::table('departments')->where('id', $id)->update(['delivery_role' => $role]);
                }
            }
            DB::table('employees')->where('organization_id', $row->organization_id)->where('position', $row->position)
                ->update(['department_id' => $id]);
        }

        DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_position_check');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('position'));
    }

    /**
     * Copies every asset of the Kendaraan category (and its subcategories) into vehicles.
     *
     * @return list<string> ids of the copied assets
     */
    private function copyVehicleAssets(): array
    {
        $roots = DB::table('tenant_asset_categories')->where('code', self::VEHICLE_CATEGORY)->get(['id', 'organization_id', 'path']);
        $moved = [];
        foreach ($roots as $root) {
            $categories = DB::table('tenant_asset_categories')->where('organization_id', $root->organization_id)
                ->where('path', 'like', $root->path.'%')->get(['id', 'path'])->keyBy('id');
            $assets = DB::table('assets')->where('organization_id', $root->organization_id)
                ->whereIn('category_id', $categories->keys())->orderBy('created_at')->get();
            foreach ($assets as $asset) {
                $fields = $this->fieldsOf($categories, $asset->category_id);
                $values = json_decode($asset->attribute_values ?? '{}', true) ?: [];
                $row = [];
                foreach (self::FIELDS as $label => $column) {
                    $field = $fields[$label] ?? null;
                    $value = $field === null ? null : ($values[$field['key']] ?? null);
                    $row[$column] = $value === null || $value === '' ? null : ($field['options'][$value] ?? $value);
                }
                $plate = trim((string) ($row['plate_number'] ?? '')) ?: ($asset->serial_number ?? $asset->asset_number);
                DB::table('vehicles')->insert([
                    'id' => $asset->id,
                    'organization_id' => $asset->organization_id,
                    'code' => $this->freeCode('vehicles', $asset->organization_id, Str::upper(Str::limit((string) ($asset->serial_number ?: $asset->asset_number), 30, ''))),
                    'name' => $asset->name,
                    'plate_number' => $this->freePlate($asset->organization_id, Str::limit(Str::upper($plate), 20, '')),
                    'type' => $this->choice((string) $row['type'], ['truk' => ['truk', 'truck'], 'pickup' => ['pick up', 'pickup'], 'mobil' => ['mobil'], 'motor' => ['motor'], 'bus' => ['bus'], 'forklift' => ['forklift'], 'alat_berat' => ['alat berat']]) ?? 'lainnya',
                    'year' => is_numeric($row['year']) && $row['year'] >= 1900 && $row['year'] <= 2200 ? (int) $row['year'] : null,
                    'chassis_number' => $row['chassis_number'] === null ? null : Str::limit((string) $row['chassis_number'], 50, ''),
                    'engine_number' => $row['engine_number'] === null ? null : Str::limit((string) $row['engine_number'], 50, ''),
                    'fuel' => $row['fuel'] === null ? null : ($this->choice((string) $row['fuel'], ['bensin' => ['bensin'], 'solar' => ['solar', 'diesel'], 'listrik' => ['listrik'], 'hybrid' => ['hybrid'], 'gas' => ['gas']]) ?? 'lainnya'),
                    'capacity' => is_numeric($row['capacity']) && $row['capacity'] >= 0 ? $row['capacity'] : null,
                    'stnk_expires_on' => $this->date($row['stnk_expires_on']),
                    'kir_expires_on' => $this->date($row['kir_expires_on']),
                    'status' => $asset->archived_at !== null ? 'archived' : (in_array($asset->status, ['in_use', 'available'], true) ? 'active' : 'inactive'),
                    'created_at' => $asset->created_at,
                    'updated_at' => now(),
                ]);
                $moved[] = $asset->id;
            }
        }

        return $moved;
    }

    /** @param list<string> $ids */
    private function retireVehicleAssets(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('assets')->whereIn('id', $chunk)->whereNull('archived_at')
                ->update(['archived_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        }
        DB::table('tenant_asset_categories')->whereIn('organization_id', DB::table('tenant_asset_categories')->select('organization_id')->where('code', self::VEHICLE_CATEGORY))
            ->where(function ($q) {
                foreach (DB::table('tenant_asset_categories')->where('code', self::VEHICLE_CATEGORY)->pluck('path') as $path) {
                    $q->orWhere('path', 'like', $path.'%');
                }
            })
            ->where('status', '!=', 'archived')->update(['status' => 'archived', 'updated_at' => now()]);
    }

    /**
     * Fields of a category including its ancestors', by label.
     *
     * @return array<string, array{key: string, options: array<string, string>}>
     */
    private function fieldsOf($categories, string $categoryId): array
    {
        $path = $categories[$categoryId]->path;
        $ids = $categories->filter(fn ($c) => str_starts_with($path, $c->path))->keys();
        $out = [];
        foreach (DB::table('tenant_category_attributes')->whereIn('category_id', $ids)->get(['key', 'label', 'options']) as $a) {
            $options = json_decode($a->options ?? '[]', true) ?: [];
            $out[$a->label] = ['key' => $a->key, 'options' => array_column($options, 'label', 'value')];
        }

        return $out;
    }

    /** @param array<string, list<string>> $choices */
    private function choice(string $value, array $choices): ?string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        foreach ($choices as $code => $words) {
            foreach ($words as $word) {
                if (str_contains($value, $word)) {
                    return $code;
                }
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    private function freeCode(string $table, string $organizationId, string $code): string
    {
        $code = preg_replace('/[^A-Z0-9_.-]/', '-', Str::upper($code)) ?: 'KODE';
        $candidate = $code;
        for ($i = 2; DB::table($table)->where('organization_id', $organizationId)->where('code', $candidate)->exists(); $i++) {
            $candidate = Str::limit($code, 30 - strlen("-{$i}"), '')."-{$i}";
        }

        return $candidate;
    }

    private function freePlate(string $organizationId, string $plate): string
    {
        $candidate = $plate;
        for ($i = 2; DB::table('vehicles')->where('organization_id', $organizationId)->whereRaw('upper(plate_number) = ?', [$candidate])->exists(); $i++) {
            $candidate = Str::limit($plate, 20 - strlen(" ({$i})"), '')." ({$i})";
        }

        return $candidate;
    }

    /** @return list<string> */
    private function editionCategories(): array
    {
        return config('edition.categories');
    }
};
