import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { BellRing, CheckCircle2, CircleSlash, ExternalLink, FileClock, SearchX, Send, XCircle, type LucideIcon } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Badge, StatusBadge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner, TableSkeleton } from '../../components/ui/Feedback'
import { Field, Select, Textarea } from '../../components/ui/Field'
import { SearchForm } from '../../components/ui/Filters'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { PillTabs } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { api, ApiError, qs } from '../../lib/api/client'
import type { Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import {
  APPROVALS_KEY,
  approvalCategoryLabel,
  approvalStatusLabel,
  type ApprovalCategory,
  type ApprovalCounts,
  type ApprovalHistoryEntry,
  type ApprovalRequest,
  type ApprovalStatus,
} from './api'

type Tab = ApprovalStatus | ''

const TABS: { status: Tab; label: string }[] = [
  { status: 'pending', label: 'Menunggu' },
  { status: 'approved', label: 'Disetujui' },
  { status: 'rejected', label: 'Ditolak' },
  { status: 'cancelled', label: 'Dibatalkan' },
  { status: '', label: 'Semua' },
]

/** A rejection reason must say something; mirrors the API rule (min 5 characters). */
const REJECT_MIN = 5

/**
 * Persetujuan (approval requests of assets and master data). Super Admin and Supervisor
 * (approval.manage) see every request and approve or reject pending ones; other users see it as
 * "Pengajuan Saya": their own submissions, opening on all of them so recent decisions show first. The page is built around deciding: what waits, who asked, what
 * changes, and who decided.
 */
export function NotificationsPage() {
  const { can } = useAuth()
  const canDecide = can('approval.manage')
  const [params, setParams] = useSearchParams()
  const [openId, setOpenId] = useState<string | null>(null)

  const status = (params.get('status') ?? (canDecide ? 'pending' : '')) as Tab
  const search = params.get('search') ?? ''
  // The typed text follows the applied search when it changes elsewhere (reset, back button).
  const [searchDraft, setSearchDraft] = useState(search)
  const [appliedSearch, setAppliedSearch] = useState(search)
  if (appliedSearch !== search) {
    setAppliedSearch(search)
    setSearchDraft(search)
  }

  const listFilters = { category: params.get('category'), search: search || null }
  const filters = { status, ...listFilters, page: params.get('page') }
  const query = useQuery({
    queryKey: [...APPROVALS_KEY, filters],
    queryFn: () => api.get<Paginated<ApprovalRequest>>(`/approvals${qs(filters)}`),
    placeholderData: keepPreviousData,
  })
  const counts = useQuery({
    queryKey: [...APPROVALS_KEY, 'counts', listFilters],
    queryFn: () => api.get<Resource<ApprovalCounts>>(`/approvals/counts${qs(listFilters)}`),
    placeholderData: keepPreviousData,
  }).data?.data

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) merged.set(k, v)
    if (!('page' in next)) merged.delete('page')
    for (const [k, v] of [...merged.entries()]) if (v === '' && k !== 'status') merged.delete(k)
    setParams(merged)
  }
  const filtered = Boolean(search || params.get('category'))
  const resetFilters = () => update({ search: '', category: '' })

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
    {
      key: 'requester',
      header: 'Diajukan oleh',
      cell: (r) => (
        <div>
          <p className="text-slate-900">{r.requester?.name ?? '—'}</p>
          <p className="text-xs text-slate-500">{formatDateTime(r.created_at)}</p>
        </div>
      ),
      hideOnMobile: true,
    },
    ...(status === 'pending'
      ? []
      : [
          {
            key: 'decider',
            header: 'Diputuskan oleh',
            cell: (r: ApprovalRequest) =>
              r.decided_at ? (
                <div>
                  <p className="text-slate-900">{r.status === 'cancelled' ? 'Dibatalkan pengaju' : (r.decider?.name ?? '—')}</p>
                  <p className="text-xs text-slate-500">{formatDateTime(r.decided_at)}</p>
                </div>
              ) : (
                <span className="text-slate-400">—</span>
              ),
            hideOnMobile: true,
          },
        ]),
    { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status}>{approvalStatusLabel[r.status]}</StatusBadge> },
  ]

  const empty = emptyState(status, canDecide, filtered)

  return (
    <>
      <PageHeader
        title={canDecide ? 'Persetujuan' : 'Pengajuan Saya'}
        description={
          canDecide
            ? 'Pengajuan perubahan aset dan master data yang menunggu keputusan Anda.'
            : 'Hasil pengajuan Anda: disetujui atau ditolak beserta alasannya. Perubahan aset dan master data berlaku setelah disetujui Super Admin atau Supervisor.'
        }
      />
      {canDecide && status !== 'pending' && (counts?.pending ?? 0) > 0 && (
        <div className="mb-4">
          <Alert tone="warning">
            {counts!.pending} pengajuan masih menunggu keputusan Anda.{' '}
            <button type="button" className="font-semibold underline" onClick={() => update({ status: 'pending' })}>
              Lihat pengajuan
            </button>
          </Alert>
        </div>
      )}
      <Card>
        <div className="space-y-3 border-b border-slate-100 p-3">
          <PillTabs
            label="Status pengajuan"
            value={status}
            onChange={(next) => update({ status: next })}
            tabs={TABS.map((t) => ({ value: t.status, label: t.label, count: counts?.[t.status || 'all'], attention: t.status === 'pending' }))}
          />
          <SearchForm
            value={searchDraft}
            onChange={setSearchDraft}
            onSubmit={() => update({ search: searchDraft.trim() })}
            label="Cari pengajuan"
            placeholder={canDecide ? 'Cari nama data, pengaju, atau jenis perubahan' : 'Cari nama data atau jenis perubahan'}
            aside={
              <div className="flex flex-wrap items-center gap-3">
                <Select aria-label="Jenis pengajuan" value={params.get('category') ?? ''} onChange={(e) => update({ category: e.target.value })} className="w-auto">
                  <option value="">Semua jenis</option>
                  {/* Transactions no longer need approval; their label stays only for older requests. */}
                  {(Object.keys(approvalCategoryLabel) as ApprovalCategory[])
                    .filter((c) => c !== 'transaction')
                    .map((c) => (
                      <option key={c} value={c}>
                        {approvalCategoryLabel[c]}
                      </option>
                    ))}
                </Select>
              </div>
            }
          />
        </div>
        {query.isPending ? (
          <TableSkeleton columns={4} label="Memuat pengajuan…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data.data.length === 0 ? (
          <EmptyState
            icon={empty.icon}
            title={empty.title}
            description={empty.description}
            action={
              filtered ? (
                <Button variant="secondary" onClick={resetFilters}>
                  Hapus pencarian dan filter
                </Button>
              ) : undefined
            }
          />
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

function emptyState(status: Tab, canDecide: boolean, filtered: boolean): { icon: LucideIcon; title: string; description: string } {
  if (filtered) {
    return { icon: SearchX, title: 'Tidak ada pengajuan yang cocok', description: 'Ubah kata kunci atau filter, atau lihat tab status lain.' }
  }
  switch (status) {
    case 'pending':
      return canDecide
        ? { icon: CheckCircle2, title: 'Semua pengajuan sudah diproses', description: 'Tidak ada perubahan yang menunggu keputusan Anda saat ini.' }
        : { icon: BellRing, title: 'Tidak ada pengajuan yang menunggu', description: 'Perubahan yang Anda ajukan tampil di sini sampai disetujui atau ditolak.' }
    case 'approved':
      return { icon: CheckCircle2, title: 'Belum ada pengajuan yang disetujui', description: 'Pengajuan yang disetujui tampil di sini beserta nama pemberi persetujuan.' }
    case 'rejected':
      return { icon: XCircle, title: 'Belum ada pengajuan yang ditolak', description: 'Pengajuan yang ditolak tampil di sini beserta alasan penolakannya.' }
    case 'cancelled':
      return { icon: CircleSlash, title: 'Belum ada pengajuan yang dibatalkan', description: 'Pengajuan yang ditarik kembali oleh pengajunya tampil di sini.' }
    default:
      return {
        icon: BellRing,
        title: 'Belum ada pengajuan',
        description: canDecide
          ? 'Perubahan aset dan master data dari pengguna lain akan tampil di sini untuk Anda putuskan.'
          : 'Perubahan aset dan master data yang Anda simpan akan tampil di sini.',
      }
  }
}

function ApprovalDetailModal({ id, canDecide, onClose }: { id: string; canDecide: boolean; onClose: () => void }) {
  const { profile } = useAuth()
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')
  // Rejecting is a second step: the approver has to write down why before confirming.
  const [rejecting, setRejecting] = useState(false)
  // Approving applies the change at once, and cancelling withdraws it for good: both ask once more.
  const [confirming, setConfirming] = useState<'approve' | 'cancel' | null>(null)
  const [noteError, setNoteError] = useState<string | null>(null)
  const noteRef = useRef<HTMLTextAreaElement>(null)
  const query = useQuery({
    queryKey: [...APPROVALS_KEY, id],
    queryFn: () => api.get<Resource<ApprovalRequest>>(`/approvals/${id}`),
  })

  const decide = useMutation({
    mutationFn: (action: 'approve' | 'reject' | 'cancel') => api.post<Resource<ApprovalRequest>>(`/approvals/${id}/${action}`, action === 'cancel' ? {} : { note: note.trim() || null }),
    onSuccess: async (res, action) => {
      // An approved change touches assets or master data: refresh everything.
      await (action === 'approve' ? queryClient.invalidateQueries() : queryClient.invalidateQueries({ queryKey: APPROVALS_KEY }))
      const what = `${res.data.action_label}: ${res.data.summary}`
      if (action === 'approve') toast.success('Pengajuan disetujui', `${what}. Perubahan sudah diterapkan.`)
      else if (action === 'reject') toast.info('Pengajuan ditolak', `${what}. Pengaju melihat alasan Anda.`)
      else toast.info('Pengajuan dibatalkan', what)
      onClose()
    },
    onError: (error) => {
      const fieldError = error instanceof ApiError ? error.fields?.note?.[0] : undefined
      if (fieldError) setNoteError(fieldError)
    },
  })

  const confirmReject = () => {
    if (note.trim().length < REJECT_MIN) {
      setNoteError(note.trim() === '' ? 'Alasan penolakan wajib diisi.' : `Alasan penolakan minimal ${REJECT_MIN} karakter.`)
      noteRef.current?.focus()
      return
    }
    decide.mutate('reject')
  }

  const approval = query.data?.data
  const pending = approval?.status === 'pending'
  const own = approval?.requester?.id === profile?.user.id
  const busy = decide.isPending

  const footer =
    pending && (canDecide || own) ? (
      rejecting ? (
        <>
          <Button
            variant="secondary"
            onClick={() => {
              setRejecting(false)
              setNoteError(null)
            }}
            disabled={busy}
          >
            Kembali
          </Button>
          <Button variant="danger" onClick={confirmReject} loading={busy}>
            Tolak pengajuan
          </Button>
        </>
      ) : confirming ? (
        <>
          <Button variant="secondary" onClick={() => setConfirming(null)} disabled={busy}>
            Kembali
          </Button>
          <Button variant={confirming === 'cancel' ? 'danger' : 'primary'} onClick={() => decide.mutate(confirming)} loading={busy}>
            {confirming === 'approve' ? 'Ya, setujui' : 'Ya, batalkan pengajuan'}
          </Button>
        </>
      ) : (
        <>
          {own && (
            <Button variant="secondary" onClick={() => setConfirming('cancel')} disabled={busy}>
              Batalkan pengajuan
            </Button>
          )}
          {canDecide && (
            <>
              <Button
                variant="danger"
                onClick={() => {
                  setRejecting(true)
                  setTimeout(() => noteRef.current?.focus())
                }}
                disabled={busy}
              >
                Tolak…
              </Button>
              <Button onClick={() => setConfirming('approve')} disabled={busy}>
                Setujui
              </Button>
            </>
          )}
        </>
      )
    ) : (
      <Button variant="secondary" onClick={onClose}>
        Tutup
      </Button>
    )

  return (
    <Modal open size="xl" title={approval?.action_label ?? 'Pengajuan'} onClose={onClose} footer={footer}>
      {query.isPending ? (
        <Spinner />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : (
        approval && (
          <>
            {decide.isError && !noteError && <Alert tone="error">{errorMessage(decide.error)}</Alert>}
            {confirming === 'approve' && (
              <Alert tone={approval.impact?.destructive ? 'warning' : 'info'} title="Setujui pengajuan ini?">
                Perubahan langsung diterapkan ke data dan tercatat atas nama Anda di Audit Trail. Keputusan tidak dapat ditarik kembali.
              </Alert>
            )}
            {confirming === 'cancel' && (
              <Alert tone="warning" title="Batalkan pengajuan ini?">
                Pengajuan ditarik dan tidak akan diproses. Untuk mengajukannya lagi, perubahan harus disimpan ulang.
              </Alert>
            )}
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <Info label="Status">
                <StatusBadge status={approval.status}>{approvalStatusLabel[approval.status]}</StatusBadge>
              </Info>
              <Info label="Jenis">{approval.category_label}</Info>
              <Info label="Data">
                {approval.summary}
                {approval.asset_id && (
                  <Link to={`/aset/${approval.asset_id}`} className="ml-2 inline-flex items-center gap-1 text-brand-700 hover:underline">
                    Lihat aset <ExternalLink className="size-3.5" aria-hidden />
                  </Link>
                )}
              </Info>
              <Info label="Diajukan oleh">
                {approval.requester?.name ?? '—'} · {formatDateTime(approval.created_at)}
              </Info>
            </dl>

            {approval.impact && pending && (
              <Alert tone={approval.impact.destructive ? 'warning' : 'info'} title="Dampak jika disetujui">
                {approval.impact.text}
              </Alert>
            )}

            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-800">Rincian pengajuan</h3>
              <DetailRows rows={approval.details ?? []} />
            </section>

            {approval.decided_at && <Decision approval={approval} />}

            {approval.history && approval.history.length > 0 && (
              <section>
                <h3 className="mb-2 text-sm font-semibold text-slate-800">Riwayat persetujuan</h3>
                <History entries={approval.history} />
              </section>
            )}

            {pending && canDecide && (
              <Field
                label={rejecting ? 'Alasan penolakan' : 'Catatan persetujuan (opsional)'}
                required={rejecting}
                error={noteError ?? undefined}
                hint={rejecting ? 'Wajib diisi. Ditampilkan kepada pengaju agar dapat memperbaiki pengajuannya.' : 'Ditampilkan kepada pengaju bersama keputusan Anda.'}
              >
                {(p) => (
                  <Textarea
                    {...p}
                    ref={noteRef}
                    rows={rejecting ? 3 : 2}
                    maxLength={1000}
                    value={note}
                    placeholder={rejecting ? 'Contoh: Nomor seri tidak sesuai dengan fisik aset.' : undefined}
                    onChange={(e) => {
                      setNote(e.target.value)
                      setNoteError(null)
                    }}
                  />
                )}
              </Field>
            )}
          </>
        )
      )}
    </Modal>
  )
}

/** Submitted values; for changes of an existing record also its current value, with changed rows marked. */
function DetailRows({ rows }: { rows: NonNullable<ApprovalRequest['details']> }) {
  if (rows.length === 0) return <p className="text-sm text-slate-500">Tidak ada data tambahan.</p>
  const compare = rows.some((r) => r.current !== undefined)

  if (!compare) {
    return (
      <dl className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
        {rows.map((d, i) => (
          <div key={i} className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-3">
            <dt className="text-slate-500">{d.label}</dt>
            <dd className="break-words text-slate-900 sm:col-span-2">{d.value}</dd>
          </div>
        ))}
      </dl>
    )
  }

  return (
    <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
      <table className="w-full text-sm">
        <thead className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-3 py-2">Data</th>
            <th className="px-3 py-2">Saat ini</th>
            <th className="px-3 py-2">Diajukan</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((d, i) => {
            const changed = d.current !== undefined && d.current !== d.value
            return (
              <tr key={i} className={clsx(changed && 'bg-amber-50/60')}>
                <td className="px-3 py-2 text-slate-500">
                  {d.label}
                  {changed && <span className="sr-only"> (berubah)</span>}
                </td>
                <td className={clsx('break-words px-3 py-2', changed ? 'text-slate-500 line-through decoration-slate-400' : 'text-slate-900')}>{d.current ?? '—'}</td>
                <td className={clsx('break-words px-3 py-2', changed ? 'font-medium text-slate-900' : 'text-slate-900')}>{d.value}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function Decision({ approval }: { approval: ApprovalRequest }) {
  const rejected = approval.status === 'rejected'
  const cancelled = approval.status === 'cancelled'
  return (
    <section className={clsx('rounded-lg p-3 text-sm ring-1 ring-inset', rejected ? 'bg-red-50 ring-red-200' : cancelled ? 'bg-slate-50 ring-slate-200' : 'bg-green-50 ring-green-200')}>
      <p className="font-semibold text-slate-900">
        {cancelled ? 'Dibatalkan oleh pengaju' : rejected ? 'Ditolak' : 'Disetujui'} · {approval.decider?.name ?? '—'}
      </p>
      <p className="text-xs text-slate-600">{formatDateTime(approval.decided_at)}</p>
      {!cancelled && (
        <div className="mt-2">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{rejected ? 'Alasan penolakan' : 'Catatan persetujuan'}</p>
          <p className="mt-0.5 whitespace-pre-line text-slate-900">{approval.decision_note || (rejected ? 'Tidak ada alasan tercatat.' : 'Tanpa catatan.')}</p>
        </div>
      )}
    </section>
  )
}

const HISTORY_STYLE: Record<ApprovalHistoryEntry['action'], { icon: LucideIcon; color: string }> = {
  'approval.submitted': { icon: Send, color: 'bg-brand-100 text-brand-700' },
  'approval.approved': { icon: CheckCircle2, color: 'bg-green-100 text-green-700' },
  'approval.rejected': { icon: XCircle, color: 'bg-red-100 text-red-700' },
  'approval.cancelled': { icon: CircleSlash, color: 'bg-slate-100 text-slate-600' },
}

function History({ entries }: { entries: ApprovalHistoryEntry[] }) {
  return (
    <ol className="space-y-3">
      {entries.map((e, i) => {
        const { icon: Icon, color } = HISTORY_STYLE[e.action] ?? { icon: FileClock, color: 'bg-slate-100 text-slate-600' }
        return (
          <li key={i} className="flex gap-3 text-sm">
            <span className={clsx('flex size-7 shrink-0 items-center justify-center rounded-full', color)}>
              <Icon className="size-4" aria-hidden />
            </span>
            <div className="min-w-0">
              <p className="text-slate-900">
                <span className="font-medium">{e.label}</span> oleh {e.actor ?? 'sistem'}
              </p>
              <p className="text-xs text-slate-500">{formatDateTime(e.at)}</p>
              {e.note && <p className="mt-1 whitespace-pre-line text-slate-700">“{e.note}”</p>}
            </div>
          </li>
        )
      })}
    </ol>
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
