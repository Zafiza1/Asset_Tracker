<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Http\Support\ImportColumn;
use App\Http\Support\MasterRequests;
use App\Http\Support\SpreadsheetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LocationController extends Controller
{
    private const RELATIONS = ['parent'];

    public function __construct(private readonly OrganizationStructureService $structure) {}

    /** Default sort is the tree order (materialized path): parents directly before their children. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'parent_id' => ['nullable', 'uuid'],
        ]);
        $query = Location::query()->with(self::RELATIONS)
            ->when($request->query('parent_id'), fn ($q, $id) => $q->where('parent_id', $id));

        return LocationResource::collection(MasterRequests::list($query, $request,
            ['tree' => 'path', 'code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'tree'));
    }

    public function store(Request $request, Tenancy $tenancy): LocationResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('locations', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'locations', $tenancy->organizationId());

        return new LocationResource($this->structure->createLocation($data)->load(self::RELATIONS));
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load(self::RELATIONS));
    }

    public function update(Request $request, Location $location): LocationResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new LocationResource($this->structure->updateLocation($location, $data)->load(self::RELATIONS));
    }

    public function archive(Request $request, Location $location): LocationResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new LocationResource($this->structure->archiveLocation($location, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(Location $location): LocationResource
    {
        return new LocationResource($this->structure->restoreLocation($location)->load(self::RELATIONS));
    }

    /**
     * Creates locations from an .xlsx (see the template). A parent may be an existing location
     * or one on an earlier row of the same file; codes are generated from the name when empty.
     */
    public function import(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns) {
            $row['code'] = SpreadsheetImport::code($row['code']);
            $data = Validator::make($row, [
                'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('locations', 'code')->where('organization_id', $tenancy->organizationId())],
                'name' => ['required', 'string', 'max:150'],
                'parent_code' => ['nullable', 'string'],
                'address' => ['nullable', 'string', 'max:1000'],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $this->structure->createLocation([
                'code' => $data['code'] ?: CodeGenerator::fromName($data['name'], 'locations', $tenancy->organizationId()),
                'name' => $data['name'],
                'address' => $data['address'],
                'parent_id' => SpreadsheetImport::idByCode(Location::query(), $data['parent_code'], 'Lokasi induk'),
            ]);
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_lokasi.xlsx', 'Template Import Lokasi', $this->importColumns(), [
            'Lokasi Induk' => SpreadsheetImport::codeList(Location::query()),
        ], [
            'Untuk sub-lokasi, isi Lokasi Induk dengan lokasi yang sudah ada (dropdown), atau dengan Kode lokasi pada baris di atasnya dalam file ini.',
        ]);
    }

    /** The locations (not archived) in tree order, as an .xlsx to edit and upload to importEdits(). */
    public function downloadEditSheet(): BinaryFileResponse
    {
        $query = Location::query()->where('status', '!=', MasterStatus::ARCHIVED);
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->with('parent')->orderBy('path')->get()->map(fn (Location $l) => [
            'id' => $l->id,
            'name' => $l->name,
            'parent_code' => SpreadsheetImport::codeItem($l->parent),
            'address' => $l->address,
            'status' => SpreadsheetImport::MASTER_STATUS_LABELS[$l->status] ?? $l->status,
        ]);

        return SpreadsheetImport::editSheet('edit_lokasi.xlsx', 'Edit Massal Lokasi', $this->editColumns(), $rows, [
            'Lokasi Induk' => SpreadsheetImport::codeList(Location::query()),
            'Status' => array_values(SpreadsheetImport::MASTER_STATUS_LABELS),
        ], [
            'Untuk memindah lokasi, ganti Lokasi Induk. Lokasi tidak dapat dipindah ke bawah dirinya sendiri atau sub-lokasinya.',
            'Lokasi hanya dapat dinonaktifkan jika tidak lagi memiliki sub-lokasi aktif.',
        ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns, edit: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($columns, &$seen) {
            $row['status'] = SpreadsheetImport::masterStatus($row['status']);
            $data = Validator::make($row, [
                'name' => ['required', 'string', 'max:150'],
                'parent_code' => ['nullable', 'string'],
                'address' => ['nullable', 'string', 'max:1000'],
                'status' => ['required', Rule::in(MasterStatus::EDITABLE)],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $location = SpreadsheetImport::existing(Location::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($location, [
                'name' => $data['name'],
                'address' => $data['address'],
                'parent_id' => SpreadsheetImport::idByCode(Location::query(), $data['parent_code'], 'Lokasi induk'),
                'status' => $data['status'],
            ]);
            if ($changes === []) {
                return false;
            }
            $this->structure->updateLocation($location, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return list<ImportColumn> the code cannot be changed, so it is not shown */
    private function editColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Lokasi', required: true, hint: 'Nama lokasi, maksimal 150 karakter.', width: 30),
            new ImportColumn('parent_code', 'Lokasi Induk', hint: 'Pilih lokasi induk. Kosongkan untuk lokasi utama.', list: 'Lokasi Induk', width: 34),
            new ImportColumn('address', 'Alamat', hint: 'Alamat lengkap lokasi. Kosongkan untuk menghapus.', width: 45),
            new ImportColumn('status', 'Status', required: true, hint: 'Aktif, atau Nonaktif agar tidak bisa dipilih untuk aset baru.', list: 'Status', width: 14),
            SpreadsheetImport::idColumn(),
        ];
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Lokasi', required: true, hint: 'Nama lokasi, maksimal 150 karakter. Contoh: Gudang Utama', width: 30),
            new ImportColumn('code', 'Kode', hint: 'Kode unik lokasi (huruf, angka, - _ .). Jika kosong, dibuat otomatis dari nama.', width: 18),
            new ImportColumn('parent_code', 'Lokasi Induk', hint: 'Pilih lokasi induk, atau ketik Kode lokasi dari baris di atasnya. Kosongkan untuk lokasi utama.', list: 'Lokasi Induk', strictList: false, width: 34),
            new ImportColumn('address', 'Alamat', hint: 'Alamat lengkap lokasi. Boleh dikosongkan.', width: 45),
        ];
    }
}
