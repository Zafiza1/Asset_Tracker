import { AlertTriangle, ExternalLink, MapPin } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Input, Select } from '../../components/ui/Field'
import { DateRangeFilter } from '../../components/ui/Filters'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { PillTabs, SegmentedControl } from '../../components/ui/Tabs'
import { formatDateTime } from '../../lib/format'
import { usePersistedFilters, usePersistedSearchParams } from '../../lib/usePersistedFilters'
import { cylinderSummary, itemTone, mapLink, stopTone, type DeliveryDetail, type DeliveryEvent } from './api'
import type { MapMarker } from './DeliveryMap'
import { DeliveryMapView } from './DeliveryParts'

type Tab = 'items' | 'stops' | 'events' | 'route'
type Stop = DeliveryDetail['stops'][number]
type Item = DeliveryDetail['items'][number]
type TimelineView = 'trip' | 'all'

const TABS: Tab[] = ['items', 'stops', 'events', 'route']

/** Scans are many (one per cylinder); the trip view of the timeline leaves them to the Tabung tab. */
const SCANS = new Set(['load', 'unload', 'unscan', 'position'])

const none = <span className="text-slate-400">—</span>

/** The day (YYYY-MM-DD, browser time zone) of a timestamp, to compare with the date filters. */
const dayOf = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('sv-SE') : null)

/** Whether one of the timestamps falls within the from–to days (an empty bound is open). */
const withinDays = (from: string, to: string, ...times: (string | null)[]) =>
  (!from && !to) || times.some((t) => {
    const day = dayOf(t)
    return day !== null && (!from || day >= from) && (!to || day <= to)
  })

/**
 * The tab and the filters of each tab live in the URL and are saved per transaction
 * (usePersistedFilters), so they are still there after another tab, another menu, or a reload.
 * Keys: tab, i_* (Tabung), s_* (Customer), e_* (Garis waktu).
 */
function useDetailFilters(deliveryId: string) {
  const endpoint = `/transaksi/${deliveryId}`
  const [params, setParams] = usePersistedSearchParams(endpoint)
  const { updateFilter, removeFilter } = usePersistedFilters(endpoint)
  const get = (key: string) => params.get(key) ?? ''
  const set = (next: Record<string, string>) => {
    // From the address bar, not this render's params: two quick changes in a row both stay.
    const merged = new URLSearchParams(window.location.search)
    for (const [k, v] of Object.entries(next)) {
      if (v) {
        merged.set(k, v)
        updateFilter(k, v)
      } else {
        merged.delete(k)
        removeFilter(k)
      }
    }
    setParams(merged, { replace: true })
  }
  /** Clears the filters of one tab (keys with its prefix), keeping the others and the tab. */
  const clear = (prefix: string) => set(Object.fromEntries([...params.keys()].filter((k) => k.startsWith(prefix)).map((k) => [k, ''])))
  return { get, set, clear }
}
type Filters = ReturnType<typeof useDetailFilters>

/**
 * Cylinders, customers, timeline and route of one transaction as tabs of one card, each list a
 * table of pages: however many cylinders and events a trip collects, the page keeps its height.
 */
export function DeliveryTabs({ delivery }: { delivery: DeliveryDetail }) {
  const filters = useDetailFilters(delivery.id)
  const tab = (TABS as string[]).includes(filters.get('tab')) ? (filters.get('tab') as Tab) : 'items'
  return (
    <Card>
      <div className="border-b border-slate-100 px-5 py-3">
        <PillTabs<Tab>
          label="Isi transaksi"
          value={tab}
          onChange={(t) => filters.set({ tab: t === 'items' ? '' : t })}
          tabs={[
            { value: 'items', label: `Tabung (${delivery.items.length})` },
            { value: 'stops', label: `Customer (${delivery.stops.length})` },
            { value: 'events', label: `Garis waktu (${delivery.events.length})` },
            { value: 'route', label: 'Rute perjalanan' },
          ]}
        />
      </div>
      {tab === 'items' && <ItemsTable delivery={delivery} filters={filters} />}
      {tab === 'stops' && <StopsTable delivery={delivery} filters={filters} />}
      {tab === 'events' && <EventsTable events={delivery.events} filters={filters} />}
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

/** The filter row of a tab: its controls, then "Hapus filter" while any of them is set. */
function FilterBar({ active, onClear, children }: { active: boolean; onClear: () => void; children: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center gap-2 px-5 py-3">
      {children}
      {active && (
        <Button size="sm" variant="ghost" onClick={onClear}>
          Hapus filter
        </Button>
      )}
    </div>
  )
}

function CustomerSelect({ stops, value, onChange }: { stops: Stop[]; value: string; onChange: (id: string) => void }) {
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)} aria-label="Filter customer" className="sm:w-60">
      <option value="">Customer: semua</option>
      {stops.map((s) => (
        <option key={s.id} value={s.id}>
          {s.sequence}. {s.customer.name}
        </option>
      ))}
    </Select>
  )
}

function Dates({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      <DateRangeFilter from={from} to={to} onChange={onChange} />
    </div>
  )
}

function Empty({ filtered, nothing }: { filtered: boolean; nothing: string }) {
  return <p className="px-5 pb-6 pt-2 text-center text-sm text-slate-500">{filtered ? 'Tidak ada yang cocok dengan filter.' : nothing}</p>
}

/** Cylinders; the dates are those of the load or unload scan. */
function ItemsTable({ delivery, filters: f }: { delivery: DeliveryDetail; filters: Filters }) {
  const [search, stopId, from, to] = [f.get('i_search'), f.get('i_stop'), f.get('i_from'), f.get('i_to')]
  const stops = new Map(delivery.stops.map((s) => [s.id, s]))
  const stopName = (i: Item) => {
    const s = i.stop_id ? stops.get(i.stop_id) : undefined
    return s ? `${s.sequence}. ${s.customer.name}` : null
  }
  const term = search.trim().toLowerCase()
  const rows = delivery.items.filter(
    (i) => (!stopId || i.stop_id === stopId) && (!term || `${i.name} ${i.serial_number ?? ''}`.toLowerCase().includes(term)) && withinDays(from, to, i.loaded_at, i.unloaded_at),
  )
  const active = !!(search || stopId || from || to)
  const { shown, pagination } = usePage(rows, `${stopId}|${term}|${from}|${to}`)

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
      <FilterBar active={active} onClear={() => f.clear('i_')}>
        <Input value={search} onChange={(e) => f.set({ i_search: e.target.value })} placeholder="Cari No Manufaktur atau nama tabung" aria-label="Cari tabung" className="min-w-0 flex-1 sm:max-w-xs" />
        <CustomerSelect stops={delivery.stops} value={stopId} onChange={(id) => f.set({ i_stop: id })} />
        <Dates from={from} to={to} onChange={(a, b) => f.set({ i_from: a, i_to: b })} />
      </FilterBar>
      {rows.length === 0 ? (
        <Empty filtered={active} nothing="Belum ada tabung." />
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

/** Customers; the dates are those of arriving at or finishing with the customer. */
function StopsTable({ delivery, filters: f }: { delivery: DeliveryDetail; filters: Filters }) {
  const [stopId, from, to] = [f.get('s_stop'), f.get('s_from'), f.get('s_to')]
  const rows = delivery.stops.filter((s) => (!stopId || s.id === stopId) && withinDays(from, to, s.arrived_at, s.done_at))
  const active = !!(stopId || from || to)
  const { shown, pagination } = usePage(rows, `${stopId}|${from}|${to}`)
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
      <FilterBar active={active} onClear={() => f.clear('s_')}>
        <CustomerSelect stops={delivery.stops} value={stopId} onChange={(id) => f.set({ s_stop: id })} />
        <Dates from={from} to={to} onChange={(a, b) => f.set({ s_from: a, s_to: b })} />
      </FilterBar>
      {rows.length === 0 ? (
        <Empty filtered={active} nothing="Belum ada customer." />
      ) : (
        <>
          <DataTable columns={columns} rows={shown} className="md:min-w-[52rem]" />
          {pagination}
        </>
      )}
    </>
  )
}

/** Events newest first; the scans only on request. */
function EventsTable({ events, filters: f }: { events: DeliveryEvent[]; filters: Filters }) {
  const view: TimelineView = f.get('e_view') === 'all' ? 'all' : 'trip'
  const [from, to] = [f.get('e_from'), f.get('e_to')]
  const rows = [...events].reverse().filter((e) => (view === 'all' || !SCANS.has(e.type)) && withinDays(from, to, e.recorded_at))
  const active = !!(from || to)
  const { shown, pagination } = usePage(rows, `${view}|${from}|${to}`)
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
      <FilterBar active={active} onClear={() => f.set({ e_from: '', e_to: '' })}>
        <SegmentedControl<TimelineView>
          label="Isi garis waktu"
          value={view}
          onChange={(v) => f.set({ e_view: v === 'all' ? 'all' : '' })}
          options={[
            { value: 'trip', label: 'Perjalanan' },
            { value: 'all', label: 'Semua, dengan scan' },
          ]}
        />
        <Dates from={from} to={to} onChange={(a, b) => f.set({ e_from: a, e_to: b })} />
      </FilterBar>
      {rows.length === 0 ? (
        <Empty filtered={active} nothing="Belum ada kejadian." />
      ) : (
        <>
          <DataTable columns={columns} rows={shown} className="md:min-w-[52rem]" />
          {pagination}
        </>
      )}
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
