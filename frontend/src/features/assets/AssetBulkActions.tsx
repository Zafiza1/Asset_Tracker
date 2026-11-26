import { Printer, X } from "lucide-react";
import { useState } from "react";
import { Button } from "../../components/ui/Button";
import { errorMessage } from "../../lib/forms";
import type { Asset } from "./api";
import { printAssetLabels } from "./AssetBarcodeModal";

interface Props {
  selected: Asset[];
  /** Picked rows that are not on the current page (picked on another page). */
  offPage: number;
  barcode: boolean;
  onClear: () => void;
}

/**
 * Bar above the list while assets are picked with the checkboxes. Printing barcode labels is the
 * one action here; Multiple Edit in the page header takes the picked assets too (all assets when
 * none are picked). The button says how many of the picked assets have a barcode.
 */
export function AssetBulkActions({ selected, offPage, barcode, onClear }: Props) {
  const [printing, setPrinting] = useState(false);
  const [printError, setPrintError] = useState<string | null>(null);
  if (selected.length === 0) return null;

  const printable = selected.filter((a) => a.barcode_enabled);

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
        <Button size="sm" variant="ghost" onClick={onClear}>
          <X className="size-4" aria-hidden /> Batalkan pilihan
        </Button>
      </div>
      {printError && <p className="text-xs text-red-600">{printError}</p>}
    </div>
  );
}
