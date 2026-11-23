import { useQuery } from '@tanstack/react-query'
import { ArrowRight, History, Inbox, Plus, ShieldCheck, Zap } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { PillTabs } from '../../components/ui/Tabs'
import { formatDate, formatDateTime } from '../../lib/format'
import { centerApi, runsApi, runStateTone, type CenterCard, type CenterGroup, type TransactionRun } from '../transactionTypes/api'
import type { ManualTransactionType } from './api'
import { TransactionFormModal } from './TransactionFormModal'

type Tab = 'buat' | 'tugas' | 'saya'
const GROUP_ORDER: CenterGroup[] = ['perpindahan', 'peminjaman', 'perawatan', 'penghapusan', 'lainnya']

/**
 * Transaction Center (F-08 §3.9, D-50): where people record transactions. "Buat transaksi" shows
 * the transactions the user may record now, grouped, each with its way of working in short;
 * "Perlu tindakan saya" the steps waiting for them; "Transaksi saya" what they started, at its
 * current stage. The full history stays under Riwayat. Works on a phone (F08-44).
 */
export function TransactionCenterPage() {
  const [params, setParams] = useSearchParams()
  const tab = (['buat', 'tugas', 'saya'].includes(params.get('tab') ?? '') ? params.get('tab') : 'buat') as Tab
  const [recording, setRecording] = useState<CenterCard | 'any' | null>(null)
  const center = useQuery({ queryKey: ['/transaction-center'], queryFn: () => centerApi.get() })
  const meta = center.data?.meta

  return (
    <>
      <PageHeader
        title="Transaksi"
        description="Pilih transaksi yang ingin Anda lakukan. Petunjuk pengisian ada di setiap formulir."
        actions={
          <>
            <ButtonLink to="/transaksi/riwayat" variant="secondary">
              <History className="size-4" aria-hidden />
              Riwayat transaksi
            </ButtonLink>
            {(center.data?.data.length ?? 0) > 0 && (
              <Button onClick={() => setRecording('any')}>
                <Plus className="size-4" aria-hidden />
                Catat transaksi
              </Button>
            )}
          </>
        }
      />
      <div className="mb-4">
        <PillTabs
          label="Transaction Center"
          value={tab}
          onChange={(t) => setParams(t === 'buat' ? {} : { tab: t }, { replace: true })}
          tabs={[
            { value: 'buat', label: 'Buat transaksi' },
            { value: 'tugas', label: 'Perlu tindakan saya', count: meta?.tasks, attention: (meta?.tasks ?? 0) > 0 },
            { value: 'saya', label: 'Transaksi saya', count: meta?.running },
          ]}
        />
      </div>

      {tab === 'buat' &&
        (center.isPending ? (
          <TableSkeleton rows={4} columns={3} label="Memuat transaksi…" />
        ) : center.isError ? (
          <ErrorState error={center.error} onRetry={() => center.refetch()} />
        ) : center.data.data.length === 0 ? (
          <Card>
            <EmptyState icon={Inbox} title="Belum ada transaksi yang dapat Anda lakukan" description="Role Anda belum diberi izin mencatat transaksi. Hubungi Supervisor atau Super Admin organisasi Anda." />
          </Card>
        ) : (
          <div className="space-y-6">
            {GROUP_ORDER.map((group) => {
              const cards = center.data.data.filter((c) => c.group === group)
              if (cards.length === 0) return null
              return (
                <section key={group}>
                  <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">{meta?.groups[group] ?? group}</h2>
                  <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {cards.map((c) => (
                      <li key={c.id}>
                        <button
                          type="button"
                          onClick={() => setRecording(c)}
                          className="flex h-full w-full flex-col rounded-xl bg-white p-4 text-left shadow-card ring-1 ring-slate-200 transition hover:ring-brand-300 focus-visible:outline-2 focus-visible:outline-brand-600"
                        >
                          <span className="flex w-full items-start justify-between gap-2">
                            <span className="font-semibold text-slate-900">{c.name}</span>
                            {c.needs_approval ? (
                              <Badge tone="amber">
                                <ShieldCheck className="size-3" aria-hidden />
                                Perlu persetujuan
                              </Badge>
                            ) : (
                              <Badge tone="green">
                                <Zap className="size-3" aria-hidden />
                                Langsung tercatat
                              </Badge>
                            )}
                          </span>
                          {c.description && <span className="mt-1 text-sm text-slate-600">{c.description}</span>}
                          {c.needs_approval && <span className="mt-2 text-xs text-slate-500">{c.summary}</span>}
                          {c.categories.length > 0 && <span className="mt-2 text-xs text-slate-500">Untuk aset {c.categories.map((x) => x.name).join(', ')}</span>}
                        </button>
                      </li>
                    ))}
                  </ul>
                </section>
              )
            })}
          </div>
        ))}

      {tab === 'tugas' && (
        <RunList
          params={{ assigned_to_me: '1', state: 'running' }}
          empty="Tidak ada langkah yang menunggu Anda."
          line={(r) => `${r.open_task?.kind === 'approval' ? 'Setujui' : 'Isi data'}: ${r.current_step?.label ?? ''}${r.open_task?.due_date ? ` · batas ${formatDate(r.open_task.due_date)}` : ''}`}
        />
      )}
      {tab === 'saya' && <RunList params={{ mine: '1' }} empty="Anda belum memulai transaksi yang berjalan dengan alur." line={(r) => (r.state === 'running' ? `Tahapan sekarang: ${r.current_step?.label ?? '-'}` : r.state_label)} />}

      {recording && (
        <TransactionFormModal
          initialCustomTypeId={recording !== 'any' && !recording.system_type ? recording.id : undefined}
          initialType={recording !== 'any' && recording.system_type ? (recording.system_type as ManualTransactionType) : undefined}
          onClose={() => setRecording(null)}
        />
      )}
    </>
  )
}

function RunList({ params, empty, line }: { params: Record<string, string>; empty: string; line: (r: TransactionRun) => string }) {
  const runs = useQuery({ queryKey: ['/transaction-runs', 'center', params], queryFn: () => runsApi.list({ ...params, per_page: '50' }) })
  if (runs.isPending) return <TableSkeleton rows={4} columns={3} />
  if (runs.isError) return <ErrorState error={runs.error} onRetry={() => runs.refetch()} />
  if (runs.data.data.length === 0) {
    return (
      <Card>
        <EmptyState icon={Inbox} title={empty} />
      </Card>
    )
  }
  return (
    <Card>
      <ul className="divide-y divide-slate-100">
        {runs.data.data.map((r) => (
          <li key={r.id}>
            <Link to={`/transaksi/berjalan/${r.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
              <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-sm font-semibold text-slate-900">{r.transaction_number}</span>
                  <span className="text-sm text-slate-700">{r.type_definition?.name}</span>
                  <Badge tone={runStateTone[r.state]}>{r.state_label}</Badge>
                </span>
                <span className="block truncate text-sm text-slate-600">{line(r)}</span>
                <span className="block truncate text-xs text-slate-500">
                  {(r.assets ?? []).map((a) => a.name).join(', ')}
                  {r.created_by ? ` · diajukan ${r.created_by.name}` : ''} · {formatDateTime(r.created_at)}
                </span>
              </span>
              <ArrowRight className="size-4 shrink-0 text-slate-400" aria-hidden />
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  )
}
