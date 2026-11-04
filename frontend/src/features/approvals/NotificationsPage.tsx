import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { BellRing } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import { APPROVALS_KEY, approvalCategoryLabel, approvalStatusLabel, approvalStatusTone, type ApprovalCategory, type ApprovalRequest, type ApprovalStatus } from './api'

const TABS: { status: ApprovalStatus | ''; label: string }[] = [
  { status: 'pending', label: 'Menunggu' },
  { status: 'approved', label: 'Disetujui' },
  { status: 'rejected', label: 'Ditolak' },
  { status: 'cancelled', label: 'Dibatalkan' },
  { status: '', label: 'Semua' },
]

/**
 * Notifications: approval requests of transactions, assets and master data. Super Admin and
 * Supervisor (approval.manage) see every request and approve or reject pending ones; other users
 * follow their own submissions here.
 */
export function NotificationsPage() {
  const { can } = useAuth()
  const canDecide = can('approval.manage')
  const [params, setParams] = useSearchParams()
  const [openId, setOpenId] = useState<string | null>(null)

  const status = params.get('status') ?? 'pending'
  const filters = { status, category: params.get('category'), mine: params.get('mine'), page: params.get('page') }
  const query = useQuery({
    queryKey: [...APPROVALS_KEY, filters],
    queryFn: () => api.get<Paginated<ApprovalRequest>>(`/approvals${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) merged.set(k, v)
    if (!('page' in next)) merged.delete('page')
    for (const [k, v] of [...merged.entries()]) if (v === '' && k !== 'status') merged.delete(k)
    setParams(merged)
  }

  const columns: Column<ApprovalRequest>[] = [
    {
      key: 'action',
      header: 'Pengajuan',
      cell: (r) => (
        <div className="min-w-0">
          <p className="font-medium text-slate-900">{r.action_label}</p>
          <p className="truncate text-xs text-slate-500">{r.summary}</p>
        </div>
      ),
    },
    { key: 'category', header: 'Jenis', cell: (r) => <Badge tone="blue">{approvalCategoryLabel[r.category] ?? r.category_label}</Badge>, hideOnMobile: true },
    { key: 'requester', header: 'Diajukan oleh', cell: (r) => r.requester?.name ?? '—', hideOnMobile: true },
    { key: 'created_at', header: 'Waktu', cell: (r) => formatDateTime(r.created_at), hideOnMobile: true },
    { key: 'status', header: 'Status', cell: (r) => <Badge tone={approvalStatusTone[r.status]}>{approvalStatusLabel[r.status]}</Badge> },
  ]

  return (
    <>
      <PageHeader
        title="Notifikasi"
        description={
          canDecide
            ? 'Pengajuan transaksi, aset, dan master data yang menunggu persetujuan Anda.'
            : 'Status pengajuan Anda. Perubahan transaksi, aset, dan master data berlaku setelah disetujui Super Admin atau Supervisor.'
        }
      />
      <Card>
        <div className="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 p-3">
          <div role="tablist" aria-label="Status pengajuan" className="flex flex-wrap gap-1">
            {TABS.map((t) => (
              <button
                key={t.label}
                type="button"
                role="tab"
                aria-selected={status === t.status}
                onClick={() => update({ status: t.status })}
                className={clsx(
                  'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                  status === t.status ? 'bg-brand-50 text-brand-800 ring-1 ring-inset ring-brand-200' : 'text-slate-600 hover:bg-slate-100',
                )}
              >
                {t.label}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <Select aria-label="Jenis pengajuan" value={params.get('category') ?? ''} onChange={(e) => update({ category: e.target.value })} className="w-auto">
              <option value="">Semua jenis</option>
              {(Object.keys(approvalCategoryLabel) as ApprovalCategory[]).map((c) => (
                <option key={c} value={c}>
                  {approvalCategoryLabel[c]}
                </option>
              ))}
            </Select>
            {canDecide && (
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" className="size-4 rounded border-slate-300 text-brand-700" checked={params.get('mine') === '1'} onChange={(e) => update({ mine: e.target.checked ? '1' : '' })} />
                Pengajuan saya
              </label>
            )}
          </div>
        </div>
        {query.isPending ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data.data.length === 0 ? (
          <EmptyState icon={BellRing} title={status === 'pending' ? 'Tidak ada pengajuan yang menunggu' : 'Belum ada pengajuan'} />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data.data} onRowClick={(r) => setOpenId(r.id)} />
            <Pagination meta={query.data.meta} onPage={(page) => update({ page: String(page) })} />
          </>
        )}
      </Card>
      {openId && <ApprovalDetailModal id={openId} canDecide={canDecide} onClose={() => setOpenId(null)} />}
    </>
  )
}

function ApprovalDetailModal({ id, canDecide, onClose }: { id: string; canDecide: boolean; onClose: () => void }) {
  const { profile } = useAuth()
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')
  const query = useQuery({
    queryKey: [...APPROVALS_KEY, id],
    queryFn: () => api.get<Resource<ApprovalRequest>>(`/approvals/${id}`),
  })

  const decide = useMutation({
    mutationFn: (action: 'approve' | 'reject' | 'cancel') => api.post<Resource<ApprovalRequest>>(`/approvals/${id}/${action}`, action === 'cancel' ? {} : { note: note.trim() || null }),
    onSuccess: async (_, action) => {
      // An approved change touches assets, transactions or master data: refresh everything.
      await (action === 'approve' ? queryClient.invalidateQueries() : queryClient.invalidateQueries({ queryKey: APPROVALS_KEY }))
      onClose()
    },
  })

  const approval = query.data?.data
  const pending = approval?.status === 'pending'
  const own = approval?.requester?.id === profile?.user.id

  return (
    <Modal
      open
      size="xl"
      title={approval?.action_label ?? 'Pengajuan'}
      onClose={onClose}
      footer={
        pending && (canDecide || own) ? (
          <>
            {own && (
              <Button variant="secondary" onClick={() => decide.mutate('cancel')} loading={decide.isPending && decide.variables === 'cancel'} disabled={decide.isPending}>
                Batalkan pengajuan
              </Button>
            )}
            {canDecide && (
              <>
                <Button variant="danger" onClick={() => decide.mutate('reject')} loading={decide.isPending && decide.variables === 'reject'} disabled={decide.isPending}>
                  Tolak
                </Button>
                <Button onClick={() => decide.mutate('approve')} loading={decide.isPending && decide.variables === 'approve'} disabled={decide.isPending}>
                  Setujui
                </Button>
              </>
            )}
          </>
        ) : (
          <Button variant="secondary" onClick={onClose}>
            Tutup
          </Button>
        )
      }
    >
      {query.isPending ? (
        <Spinner />
      ) : query.isError ? (
        <ErrorState error={query.error} />
      ) : (
        approval && (
          <>
            {decide.isError && <Alert tone="error">{errorMessage(decide.error)}</Alert>}
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <Info label="Jenis">{approval.category_label}</Info>
              <Info label="Status">
                <Badge tone={approvalStatusTone[approval.status]}>{approvalStatusLabel[approval.status]}</Badge>
              </Info>
              <Info label="Data">{approval.summary}</Info>
              <Info label="Diajukan oleh">
                {approval.requester?.name ?? '—'} · {formatDateTime(approval.created_at)}
              </Info>
              {approval.decided_at && (
                <Info label={approval.status === 'cancelled' ? 'Dibatalkan' : 'Diputuskan oleh'}>
                  {approval.decider?.name ?? '—'} · {formatDateTime(approval.decided_at)}
                </Info>
              )}
              {approval.decision_note && <Info label="Catatan keputusan">{approval.decision_note}</Info>}
            </dl>
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-800">Rincian pengajuan</h3>
              {approval.details && approval.details.length > 0 ? (
                <dl className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                  {approval.details.map((d, i) => (
                    <div key={i} className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-3">
                      <dt className="text-slate-500">{d.label}</dt>
                      <dd className="break-words text-slate-900 sm:col-span-2">{d.value}</dd>
                    </div>
                  ))}
                </dl>
              ) : (
                <p className="text-sm text-slate-500">Tidak ada data tambahan.</p>
              )}
            </section>
            {pending && canDecide && (
              <Field label="Catatan (opsional)" hint="Ditampilkan kepada pengaju bersama keputusan Anda.">
                {(p) => <Textarea {...p} rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />}
              </Field>
            )}
          </>
        )
      )}
    </Modal>
  )
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 text-slate-900">{children}</dd>
    </div>
  )
}
