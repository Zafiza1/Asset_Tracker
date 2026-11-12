import clsx from 'clsx'
import type { License } from '../../../lib/api/types'
import { Card } from '../../../components/ui/Card'

/**
 * Seats as one bar: active members and open invitations fill it, the rest is free. Each part
 * says what it counts, so "aktif" (people) and "tersedia" (seats) cannot be confused.
 */
export function SeatUsage({ license }: { license: License }) {
  const { capacity, active_members: members, pending_invitations: pending, available } = license.seats
  const used = members + pending
  const total = Math.max(capacity, used, 1)
  const pct = (n: number) => `${(n / total) * 100}%`

  const parts = [
    { key: 'members', label: 'Pengguna aktif', value: members, hint: 'Akun aktif, masing-masing memakai 1 seat.', dot: 'bg-brand-600' },
    { key: 'pending', label: 'Undangan tertunda', value: pending, hint: 'Undangan yang belum diterima tetap memesan seat.', dot: 'bg-amber-400' },
    {
      key: 'free',
      label: 'Seat tersisa',
      value: available,
      hint: license.over_limit ? `Kelebihan ${used - capacity} seat dari kapasitas.` : 'Bisa dipakai untuk mengundang pengguna baru.',
      dot: 'bg-slate-200 ring-1 ring-inset ring-slate-300',
    },
  ]

  return (
    <Card title="Pemakaian seat">
      <div className="space-y-4 px-5 py-4">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <p className="text-sm text-slate-600">
            <span className="text-2xl font-semibold tabular-nums text-slate-900">{used}</span> dari <span className="font-semibold tabular-nums text-slate-900">{capacity}</span> seat terpakai
          </p>
          <p className={clsx('text-sm font-medium tabular-nums', license.over_limit ? 'text-red-700' : available === 0 ? 'text-amber-700' : 'text-slate-600')}>
            {license.over_limit ? 'Melebihi kapasitas' : available === 0 ? 'Seat habis' : `${available} seat tersisa`}
          </p>
        </div>

        <div
          className={clsx('flex h-2.5 overflow-hidden rounded-full', license.over_limit ? 'bg-red-100' : 'bg-slate-100')}
          role="meter"
          aria-label="Seat terpakai"
          aria-valuemin={0}
          aria-valuemax={capacity}
          aria-valuenow={used}
          aria-valuetext={`${used} dari ${capacity} seat terpakai`}
        >
          <div className="bg-brand-600" style={{ width: pct(members) }} />
          <div className="bg-amber-400" style={{ width: pct(pending) }} />
        </div>

        <dl className="grid gap-3 sm:grid-cols-3">
          {parts.map((p) => (
            <div key={p.key} className="rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-inset ring-slate-200/80">
              <dt className="flex items-center gap-2 text-sm font-medium text-slate-700">
                <span className={clsx('size-2.5 shrink-0 rounded-full', p.dot)} aria-hidden />
                {p.label}
              </dt>
              <dd className="mt-1 text-xl font-semibold tabular-nums text-slate-900">{p.value}</dd>
              <dd className="mt-0.5 text-xs text-slate-500">{p.hint}</dd>
            </div>
          ))}
        </dl>

        <p className="text-xs text-slate-500">Kapasitas = jumlah lisensi yang sedang berlaku. Setiap lisensi memberi 1 seat dan 1 kode lisensi.</p>
      </div>
    </Card>
  )
}
