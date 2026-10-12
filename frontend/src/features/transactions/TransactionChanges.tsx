import { ArrowRight } from 'lucide-react'
import { assetStatusLabel } from '../assets/api'
import type { AssetTransaction } from './api'

/** One line per thing the transaction changed ("Lokasi: Gudang → Kantor"), plus borrower and notes. */
export function TransactionChanges({ trx }: { trx: AssetTransaction }) {
  const rows: { label: string; from?: string | null; to?: string | null }[] = []
  if (trx.to_location) rows.push({ label: 'Lokasi', from: trx.from_location?.name, to: trx.to_location.name })
  if (trx.to_status) rows.push({ label: 'Status', from: trx.from_status && assetStatusLabel[trx.from_status], to: assetStatusLabel[trx.to_status] })
  if (trx.to_condition) rows.push({ label: 'Kondisi', from: trx.from_condition?.name, to: trx.to_condition.name })

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
