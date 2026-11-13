import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { CheckCircle2, PowerOff, QrCode, type LucideIcon } from "lucide-react";
import { Badge } from "../../components/ui/Badge";
import { Card, PageHeader } from "../../components/ui/Card";
import { Alert, ErrorState, FormSkeleton } from "../../components/ui/Feedback";
import { toast } from "../../components/ui/toast";
import { api } from "../../lib/api/client";
import type { ModuleKey, OrganizationModule } from "../../lib/api/types";
import { ME_KEY } from "../../lib/auth/context";

const ICONS: Record<ModuleKey, LucideIcon> = { barcode: QrCode };

/**
 * One optional module of the organization (sidebar section "Modul"). A module is the global switch:
 * while Barcode is off no asset shows a barcode; once on, each asset still needs its own "Barcode"
 * field set to Ya.
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
      // The profile carries the switched-on modules; asset lists carry whether each asset has a barcode.
      await Promise.all(
        [ME_KEY, ["/assets"]].map((queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
        ),
      );
    },
  });

  return (
    <>
      <PageHeader
        title={`Modul ${modules.data?.data.find((m) => m.key === module)?.name ?? ""}`.trim()}
        description="Aktifkan modul sesuai kebutuhan organisasi. Modul yang nonaktif tidak tampil di aplikasi."
      />
      {modules.isLoading ? (
        <FormSkeleton fields={2} label="Memuat modul…" />
      ) : modules.isError ? (
        <ErrorState error={modules.error} onRetry={() => void modules.refetch()} />
      ) : (
        <div className="grid gap-6">
          {toggle.isError && <Alert tone="error">{toggle.error.message}</Alert>}
          {modules
            .data!.data.filter((m) => m.key === module)
            .map((m) => {
              const Icon = ICONS[m.key];
              const pending =
                toggle.isPending && toggle.variables?.key === m.key;
              return (
                <Card key={m.key}>
                  <div className="flex items-start gap-4 p-5">
                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 ring-1 ring-brand-100">
                      <Icon className="size-5" aria-hidden />
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2
                          id={`module-${m.key}`}
                          className="text-base font-semibold text-slate-900"
                        >
                          Modul {m.name}
                        </h2>
                        <Badge tone={m.enabled ? "green" : "slate"} icon={m.enabled ? CheckCircle2 : PowerOff}>
                          {m.enabled ? "Aktif" : "Nonaktif"}
                        </Badge>
                      </div>
                      <p className="mt-1 text-sm text-slate-600">
                        {m.description}
                      </p>
                    </div>
                    <Switch
                      checked={m.enabled}
                      busy={pending}
                      labelledBy={`module-${m.key}`}
                      onChange={() => toggle.mutate(m)}
                    />
                  </div>
                  {m.key === "barcode" && <BarcodeSteps enabled={m.enabled} />}
                </Card>
              );
            })}
        </div>
      )}
    </>
  );
}

/** The steps from switching the module on to a printed label. */
function BarcodeSteps({ enabled }: { enabled: boolean }) {
  const steps = [
    { title: "Aktifkan Modul Barcode", done: enabled },
    { title: "Aktifkan barcode pada aset", done: false },
    { title: "Cetak label", done: false },
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
