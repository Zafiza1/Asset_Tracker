import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ExternalLink, MapPin, Pencil, Phone, XCircle } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, ErrorState } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { PillTabs } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDate, formatDateTime } from '../../lib/format'
import { cylinderSummary, deliveriesApi, DELIVERIES_KEY, itemTone, mapLink, REFRESH_MS, stopTone, type DeliveryDetail, type DeliveryEvent } from './api'
import { DeliveryFormModal } from './DeliveryFormModal'
import type { MapMarker } from './DeliveryMap'
import { DeliveryMapView, DeliveryProgress, DeliveryStatusBadge, DirectionBadge } from './DeliveryParts'

type TimelineView = 'trip' | 'all'
/** Scans are many; the trip view leaves them to the cylinder list. */
const SCANS = new Set(['load', 'unload', 'unscan', 'position'])

/** One transaction: how far the trip is, its crew, cylinders per customer, route and timeline. */
export function DeliveryDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const query = useQuery({
    queryKey: [...DELIVERIES_KEY, id],
    queryFn: () => deliveriesApi.get(id),
    refetchInterval: (q) => (['completed', 'cancelled'].includes(q.state.data?.data.status ?? '') ? false : REFRESH_MS),
  })
  if (query.isPending) return <DetailSkeleton label="Memuat transaksi…" />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  const d = query.data.data
  const manage = can('delivery.manage') && can(d.direction === 'outgoing' ? 'asset.outgoing' : 'asset.incoming')
  const cancellable = d.status === 'draft' || d.status === 'loading' || (d.status === 'in_transit' && d.direction === 'incoming' && d.counts.loaded === 0)
  const unclear = d.items.filter((i) => i.state === 'missing' || i.state === 'returned')

  return (
    <>
      <PageHeader
        title={d.number}
        badges={
          <>
            <DirectionBadge delivery={d} />
            <DeliveryStatusBadge delivery={d} />
          </>
        }
        description={d.stops.map((s) => s.customer.name).join(', ')}
        actions={
          manage && (
            <>
              {d.status === 'draft' && (
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  <Pencil className="size-4" aria-hidden /> Ubah
                </Button>
              )}
              {cancellable && (
                <Button variant="secondary" onClick={() => setCancelling(true)}>
                  <XCircle className="size-4" aria-hidden /> Batalkan
                </Button>
              )}
            </>
          )
        }
      />
      <div className="space-y-6">
        {d.status === 'cancelled' && <Alert tone="warning" title="Transaksi dibatalkan">{d.end_note}</Alert>}
        {d.last_event?.type === 'issue' && d.status === 'in_transit' && (
          <Alert tone="warning" title="Kendala di jalan">
            {d.last_event.note} ({formatDateTime(d.last_event.recorded_at)})
          </Alert>
        )}
        {unclear.length > 0 && (
          <Alert tone="warning" title={d.direction === 'incoming' ? `${unclear.length} tabung selisih` : `${unclear.length} tabung dibawa kembali ke gudang`}>
            {unclear.map((i) => i.serial_number ?? i.name).join(', ')}
            {d.end_note && `. Keterangan: ${d.end_note}`}
          </Alert>
        )}
        <Card className="p-5">
          <DeliveryProgress delivery={d} />
          <dl className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Fact label="Kendaraan" value={d.vehicle ? <Link className="hover:text-brand-700" to={`/aset/${d.vehicle.id}`}>{d.vehicle.name}</Link> : null} empty="Belum dipilih" />
            <Fact label="No Polisi" value={d.vehicle?.plate} />
            <Fact label="Supir" value={d.driver && <PersonText name={d.driver.name} phone={d.driver.phone} />} empty="Diisi di mobile" />
            <Fact label="Kernet" value={d.helper && <PersonText name={d.helper.name} phone={d.helper.phone} />} empty={d.driver ? '—' : 'Diisi di mobile'} />
            <Fact label={d.direction === 'outgoing' ? 'Tanggal kirim' : 'Tanggal jemput'} value={formatDate(d.planned_date)} />
            {d.expected_return_date && <Fact label="Perkiraan tabung kembali" value={formatDate(d.expected_return_date)} />}
            <Fact label="Berangkat" value={d.departed_at && formatDateTime(d.departed_at)} />
            {d.returned_at && <Fact label="Tiba di gudang" value={formatDateTime(d.returned_at)} />}
            <Fact label="Selesai" value={d.ended_at && formatDateTime(d.ended_at)} />
            <Fact label="Tabung" value={`${d.counts.total} (muat ${d.counts.loaded}, bongkar ${d.counts.unloaded})`} />
            <Fact label="Dibuat oleh" value={d.created_by} />
            {d.notes && <Fact label="Catatan" value={d.notes} />}
          </dl>
        </Card>
        <div className="grid items-start gap-6 xl:grid-cols-[3fr_2fr]">
          <div className="min-w-0 space-y-6">
            <RouteCard delivery={d} />
            <StopsCard delivery={d} />
          </div>
          <TimelineCard events={d.events} />
        </div>
      </div>
      {editing && <DeliveryFormModal direction={d.direction} delivery={d} onClose={() => setEditing(false)} onSaved={() => setEditing(false)} />}
      {cancelling && <CancelModal delivery={d} onClose={() => setCancelling(false)} />}
    </>
  )
}

function Fact({ label, value, empty = '—' }: { label: string; value: ReactNode; empty?: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm font-medium text-slate-900">{value || <span className="font-normal text-slate-400">{empty}</span>}</dd>
    </div>
  )
}

function PersonText({ name, phone }: { name: string; phone: string | null }) {
  return (
    <>
      {name}
      {phone && (
        <a href={`tel:${phone}`} className="mt-0.5 flex items-center gap-1 text-xs font-normal text-slate-500 hover:text-brand-700">
          <Phone className="size-3" aria-hidden /> {phone}
        </a>
      )}
    </>
  )
}

function RouteCard({ delivery }: { delivery: DeliveryDetail }) {
  const { markers, route } = useMemo(() => {
    const points = delivery.route
    const named = delivery.events.filter((e) => e.position && ['depart', 'arrive', 'issue', 'return', 'completed'].includes(e.type))
    const list: MapMarker[] = named.map((e) => ({
      id: e.id,
      latitude: e.position!.latitude,
      longitude: e.position!.longitude,
      label: [e.type_label, e.customer, e.note, formatDateTime(e.recorded_at)].filter(Boolean).join(' · '),
      kind: e.type === 'issue' ? 'issue' : e.type === 'depart' ? 'start' : 'event',
    }))
    const last = delivery.last_position
    if (last && delivery.status === 'in_transit') list.push({ id: 'vehicle', latitude: last.latitude, longitude: last.longitude, label: `Posisi terakhir · ${formatDateTime(last.recorded_at)}`, kind: 'vehicle' })
    return { markers: list, route: points.map((p): [number, number] => [p.latitude, p.longitude]) }
  }, [delivery])
  const last = delivery.last_position
  return (
    <Card
      title="Rute perjalanan"
      actions={
        last && (
          <a href={mapLink(last)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">
            Buka posisi terakhir <ExternalLink className="size-3.5" aria-hidden />
          </a>
        )
      }
    >
      <div className="p-4">
        {delivery.route.length === 0 ? <p className="py-6 text-center text-sm text-slate-500">Belum ada posisi. Posisi terkirim dari HP supir saat scan, berangkat, dan selama aplikasi terbuka.</p> : <DeliveryMapView markers={markers} route={route} />}
      </div>
    </Card>
  )
}

function StopsCard({ delivery }: { delivery: DeliveryDetail }) {
  return (
    <Card title="Customer & tabung">
      <ol className="divide-y divide-slate-100">
        {delivery.stops.map((s) => {
          const items = delivery.items.filter((i) => i.stop_id === s.id)
          return (
            <li key={s.id} className="px-5 py-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-semibold text-slate-900">
                    {s.sequence}. {s.customer.name}
                  </p>
                  {s.customer.address && <p className="text-sm text-slate-500">{s.customer.address}</p>}
                  <p className="mt-0.5 text-xs text-slate-500">
                    {[s.arrived_at && `Tiba ${formatDateTime(s.arrived_at)}`, s.done_at && `Selesai ${formatDateTime(s.done_at)}`, s.receiver_name && `${delivery.direction === 'outgoing' ? 'Penerima' : 'Diserahkan oleh'} ${s.receiver_name}`, s.note].filter(Boolean).join(' · ')}
                  </p>
                </div>
                <Badge tone={stopTone[s.status]}>{s.status_label}</Badge>
              </div>
              {items.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">Belum ada tabung.</p>
              ) : (
                <ul className="mt-3 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                  {items.map((i) => (
                    <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                      <div className="min-w-0">
                        <Link to={`/aset/${i.asset_id}`} className="text-sm font-medium text-slate-900 hover:text-brand-700">
                          {i.name} <span className="font-mono text-xs font-normal text-slate-600">{i.serial_number}</span>
                        </Link>
                        <p className="text-xs text-slate-500">{cylinderSummary(i.details)}</p>
                        {(i.loaded_at || i.unloaded_at) && (
                          <p className="text-xs text-slate-500">
                            {[i.loaded_at && `Muat ${formatDateTime(i.loaded_at)} (${i.loaded_by})`, i.unloaded_at && `Bongkar ${formatDateTime(i.unloaded_at)} (${i.unloaded_by})`].filter(Boolean).join(' · ')}
                          </p>
                        )}
                      </div>
                      <div className="flex gap-1">
                        {!i.planned && <Badge tone="amber">Di luar rencana</Badge>}
                        <Badge tone={itemTone[i.state]}>{i.state_label}</Badge>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )
        })}
      </ol>
    </Card>
  )
}

function TimelineCard({ events }: { events: DeliveryEvent[] }) {
  const [view, setView] = useState<TimelineView>('trip')
  const shown = [...events].reverse().filter((e) => view === 'all' || !SCANS.has(e.type))
  return (
    // Grows with its events (no inner scroll that cut the oldest ones off) and only as tall as they are.
    <Card title="Garis waktu" className="min-w-0">
      <div className="px-5 pt-3">
        <PillTabs<TimelineView>
          label="Isi garis waktu"
          value={view}
          onChange={setView}
          tabs={[
            { value: 'trip', label: 'Perjalanan' },
            { value: 'all', label: 'Semua, dengan scan' },
          ]}
        />
      </div>
      <ol className="space-y-4 px-5 py-4">
        {shown.map((e) => (
          <li key={e.id} className="flex gap-3">
            <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${e.type === 'issue' ? 'bg-red-600' : e.type === 'completed' ? 'bg-green-600' : 'bg-brand-500'}`} aria-hidden />
            <div className="min-w-0 text-sm">
              <p className="font-semibold text-slate-900">
                {e.type === 'issue' && <AlertTriangle className="mr-1 inline size-4 text-red-600" aria-hidden />}
                {e.type_label}
                {e.customer && <span className="font-normal text-slate-600"> · {e.customer}</span>}
              </p>
              {e.asset && <p className="text-slate-600">{e.asset}</p>}
              {e.note && <p className="text-slate-600">{e.note}</p>}
              <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                {formatDateTime(e.recorded_at)} · {e.actor}
                {e.position && (
                  <a href={mapLink(e.position)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 text-brand-700 hover:underline">
                    <MapPin className="size-3" aria-hidden /> Lokasi
                  </a>
                )}
              </p>
            </div>
          </li>
        ))}
      </ol>
    </Card>
  )
}

function CancelModal({ delivery, onClose }: { delivery: DeliveryDetail; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => deliveriesApi.cancel(delivery.id, reason.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: DELIVERIES_KEY })
      toast.success('Transaksi dibatalkan', delivery.number)
      onClose()
    },
  })
  const tooShort = reason.trim().length < 5
  return (
    <Modal
      open
      title={`Batalkan ${delivery.number}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Kembali
          </Button>
          <Button variant="danger" onClick={() => cancel.mutate()} loading={cancel.isPending} disabled={tooShort}>
            Batalkan transaksi
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {cancel.isError && <Alert tone="error">{errorMessage(cancel.error)}</Alert>}
        <p className="text-sm text-slate-600">Semua tabung di transaksi ini dilepas dan bisa dipakai transaksi lain.</p>
        <Field label="Alasan" required hint="Minimal 5 karakter.">
          {(p) => <Textarea {...p} rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </div>
    </Modal>
  )
}
