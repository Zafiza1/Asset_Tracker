import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { CheckCircle2, FolderTree, PowerOff, QrCode, type LucideIcon } from "lucide-react";
import { useState } from "react";
import { Badge } from "../../components/ui/Badge";
import { Card, PageHeader } from "../../components/ui/Card";
import { Alert, EmptyState, ErrorState, FormSkeleton, TableSkeleton } from "../../components/ui/Feedback";
import { SearchForm } from "../../components/ui/Filters";
import { toast } from "../../components/ui/toast";
import { api } from "../../lib/api/client";
import type { ModuleCategory, ModuleKey, OrganizationModule } from "../../lib/api/types";
import { ME_KEY } from "../../lib/auth/context";

const ICONS: Record<ModuleKey, LucideIcon> = { barcode: QrCode };

/**
 * One optional module of the organization (sidebar section "Modul"). A module is the global switch:
 * while Barcode is off no asset shows a barcode; once on, only the assets of the categories checked
 * below (subcategories included) use it. The asset detail lists an asset's modules as "Modul"
 * under Informasi Dasar.
 */
export function ModulesPage({ module }: { module: ModuleKey }) {
  const queryClient = useQueryClient();
  const modules = useQuery({
    queryKey: ["/modules"],
    queryFn: () => api.get<{ data: OrganizationModule[] }>("/modules"),
  });

  const toggle = useMutation({
    mutationFn: (m: OrganizationModule) =>
      api.put<{ data: OrganizationModule[] }>(`/modules/${m.key}`, {
        enabled: !m.enabled,
      }),
    onSuccess: async (result, m) => {
      queryClient.setQueryData(["/modules"], result);
      toast.success(m.enabled ? `Modul ${m.name} dinonaktifkan` : `Modul ${m.name} diaktifkan`);
      // The profile carries the switched-on modules; asset lists carry the modules of each asset.
      await Promise.all(
        [ME_KEY, ["/assets"]].map((queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
        ),
      );
    },
  });

  const current = modules.data?.data.find((m) => m.key === module);

  return (
    <>
      <PageHeader
        title={`Modul ${current?.name ?? ""}`.trim()}
        description="Aktifkan modul sesuai kebutuhan business unit, lalu pilih kategori aset yang memakainya. Modul yang nonaktif tidak tampil di aplikasi."
      />
      {modules.isLoading ? (
        <FormSkeleton fields={2} label="Memuat modul…" />
      ) : modules.isError ? (
        <ErrorState error={modules.error} onRetry={() => void modules.refetch()} />
      ) : current ? (
        <div className="grid gap-6">
          {toggle.isError && <Alert tone="error">{toggle.error.message}</Alert>}
          <ModuleCard
            module={current}
            pending={toggle.isPending && toggle.variables?.key === current.key}
            onToggle={() => toggle.mutate(current)}
          />
          <ModuleCategories module={current} />
        </div>
      ) : null}
    </>
  );
}

function ModuleCard({
  module: m,
  pending,
  onToggle,
}: {
  module: OrganizationModule;
  pending: boolean;
  onToggle: () => void;
}) {
  const Icon = ICONS[m.key];
  return (
    <Card>
      <div className="flex items-start gap-4 p-5">
        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 ring-1 ring-brand-100">
          <Icon className="size-5" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 id={`module-${m.key}`} className="text-base font-semibold text-slate-900">
              Modul {m.name}
            </h2>
            <Badge tone={m.enabled ? "green" : "slate"} icon={m.enabled ? CheckCircle2 : PowerOff}>
              {m.enabled ? "Aktif" : "Nonaktif"}
            </Badge>
          </div>
          <p className="mt-1 text-sm text-slate-600">{m.description}</p>
        </div>
        <Switch checked={m.enabled} busy={pending} labelledBy={`module-${m.key}`} onChange={onToggle} />
      </div>
      {m.key === "barcode" && <BarcodeSteps module={m} />}
    </Card>
  );
}

/** The steps from switching the module on to a printed label. */
function BarcodeSteps({ module }: { module: OrganizationModule }) {
  const categories = useQuery(categoriesQuery(module.key));
  const steps = [
    { title: "Aktifkan Modul Barcode", done: module.enabled },
    { title: "Centang kategori aset yang memakai barcode pada daftar di bawah", done: (categories.data?.data ?? []).some((c) => c.assigned) },
    { title: "Cetak label dari Data Aset atau detail aset", done: false },
  ];

  return (
    <div className="border-t border-slate-100 px-5 py-4">
      <h3 className="text-sm font-semibold text-slate-700">
        Langkah mengaktifkan barcode
      </h3>
      <ol className="mt-3 space-y-3">
        {steps.map((s, i) => (
          <li key={s.title} className="flex items-center gap-3">
            <span
              className={clsx(
                "grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold",
                s.done
                  ? "bg-green-600 text-white"
                  : "bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200",
              )}
              aria-hidden
            >
              {i + 1}
            </span>
            <p className="text-sm font-medium text-slate-900">
              {s.title}
              {s.done && <span className="sr-only"> (selesai)</span>}
            </p>
          </li>
        ))}
      </ol>
    </div>
  );
}

function categoriesQuery(module: ModuleKey) {
  return {
    queryKey: ["/modules", module, "categories"],
    queryFn: () => api.get<{ data: ModuleCategory[]; assigned_assets: number }>(`/modules/${module}/categories`),
  };
}

/**
 * Checklist of the asset categories, as a tree: a checked category's assets (subcategories
 * included) use the module. A subcategory of a checked category is shown checked and locked.
 * Every click saves right away.
 */
function ModuleCategories({ module }: { module: OrganizationModule }) {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState("");
  const [search, setSearch] = useState("");
  const categories = useQuery(categoriesQuery(module.key));

  const save = useMutation({
    mutationFn: ({ category, assigned }: { category: ModuleCategory; assigned: boolean }) =>
      api.put<{ data: { changed: number } }>(`/modules/${module.key}/categories`, {
        category_ids: [category.id],
        assigned,
      }),
    onSuccess: async (_result, { category, assigned }) => {
      toast.success(
        assigned
          ? `Aset kategori ${category.name} memakai Modul ${module.name}`
          : `Aset kategori ${category.name} tidak lagi memakai Modul ${module.name}`,
      );
      // The checklist, and the asset lists/details that show the asset's modules and print buttons.
      await Promise.all(
        [["/modules", module.key, "categories"], ["/assets"]].map((queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
        ),
      );
    },
  });

  const all = categories.data?.data ?? [];
  const term = search.toLowerCase();
  // While searching, the tree is flattened to the matching categories.
  const rows = term ? all.filter((c) => c.name.toLowerCase().includes(term) || c.code.toLowerCase().includes(term)) : all;

  return (
    <Card
      title={`Kategori aset yang memakai Modul ${module.name}`}
      actions={
        categories.data && (
          <span className="text-sm text-slate-500">{categories.data.assigned_assets} aset memakai modul ini</span>
        )
      }
    >
      <div className="space-y-3 p-4">
        <p className="text-sm text-slate-600">
          Centang kategori yang asetnya memakai modul ini. Subkategori ikut kategori induknya, dan aset baru
          di kategori itu langsung memakai modul. Perubahan langsung tersimpan.
          {!module.enabled && " Pilihan berlaku setelah modul diaktifkan."}
        </p>
        <SearchForm
          label="Cari kategori"
          placeholder="Cari nama atau kode kategori"
          value={draft}
          onChange={setDraft}
          onSubmit={() => setSearch(draft.trim())}
          onClear={() => {
            setDraft("");
            setSearch("");
          }}
        />
        {save.isError && <Alert tone="error">{save.error.message}</Alert>}
      </div>
      {categories.isLoading ? (
        <TableSkeleton rows={5} columns={2} label="Memuat kategori…" />
      ) : categories.isError ? (
        <ErrorState error={categories.error} onRetry={() => void categories.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={FolderTree}
          title="Tidak ada kategori"
          description={term ? "Tidak ada kategori yang cocok dengan pencarian ini." : "Belum ada kategori aset aktif."}
        />
      ) : (
        <ul
          className={clsx("divide-y divide-slate-100 border-t border-slate-100", save.isPending && "pointer-events-none opacity-60")}
          aria-busy={save.isPending || undefined}
        >
          {rows.map((c) => {
            const checked = c.assigned || c.inherited;
            return (
              <li key={c.id}>
                <label
                  className={clsx(
                    "flex items-center gap-3 px-4 py-2.5 text-sm",
                    c.inherited ? "cursor-not-allowed" : "cursor-pointer hover:bg-slate-50",
                  )}
                  style={term ? undefined : { paddingLeft: `${1 + c.depth * 1.5}rem` }}
                >
                  <input
                    type="checkbox"
                    className="size-4 shrink-0 rounded border-slate-300 accent-brand-700 disabled:opacity-50"
                    checked={checked}
                    disabled={c.inherited}
                    aria-label={c.name}
                    onChange={() => save.mutate({ category: c, assigned: !c.assigned })}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="font-medium text-slate-900">{c.name}</span>{" "}
                    <span className="text-xs text-slate-500">{c.code}</span>
                  </span>
                  {c.inherited && <span className="text-xs text-slate-500">Ikut kategori induk</span>}
                  <span className="w-16 shrink-0 text-right text-xs tabular-nums text-slate-500">{c.asset_count} aset</span>
                </label>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function Switch({
  checked,
  busy,
  labelledBy,
  onChange,
}: {
  checked: boolean;
  busy: boolean;
  labelledBy: string;
  onChange: () => void;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-labelledby={labelledBy}
      aria-busy={busy || undefined}
      disabled={busy}
      onClick={onChange}
      className={clsx(
        "relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-wait disabled:opacity-60",
        checked ? "bg-brand-700" : "bg-slate-300",
      )}
    >
      <span
        className={clsx(
          "inline-block size-5 rounded-full bg-white shadow transition-transform duration-150",
          checked ? "translate-x-5.5" : "translate-x-0.5",
        )}
      />
    </button>
  );
}
