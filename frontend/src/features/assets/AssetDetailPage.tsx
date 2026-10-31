import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, Pencil, Plus, Power, RotateCcw, Undo2, UserRoundPlus } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { CategoryAttribute, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate, formatDateTime } from '../../lib/format'
import { errorMessage } from '../../lib/forms'
import { attributeRowsBySection, SECTION_LABELS } from '../master/categoryAttributeData'
import { allowedTypes, type AssetTransaction, type ManualTransactionType } from '../transactions/api'
import { TransactionChanges } from '../transactions/TransactionChanges'
import { TransactionFormModal } from '../transactions/TransactionFormModal'
import { assetStatusLabel, assetStatusTone, formatMoney, type Asset } from './api'
import { AssetFormModal } from './AssetFormModal'

/** Detail of one asset: data, quick actions, and its full transaction history. */
export function AssetDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [recording, setRecording] = useState<ManualTransactionType | 'any' | null>(null)
  const [archiving, setArchiving] = useState(false)
  const [today] = useState(() => new Date().toISOString().slice(0, 10))

  const asset = useQuery({ queryKey: ['/assets', id], queryFn: () => api.get<Resource<Asset> & { attribute_fields: CategoryAttribute[] }>(`/assets/${id}`) })
  const history = useQuery({
    queryKey: ['/assets', id, 'transactions'],
    queryFn: () => api.get<Paginated<AssetTransaction>>(`/assets/${id}/transactions?per_page=100`),
    enabled: can('transaction.view'),
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
        description={`${a.asset_number}${a.serial_number ? ` · No Seri ${a.serial_number}` : ''}`}
        actions={
          <>
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

      <div className="grid gap-4 lg:grid-cols-3">
        <Card
          title="Data aset"
          className="lg:col-span-1"
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
            <a href={a.image_url} target="_blank" rel="noreferrer" className="block border-b border-slate-100 bg-slate-50" title="Buka gambar ukuran penuh">
              <img src={a.image_url} alt={`Gambar ${a.name}`} className="mx-auto max-h-72 w-full object-contain" />
            </a>
          )}
          <dl className="divide-y divide-slate-100 text-sm">
            {/* Built-in fields lead their section; the category's attributes follow. */}
            {(() => {
              const groupedAttrs = attributeRowsBySection(asset.data!.attribute_fields ?? [], a.attributes ?? {})
              const builtIn: Record<string, ReactNode> = {
                basic_information: (
                  <>
                    <Row label="Kategori">{a.category?.name}</Row>
                    <Row label="Tanggal perolehan">{formatDate(a.acquisition_date)}</Row>
                    <Row label="Nilai perolehan">{formatMoney(a.acquisition_cost)}</Row>
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

        <Card title="Riwayat transaksi" className="lg:col-span-2">
          {!can('transaction.view') ? (
            <p className="p-4 text-sm text-slate-500">Anda tidak memiliki izin melihat riwayat transaksi.</p>
          ) : history.isLoading ? (
            <Spinner />
          ) : history.isError ? (
            <ErrorState error={history.error} />
          ) : history.data!.data.length === 0 ? (
            <EmptyState title="Belum ada riwayat" />
          ) : (
            <ol className="divide-y divide-slate-100">
              {history.data!.data.map((t) => (
                <li key={t.id} className="flex flex-wrap gap-x-4 gap-y-1 px-4 py-3">
                  <div className="w-36 shrink-0">
                    <p className="text-sm font-medium text-slate-900">{formatDate(t.transaction_date)}</p>
                    <p className="font-mono text-xs text-slate-500">{t.transaction_number}</p>
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-slate-800">{t.type_label}</p>
                    <TransactionChanges trx={t} />
                  </div>
                  <p className="text-xs text-slate-500">{t.created_by ?? ''}</p>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>

      {editing && <AssetFormModal asset={a} onClose={() => setEditing(false)} />}
      {recording && <TransactionFormModal asset={a} initialType={recording === 'any' ? undefined : recording} onClose={() => setRecording(null)} />}
      {archiving && <ArchiveModal asset={a} onClose={() => setArchiving(false)} />}
    </>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-3 gap-2 px-4 py-2.5">
      <dt className="text-slate-500">{label}</dt>
      <dd className="col-span-2 text-slate-800">{children}</dd>
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
