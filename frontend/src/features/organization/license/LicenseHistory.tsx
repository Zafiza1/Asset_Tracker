import { Card } from '../../../components/ui/Card'
import { EmptyState } from '../../../components/ui/Feedback'
import type { LicenseEntry } from '../../../lib/api/types'
import { formatDate } from '../../../lib/format'
import { daysUntil, groupLicenses, licenseSourceLabel, licenseStatusLabel, type Batch } from './labels'
import { StatusBadge } from '../../../components/ui/Badge'

function referenceRange(refs: string[]): string | null {
  if (refs.length === 0) return null
  const sorted = [...refs].sort()
  return sorted.length === 1 ? sorted[0] : `${sorted[0]} … ${sorted[sorted.length - 1]}`
}

function Remaining({ batch }: { batch: Batch }) {
  if (batch.status !== 'active') return null
  const days = daysUntil(batch.expiresAt)
  if (days > 60) return null
  return <span className={days <= 14 ? 'font-medium text-red-700' : 'font-medium text-amber-700'}>Berakhir {days <= 0 ? 'hari ini' : `dalam ${days} hari`}</span>
}

export function LicenseHistory({ licenses }: { licenses: LicenseEntry[] }) {
  const batches = groupLicenses(licenses)

  return (
    <Card title="Riwayat lisensi">
      {batches.length === 0 ? (
        <EmptyState title="Belum ada lisensi" />
      ) : (
        <ul className="divide-y divide-slate-100">
          {batches.map((b) => (
            <li key={b.key} className="flex flex-col gap-2 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="font-medium text-slate-900">
                    {b.kind}
                    {b.references.length > 1 && <span className="font-normal text-slate-500"> · {b.references.length} lisensi</span>}
                  </p>
                  <StatusBadge status={b.status}>{licenseStatusLabel[b.status]}</StatusBadge>
                </div>
                <p className="mt-0.5 truncate text-xs text-slate-500" title={b.references.join(', ')}>
                  {licenseSourceLabel[b.source] ?? b.source}
                  {referenceRange(b.references) && <> · <span className="font-mono">{referenceRange(b.references)}</span></>}
                </p>
              </div>
              <div className="shrink-0 text-sm text-slate-600 sm:text-right">
                <p className="tabular-nums">
                  {formatDate(b.startsAt)} – {formatDate(b.expiresAt)}
                </p>
                <p className="text-xs">
                  {b.revokedAt ? <span className="text-red-700">Dicabut {formatDate(b.revokedAt)}</span> : <Remaining batch={b} />}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
