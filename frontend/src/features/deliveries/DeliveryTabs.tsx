import { AlertTriangle, ExternalLink, MapPin } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Card } from '../../components/ui/Card'
import { Input, Select } from '../../components/ui/Field'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { PillTabs, SegmentedControl } from '../../components/ui/Tabs'
import { formatDateTime } from '../../lib/format'
import { cylinderSummary, itemTone, mapLink, stopTone, type DeliveryDetail, type DeliveryEvent } from './api'
import type { MapMarker } from './DeliveryMap'
import { DeliveryMapView } from './DeliveryParts'

type Tab = 'items' | 'stops' | 'events' | 'route'
type Stop = DeliveryDetail['stops'][number]
type Item = DeliveryDetail['items'][number]
type TimelineView = 'trip' | 'all'

/** Scans are many (one per cylinder); the trip view of the timeline leaves them to the Tabung tab. */
const SCANS = new Set(['load', 'unload', 'unscan', 'position'])

const none = <span className="text-slate-400">—</span>

/**
 * Cylinders, customers, timeline and route of one transaction as tabs of one card, each list a
 * table of pages: however many cylinders and events a trip collects, the page keeps its height.
 */
export function DeliveryTabs({ delivery }: { delivery: DeliveryDetail }) {
  const [tab, setTab] = useState<Tab>('items')
  return (
    <Card>
      <div className="border-b border-slate-100 px-5 py-3">
        <PillTabs<Tab>
          label="Isi transaksi"
          value={tab}
          onChange={setTab}
          tabs={[
            { value: 'items', label: `Tabung (${delivery.items.length})` },
            { value: 'stops', label: `Customer (${delivery.stops.length})` },
            { value: 'events', label: `Garis waktu (${delivery.events.length})` },
            { value: 'route', label: 'Rute perjalanan' },
          ]}
        />
      </div>
      {tab === 'items' && <ItemsTable delivery={delivery} />}
      {tab === 'stops' && <StopsTable delivery={delivery} />}
      {tab === 'events' && <EventsTable events={delivery.events} />}
      {tab === 'route' && <RouteMap delivery={delivery} />}
    </Card>
  )
}

/**
 * One page of rows kept in the browser (the transaction is loaded whole), with the Pagination bar.
 * Back to page 1 whenever `resetKey` (the filters) changes.
 */
function usePage<T>(rows: T[], resetKey: string) {
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [key, setKey] = useState(resetKey)
  if (key !== resetKey) {
    setKey(resetKey)
    setPage(1)
  }
  const last = Math.max(1, Math.ceil(rows.length / perPage))
  const current = Math.min(page, last)
  const start = (current - 1) * perPage
  const shown = rows.slice(start, start + perPage)
  const meta = { current_page: current, last_page: last, per_page: perPage, total: rows.length, from: rows.length ? start + 1 : null, to: rows.length ? start + shown.length : null }
  return { shown, pagination: <Pagination meta={meta} onPage={setPage} onPerPage={(n) => (setPerPage(n), setPage(1))} /> }
}

function ItemsTable({ delivery }: { delivery: DeliveryDetail }) {
  const [stopId, setStopId] = useState('')
  const [search, setSearch] = useState('')
  const stops = new Map(delivery.stops.map((s) => [s.id, s]))
  const stopName = (i: Item) => {
    const s = i.stop_id ? stops.get(i.stop_id) : undefined
    return s ? `${s.sequence}. ${s.customer.name}` : null
  }
  const term = search.trim().toLowerCase()
  const rows = delivery.items.filter((i) => (!stopId || i.stop_id === stopId) && (!term || `${i.name} ${i.serial_number ?? ''}`.toLowerCase().includes(term)))
  const { shown, pagination } = usePage(rows, `${stopId}|${term}`)

  const columns: Column<Item>[] = [
    {
      key: 'cylinder',
      header: 'Tabung',
      title: (i) => cylinderSummary(i.details),
      cell: (i) => (
        <div className="min-w-0">
          <Link to={`/aset/${i.asset_id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">
            {i.serial_number ?? '—'}
          </Link>
          <p className="truncate text-xs text-slate-500">{i.name}</p>
        </div>
      ),
    },
    { key: 'customer', header: 'Customer', className: 'w-56', title: (i) => stopName(i), cell: (i) => stopName(i) ?? none },
    { key: 'loaded', header: 'Muat', className: 'w-44', hideOnMobile: true, cell: (i) => <When at={i.loaded_at} by={i.loaded_by} /> },
    { key: 'unloaded', header: 'Bongkar', className: 'w-44', hideOnMobile: true, cell: (i) => <When at={i.unloaded_at} by={i.unloaded_by} /> },
    {
      key: 'state',
      header: 'Status',
      className: 'w-48',
      cell: (i) => (
        <span className="flex flex-wrap gap-1">
          <Badge tone={itemTone[i.state]}>{i.state_label}</Badge>
          {!i.planned && <Badge tone="amber">Di luar rencana</Badge>}
        </span>
      ),
    },
  ]

  return (
    <>
      <div className="flex flex-wrap gap-2 px-5 py-3">
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari No Manufaktur atau nama tabung" aria-label="Cari tabung" className="min-w-0 flex-1 sm:max-w-sm" />
        {delivery.stops.length > 1 && (
          <Select value={stopId} onChange={(e) => setStopId(e.target.value)} aria-label="Filter customer" className="sm:w-64">
            <option value="">Customer: semua</option>
            {delivery.stops.map((s) => (
              <option key={s.id} value={s.id}>
                {s.sequence}. {s.customer.name}
              </option>
            ))}
          </Select>
        )}
      </div>
      {rows.length === 0 ? (
        <p className="px-5 pb-6 pt-2 text-center text-sm text-slate-500">{delivery.items.length === 0 ? 'Belum ada tabung.' : 'Tidak ada tabung yang cocok.'}</p>
      ) : (
        <>
          <DataTable columns={columns} rows={shown} className="md:min-w-[52rem]" />
          {pagination}
        </>
      )}
    </>
  )
}

function When({ at, by }: { at: string | null; by: string | null }) {
  if (!at) return none
  return (
    <div className="min-w-0">
      <div>{formatDateTime(at)}</div>
      {by && <div className="truncate text-xs text-slate-500">{by}</div>}
    </div>
  )
}

function StopsTable({ delivery }: { delivery: DeliveryDetail }) {
  const { shown, pagination } = usePage(delivery.stops, '')
  const columns: Column<Stop>[] = [
    {
      key: 'customer',
      header: 'Customer',
      title: (s) => s.customer.address,
      cell: (s) => (
        <div className="min-w-0">
          <p className="font-medium text-slate-900">
            {s.sequence}. {s.customer.name}
          </p>
          {s.customer.address && <p className="truncate text-xs text-slate-500">{s.customer.address}</p>}
        </div>
      ),
    },
    {
      key: 'cylinders',
      header: 'Tabung',
      className: 'w-40',
      cell: (s) => {
        const items = delivery.items.filter((i) => i.stop_id === s.id)
        return (
          <div>
            <div>{items.length} tabung</div>
            <div className="text-xs text-slate-500">{items.filter((i) => i.state === 'unloaded').length} sudah dibongkar</div>
          </div>
        )
      },
    },
    { key: 'arrived', header: 'Tiba', className: 'w-40', hideOnMobile: true, cell: (s) => (s.arrived_at ? formatDateTime(s.arrived_at) : none) },
    { key: 'done', header: 'Selesai', className: 'w-40', hideOnMobile: true, cell: (s) => (s.done_at ? formatDateTime(s.done_at) : none) },
    {
      key: 'receiver',
      header: delivery.direction === 'outgoing' ? 'Penerima' : 'Diserahkan oleh',
      className: 'w-48',
      hideOnMobile: true,
      title: (s) => s.note,
      cell: (s) => (
        <div className="min-w-0">
          <div className="truncate">{s.receiver_name ?? none}</div>
          {s.note && <div className="truncate text-xs text-slate-500">{s.note}</div>}
        </div>
      ),
    },
    { key: 'status', header: 'Status', className: 'w-32', cell: (s) => <Badge tone={stopTone[s.status]}>{s.status_label}</Badge> },
  ]
  return (
    <>
      <DataTable columns={columns} rows={shown} className="md:min-w-[52rem]" />
      {pagination}
    </>
  )
}

/** Events newest first; the scans only on request. */
function EventsTable({ events }: { events: DeliveryEvent[] }) {
  const [view, setView] = useState<TimelineView>('trip')
  const rows = [...events].reverse().filter((e) => view === 'all' || !SCANS.has(e.type))
  const { shown, pagination } = usePage(rows, view)
  const detail = (e: DeliveryEvent) => [e.customer, e.asset, e.note].filter(Boolean).join(' · ')
  const columns: Column<DeliveryEvent>[] = [
    { key: 'at', header: 'Waktu', className: 'w-40', cell: (e) => formatDateTime(e.recorded_at) },
    {
      key: 'type',
      header: 'Kejadian',
      className: 'w-52',
      cell: (e) => (
        <span className="flex items-center gap-2 font-medium text-slate-900">
          <span className={`size-2.5 shrink-0 rounded-full ${e.type === 'issue' ? 'bg-red-600' : e.type === 'completed' ? 'bg-green-600' : 'bg-brand-500'}`} aria-hidden />
          {e.type === 'issue' && <AlertTriangle className="size-4 text-red-600" aria-hidden />}
          {e.type_label}
        </span>
      ),
    },
    { key: 'detail', header: 'Keterangan', title: detail, cell: (e) => detail(e) || none },
    { key: 'actor', header: 'Oleh', className: 'w-36', hideOnMobile: true, cell: (e) => e.actor },
    {
      key: 'position',
      header: 'Lokasi',
      className: 'w-24',
      hideOnMobile: true,
      cell: (e) =>
        e.position ? (
          <a href={mapLink(e.position)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 text-brand-700 hover:underline">
            <MapPin className="size-3.5" aria-hidden /> Peta
          </a>
        ) : (
          none
        ),
    },
  ]
  return (
    <>
      <div className="px-5 py-3">
        <SegmentedControl<TimelineView>
          label="Isi garis waktu"
          value={view}
          onChange={setView}
          options={[
            { value: 'trip', label: 'Perjalanan' },
            { value: 'all', label: 'Semua, dengan scan' },
          ]}
        />
      </div>
      <DataTable columns={columns} rows={shown} className="md:min-w-[52rem]" />
      {pagination}
    </>
  )
}

function RouteMap({ delivery }: { delivery: DeliveryDetail }) {
  const { markers, route } = useMemo(() => {
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
    return { markers: list, route: delivery.route.map((p): [number, number] => [p.latitude, p.longitude]) }
  }, [delivery])
  const last = delivery.last_position
  return (
    <div className="space-y-2 p-4">
      {last && (
        <a href={mapLink(last)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">
          Buka posisi terakhir <ExternalLink className="size-3.5" aria-hidden />
        </a>
      )}
      {delivery.route.length === 0 ? <p className="py-6 text-center text-sm text-slate-500">Belum ada posisi. Posisi terkirim dari HP supir saat scan, berangkat, dan selama aplikasi terbuka.</p> : <DeliveryMapView markers={markers} route={route} />}
    </div>
  )
}
