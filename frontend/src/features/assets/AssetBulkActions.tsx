import { useQueryClient } from "@tanstack/react-query";
import { Archive, FileSpreadsheet, Printer, X } from "lucide-react";
import { useState } from "react";
import { Button } from "../../components/ui/Button";
import { Alert } from "../../components/ui/Feedback";
import { Field, Textarea } from "../../components/ui/Field";
import { Modal } from "../../components/ui/Modal";
import { api, isApprovalPending } from "../../lib/api/client";
import { errorMessage } from "../../lib/forms";
import { MAX_EDIT_SELECTION, type Asset } from "./api";
import { printAssetLabels } from "./AssetBarcodeModal";

interface Props {
  selected: Asset[];
  /** Picked rows that are not on the current page (picked on another page). */
  offPage: number;
  canEdit: boolean;
  /** Picked assets that go into the Multiple Edit sheet (active ones only). */
  editable: number;
  canArchive: boolean;
  barcode: boolean;
  onEdit: () => void;
  onClear: () => void;
  /** Drops these assets from the selection (after they were deactivated). */
  onDone: (ids: string[]) => void;
}

/**
 * Actions on the assets picked with the list's checkboxes. Each action says how many of the
 * picked assets it applies to (only assets with a barcode get labels, only active ones can be
 * deactivated), so nothing happens to an asset silently.
 */
export function AssetBulkActions({ selected, offPage, canEdit, editable, canArchive, barcode, onEdit, onClear, onDone }: Props) {
  const [archiving, setArchiving] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [printError, setPrintError] = useState<string | null>(null);
  if (selected.length === 0) return null;

  const printable = selected.filter((a) => a.barcode_enabled);
  const archivable = selected.filter((a) => !a.is_archived);
  const tooMany = editable > MAX_EDIT_SELECTION;

  const print = async () => {
    setPrinting(true);
    setPrintError(null);
    try {
      await printAssetLabels(printable);
    } catch (e) {
      setPrintError(errorMessage(e));
    } finally {
      setPrinting(false);
    }
  };

  return (
    <div
      role="region"
      aria-label="Aksi untuk aset terpilih"
      className="flex flex-col gap-2 border-b border-brand-100 bg-brand-50/60 px-4 py-2.5 text-sm sm:flex-row sm:items-center"
    >
      <p className="text-slate-700" aria-live="polite">
        <span className="font-semibold text-slate-900">{selected.length} aset dipilih</span>
        {offPage > 0 && <span className="text-slate-500"> ({offPage} dari halaman lain)</span>}
      </p>
      <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
        {canEdit && (
          <Button
            size="sm"
            variant="secondary"
            onClick={onEdit}
            disabled={tooMany || editable === 0}
            title={
              tooMany
                ? `Multiple Edit maksimal ${MAX_EDIT_SELECTION} aset sekaligus`
                : editable === 0
                  ? "Aset nonaktif tidak dapat diubah lewat Multiple Edit"
                  : `Ubah ${editable} aset terpilih lewat Excel`
            }
          >
            <FileSpreadsheet className="size-4" aria-hidden /> Edit di Excel
            {editable !== selected.length && <span className="tabular-nums">({editable})</span>}
          </Button>
        )}
        {barcode && (
          <Button
            size="sm"
            variant="secondary"
            onClick={print}
            loading={printing}
            disabled={printable.length === 0}
            title={printable.length === 0 ? "Tidak ada aset terpilih yang memiliki barcode" : `Cetak label barcode ${printable.length} aset`}
          >
            <Printer className="size-4" aria-hidden /> Cetak barcode
            {printable.length !== selected.length && <span className="tabular-nums">({printable.length})</span>}
          </Button>
        )}
        {canArchive && (
          <Button
            size="sm"
            variant="secondary"
            onClick={() => setArchiving(true)}
            disabled={archivable.length === 0}
            title={archivable.length === 0 ? "Semua aset terpilih sudah nonaktif" : `Nonaktifkan ${archivable.length} aset`}
          >
            <Archive className="size-4" aria-hidden /> Nonaktifkan
            {archivable.length !== selected.length && <span className="tabular-nums">({archivable.length})</span>}
          </Button>
        )}
        <Button size="sm" variant="ghost" onClick={onClear}>
          <X className="size-4" aria-hidden /> Batalkan pilihan
        </Button>
      </div>
      {printError && <p className="text-xs text-red-600">{printError}</p>}
      {archiving && (
        <BulkArchiveModal
          assets={archivable}
          onClose={() => setArchiving(false)}
          onDone={onDone}
        />
      )}
    </div>
  );
}

interface Outcome {
  done: number;
  pending: number;
  failed: { asset: Asset; message: string }[];
}

/** Deactivates the assets one by one, so one refused asset does not stop the others. */
function BulkArchiveModal({ assets, onClose, onDone }: { assets: Asset[]; onClose: () => void; onDone: (ids: string[]) => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState("");
  const [progress, setProgress] = useState<number | null>(null);
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [handled, setHandled] = useState<string[]>([]);
  const running = progress !== null && outcome === null;

  const run = async () => {
    const result: Outcome = { done: 0, pending: 0, failed: [] };
    const ids: string[] = [];
    setProgress(0);
    for (const [i, asset] of assets.entries()) {
      try {
        const response = await api.post(`/assets/${asset.id}/archive`, { reason: reason.trim() || null });
        if (isApprovalPending(response)) result.pending++;
        else result.done++;
        ids.push(asset.id);
      } catch (e) {
        result.failed.push({ asset, message: errorMessage(e) });
      }
      setProgress(i + 1);
    }
    setOutcome(result);
    setHandled(ids);
    await queryClient.invalidateQueries({ queryKey: ["/assets"] });
  };

  return (
    <Modal
      open
      title={`Nonaktifkan ${assets.length} aset?`}
      onClose={running ? () => {} : outcome ? () => (onClose(), onDone(handled)) : onClose}
      footer={
        outcome ? (
          // Handled assets leave the selection only now, so the summary stays on screen until read.
          <Button onClick={() => (onClose(), onDone(handled))}>Tutup</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={running}>
              Batal
            </Button>
            <Button variant="danger" loading={running} onClick={run}>
              Nonaktifkan {assets.length} aset
            </Button>
          </>
        )
      }
    >
      {outcome ? (
        <div className="space-y-3 text-sm">
          {outcome.done > 0 && <Alert tone="success">{outcome.done} aset dinonaktifkan.</Alert>}
          {outcome.pending > 0 && <Alert tone="info">{outcome.pending} aset menunggu persetujuan sebelum dinonaktifkan.</Alert>}
          {outcome.failed.length > 0 && (
            <Alert tone="error" title={`${outcome.failed.length} aset gagal dinonaktifkan`}>
              <ul className="mt-1 list-disc space-y-0.5 pl-4">
                {outcome.failed.map(({ asset, message }) => (
                  <li key={asset.id}>
                    <span className="font-mono text-xs">{asset.asset_number}</span> {asset.name}: {message}
                  </li>
                ))}
              </ul>
            </Alert>
          )}
        </div>
      ) : (
        <>
          <p className="text-sm text-slate-700">
            Aset tidak lagi dihitung sebagai inventaris aktif dan tidak dapat ditransaksikan. Nomor aset, data, dan seluruh riwayatnya tetap tersimpan, dan aset dapat diaktifkan kembali kapan saja dari halaman detailnya.
          </p>
          <ul className="max-h-40 divide-y divide-slate-100 overflow-y-auto rounded-md text-sm ring-1 ring-slate-200">
            {assets.map((a) => (
              <li key={a.id} className="flex gap-2 px-3 py-1.5">
                <span className="shrink-0 font-mono text-xs leading-5 text-slate-500">{a.asset_number}</span>
                <span className="truncate text-slate-900" title={a.name}>{a.name}</span>
              </li>
            ))}
          </ul>
          <Field label="Alasan (berlaku untuk semua aset di atas)">
            {(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. rusak total, dijual, hilang" disabled={running} />}
          </Field>
          {running && (
            <p className="text-sm text-slate-600" aria-live="polite">
              Memproses {progress} dari {assets.length} aset…
            </p>
          )}
        </>
      )}
    </Modal>
  );
}
