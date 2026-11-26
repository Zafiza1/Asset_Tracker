import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, FolderTree, MapPin, Pencil, Printer, RotateCcw, Wallet, type LucideIcon } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { toast } from '../../components/ui/toast'
import { DataTable, type Column } from '../../components/ui/Table'
import { SegmentedControl } from '../../components/ui/Tabs'
import { api, qs } from '../../lib/api/client'
import { MODULE_NAMES, type CategoryAttribute, type Paginated, type Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatAttributeValue, formatDate, formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { attributeRowsBySection, SECTION_LABELS } from '../master/categoryAttributeData'
import { daysLate, withStateAfter, type AssetTransaction, type TransactionWithState } from '../transactions/api'
import { StatusChange } from '../transactions/TransactionChanges'
import { formatMoney, isCylinder as isCylinderAsset, statusLabel, type Asset } from './api'
import { LocationStatusBadge } from './LocationStatusBadge'
import { AssetBarcodeModal } from './AssetBarcodeModal'
import { AssetFormModal } from './AssetFormModal'
import { AssetTimeline, type TimelineEvent } from './AssetTimeline'

/** Detail of one asset: data, quick actions, and its timeline (transactions and data edits). */
export function AssetDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const [historyView, setHistoryView] = useState<'timeline' | 'table'>('timeline')

  const asset = useQuery({ queryKey: ['/assets', id], queryFn: () => api.get<Resource<Asset> & { attribute_fields: CategoryAttribute[] }>(`/assets/${id}`) })
  const attributeFields = asset.data?.attribute_fields ?? []
  const history = useQuery({
    queryKey: ['/assets', id, 'transactions'],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/assets/${id}/transactions?per_page=100`),
    enabled: can('transaction.view') && historyView === 'table',
  })
  // Off-site assets: the outgoing transaction that sent them out (newest first).
  const outgoing = useQuery({
    queryKey: ['/transactions', 'outgoing-of', id],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/transactions${qs({ asset_id: id, type: 'outgoing', per_page: 1 })}`),
    enabled: can('transaction.view') && asset.data?.data.status === 'off_site',
  })
  const timeline = useQuery({
    queryKey: ['/assets', id, 'timeline'],
    queryFn: () => api.get<{ data: TimelineEvent[] }>(`/assets/${id}/timeline`),
    enabled: historyView === 'timeline',
  })

  if (asset.isLoading) return <DetailSkeleton label="Memuat data aset…" />
  if (asset.isError) return <ErrorState error={asset.error} onRetry={() => void asset.refetch()} />
  const a = asset.data!.data
  const isCylinder = isCylinderAsset(a)
  const overdue = a.status === 'on_loan' && a.loan_due_date !== null && a.loan_due_date < today

  return (
    <>
      <PageHeader
        title={a.name}
        badges={<LocationStatusBadge asset={a} compact />}
        actions={
          <>
            {a.barcode_enabled && (
              <Button variant="secondary" onClick={() => setPrinting(true)}>
                <Printer className="size-4" aria-hidden /> Print barcode
              </Button>
            )}
          </>
        }
      />

      {a.is_archived && (
        <div className="mb-4">
          <Alert tone="warning" title="Aset nonaktif">
            Aset ini tidak termasuk inventaris aktif sejak {formatDateTime(a.archived_at)}. Data dan riwayat tetap tersimpan; aktifkan kembali untuk mencatat transaksi.
          </Alert>
        </div>
      )}
      {a.status === 'off_site' && <OffSiteAlert trx={outgoing.data?.data[0]} />}
      {overdue && (
        <div className="mb-4">
          <Alert tone="warning" title="Peminjaman melewati batas">
            Dipinjam oleh {a.borrower_name}, seharusnya kembali {formatDate(a.loan_due_date)}.
          </Alert>
        </div>
      )}

      {/* The facts people look for first, before reading the full data sheet. */}
      <dl className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {/* Set by the system from the cylinder's transactions; there is no status to change by hand. */}
        <Fact icon={MapPin} label="Lokasi Status">
          <span className="block">
            {a.location_status.label}
            {a.location_status.detail && <span className="block text-xs font-normal text-slate-500">{a.location_status.detail}</span>}
            {a.location_status.delivery_id && (
              <Link to={`/transaksi/${a.location_status.delivery_id}`} className="block text-xs font-normal text-brand-700 hover:underline">
                {a.location_status.delivery_number}
              </Link>
            )}
          </span>
        </Fact>
        <Fact icon={FolderTree} label="Kategori">
          {a.category?.name ?? '—'}
        </Fact>
        <Fact icon={Wallet} label="Harga perolehan">
          <span className="tabular-nums">{formatMoney(a.acquisition_cost)}</span>
        </Fact>
      </dl>

      <div className="grid items-start gap-4 xl:grid-cols-5">
        <Card
          title="Data aset"
          className="min-w-0 xl:col-span-2"
          actions={
            <div className="flex gap-1">
              {can('asset.update') && !a.is_archived && (
                <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
                  <Pencil className="size-4" aria-hidden /> Ubah
                </Button>
              )}
              {can('asset.restore') && a.is_archived && <RestoreButton asset={a} />}
            </div>
          }
        >
          {a.image_url && (
            // No "noreferrer": Sanctum only accepts the session cookie when a Referer is sent, so the new tab would get a 401.
            <a href={a.image_url} target="_blank" rel="noopener" className="block border-b border-slate-100 bg-slate-50" title="Buka gambar ukuran penuh">
              <img src={a.image_url} alt={`Gambar ${a.name}`} className="mx-auto max-h-72 w-full object-contain" />
            </a>
          )}
          <dl className="divide-y divide-slate-100 text-sm">
            {/* Built-in fields lead their section; the category's attributes follow. */}
            {(() => {
              // Fields the asset does not use are left out; its own fields join the category-specific ones.
              const groupedAttrs = attributeRowsBySection(attributeFields.filter((f) => !(a.hidden_attributes ?? []).includes(f.key)), a.attributes ?? {})
              if ((a.extra_attributes ?? []).length > 0) {
                groupedAttrs.specific_attributes = [
                  ...(groupedAttrs.specific_attributes ?? []),
                  ...a.extra_attributes.map((e, i) => ({ key: `extra-${i}`, label: e.label, value: e.data_type === 'date' && typeof e.value === 'string' ? formatDate(e.value) : formatAttributeValue(e.value) })),
                ]
              }
              const builtIn: Record<string, ReactNode> = {
                basic_information: (
                  <>
                    <Row label={isCylinder ? 'Nama Produk' : 'Nama aset'}>{a.name}</Row>
                    <Row label={isCylinder ? 'No Manufaktur' : 'No Seri'}>{a.serial_number ?? '—'}</Row>
                    <Row label="No Reference">{a.reference_number}</Row>
                    <Row label="Kategori">{a.category?.name}</Row>
                    <Row label="Tanggal perolehan">{formatDate(a.acquisition_date)}</Row>
                    <Row label="Harga perolehan">{formatMoney(a.acquisition_cost)}</Row>
                    {/* Modules picked for this asset in Menu Modul (only those switched on). */}
                    <Row label="Modul">
                      {a.modules.length > 0 ? (
                        <span className="flex flex-wrap gap-1">
                          {a.modules.map((m) => (
                            <Badge key={m} tone="blue">
                              {MODULE_NAMES[m] ?? m}
                            </Badge>
                          ))}
                        </span>
                      ) : (
                        '—'
                      )}
                    </Row>
                    <Row label="Dokumen aset">
                      {a.document_url ? (
                        <a href={a.document_url} className="text-brand-700 underline" download={a.document_name ?? undefined}>
                          {a.document_name ?? 'Unduh dokumen'}
                        </a>
                      ) : (
                        '—'
                      )}
                    </Row>
                  </>
                ),
                status_condition: (
                  <>
                    <Row label="Lokasi Status">
                      <LocationStatusBadge asset={a} />
                    </Row>
                    {a.status === 'on_loan' && <Row label="Peminjam">{`${a.borrower_name}${a.loan_due_date ? ` (kembali ${formatDate(a.loan_due_date)})` : ''}`}</Row>}
                  </>
                ),
              }
              const sections = [...Object.keys(builtIn), ...Object.keys(groupedAttrs).filter((s) => !(s in builtIn))]

              return sections.map((section) => (
                <div key={section} className="divide-y divide-slate-100">
                  <div className="bg-slate-50 px-4 py-2">
                    <h3 className="text-base font-semibold text-slate-900">{SECTION_LABELS[section] || section}</h3>
                  </div>
                  {builtIn[section]}
                  {(groupedAttrs[section] ?? []).map((r) => (
                    <Row key={r.key} label={r.label}>
                      {r.value}
                    </Row>
                  ))}
                </div>
              ))
            })()}

            <Row label="Ditambahkan">{`${formatDateTime(a.created_at)}${a.created_by ? ` oleh ${a.created_by}` : ''}`}</Row>
          </dl>
        </Card>

        <Card
          title={historyView === 'timeline' ? 'Timeline aset' : 'Riwayat transaksi'}
          className="min-w-0 xl:col-span-3"
          actions={
            can('transaction.view') && (
              <SegmentedControl
                label="Tampilan riwayat"
                value={historyView}
                onChange={setHistoryView}
                options={[
                  { value: 'timeline', label: 'Timeline' },
                  { value: 'table', label: 'Tabel' },
                ]}
              />
            )
          }
        >
          {historyView === 'timeline' ? (
            timeline.isLoading ? (
              <TableSkeleton rows={5} columns={2} label="Memuat timeline…" />
            ) : timeline.isError ? (
              <ErrorState error={timeline.error} onRetry={() => void timeline.refetch()} />
            ) : (
              <AssetTimeline events={timeline.data!.data} cylinder={isCylinder} />
            )
          ) : history.isLoading ? (
            <TableSkeleton rows={5} columns={4} label="Memuat riwayat transaksi…" />
          ) : history.isError ? (
            <ErrorState error={history.error} onRetry={() => void history.refetch()} />
          ) : history.data!.data.length === 0 ? (
            <EmptyState
              icon={ArrowLeftRight}
              title="Belum ada riwayat"
              description="Keluar, masuk, dan perubahan status aset ini akan tercatat di sini."
            />
          ) : (
            <DataTable columns={historyColumns(isCylinder)} rows={withStateAfter(history.data!.data, a)} className="md:min-w-[36rem]" />
          )}
        </Card>
      </div>

      {editing && <AssetFormModal asset={a} onClose={() => setEditing(false)} />}
      {printing && <AssetBarcodeModal asset={a} onClose={() => setPrinting(false)} />}
    </>
  )
}

// The asset's own history: the Riwayat Transaksi table without the asset column.
const historyColumns = (cylinder: boolean): Column<TransactionWithState>[] => [
  // Number, date and recorder share one cell so the before → after columns get the room.
  {
    key: 'number',
    header: 'Transaksi',
    className: 'w-48 whitespace-normal',
    // Oldest to newest; same-day transactions keep their numbering order.
    sortValue: (t) => `${t.transaction_date} ${t.transaction_number}`,
    cell: (t) => (
      <div>
        <p className="font-mono text-xs text-slate-900">{t.transaction_number}</p>
        <p className="mt-0.5 text-xs text-slate-500">
          {formatDate(t.transaction_date)}
          {t.created_by && ` · ${t.created_by}`}
        </p>
      </div>
    ),
  },
  { key: 'type', header: 'Jenis', sortValue: (t) => t.type_label, cell: (t) => <Badge tone={t.type === 'registration' ? 'green' : 'blue'}>{t.type_label}</Badge>, className: 'w-40' },
  { key: 'status', header: 'Status', sortValue: (t) => statusLabel(t.status_after, cylinder), cell: (t) => <StatusChange trx={t} cylinder={cylinder} />, className: 'whitespace-normal', hideOnMobile: true },
]

function Fact({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 gap-3 rounded-xl bg-white p-4 shadow-card ring-1 ring-slate-200/80">
      <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700" aria-hidden>
        <Icon className="size-4.5" />
      </span>
      <div className="min-w-0">
        <dt className="text-xs font-medium text-slate-500">{label}</dt>
        <dd className="mt-0.5 break-words text-sm font-semibold text-slate-900">{children}</dd>
      </div>
    </div>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-3 gap-2 px-4 py-2.5">
      <dt className="text-slate-500">{label}</dt>
      <dd className="col-span-2 min-w-0 text-slate-800 [overflow-wrap:anywhere]">{children}</dd>
    </div>
  )
}

function useRefreshAsset() {
  const queryClient = useQueryClient()
  return () => Promise.all(['/assets', '/dashboards', '/transactions'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
}

function RestoreButton({ asset }: { asset: Asset }) {
  const refresh = useRefreshAsset()
  const restore = useMutation({
    mutationFn: () => api.post(`/assets/${asset.id}/restore`),
    onSuccess: async (response) => {
      await refresh()
      toast.saved(response, 'Aset diaktifkan kembali', asset.name)
    },
  })
  return (
    <>
      {restore.isError && <span className="text-xs text-red-600">{errorMessage(restore.error)}</span>}
      <Button size="sm" variant="ghost" loading={restore.isPending} onClick={() => restore.mutate()}>
        <RotateCcw className="size-4" aria-hidden /> Aktifkan kembali
      </Button>
    </>
  )
}

/** Where an off-site asset is and since when; red once it is past its planned return date. */
function OffSiteAlert({ trx }: { trx: AssetTransaction | undefined }) {
  if (!trx) {
    return (
      <div className="mb-4">
        <Alert tone="info" title="Di luar business unit">
          Aset ini sedang berada di pihak luar. Catat transaksi masuk saat aset kembali.
        </Alert>
      </div>
    )
  }
  const late = daysLate(trx)
  const party = [trx.counterparty_name, trx.counterparty_org && `(${trx.counterparty_org})`].filter(Boolean).join(' ')
  return (
    <div className="mb-4">
      <Alert tone={late > 0 ? 'error' : 'info'} title={late > 0 ? `Lewat rencana tanggal kembali ${late} hari` : 'Di luar business unit'}>
        {trx.reason_label} · {party} sejak {formatDate(trx.transaction_date)}
        {trx.due_date && <>, rencana kembali {formatDate(trx.due_date)}</>} (<span className="font-mono text-xs">{trx.transaction_number}</span>
        {trx.document_number && <>, dokumen {trx.document_number}</>}).
      </Alert>
    </div>
  )
}
