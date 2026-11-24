import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight, FolderTree, Handshake, MapPin, Pencil, Plus, Power, Printer, RotateCcw, Undo2, UserRound, UserRoundPlus, Wallet, type LucideIcon } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { toast } from '../../components/ui/toast'
import { DataTable, type Column } from '../../components/ui/Table'
import { SegmentedControl } from '../../components/ui/Tabs'
import { api, qs } from '../../lib/api/client'
import { MODULE_NAMES, type CategoryAttribute, type Paginated, type Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatAttributeValue, formatDate, formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { attributeRowsBySection, SECTION_LABELS } from '../master/categoryAttributeData'
import { allowedTypes, daysLate, withStateAfter, type AssetTransaction, type ManualTransactionType, type TransactionWithState } from '../transactions/api'
import { LocationChange, StatusChange } from '../transactions/TransactionChanges'
import { TransactionFormModal } from '../transactions/TransactionFormModal'
import { typesApi } from '../transactionTypes/api'
import { assetStatusLabel, formatMoney, type Asset } from './api'
import { AssetStatusBadge } from './AssetStatusBadge'
import { AssetBarcodeModal } from './AssetBarcodeModal'
import { AssetFormModal } from './AssetFormModal'
import { AssetTimeline, type TimelineEvent } from './AssetTimeline'

/** Detail of one asset: data, quick actions, and its timeline (transactions and data edits). */
export function AssetDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [recording, setRecording] = useState<ManualTransactionType | 'any' | null>(null)
  // "Serah terima" (F08-54): one way for a lasting handover, the type made from that template.
  const [handingOver, setHandingOver] = useState<string | null>(null)
  const activeTypes = useQuery({ queryKey: ['/transaction-types', 'active'], queryFn: () => typesApi.list({ status: 'active' }), staleTime: 30_000, enabled: can('transaction.create') })
  const handover = (activeTypes.data?.data ?? []).find((t) => t.source_template_key === 'serah_terima_penanggung_jawab')
  const [archiving, setArchiving] = useState(false)
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
  const types = allowedTypes(a.status)
  const canRecord = can('transaction.create') && !a.is_archived
  const overdue = a.status === 'on_loan' && a.loan_due_date !== null && a.loan_due_date < today

  return (
    <>
      <PageHeader
        title={a.name}
        badges={<AssetStatusBadge status={a.status} archived={a.is_archived} />}
        description={a.serial_number ? <span className="text-sm text-slate-500">No Seri {a.serial_number}</span> : undefined}
        actions={
          <>
            {a.barcode_enabled && (
              <Button variant="secondary" onClick={() => setPrinting(true)}>
                <Printer className="size-4" aria-hidden /> Print barcode
              </Button>
            )}
            {canRecord && types.includes('transfer') && can('asset.transfer') && (
              <Button variant="secondary" onClick={() => setRecording('transfer')}>
                <ArrowLeftRight className="size-4" aria-hidden /> Pindahkan
              </Button>
            )}
            {canRecord && types.includes('loan') && can('asset.assign') && (
              <Button variant="secondary" onClick={() => setRecording('loan')}>
                <UserRoundPlus className="size-4" aria-hidden /> Pinjamkan
              </Button>
            )}
            {canRecord && types.includes('outgoing') && can('asset.outgoing') && (
              <Button variant="secondary" onClick={() => setRecording('outgoing')}>
                <ArrowUpRight className="size-4" aria-hidden /> Catat keluar
              </Button>
            )}
            {canRecord && types.includes('incoming') && can('asset.incoming') && (
              <Button variant="secondary" onClick={() => setRecording('incoming')}>
                <ArrowDownLeft className="size-4" aria-hidden /> Catat masuk
              </Button>
            )}
            {canRecord && types.includes('return') && can('asset.assign') && (
              <Button variant="secondary" onClick={() => setRecording('return')}>
                <Undo2 className="size-4" aria-hidden /> Catat pengembalian
              </Button>
            )}
            {canRecord && handover && (
              <Button variant="secondary" onClick={() => setHandingOver(handover.id)}>
                <Handshake className="size-4" aria-hidden /> Serah terima
              </Button>
            )}
            {canRecord && (
              <Button onClick={() => setRecording('any')}>
                <Plus className="size-4" aria-hidden /> Catat transaksi
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
        {/* F-09: "who holds it?" is the first question about an asset. */}
        <Fact icon={UserRound} label="Dipegang oleh">
          {a.custodian ? (
            <span className="block">
              <span className="flex flex-wrap items-center gap-1">
                {a.custodian.name}
                {!a.custodian.is_active && <Badge tone="amber">nonaktif</Badge>}
              </span>
              {(a.department || !a.custodian.is_active) && (
                <span className="block text-xs font-normal text-slate-500">{a.custodian.is_active ? a.department?.name : 'Perlu penanggung jawab baru'}</span>
              )}
            </span>
          ) : can('asset.update') && !a.is_archived ? (
            <button type="button" className="text-left font-semibold text-brand-700 hover:underline" onClick={() => setEditing(true)}>
              Tunjuk penanggung jawab
            </button>
          ) : (
            <span className="text-slate-400">Belum ada</span>
          )}
        </Fact>
        <Fact icon={MapPin} label="Lokasi">
          {a.location?.name ?? <span className="text-slate-400">Tanpa lokasi</span>}
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
              {can('asset.archive') && !a.is_archived && (
                <Button size="sm" variant="ghost" onClick={() => setArchiving(true)}>
                  <Power className="size-4" aria-hidden /> Nonaktifkan
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
                location_ownership: (
                  <>
                    <Row label="Lokasi">{a.location?.name ?? 'Tanpa lokasi'}</Row>
                    <Row label="Penanggung jawab">
                      {a.custodian ? `${a.custodian.name ?? '—'}${a.custodian.is_active ? '' : ' (nonaktif)'}` : '—'}
                    </Row>
                    <Row label="Departemen">{a.department?.name ?? '—'}</Row>
                  </>
                ),
                status_condition: (
                  <>
                    <Row label="Status">
                      <AssetStatusBadge status={a.status} />
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

            <Row label="Deskripsi">{a.description ? <span className="whitespace-pre-line">{a.description}</span> : '—'}</Row>
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
              <AssetTimeline events={timeline.data!.data} />
            )
          ) : history.isLoading ? (
            <TableSkeleton rows={5} columns={4} label="Memuat riwayat transaksi…" />
          ) : history.isError ? (
            <ErrorState error={history.error} onRetry={() => void history.refetch()} />
          ) : history.data!.data.length === 0 ? (
            <EmptyState
              icon={ArrowLeftRight}
              title="Belum ada riwayat"
              description="Perpindahan, peminjaman, dan perubahan status aset ini akan tercatat di sini."
              action={
                canRecord && (
                  <Button variant="secondary" onClick={() => setRecording('any')}>
                    <Plus className="size-4" aria-hidden /> Catat transaksi
                  </Button>
                )
              }
            />
          ) : (
            <DataTable columns={historyColumns} rows={withStateAfter(history.data!.data, a)} className="md:min-w-[36rem]" />
          )}
        </Card>
      </div>

      {editing && <AssetFormModal asset={a} onClose={() => setEditing(false)} />}
      {printing && <AssetBarcodeModal asset={a} onClose={() => setPrinting(false)} />}
      {recording && <TransactionFormModal asset={a} initialType={recording === 'any' ? undefined : recording} onClose={() => setRecording(null)} />}
      {handingOver && <TransactionFormModal asset={a} initialCustomTypeId={handingOver} onClose={() => setHandingOver(null)} />}
      {archiving && <ArchiveModal asset={a} onClose={() => setArchiving(false)} />}
    </>
  )
}

// The asset's own history: the Riwayat Transaksi table without the asset column.
const historyColumns: Column<TransactionWithState>[] = [
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
  { key: 'location', header: 'Lokasi', sortValue: (t) => t.location_after, cell: (t) => <LocationChange trx={t} />, className: 'whitespace-normal', hideOnMobile: true },
  { key: 'status', header: 'Status', sortValue: (t) => assetStatusLabel[t.status_after], cell: (t) => <StatusChange trx={t} />, className: 'whitespace-normal', hideOnMobile: true },
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

function ArchiveModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const refresh = useRefreshAsset()
  const [reason, setReason] = useState('')
  const archive = useMutation({
    mutationFn: () => api.post(`/assets/${asset.id}/archive`, { reason: reason.trim() || null }),
    onSuccess: async (response) => {
      await refresh()
      toast.saved(response, 'Aset dinonaktifkan', asset.name)
      onClose()
    },
  })

  return (
    <ConfirmDialog
      open
      tone="danger"
      title={`Nonaktifkan ${asset.name}?`}
      confirmLabel="Nonaktifkan"
      loading={archive.isPending}
      error={archive.error}
      onConfirm={() => archive.mutate()}
      onClose={onClose}
    >
      <p>
        Aset tidak lagi dihitung sebagai inventaris aktif dan tidak dapat ditransaksikan. Data dan seluruh riwayatnya tetap tersimpan, dan aset dapat diaktifkan kembali kapan saja.
      </p>
      <Field label="Alasan">{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. rusak total, dijual, hilang" />}</Field>
    </ConfirmDialog>
  )
}

/** Where an off-site asset is and since when; red once it is past its planned return date. */
function OffSiteAlert({ trx }: { trx: AssetTransaction | undefined }) {
  if (!trx) {
    return (
      <div className="mb-4">
        <Alert tone="info" title="Di luar organisasi">
          Aset ini sedang berada di pihak luar. Catat transaksi masuk saat aset kembali.
        </Alert>
      </div>
    )
  }
  const late = daysLate(trx)
  const party = [trx.counterparty_name, trx.counterparty_org && `(${trx.counterparty_org})`].filter(Boolean).join(' ')
  return (
    <div className="mb-4">
      <Alert tone={late > 0 ? 'error' : 'info'} title={late > 0 ? `Lewat rencana tanggal kembali ${late} hari` : 'Di luar organisasi'}>
        {trx.reason_label} · {party} sejak {formatDate(trx.transaction_date)}
        {trx.due_date && <>, rencana kembali {formatDate(trx.due_date)}</>} (<span className="font-mono text-xs">{trx.transaction_number}</span>
        {trx.document_number && <>, dokumen {trx.document_number}</>}).
      </Alert>
    </div>
  )
}
