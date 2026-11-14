import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Boxes, ImageOff, Plus, Printer, SearchX } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useNavigate } from "react-router";
import {
  ActiveFilters,
  AdvancedFilters,
  DateRangeFilter,
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
import { assetStatusLabel, type Asset, type AssetStatus } from "./api";
import { AssetStatusBadge } from "./AssetStatusBadge";
import { AddMenu } from "../../components/ui/AddMenu";
import { AssetBarcodeModal } from "./AssetBarcodeModal";
import { AssetBulkActions } from "./AssetBulkActions";
import { BulkEditModal } from "./BulkEditModal";
import { AssetFormModal } from "./AssetFormModal";
import { CategoryFilter } from "./CategoryFilter";

const FILTERS = [
  "page",
  "search",
  "category_ids",
  "location_id",
  "status",
  "archived",
  "missing",
  "acquired_from",
  "acquired_to",
  "sort",
  "per_page",
  "columns",
] as const;
/** URL keys that change how the list is shown, not which assets are in it. */
const VIEW_KEYS = new Set<string>(["page", "sort", "per_page", "columns"]);
/** Labels for the "missing" filter, set from the dashboard's "Kelengkapan data" links. */
const MISSING_LABEL: Record<string, string> = {
  location_id: "Lokasi",
  acquisition_date: "Tanggal perolehan",
  acquisition_cost: "Nilai perolehan",
  serial_number: "No seri",
  image_path: "Foto aset",
};
const ADVANCED = [
  "category_ids",
  "location_id",
  "status",
  "archived",
  "missing",
  "acquired_from",
  "acquired_to",
] as const;
/** Sort options of the dropdown; the column headers can set the other directions too. */
const SORT_OPTIONS = ["", "name", "-name", "-acquisition_date", "acquisition_date", "status"];
const SORT_LABEL: Record<string, string> = {
  "": "terbaru ditambahkan",
  "-created_at": "terbaru ditambahkan",
  created_at: "terlama ditambahkan",
  name: "nama (A–Z)",
  "-name": "nama (Z–A)",
  "-acquisition_date": "tanggal perolehan terbaru",
  acquisition_date: "tanggal perolehan terlama",
  status: "status (A–Z)",
  serial_number: "no seri (A–Z)",
  "-serial_number": "no seri (Z–A)",
  category: "kategori (A–Z)",
  "-category": "kategori (Z–A)",
  location: "lokasi (A–Z)",
  "-location": "lokasi (Z–A)",
  "-status": "status (Z–A)",
};
const dateLabel = (d: string) =>
  new Date(`${d}T00:00:00`).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });

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
  "code",
  "name",
  "serial_number",
  "category",
  "location",
  "status",
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

  const visibleColumns = (params.get("columns") ?? DEFAULT_COLUMNS.join(","))
    .split(",")
    .filter(Boolean);

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
  const locationRows = useOptions("/locations").data;
  const locations = toOptions(locationRows);

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
  // Multiple Edit takes the checked rows; archived assets cannot be edited.
  const editAssets = [...selected.values()].filter((a) => !a.is_archived);

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
    ...(params.get("location_id")
      ? [
          {
            key: "location",
            label: `Lokasi: ${nameOf(locationRows, params.get("location_id"))}`,
            clear: () => update({ location_id: "" }),
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
    ...(params.get("missing")
      ? [
          {
            key: "missing",
            label: `Belum diisi: ${MISSING_LABEL[params.get("missing")!] ?? params.get("missing")}`,
            clear: () => update({ missing: "" }),
          },
        ]
      : []),
    ...(params.get("acquired_from") || params.get("acquired_to")
      ? [
          {
            key: "acquired",
            label: `Perolehan: ${params.get("acquired_from") ? dateLabel(params.get("acquired_from")!) : "…"} – ${params.get("acquired_to") ? dateLabel(params.get("acquired_to")!) : "…"}`,
            clear: () => update({ acquired_from: "", acquired_to: "" }),
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
              {[a.category?.name, a.location?.name ?? "Tanpa lokasi"].filter(Boolean).join(" · ")}
            </span>
          </span>
        </Link>
      ),
    },
    {
      key: "serial_number",
      header: "No Seri",
      sortKey: "serial_number",
      className: "w-36",
      title: (a) => a.serial_number,
      cell: (a) =>
        a.serial_number ? (
          <span className="font-mono text-xs">{a.serial_number}</span>
        ) : (
          <span className="text-slate-400">—</span>
        ),
      hideOnMobile: true,
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
      key: "location",
      header: "Lokasi",
      sortKey: "location",
      className: "w-40",
      title: (a) => a.location?.name ?? "Tanpa lokasi",
      cell: (a) =>
        a.location?.name ?? (
          <span className="text-slate-400">Tanpa lokasi</span>
        ),
      hideOnMobile: true,
    },
    {
      key: "status",
      header: "Status",
      className: "w-32 md:w-36",
      sortKey: "status",
      cell: (a) => (
        <div className="flex flex-wrap gap-1">
          <AssetStatusBadge status={a.status} archived={a.is_archived} />
        </div>
      ),
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
                : "Barcode belum diaktifkan untuk aset ini (isian Barcode pada data aset)",
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
        description="Seluruh aset organisasi: cari, saring, lalu buka untuk melihat detail dan riwayatnya."
        actions={
          can("asset.create") && (
            <AddMenu
              label="Tambah aset"
              noun="aset"
              onForm={() => setCreating(true)}
              onImport={() => setImporting(true)}
            />
          )
        }
      />
      <Card>
        <FilterPanel>
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari nama atau no seri"
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
              value={draft.location_id}
              onChange={(e) => advanced.set({ location_id: e.target.value })}
              aria-label="Filter lokasi"
            >
              <option value="">Lokasi: semua</option>
              {locations.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Select
              value={draft.status}
              onChange={(e) => advanced.set({ status: e.target.value })}
              aria-label="Filter status"
            >
              <option value="">Status: semua</option>
              {(Object.keys(assetStatusLabel) as AssetStatus[]).map((s) => (
                <option key={s} value={s}>
                  {assetStatusLabel[s]}
                </option>
              ))}
            </Select>
            <Select
              value={draft.archived}
              onChange={(e) => advanced.set({ archived: e.target.value })}
              aria-label="Aset aktif atau nonaktif"
            >
              <option value="">Aset aktif</option>
              <option value="only">Aset nonaktif</option>
              <option value="all">Aktif &amp; nonaktif</option>
            </Select>
            <Select
              value={draft.missing}
              onChange={(e) => advanced.set({ missing: e.target.value })}
              aria-label="Filter kelengkapan data"
            >
              <option value="">Kelengkapan data: semua</option>
              {Object.entries(MISSING_LABEL).map(([k, label]) => (
                <option key={k} value={k}>
                  Belum diisi: {label}
                </option>
              ))}
            </Select>
            <DateRangeFilter
              from={draft.acquired_from}
              to={draft.acquired_to}
              onChange={(from, to) => advanced.set({ acquired_from: from, acquired_to: to })}
            />
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
                : "Tambahkan aset pertama Anda. Pastikan kategori dan lokasi sudah dibuat di Master Data."
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
              canEdit={can("asset.update")}
              canArchive={can("asset.archive")}
              barcode={barcodeModule}
              editable={editAssets.length}
              onEdit={() => setBulkEditing(true)}
              onClear={() => setSelected(new Map())}
              onDone={(ids) =>
                setSelected((prev) => {
                  const next = new Map(prev);
                  ids.forEach((id) => next.delete(id));
                  return next;
                })
              }
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
      {/* Multiple Edit starts from the bulk action bar, so it always has checked assets. */}
      {bulkEditing && editAssets.length > 0 && (
        <BulkEditModal
          assets={editAssets}
          onClose={() => setBulkEditing(false)}
          onDone={() => setSelected(new Map())}
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
