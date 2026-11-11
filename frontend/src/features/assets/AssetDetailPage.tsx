import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, CalendarDays, FolderTree, MapPin, Pencil, Plus, Power, Printer, RotateCcw, Undo2, UserRoundPlus, Wallet, type LucideIcon } from 'lucide-react'
import clsx from 'clsx'
import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, type Column } from '../../components/ui/Table'
import { api } from '../../lib/api/client'
import type { CategoryAttribute, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate, formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { attributeRowsBySection, SECTION_LABELS, useAssetFields } from '../master/categoryAttributeData'
import { allowedTypes, withStateAfter, type AssetTransaction, type ManualTransactionType, type TransactionWithState } from '../transactions/api'
import { LocationChange, StatusChange } from '../transactions/TransactionChanges'
import { TransactionFormModal } from '../transactions/TransactionFormModal'
import { assetStatusLabel, assetStatusTone, formatMoney, type Asset } from './api'
import { AssetBarcodeModal } from './AssetBarcodeModal'
import { AssetFormModal } from './AssetFormModal'
import { AssetTimeline, type TimelineEvent } from './AssetTimeline'

/** Detail of one asset: data, quick actions, and its timeline (transactions and data edits). */
export function AssetDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [recording, setRecording] = useState<ManualTransactionType | 'any' | null>(null)
  const [archiving, setArchiving] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [today] = useState(() => new Date().toISOString().slice(0, 10))
  const [historyView, setHistoryView] = useState<'timeline' | 'table'>('timeline')

  const asset = useQuery({ queryKey: ['/assets', id], queryFn: () => api.get<Resource<Asset> & { attribute_fields: CategoryAttribute[] }>(`/assets/${id}`) })
  const attributeFields = useAssetFields(asset.data?.attribute_fields ?? [])
  const history = useQuery({
    queryKey: ['/assets', id, 'transactions'],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/assets/${id}/transactions?per_page=100`),
    enabled: can('transaction.view') && historyView === 'table',
  })
  const timeline = useQuery({
    queryKey: ['/assets', id, 'timeline'],
    queryFn: () => api.get<{ data: TimelineEvent[] }>(`/assets/${id}/timeline`),
    enabled: historyView === 'timeline',
  })

  if (asset.isLoading) return <Spinner />
  if (asset.isError) return <ErrorState error={asset.error} />
  const a = asset.data!.data
  const types = allowedTypes(a.status)
  const canRecord = can('transaction.create') && !a.is_archived
  const overdue = a.status === 'on_loan' && a.loan_due_date !== null && a.loan_due_date < today

  return (
    <>
      <PageHeader
        title={a.name}
        badges={
          <>
            <Badge tone={assetStatusTone[a.status]}>{assetStatusLabel[a.status]}</Badge>
            {a.is_archived && <Badge tone="slate">Nonaktif</Badge>}
          </>
        }
        description={
          <span className="font-mono text-sm">
            {a.asset_number}
            {a.serial_number && <span className="font-sans text-slate-500"> · No Seri {a.serial_number}</span>}
          </span>
        }
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
            {canRecord && types.includes('return') && can('asset.assign') && (
              <Button variant="secondary" onClick={() => setRecording('return')}>
                <Undo2 className="size-4" aria-hidden /> Catat pengembalian
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
      {overdue && (
        <div className="mb-4">
          <Alert tone="warning" title="Peminjaman melewati batas">
            Dipinjam oleh {a.borrower_name}, seharusnya kembali {formatDate(a.loan_due_date)}.
          </Alert>
        </div>
      )}

      {/* The facts people look for first, before reading the full data sheet. */}
      <dl className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Fact icon={CalendarDays} label="Tanggal perolehan">
          {a.acquisition_date ? formatDate(a.acquisition_date) : '—'}
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
              const groupedAttrs = attributeRowsBySection(attributeFields, a.attributes ?? {})
              const builtIn: Record<string, ReactNode> = {
                basic_information: (
                  <>
                    <Row label="Kategori">{a.category?.name}</Row>
                    <Row label="Tanggal perolehan">{formatDate(a.acquisition_date)}</Row>
                    <Row label="Harga perolehan">{formatMoney(a.acquisition_cost)}</Row>
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
                location_ownership: <Row label="Lokasi">{a.location?.name ?? 'Tanpa lokasi'}</Row>,
                status_condition: (
                  <>
                    <Row label="Status">
                      <Badge tone={assetStatusTone[a.status]}>{assetStatusLabel[a.status]}</Badge>
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
              <div className="inline-flex rounded-lg bg-slate-100 p-0.5" role="group" aria-label="Tampilan riwayat">
                {(['timeline', 'table'] as const).map((v) => (
                  <button
                    key={v}
                    type="button"
                    aria-pressed={historyView === v}
                    onClick={() => setHistoryView(v)}
                    className={clsx('rounded-md px-3 py-1 text-xs font-medium', historyView === v ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900')}
                  >
                    {v === 'timeline' ? 'Timeline' : 'Tabel'}
                  </button>
                ))}
              </div>
            )
          }
        >
          {historyView === 'timeline' ? (
            timeline.isLoading ? (
              <Spinner />
            ) : timeline.isError ? (
              <ErrorState error={timeline.error} />
            ) : (
              <AssetTimeline events={timeline.data!.data} />
            )
          ) : history.isLoading ? (
            <Spinner />
          ) : history.isError ? (
            <ErrorState error={history.error} />
          ) : history.data!.data.length === 0 ? (
            <EmptyState title="Belum ada riwayat" />
          ) : (
            <DataTable columns={historyColumns} rows={withStateAfter(history.data!.data, a)} className="md:min-w-[36rem]" />
          )}
        </Card>
      </div>

      {editing && <AssetFormModal asset={a} onClose={() => setEditing(false)} />}
      {printing && <AssetBarcodeModal asset={a} onClose={() => setPrinting(false)} />}
      {recording && <TransactionFormModal asset={a} initialType={recording === 'any' ? undefined : recording} onClose={() => setRecording(null)} />}
      {archiving && <ArchiveModal asset={a} onClose={() => setArchiving(false)} />}
    </>
  )
}

// The asset's own history: the Transaksi Aset table without the asset column.
const historyColumns: Column<TransactionWithState>[] = [
  // Number, date and recorder share one cell so the before → after columns get the room.
  {
    key: 'number',
    header: 'Transaksi',
    className: 'w-48 whitespace-normal',
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
  { key: 'type', header: 'Jenis', cell: (t) => <Badge tone={t.type === 'registration' ? 'green' : 'blue'}>{t.type_label}</Badge>, className: 'w-40' },
  { key: 'location', header: 'Lokasi', cell: (t) => <LocationChange trx={t} />, className: 'whitespace-normal', hideOnMobile: true },
  { key: 'status', header: 'Status', cell: (t) => <StatusChange trx={t} />, className: 'whitespace-normal', hideOnMobile: true },
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
  return () => Promise.all(['/assets', '/dashboard', '/transactions'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
}

function RestoreButton({ asset }: { asset: Asset }) {
  const refresh = useRefreshAsset()
  const restore = useMutation({ mutationFn: () => api.post(`/assets/${asset.id}/restore`), onSuccess: refresh })
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
    onSuccess: async () => {
      await refresh()
      onClose()
    },
  })

  return (
    <Modal
      open
      title={`Nonaktifkan ${asset.name}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            Nonaktifkan
          </Button>
        </>
      }
    >
      {archive.isError && <Alert tone="error">{errorMessage(archive.error)}</Alert>}
      <p className="text-sm text-slate-700">
        Aset tidak lagi dihitung sebagai inventaris aktif dan tidak dapat ditransaksikan. Nomor aset, data, dan seluruh riwayatnya tetap tersimpan, dan aset dapat diaktifkan kembali kapan saja.
      </p>
      <Field label="Alasan">{(p) => <Textarea {...p} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="mis. rusak total, dijual, hilang" />}</Field>
    </Modal>
  )
}
