import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { MapPinOff, Truck } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { PillTabs } from '../../components/ui/Tabs'
import { formatDateTime } from '../../lib/format'
import { ago, crewText, deliveriesApi, DELIVERIES_KEY, REFRESH_MS, vehicleText, type Delivery } from './api'
import type { MapMarker } from './DeliveryMap'
import { DeliveryMapView, DeliveryProgress, DeliveryStatusBadge, DirectionBadge } from './DeliveryParts'

type View = 'moving' | 'waiting'

/** Menu Tracking: where every vehicle is now, with its driver, helper and cylinders. */
export function TrackingPage() {
  const [view, setView] = useState<View>('moving')
  const [selected, setSelected] = useState<string | null>(null)
  const query = useQuery({ queryKey: [...DELIVERIES_KEY, 'tracking'], queryFn: () => deliveriesApi.tracking(), refetchInterval: REFRESH_MS })
  const all = useMemo(() => query.data?.data ?? [], [query.data])
  const moving = useMemo(() => all.filter((d) => d.status === 'in_transit'), [all])
  const waiting = all.filter((d) => d.status !== 'in_transit')
  const markers = useMemo(
    () =>
      moving.flatMap((d): MapMarker[] =>
        d.last_position
          ? [{ id: d.id, latitude: d.last_position.latitude, longitude: d.last_position.longitude, label: `${vehicleText(d)} · ${d.number} · ${ago(d.last_position.recorded_at)}`, kind: d.last_event?.type === 'issue' ? 'issue' : 'vehicle', selected: d.id === selected }]
          : [],
      ),
    [moving, selected],
  )

  return (
    <>
      <PageHeader title="Tracking" description="Posisi kendaraan dan tahap perjalanan tabung, diperbarui otomatis dari HP supir." />
      {query.isPending ? (
        <TableSkeleton rows={4} columns={3} label="Memuat tracking…" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : (
        <div className="space-y-6">
          <PillTabs<View>
            label="Perjalanan"
            value={view}
            onChange={setView}
            tabs={[
              { value: 'moving', label: `Dalam perjalanan (${moving.length})` },
              { value: 'waiting', label: `Disiapkan & muat (${waiting.length})` },
            ]}
          />
          {view === 'moving' ? (
            moving.length === 0 ? (
              <Card>
                <EmptyState icon={Truck} title="Tidak ada kendaraan di jalan" description="Kendaraan muncul di sini setelah supir menekan Berangkat di aplikasi mobile." />
              </Card>
            ) : (
              <div className="grid gap-6 xl:grid-cols-[2fr_3fr]">
                <ul className="space-y-4">
                  {moving.map((d) => (
                    <li key={d.id}>
                      <TripCard delivery={d} selected={d.id === selected} onSelect={() => setSelected(d.id)} />
                    </li>
                  ))}
                </ul>
                <Card className="order-first p-4 xl:sticky xl:top-20 xl:order-none xl:self-start">
                  {markers.length > 0 ? <DeliveryMapView markers={markers} onSelect={setSelected} className="h-80 xl:h-[36rem]" /> : <EmptyState icon={MapPinOff} title="Belum ada posisi" description="Pastikan izin lokasi di HP supir aktif." />}
                </Card>
              </div>
            )
          ) : waiting.length === 0 ? (
            <Card>
              <EmptyState icon={Truck} title="Tidak ada transaksi yang sedang disiapkan" />
            </Card>
          ) : (
            <ul className="grid gap-4 lg:grid-cols-2">
              {waiting.map((d) => (
                <li key={d.id}>
                  <TripCard delivery={d} />
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </>
  )
}

function TripCard({ delivery: d, selected = false, onSelect }: { delivery: Delivery; selected?: boolean; onSelect?: () => void }) {
  return (
    <Card className={clsx('p-4', selected && 'ring-2 ring-brand-600')}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <Link to={`/transaksi/${d.id}`} className="font-mono text-sm font-semibold text-slate-900 hover:text-brand-700">
            {d.number}
          </Link>
          <p className="font-semibold text-slate-900">{vehicleText(d)}</p>
          <p className="text-sm text-slate-600">{crewText(d)}</p>
        </div>
        <div className="flex flex-wrap gap-1">
          <DirectionBadge delivery={d} />
          <DeliveryStatusBadge delivery={d} />
        </div>
      </div>
      <div className="mt-4">
        <DeliveryProgress delivery={d} />
      </div>
      <ol className="mt-4 space-y-1 text-sm">
        {d.stops.map((s) => (
          <li key={s.id} className="flex justify-between gap-2">
            <span className="min-w-0 truncate text-slate-700">
              {s.sequence}. {s.customer.name}
            </span>
            <span className={clsx('shrink-0', s.status === 'done' ? 'text-green-700' : s.status === 'arrived' ? 'font-semibold text-amber-800' : 'text-slate-500')}>{s.status_label}</span>
          </li>
        ))}
      </ol>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
        <span>
          {d.counts.total} tabung · muat {d.counts.loaded} · bongkar {d.counts.unloaded}
        </span>
        {d.last_event && (
          <span className={d.last_event.type === 'issue' ? 'font-semibold text-red-700' : undefined} title={formatDateTime(d.last_event.recorded_at)}>
            {d.last_event.type_label}
            {d.last_event.note ? `: ${d.last_event.note}` : ''}, {ago(d.last_event.recorded_at)}
          </span>
        )}
        {onSelect && d.last_position && (
          <button type="button" onClick={onSelect} className="font-semibold text-brand-700 hover:underline" title={formatDateTime(d.last_position.recorded_at)}>
            Tunjukkan di peta (posisi {ago(d.last_position.recorded_at)})
          </button>
        )}
      </div>
    </Card>
  )
}
