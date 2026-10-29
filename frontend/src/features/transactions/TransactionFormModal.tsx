import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { Badge } from '../../components/ui/Badge'
import { DataTable, Pagination } from '../../components/ui/Table'
import { AdvancedFilters, FilterPanel, SearchForm } from '../../components/ui/Filters'
import { ColumnToggle } from '../../components/ui/ColumnToggle'
import { ApiError, api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, assetStatusTone, type Asset, type AssetStatus } from '../assets/api'
import { CategoryFilter } from '../assets/CategoryFilter'
import { allowedTypes, transactionTypeLabel, typePermission, type ManualTransactionType } from './api'

const selectableStatuses: AssetStatus[] = ['available', 'in_use', 'under_maintenance', 'disposed']
const DEFAULT_COLUMNS = ['code', 'name', 'serial_number', 'category', 'location', 'status']

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

interface Props {
  /** Pre-selected asset (from the asset detail page); otherwise the user searches for one. */
  asset?: Asset
  initialType?: ManualTransactionType
  onClose: () => void
}

/** Record one manual transaction. Each type shows only the fields it needs. */
export function TransactionFormModal({ asset: fixedAsset, initialType, onClose }: Props) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [picked, setPicked] = useState<Asset | null>(fixedAsset ?? null)
  const asset = fixedAsset ?? picked
  const types = allowedTypes(asset?.status).filter((t) => !typePermission[t] || can(typePermission[t]!))
  const [type, setType] = useState<ManualTransactionType | ''>(initialType && types.includes(initialType) ? initialType : '')
  const [values, setValues] = useState<Record<string, string>>({ transaction_date: today() })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [filters, setFilters] = useState({ location_id: '', status: '', category_ids: '' })
  const [visibleColumns, setVisibleColumns] = useState(DEFAULT_COLUMNS)

  const locations = toOptions(useOptions('/locations').data)
  const categoryRows = useOptions('/tenant-masters/categories').data
  const categories = toOptions(categoryRows)

  const columns = [
    { key: 'code', header: 'Kode', cell: (a: Asset) => <span className="font-mono text-xs">{a.asset_number}</span> },
    { key: 'name', header: 'Aset', cell: (a: Asset) => <span className="font-medium text-slate-900">{a.name}</span> },
    { key: 'serial_number', header: 'No Seri', cell: (a: Asset) => (a.serial_number ? <span className="font-mono text-xs">{a.serial_number}</span> : <span className="text-slate-400">—</span>) },
    { key: 'category', header: 'Kategori', cell: (a: Asset) => a.category?.name ?? '—' },
    { key: 'location', header: 'Lokasi', cell: (a: Asset) => a.location?.name ?? <span className="text-slate-400">Tanpa lokasi</span> },
    { key: 'status', header: 'Status', cell: (a: Asset) => <Badge tone={assetStatusTone[a.status]}>{assetStatusLabel[a.status]}</Badge> },
  ]

  const toggleColumn = (key: string) => {
    setVisibleColumns((prev) => (prev.includes(key) ? prev.filter((c) => c !== key) : [...prev, key]))
  }

  const resetColumns = () => {
    setVisibleColumns(DEFAULT_COLUMNS)
  }

  const candidates = useQuery({
    queryKey: ['/assets', 'picker', search, currentPage, filters],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs({ search, per_page: 10, page: currentPage, ...filters })}`),
    enabled: !fixedAsset && !picked,
  })

  const set = (name: string) => (e: { target: { value: string } }) => setValues((v) => ({ ...v, [name]: e.target.value }))
  const err = (name: string) => fieldErrors[name]?.[0]

  const handleSearchChange = (value: string) => {
    setSearch(value)
    setCurrentPage(1)
  }

  const handleFilterChange = (newFilters: Record<string, string>) => {
    setFilters((prev) => ({ ...prev, ...newFilters }))
    setCurrentPage(1)
  }

  const resetFilters = () => {
    setFilters({ location_id: '', status: '', category_ids: '' })
    setCurrentPage(1)
  }

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = { asset_id: asset!.id, type }
      for (const [k, v] of Object.entries(values)) payload[k] = v.trim() === '' ? null : v.trim()
      return api.post('/transactions', payload)
    },
    onSuccess: async () => {
      await Promise.all(['/transactions', '/assets', '/dashboard'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
      onClose()
    },
    onError: (e) => setFieldErrors(e instanceof ApiError ? e.fields : {}),
  })

  return (
    <Modal
      size="full"
      open
      title="Catat transaksi aset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="transaction-form" loading={save.isPending} disabled={!asset || !type}>
            Simpan transaksi
          </Button>
        </>
      }
    >
      <form
        id="transaction-form"
        noValidate
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setFieldErrors({})
          save.mutate()
        }}
      >
        {save.isError && <Alert tone="error">{errorMessage(save.error)}</Alert>}

        {asset ? (
          <div className="rounded-md bg-slate-50 p-3 text-sm ring-1 ring-slate-200">
            <p className="font-medium text-slate-900">{asset.name}</p>
            <p className="text-slate-600">
              {asset.asset_number} · {assetStatusLabel[asset.status]}
              {asset.location ? ` · ${asset.location.name}` : ''}
            </p>
            {!fixedAsset && (
              <button type="button" className="mt-1 text-xs font-medium text-brand-700 hover:underline" onClick={() => (setPicked(null), setType(''))}>
                Ganti aset
              </button>
            )}
          </div>
        ) : (
          <div className="space-y-2">
            <label className="mb-1.5 block text-sm font-semibold text-slate-700">
              Aset <span className="text-red-600">*</span>
            </label>
            {err('asset_id') && <p className="mt-1.5 text-sm text-red-700">{err('asset_id')}</p>}
            <p className="mt-1.5 text-xs text-slate-500">Cari berdasarkan nama, nomor aset, atau nomor seri.</p>
            <FilterPanel>
              <SearchForm
                value={search}
                onChange={handleSearchChange}
                onSubmit={() => setCurrentPage(1)}
                placeholder="Ketik untuk mencari aset"
                label="Cari aset"
                aside={
                  <ColumnToggle
                    columns={columns}
                    visibleColumns={visibleColumns}
                    onToggle={toggleColumn}
                    onReset={resetColumns}
                  />
                }
              />
              <AdvancedFilters
                activeCount={Object.values(filters).filter(Boolean).length}
                dirty={false}
                onApply={() => setCurrentPage(1)}
                onClear={resetFilters}
              >
                <CategoryFilter
                  options={categories}
                  selected={filters.category_ids.split(',').filter(Boolean)}
                  onChange={(ids) => handleFilterChange({ category_ids: ids.join(',') })}
                />
                <Select value={filters.location_id} onChange={(e) => handleFilterChange({ location_id: e.target.value })} aria-label="Filter lokasi">
                  <option value="">Lokasi: semua</option>
                  {locations.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </Select>
                <Select value={filters.status} onChange={(e) => handleFilterChange({ status: e.target.value })} aria-label="Filter status">
                  <option value="">Status: semua</option>
                  {(Object.keys(assetStatusLabel) as AssetStatus[]).map((s) => (
                    <option key={s} value={s}>
                      {assetStatusLabel[s]}
                    </option>
                  ))}
                </Select>
              </AdvancedFilters>
            </FilterPanel>
            {candidates.data?.data.length === 0 ? (
              <p className="text-sm text-slate-500">Aset tidak ditemukan.</p>
            ) : (
              <>
                <DataTable
                  columns={columns}
                  rows={candidates.data?.data ?? []}
                  visibleColumns={visibleColumns}
                  onRowClick={(a) => setPicked(a)}
                />
                {candidates.data?.meta && (
                  <Pagination meta={candidates.data.meta} onPage={setCurrentPage} />
                )}
              </>
            )}
          </div>
        )}

        {asset && (
          <Field label="Jenis transaksi" required error={err('type')}>
            {(p) => (
              <Select
                {...p}
                value={type}
                onChange={(e) => {
                  setType(e.target.value as ManualTransactionType)
                  // Fields of the previous type must not be sent with the new one.
                  setValues((v) => ({ transaction_date: v.transaction_date ?? today(), notes: v.notes ?? '' }))
                  setFieldErrors({})
                }}
              >
                <option value="">— Pilih —</option>
                {types.map((t) => (
                  <option key={t} value={t}>
                    {transactionTypeLabel[t]}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'transfer' && (
          <Field label="Lokasi tujuan" required error={err('to_location_id')}>
            {(p) => (
              <Select {...p} value={values.to_location_id ?? ''} onChange={set('to_location_id')}>
                <option value="">— Pilih —</option>
                {locations.map((o) => (
                  <option key={o.value} value={o.value} disabled={o.value === asset?.location_id}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'status_change' && (
          <Field label="Status baru" required error={err('to_status')}>
            {(p) => (
              <Select {...p} value={values.to_status ?? ''} onChange={set('to_status')}>
                <option value="">— Pilih —</option>
                {selectableStatuses
                  .filter((s) => s !== asset?.status && (s !== 'disposed' || can('asset.dispose')))
                  .map((s) => (
                    <option key={s} value={s}>
                      {assetStatusLabel[s]}
                    </option>
                  ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'loan' && (
          <>
            <Field label="Nama peminjam" required error={err('borrower_name')}>
              {(p) => <Input {...p} value={values.borrower_name ?? ''} onChange={set('borrower_name')} placeholder="mis. Budi (Divisi Operasional)" />}
            </Field>
            <Field label="Rencana tanggal kembali" error={err('due_date')} hint="Aset yang melewati tanggal ini ditandai di dashboard.">
              {(p) => <Input {...p} type="date" value={values.due_date ?? ''} onChange={set('due_date')} />}
            </Field>
          </>
        )}

        {type === 'return' && (
          <Field label="Dikembalikan ke lokasi" error={err('to_location_id')} hint="Kosongkan bila kembali ke lokasi semula.">
            {(p) => (
              <Select {...p} value={values.to_location_id ?? ''} onChange={set('to_location_id')}>
                <option value="">— Lokasi semula —</option>
                {locations.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type && (
          <>
            <Field label="Tanggal transaksi" required error={err('transaction_date')}>
              {(p) => <Input {...p} type="date" max={today()} value={values.transaction_date ?? ''} onChange={set('transaction_date')} />}
            </Field>
            <Field label={type === 'note' ? 'Keterangan aktivitas' : 'Catatan'} required={type === 'note'} error={err('notes')}>
              {(p) => <Textarea {...p} rows={3} value={values.notes ?? ''} onChange={set('notes')} />}
            </Field>
          </>
        )}
      </form>
    </Modal>
  )
}
