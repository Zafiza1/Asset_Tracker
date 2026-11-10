import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ListChecks, X } from "lucide-react";
import { useState } from "react";
import { Button } from "../../components/ui/Button";
import { ErrorState, Spinner } from "../../components/ui/Feedback";
import { Input, Select } from "../../components/ui/Field";
import { Pagination } from "../../components/ui/Table";
import { api, qs } from "../../lib/api/client";
import type { Paginated } from "../../lib/api/types";
import { toOptions, useOptions } from "../master/options";
import type { Asset } from "./api";

/** Mirrors AssetController::MAX_EDIT_SELECTION: the picked ids travel in the download URL. */
export const MAX_EDIT_SELECTION = 100;

export type PickedAssets = Map<
  string,
  Pick<Asset, "id" | "asset_number" | "name">
>;

interface Props {
  picked: PickedAssets;
  onChange: (next: PickedAssets) => void;
}

/**
 * Multiple Edit: choose which (active) assets go into the downloaded sheet.
 * A category is chosen first, then its assets (subcategories included) are listed.
 * With nothing picked, the download follows the list's search and filters.
 */
export function AssetEditSelection({ picked, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const [categoryId, setCategoryId] = useState("");
  const [search, setSearch] = useState("");
  const [applied, setApplied] = useState("");
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ["/assets", "edit-selection", categoryId, applied, page],
    queryFn: () =>
      api.get<Paginated<Asset>>(
        `/assets${qs({ category_ids: categoryId, search: applied, page: String(page), sort: "asset_number" })}`,
      ),
    placeholderData: keepPreviousData,
    enabled: open && categoryId !== "",
  });
  const categories = toOptions(
    useOptions("/tenant-masters/categories", {}, open).data,
  );

  const rows = query.data?.data ?? [];
  const full = picked.size >= MAX_EDIT_SELECTION;
  const allOnPage = rows.length > 0 && rows.every((a) => picked.has(a.id));

  const toggle = (asset: Asset) => {
    const next = new Map(picked);
    if (next.has(asset.id)) next.delete(asset.id);
    else if (!full) next.set(asset.id, asset);
    onChange(next);
  };

  const togglePage = () => {
    const next = new Map(picked);
    if (allOnPage) rows.forEach((a) => next.delete(a.id));
    else
      for (const a of rows) {
        if (next.size >= MAX_EDIT_SELECTION) break;
        next.set(a.id, a);
      }
    onChange(next);
  };

  return (
    <div className="rounded-md border border-slate-200 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-700">
          {picked.size > 0 ? (
            <>
              <span className="font-medium text-slate-900">
                {picked.size} aset dipilih
              </span>
              {full && (
                <span className="text-amber-700">
                  {" "}
                  (maksimal {MAX_EDIT_SELECTION})
                </span>
              )}
            </>
          ) : (
            "Belum ada aset dipilih — download memakai pencarian & filter saat ini."
          )}
        </p>
        <div className="flex gap-2">
          {picked.size > 0 && (
            <Button
              size="sm"
              variant="ghost"
              onClick={() => onChange(new Map())}
            >
              <X className="size-4" aria-hidden /> Hapus pilihan
            </Button>
          )}
          <Button
            size="sm"
            variant="secondary"
            onClick={() => setOpen((o) => !o)}
            aria-expanded={open}
          >
            <ListChecks className="size-4" aria-hidden />{" "}
            {open ? "Selesai memilih" : "Pilih Aset"}
          </Button>
        </div>
      </div>

      {open && (
        <div className="mt-3 space-y-2">
          <Select
            value={categoryId}
            onChange={(e) => {
              setCategoryId(e.target.value);
              setPage(1);
            }}
            aria-label="Kategori aset untuk dipilih"
          >
            <option value="">— Pilih kategori terlebih dahulu —</option>
            {categories.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          {categoryId === "" ? (
            <p className="py-4 text-center text-sm text-slate-500">
              Pilih kategori untuk menampilkan daftar aset.
            </p>
          ) : (
            <>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  setApplied(search.trim());
                  setPage(1);
                }}
              >
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Cari nama, no aset, atau no alias lalu tekan Enter"
                  aria-label="Cari aset untuk dipilih"
                />
              </form>
              {query.isLoading ? (
                <Spinner />
              ) : query.isError ? (
                <ErrorState error={query.error} />
              ) : rows.length === 0 ? (
                <p className="py-4 text-center text-sm text-slate-500">
                  Tidak ada aset yang cocok di kategori ini.
                </p>
              ) : (
                <>
                  <label className="flex items-center gap-2 border-b border-slate-200 px-2 pb-2 text-sm font-medium text-slate-700">
                    <input
                      type="checkbox"
                      className="size-4 rounded border-slate-300"
                      checked={allOnPage}
                      disabled={full && !allOnPage}
                      onChange={togglePage}
                    />
                    Pilih semua di halaman ini
                  </label>
                  <ul className="max-h-64 divide-y divide-slate-100 overflow-y-auto">
                    {rows.map((a) => {
                      const checked = picked.has(a.id);
                      return (
                        <li key={a.id}>
                          <label className="flex cursor-pointer items-center gap-2 px-2 py-1.5 text-sm hover:bg-slate-50">
                            <input
                              type="checkbox"
                              className="size-4 rounded border-slate-300"
                              checked={checked}
                              disabled={full && !checked}
                              onChange={() => toggle(a)}
                            />
                            <span className="font-mono text-xs text-slate-500">
                              {a.asset_number}
                            </span>
                            <span className="truncate text-slate-900">
                              {a.name}
                            </span>
                          </label>
                        </li>
                      );
                    })}
                  </ul>
                  <Pagination meta={query.data!.meta} onPage={setPage} />
                </>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
