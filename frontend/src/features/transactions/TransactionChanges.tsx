import { ArrowRight, CircleCheck, Clock } from 'lucide-react'
import { Badge } from '../../components/ui/Badge'
import { formatDate } from '../../lib/format'
import { assetStatusLabel } from '../assets/api'
import { daysLate, type AssetTransaction, type TransactionWithState } from './api'
import { Link } from 'react-router'
import { FieldValueList } from '../transactionTypes/FieldValueList'

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
      {trx.type === 'outgoing' && (
        <>
          <p className="text-slate-700">
            <span className="font-medium text-slate-900">{[trx.outgoing_nature_label, trx.reason_label].filter(Boolean).join(' · ')}</span>
          </p>
          <p className="text-slate-700">
            <span className="text-slate-500">Ke:</span> {trx.counterparty_name}
            {trx.counterparty_org && <span className="text-slate-500"> ({trx.counterparty_org})</span>}
            {trx.counterparty_contact && <span className="text-slate-500"> · {trx.counterparty_contact}</span>}
          </p>
          {trx.due_date && (
            <p className="text-slate-700">
              <span className="text-slate-500">Rencana kembali:</span> <span className="tabular-nums">{formatDate(trx.due_date)}</span>
            </p>
          )}
          <ReturnStatusBadge trx={trx} />
        </>
      )}
      {trx.type === 'incoming' && trx.counterparty_name && (
        <p className="text-slate-700">
          <span className="text-slate-500">Dari:</span> {trx.counterparty_name}
          {trx.counterparty_org && <span className="text-slate-500"> ({trx.counterparty_org})</span>}
        </p>
      )}
      {trx.document_number && (
        <p className="text-slate-700">
          <span className="text-slate-500">Dokumen:</span> <span className="font-mono text-xs">{trx.document_number}</span>
        </p>
      )}
      {trx.borrower_name && (
        <p className="text-slate-700">
          <span className="text-slate-500">Peminjam:</span> {trx.borrower_name}
          {trx.due_date && <span className="text-slate-500"> · kembali {trx.due_date}</span>}
        </p>
      )}
      {trx.field_values && <FieldValueList values={trx.field_values} />}
      {trx.notes && <p className="whitespace-pre-line text-slate-600">{trx.notes}</p>}
      {trx.run_id && (
        <Link to={`/transaksi/berjalan/${trx.run_id}`} className="text-xs font-semibold text-brand-700 hover:underline">
          Lihat alur transaksi
        </Link>
      )}
      {rows.length === 0 && !trx.borrower_name && !trx.notes && !trx.counterparty_name && !trx.field_values?.some((v) => v.display) && <p className="text-slate-400">—</p>}
    </div>
  )
}

/** Return state of a temporary outgoing row: late (with days), back, or still outside. */
export function ReturnStatusBadge({ trx }: { trx: AssetTransaction }) {
  if (!trx.return_status) return null
  if (trx.return_status === 'Terlambat') {
    return (
      <Badge tone="red" icon={Clock}>
        Terlambat {daysLate(trx)} hari
      </Badge>
    )
  }
  if (trx.return_status === 'Sudah kembali') {
    return (
      <Badge tone="green" icon={CircleCheck}>
        Sudah kembali{trx.days_out != null ? ` · ${trx.days_out} hari di luar` : ''}
      </Badge>
    )
  }
  return (
    <Badge tone="amber" icon={Clock}>
      Belum kembali{trx.days_out != null ? ` · ${trx.days_out} hari` : ''}
    </Badge>
  )
}
