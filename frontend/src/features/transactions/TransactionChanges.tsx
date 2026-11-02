import { ArrowRight } from 'lucide-react'
import { assetStatusLabel } from '../assets/api'
import type { AssetTransaction, TransactionWithState } from './api'

const locationChange = (trx: AssetTransaction) => trx.to_location && { from: trx.from_location?.name, to: trx.to_location.name }
const statusChange = (trx: AssetTransaction) => trx.to_status && { from: trx.from_status && assetStatusLabel[trx.from_status], to: assetStatusLabel[trx.to_status] }

/** "Gudang → Kantor", or just the new value when there was none before; otherwise the unchanged value. Inherits the cell's font. */
function ChangeValue({ change, unchanged }: { change: { from?: string | null; to: string } | null | undefined; unchanged: string | null }) {
  if (!change) return unchanged ?? '—'
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {change.from && (
        <>
          <span>{change.from}</span>
          <ArrowRight className="size-3.5 text-slate-400" aria-label="menjadi" />
        </>
      )}
      <span>{change.to}</span>
    </span>
  )
}

/** Location / status columns of an asset's history: the change, or the value the asset kept through the transaction. */
export const LocationChange = ({ trx }: { trx: TransactionWithState }) => <ChangeValue change={locationChange(trx)} unchanged={trx.location_after ?? 'Tanpa lokasi'} />
export const StatusChange = ({ trx }: { trx: TransactionWithState }) => <ChangeValue change={statusChange(trx)} unchanged={assetStatusLabel[trx.status_after]} />

/** One line per thing the transaction changed ("Lokasi: Gudang → Kantor"), plus borrower and notes. */
export function TransactionChanges({ trx }: { trx: AssetTransaction }) {
  const rows: { label: string; from?: string | null; to?: string | null }[] = []
  if (trx.to_location) rows.push({ label: 'Lokasi', ...locationChange(trx) })
  if (trx.to_status) rows.push({ label: 'Status', ...statusChange(trx) })

  return (
    <div className="space-y-0.5 text-sm">
      {rows.map((r) => (
        <p key={r.label} className="flex flex-wrap items-center gap-1 text-slate-700">
          <span className="text-slate-500">{r.label}:</span>
          {r.from && (
            <>
              <span>{r.from}</span>
              <ArrowRight className="size-3.5 text-slate-400" aria-label="menjadi" />
            </>
          )}
          <span className="font-medium text-slate-900">{r.to}</span>
        </p>
      ))}
      {trx.borrower_name && (
        <p className="text-slate-700">
          <span className="text-slate-500">Peminjam:</span> {trx.borrower_name}
          {trx.due_date && <span className="text-slate-500"> · kembali {trx.due_date}</span>}
        </p>
      )}
      {trx.notes && <p className="whitespace-pre-line text-slate-600">{trx.notes}</p>}
      {rows.length === 0 && !trx.borrower_name && !trx.notes && <p className="text-slate-400">—</p>}
    </div>
  )
}
