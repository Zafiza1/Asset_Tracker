import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Boxes, FileSpreadsheet, ImageOff, Plus, Printer, SearchX } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useNavigate } from "react-router";
import {
  ActiveFilters,
  AdvancedFilters,
  FilterPanel,
  SearchForm,
  useFilterDraft,
} from "../../components/ui/Filters";
import { searchChip, type FilterChip } from "../../components/ui/filterChips";
import { Button } from "../../components/ui/Button";
import { Card, PageHeader } from "../../components/ui/Card";
import { ColumnToggle } from "../../components/ui/ColumnToggle";
import { EmptyState, ErrorState, TableSkeleton } from "../../components/ui/Feedback";
import { ImportModal } from "../../components/ui/ImportModal";
import { Select } from "../../components/ui/Field";
import { DataTable, Pagination, type Column, type RowSelection } from "../../components/ui/Table";
import { api, qs } from "../../lib/api/client";
import type { Paginated } from "../../lib/api/types";
import { useAuth, useModule } from "../../lib/auth/context";
import {
  usePersistedFilters,
  usePersistedSearchParams,
} from "../../lib/usePersistedFilters";
import { toOptions, useOptions } from "../master/options";
import { assetStatusLabel, locationStatusLabel, MAX_EDIT_SELECTION, type Asset, type AssetStatus, type LocationStatusKey } from "./api";
import { LocationStatusBadge } from "./LocationStatusBadge";
import { AddMenu } from "../../components/ui/AddMenu";
import { AssetBarcodeModal } from "./AssetBarcodeModal";
import { AssetBulkActions } from "./AssetBulkActions";
import { AssetFormModal } from "./AssetFormModal";
import { CategoryFilter } from "./CategoryFilter";

const FILTERS = [
  "page",
  "search",
  "category_ids",
  "location_status",
  "status",
  "archived",
  "missing",
  "overdue",
  "sort",
  "per_page",
  "columns",
] as const;
/** URL keys that change how the list is shown, not which assets are in it. */
const VIEW_KEYS = new Set<string>(["page", "sort", "per_page", "columns"]);
/** Labels for the "missing" filter, set from the dashboard's "Kelengkapan data" links. */
const MISSING_LABEL: Record<string, string> = {
  acquisition_date: "Tanggal perolehan",
  acquisition_cost: "Nilai perolehan",
  serial_number: "No Manufaktur",
  image_path: "Foto aset",
};
const ADVANCED = [
  "category_ids",
  "location_status",
  "overdue",
] as const;
/** Sort options of the dropdown; the column headers can set the other directions too. */
const SORT_OPTIONS = ["", "name", "-name", "-acquisition_date", "acquisition_date", "location_status"];
const SORT_LABEL: Record<string, string> = {
  "": "terbaru ditambahkan",
  "-created_at": "terbaru ditambahkan",
  created_at: "terlama ditambahkan",
  name: "nama (A–Z)",
  "-name": "nama (Z–A)",
  "-acquisition_date": "tanggal perolehan terbaru",
  acquisition_date: "tanggal perolehan terlama",
  status: "status (A–Z)",
  location_status: "Lokasi Status (Di Customer dulu)",
  "-location_status": "Lokasi Status (Di Gudang dulu)",
  serial_number: "No Manufaktur (A–Z)",
  "-serial_number": "No Manufaktur (Z–A)",
  category: "kategori (A–Z)",
  "-category": "kategori (Z–A)",
  "-status": "status (Z–A)",
};

/**
 * Small square photo of the asset; a missing or broken image falls back to the same placeholder,
 * so a deleted file never shows the browser's broken-image icon.
 */
function AssetThumb({ asset }: { asset: Asset }) {
  const [failed, setFailed] = useState<string | null>(null);
  const src = asset.image_url;
  if (!src || failed === src) {
    return (
      <span
        className="flex size-10 shrink-0 items-center justify-center rounded-md bg-slate-100 ring-1 ring-slate-200"
        title={src ? "Foto tidak dapat dimuat" : "Belum ada foto"}
      >
        <ImageOff className="size-4 text-slate-400" aria-hidden />
      </span>
    );
  }
  return (
    <img
      src={src}
      alt={`Foto ${asset.name}`}
      loading="lazy"
      decoding="async"
      width={40}
      height={40}
      onError={() => setFailed(src)}
      className="size-10 shrink-0 rounded-md bg-slate-100 object-cover ring-1 ring-slate-200"
    />
  );
}
const DEFAULT_COLUMNS = [
  "serial_number",
  "name",
  "category",
  "location_status",
  "barcode",
];

/** Asset Management module: search and filter the asset register, register new assets. */
export function AssetsPage() {
  const { can } = useAuth();
  const barcodeModule = useModule("barcode");
  const navigate = useNavigate();
  const [params, setParams] = usePersistedSearchParams("/assets");
  const [search, setSearch] = useState(params.get("search") ?? "");
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const [bulkEditing, setBulkEditing] = useState(false);
  // Checked rows of the list; kept across pages so a selection can span several pages.
  const [selected, setSelected] = useState<Map<string, Asset>>(new Map());
  const [printing, setPrinting] = useState<Asset | null>(null);
  // The search field follows the applied search when it changes elsewhere (global search, Back).
  const appliedSearch = params.get("search") ?? "";
  const [syncedSearch, setSyncedSearch] = useState(appliedSearch);
  if (syncedSearch !== appliedSearch) {
    setSyncedSearch(appliedSearch);
    setSearch(appliedSearch);
  }

  // Persisted filters from localStorage
  const {
    updateFilter: persistFilter,
    removeFilter: unPersistFilter,
    resetFilters,
  } = usePersistedFilters("/assets");

  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]));
  const selectedCategories = (params.get("category_ids") ?? "")
    .split(",")
    .filter(Boolean);
  const advanced = useFilterDraft(params, ADVANCED);
  const draft = advanced.draft;

  // Posisi and Status became one column, Lokasi Status; saved column lists still name the old ones.
  const visibleColumns = [
    ...new Set(
      (params.get("columns") ?? DEFAULT_COLUMNS.join(","))
        .split(",")
        .filter(Boolean)
        .map((c) => (c === "position" || c === "status" ? "location_status" : c)),
    ),
  ];

  const toggleColumn = (key: string) => {
    const next = visibleColumns.includes(key)
      ? visibleColumns.filter((c) => c !== key)
      : [...visibleColumns, key];
    update({ columns: next.join(",") });
  };

  const resetColumns = () => {
    update({ columns: DEFAULT_COLUMNS.join(",") });
  };

  const categoryRows = useOptions("/tenant-masters/categories").data;
  const categories = toOptions(categoryRows);

  const query = useQuery({
    queryKey: ["/assets", filters],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs(filters)}`),
    placeholderData: keepPreviousData,
  });

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params);
    for (const [k, v] of Object.entries(next)) {
      if (v) {
        merged.set(k, v);
        persistFilter(k, v);
      } else {
        merged.delete(k);
        unPersistFilter(k);
      }
    }
    if (!("page" in next)) merged.delete("page");
    setParams(merged);
  };
  const hasFilters = FILTERS.some((k) => !VIEW_KEYS.has(k) && params.get(k));
  const sort = params.get("sort") ?? "";
  const sortOptions = SORT_OPTIONS.includes(sort) ? SORT_OPTIONS : [...SORT_OPTIONS, sort];

  const selectedIds = useMemo(() => new Set(selected.keys()), [selected]);
  const selection: RowSelection<Asset> = {
    selected: selectedIds,
    rowLabel: (a) => a.name,
    onChange: (rows, pick) =>
      setSelected((prev) => {
        const next = new Map(prev);
        for (const a of rows) {
          if (pick) next.set(a.id, a);
          else next.delete(a.id);
        }
        return next;
      }),
  };
  const pageIds = new Set(query.data?.data.map((a) => a.id) ?? []);
  const offPage = [...selected.keys()].filter((id) => !pageIds.has(id)).length;
  // Multiple Edit (Excel, same flow as Kategori): the checked rows when there are any,
  // otherwise every active asset matching the applied filters. Archived assets are never in the sheet.
  const editAssets = [...selected.values()].filter((a) => !a.is_archived);
  const editIds = editAssets.map((a) => a.id);
  const skippedArchived = selected.size - editIds.length;
  const editingSelection = editIds.length > 0;
  const tooManyToEdit = editIds.length > MAX_EDIT_SELECTION;
  const listFilters = Object.fromEntries(
    Object.entries(filters).filter(([k]) => !VIEW_KEYS.has(k)),
  );
  const editSheetEndpoint = editingSelection
    ? `/assets/edit-sheet${qs({ ids: editIds.join(",") })}`
    : `/assets/edit-sheet${qs(listFilters)}`;

  // Every applied filter as a removable chip, so the filter controls stay one tidy row.
  const nameOf = (
    rows: { id: string; name: string }[] | undefined,
    id: string | null,
  ) => rows?.find((r) => r.id === id)?.name ?? "…";
  const archived = params.get("archived");
  const chips: FilterChip[] = [
    ...searchChip(
      params.get("search"),
      () => (setSearch(""), update({ search: "" })),
    ),
    ...selectedCategories.map((id) => ({
      key: `category:${id}`,
      label: nameOf(categoryRows, id),
      clear: () =>
        update({
          category_ids: selectedCategories.filter((c) => c !== id).join(","),
        }),
    })),
    ...(params.get("location_status")
      ? [
          {
            key: "location_status",
            label: `Lokasi Status: ${locationStatusLabel[params.get("location_status") as LocationStatusKey] ?? params.get("location_status")}`,
            clear: () => update({ location_status: "" }),
          },
        ]
      : []),
    ...(params.get("status")
      ? [
          {
            key: "status",
            label: `Status: ${assetStatusLabel[params.get("status") as AssetStatus] ?? params.get("status")}`,
            clear: () => update({ status: "" }),
          },
        ]
      : []),
    ...(params.get("overdue") === "1"
      ? [
          {
            key: "overdue",
            label: "Pinjaman lewat batas",
            clear: () => update({ overdue: "" }),
          },
        ]
      : []),
    ...(params.get("missing")
      ? [
          {
            key: "missing",
            label: `Belum diisi: ${MISSING_LABEL[params.get("missing")!] ?? params.get("missing")}`,
            clear: () => update({ missing: "" }),
          },
        ]
      : []),
    ...(archived
      ? [
          {
            key: "archived",
            label: archived === "only" ? "Aset nonaktif" : "Aktif & nonaktif",
            clear: () => update({ archived: "" }),
          },
        ]
      : []),
  ];

  const columns: Column<Asset>[] = [
    {
      key: "serial_number",
      header: "No Manufaktur",
      sortKey: "serial_number",
      className: "w-36",
      title: (a) => a.serial_number,
      cell: (a) =>
        a.serial_number ? (
          <span className="font-mono text-xs">{a.serial_number}</span>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
    {
      key: "name",
      header: "Aset",
      sortKey: "name",
      title: (a) => a.name,
      cell: (a) => (
        <Link
          to={`/aset/${a.id}`}
          className="flex min-w-0 items-center gap-3 font-medium text-slate-900 hover:text-brand-700"
        >
          <AssetThumb asset={a} />
          <span className="min-w-0">
            {/* Long names stop after two lines; the full name is the cell's tooltip. */}
            <span className="line-clamp-2 whitespace-normal break-words">{a.name}</span>
            <span className="block truncate text-xs font-normal text-slate-500 md:hidden">
              {a.location_status.detail ?? a.location_status.label}
            </span>
          </span>
        </Link>
      ),
    },
    {
      key: "category",
      header: "Kategori",
      sortKey: "category",
      className: "w-40",
      title: (a) => a.category?.name,
      cell: (a) => a.category?.name ?? <span className="text-slate-400">—</span>,
      hideOnMobile: true,
    },
    {
      key: "reference_number",
      header: "No Reference",
      className: "w-40",
      cell: (a) => <span className="font-mono text-xs">{a.reference_number}</span>,
      hideOnMobile: true,
    },
    {
      // Set by the system from the cylinder's transactions: Di Gudang, Dalam Perjalanan, Di Customer.
      key: "location_status",
      header: "Lokasi Status",
      className: "w-56",
      sortKey: "location_status",
      title: (a) => a.location_status.detail ?? undefined,
      cell: (a) => <LocationStatusBadge asset={a} />,
    },
    // Only while the Barcode module is on (Menu Modul).
    ...(barcodeModule
      ? [
          {
            key: "barcode",
            header: "Barcode",
            className: "w-28",
            hideOnMobile: true,
            title: (a) =>
              a.barcode_enabled
                ? undefined
                : "Kategori aset ini belum dipilih untuk Modul Barcode (Menu Modul › Barcode)",
            cell: (a) =>
              a.barcode_enabled ? (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => setPrinting(a)}
                  aria-label={`Lihat dan cetak barcode ${a.name}`}
                  title="Lihat & cetak barcode"
                >
                  <Printer className="size-4" aria-hidden /> Cetak
                </Button>
              ) : (
                <span className="text-xs text-slate-400">Tidak aktif</span>
              ),
          } satisfies Column<Asset>,
        ]
      : []),
  ];

  const resetAll = () => (
    setSearch(""),
    resetFilters(),
    setParams(new URLSearchParams())
  );

  return (
    <>
      <PageHeader
        title="Data Aset"
        description="Seluruh aset business unit: cari, saring, lalu buka untuk melihat detail dan riwayatnya."
        actions={
          <>
            {can("asset.update") && (
              <Button
                variant="secondary"
                onClick={() => setBulkEditing(true)}
                disabled={tooManyToEdit}
                title={tooManyToEdit ? `Multiple Edit maksimal ${MAX_EDIT_SELECTION} aset sekaligus` : undefined}
              >
                <FileSpreadsheet className="size-4" aria-hidden /> Multiple Edit{editingSelection ? ` (${editIds.length})` : ""}
              </Button>
            )}
            {can("asset.create") && (
              <AddMenu
                label="Tambah aset"
                noun="aset"
                onForm={() => setCreating(true)}
                onImport={() => setImporting(true)}
              />
            )}
          </>
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama produk, No Manufaktur, atau No Reference"
            label="Cari aset"
            aside={
              <div className="flex gap-2">
                <ColumnToggle
                  columns={columns}
                  visibleColumns={visibleColumns}
                  onToggle={toggleColumn}
                  onReset={resetColumns}
                />
                <Select
                  value={sort}
                  onChange={(e) => update({ sort: e.target.value })}
                  className="min-w-0 flex-1 sm:w-64 sm:flex-none"
                  aria-label="Urutkan"
                >
                  {sortOptions.map((v) => (
                    <option key={v} value={v}>
                      Urutkan: {SORT_LABEL[v] ?? v}
                    </option>
                  ))}
                </Select>
              </div>
            }
          />
          <AdvancedFilters
            activeCount={advanced.activeCount}
            dirty={advanced.dirty}
            onApply={() => update(draft)}
            onClear={() => advanced.set(advanced.empty)}
          >
            <CategoryFilter
              options={categories}
              selected={draft.category_ids.split(",").filter(Boolean)}
              onChange={(ids) => advanced.set({ category_ids: ids.join(",") })}
            />
            <Select
              value={draft.location_status}
              onChange={(e) => advanced.set({ location_status: e.target.value })}
              aria-label="Filter lokasi status"
            >
              <option value="">Lokasi Status: semua</option>
              {(Object.keys(locationStatusLabel) as LocationStatusKey[]).map((k) => (
                <option key={k} value={k}>
                  {locationStatusLabel[k]}
                </option>
              ))}
            </Select>
          </AdvancedFilters>
          <ActiveFilters
            chips={chips}
            onReset={resetAll}
          />
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={6} label="Memuat data aset…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={hasFilters ? SearchX : Boxes}
            title={hasFilters ? "Tidak ada aset yang cocok" : "Belum ada aset"}
            description={
              hasFilters
                ? "Ubah kata kunci atau filter pencarian."
                : "Tambahkan aset pertama Anda. Pilih kategori Tabung atau Kendaraan."
            }
            action={
              hasFilters ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : (
                can("asset.create") && (
                  <Button onClick={() => setCreating(true)}>
                    <Plus className="size-4" aria-hidden /> Tambah aset
                  </Button>
                )
              )
            }
          />
        ) : (
          <>
            <AssetBulkActions
              selected={[...selected.values()]}
              offPage={offPage}
              barcode={barcodeModule}
              onClear={() => setSelected(new Map())}
            />
            <DataTable
              columns={columns}
              rows={query.data!.data}
              visibleColumns={[...visibleColumns, "actions"]}
              selection={selection}
              sort={sort}
              onSort={(next) => update({ sort: next })}
              onRowClick={(a) => navigate(`/aset/${a.id}`)}
              rowHref={(a) => `/aset/${a.id}`}
              className="md:min-w-[68rem]"
            />
            <Pagination
              meta={query.data!.meta}
              onPage={(p) => update({ page: String(p) })}
              onPerPage={(n) => update({ per_page: n === 10 ? "" : String(n) })}
            />
          </>
        )}
      </Card>
      {bulkEditing && (
        <ImportModal
          open
          mode="edit"
          onClose={() => setBulkEditing(false)}
          onSuccess={() => setSelected(new Map())}
          title="Multiple Edit Aset (Excel)"
          endpoint="/assets/edit-sheet"
          templateEndpoint={editSheetEndpoint}
          templateFilename="edit_aset.xlsx"
          scopeNote={
            editingSelection
              ? `${editIds.length} aset yang dicentang${skippedArchived > 0 ? `; ${skippedArchived} aset nonaktif tidak ikut` : ""}`
              : hasFilters
                ? "semua aset aktif sesuai filter yang aktif"
                : "semua aset aktif"
          }
        />
      )}
      {printing && (
        <AssetBarcodeModal asset={printing} onClose={() => setPrinting(null)} />
      )}
      {creating && (
        <AssetFormModal
          onClose={() => setCreating(false)}
          onSaved={(a) => navigate(`/aset/${a.id}`)}
        />
      )}
      {importing && (
        <ImportModal
          open={importing}
          onClose={() => setImporting(false)}
          title="Tambah Aset dari Excel (Import)"
          endpoint="/assets/import"
          templateEndpoint="/assets/template"
          templateFilename="template_aset.xlsx"
        />
      )}
    </>
  );
}
