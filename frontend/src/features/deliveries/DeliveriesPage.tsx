import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowDownToLine, ArrowUpFromLine, ChevronDown, Plus, SearchX, Truck } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { MenuItem } from '../../components/ui/AddMenu'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, DateRangeFilter, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { PillTabs } from '../../components/ui/Tabs'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { ago, crewText, deliveriesApi, DELIVERIES_KEY, deliveryStatusLabel, REFRESH_MS, vehicleText, type Delivery, type Direction } from './api'
import { DeliveryFormModal } from './DeliveryFormModal'
import { DeliveryStatusBadge, DirectionBadge } from './DeliveryParts'

const FILTERS = ['page', 'per_page', 'search', 'direction', 'status', 'date_from', 'date_to', 'vehicle_id', 'sort'] as const
/** Behind "Filter lanjutan": applied together with "Terapkan filter". */
const ADVANCED = ['date_from', 'date_to', 'vehicle_id'] as const

/** Menu Transaksi: outgoing and incoming transactions of cylinders. */
export function DeliveriesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState<Direction | null>(null)
  const filters = Object.fromEntries(FILTERS.map((k) => [k, params.get(k)]))
  const query = useQuery({ queryKey: [...DELIVERIES_KEY, 'list', filters], queryFn: () => deliveriesApi.list(filters), placeholderData: keepPreviousData, refetchInterval: REFRESH_MS })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged)
  }
  const hasFilters = ['search', 'status', 'direction', ...ADVANCED].some((k) => params.get(k))
  const advanced = useFilterDraft(params, ADVANCED)
  const draft = advanced.draft
  const options = useQuery({ queryKey: [...DELIVERIES_KEY, 'options'], queryFn: () => deliveriesApi.options() })
  const vehicles = options.data?.data.vehicles ?? []
  const vehicleName = (id: string) => {
    const v = vehicles.find((x) => x.id === id)
    return v ? [v.plate, v.name].filter(Boolean).join(' · ') : '…'
  }
  const resetAll = () => (setSearch(''), setParams(new URLSearchParams()))
  const from = params.get('date_from')
  const to = params.get('date_to')
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '' }))),
    ...(from || to ? [{ key: 'date', label: `Tanggal: ${from ? formatDate(from) : '…'} – ${to ? formatDate(to) : '…'}`, clear: () => update({ date_from: '', date_to: '' }) }] : []),
    ...(params.get('vehicle_id') ? [{ key: 'vehicle', label: `Kendaraan: ${vehicleName(params.get('vehicle_id')!)}`, clear: () => update({ vehicle_id: '' }) }] : []),
  ]
  const mayCreate = (direction: Direction) => can('delivery.manage') && can(direction === 'outgoing' ? 'asset.outgoing' : 'asset.incoming')

  const columns: Column<Delivery>[] = [
    {
      key: 'number',
      header: 'No Transaksi',
      sortKey: 'number',
      cell: (d) => (
        <div className="min-w-0">
          <Link to={`/transaksi/${d.id}`} className="font-mono text-sm font-semibold text-slate-900 hover:text-brand-700">
            {d.number}
          </Link>
          <div className="mt-1 flex flex-wrap gap-1">
            <DirectionBadge delivery={d} />
            <span className="md:hidden">
              <DeliveryStatusBadge delivery={d} />
            </span>
          </div>
        </div>
      ),
    },
    { key: 'planned_date', header: 'Tanggal', sortKey: 'planned_date', className: 'w-32', hideOnMobile: true, cell: (d) => formatDate(d.planned_date) },
    { key: 'customers', header: 'Customer', title: (d) => d.stops.map((s) => s.customer.name).join(', '), cell: (d) => <span className="line-clamp-2 whitespace-normal">{d.stops.map((s) => s.customer.name).join(', ')}</span> },
    {
      key: 'crew',
      header: 'Kendaraan & kru',
      hideOnMobile: true,
      title: (d) => `${vehicleText(d)} · ${crewText(d)}`,
      cell: (d) => (
        <div className="min-w-0">
          <div className="truncate">{vehicleText(d)}</div>
          <div className="truncate text-xs text-slate-500">{crewText(d)}</div>
        </div>
      ),
    },
    {
      key: 'counts',
      header: 'Tabung',
      className: 'w-40',
      hideOnMobile: true,
      cell: (d) => (
        <div>
          <div>{d.counts.total} tabung</div>
          <div className="text-xs text-slate-500">
            Muat {d.counts.loaded} · Bongkar {d.counts.unloaded}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortKey: 'status',
      className: 'w-44',
      hideOnMobile: true,
      cell: (d) => (
        <div>
          <DeliveryStatusBadge delivery={d} />
          {d.last_event && d.status === 'in_transit' && (
            <div className="mt-1 truncate text-xs text-slate-500" title={d.last_event.note ?? undefined}>
              {d.last_event.type_label}, {ago(d.last_event.recorded_at)}
            </div>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Transaksi"
        description="Tabung keluar ke customer dan masuk kembali ke gudang. Scan muat, perjalanan, dan scan bongkar dicatat dari aplikasi mobile."
        actions={
          <>
            {(mayCreate('incoming') || mayCreate('outgoing')) && <NewTransactionMenu incoming={mayCreate('incoming')} outgoing={mayCreate('outgoing')} onPick={setCreating} />}
          </>
        }
      />
      <Card>
        <FilterPanel>
          <PillTabs
            label="Arah transaksi"
            value={params.get('direction') ?? ''}
            onChange={(v) => update({ direction: v })}
            tabs={[
              { value: '', label: 'Semua' },
              { value: 'outgoing', label: 'Keluar' },
              { value: 'incoming', label: 'Masuk' },
            ]}
          />
          <SearchForm
            value={search}
            onChange={setSearch}
            onSubmit={() => update({ search })}
            placeholder="Cari no transaksi, customer, supir, atau No Manufaktur"
            label="Cari transaksi"
            aside={
              <Select value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value })} aria-label="Filter status" className="sm:w-52">
                <option value="">Status: semua</option>
                <option value="open">Belum selesai</option>
                {Object.entries(deliveryStatusLabel).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </Select>
            }
          />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update(draft)} onClear={() => advanced.set(advanced.empty)} error={draft.date_from && draft.date_to && draft.date_from > draft.date_to ? 'Tanggal "Dari" tidak boleh setelah "Sampai".' : null}>
            <DateRangeFilter from={draft.date_from} to={draft.date_to} onChange={(f, t) => advanced.set({ date_from: f, date_to: t })} />
            <Select value={draft.vehicle_id} onChange={(e) => advanced.set({ vehicle_id: e.target.value })} aria-label="Filter kendaraan">
              <option value="">Kendaraan: semua</option>
              {vehicles.map((v) => (
                <option key={v.id} value={v.id}>
                  {[v.plate, v.name].filter(Boolean).join(' · ')}
                </option>
              ))}
            </Select>
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
        </FilterPanel>
        {query.isPending ? (
          <TableSkeleton columns={5} label="Memuat transaksi…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data.data.length === 0 ? (
          <EmptyState
            icon={hasFilters ? SearchX : Truck}
            title={hasFilters ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi'}
            description={hasFilters ? 'Ubah kata kunci atau filter.' : 'Buat transaksi keluar untuk mengirim tabung ke customer, atau transaksi masuk untuk menjemputnya.'}
            action={
              hasFilters ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data.data} sort={params.get('sort') ?? ''} onSort={(sort) => update({ sort })} onRowClick={(d) => navigate(`/transaksi/${d.id}`)} rowHref={(d) => `/transaksi/${d.id}`} />
            <Pagination meta={query.data.meta} onPage={(p) => update({ page: String(p) })} onPerPage={(n) => update({ per_page: n === 10 ? '' : String(n) })} />
          </>
        )}
      </Card>
      {creating && <DeliveryFormModal direction={creating} onClose={() => setCreating(null)} onSaved={(d) => navigate(`/transaksi/${d.id}`)} />}
    </>
  )
}

/** "Transaksi" with its two kinds in a dropdown: cylinders out to customers, or back to the warehouse. */
function NewTransactionMenu({ incoming, outgoing, onPick }: { incoming: boolean; outgoing: boolean; onPick: (direction: Direction) => void }) {
  const [open, setOpen] = useState(false)
  const choose = (direction: Direction) => () => (setOpen(false), onPick(direction))
  return (
    <div className="relative" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <Button onClick={() => setOpen(!open)} aria-expanded={open} aria-haspopup="menu">
        <Plus className="size-4" aria-hidden /> Transaksi
        <ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </Button>
      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div role="menu" aria-label="Jenis transaksi" className="absolute right-0 z-20 mt-2 w-72 animate-fade-in rounded-lg bg-white p-1.5 shadow-overlay ring-1 ring-slate-900/10">
            {outgoing && <MenuItem icon={<ArrowUpFromLine className="size-4" aria-hidden />} title="Transaksi keluar" hint="Kirim tabung dari gudang ke customer" onClick={choose('outgoing')} />}
            {incoming && <MenuItem icon={<ArrowDownToLine className="size-4" aria-hidden />} title="Transaksi masuk" hint="Jemput tabung dari customer ke gudang" onClick={choose('incoming')} />}
          </div>
        </>
      )}
    </div>
  )
}
