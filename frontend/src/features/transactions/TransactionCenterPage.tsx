import { useQuery } from '@tanstack/react-query'
import { History, Inbox, Plus, ShieldCheck, Zap } from 'lucide-react'
import { useState } from 'react'
import { Navigate, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { centerApi, type CenterCard, type CenterGroup } from '../transactionTypes/api'
import type { ManualTransactionType } from './api'
import { TransactionFormModal } from './TransactionFormModal'

const GROUP_ORDER: CenterGroup[] = ['perpindahan', 'peminjaman', 'perawatan', 'penghapusan', 'lainnya']

/**
 * Transaction Center (F-08 §3.9, D-50): where people record transactions: the transactions the
 * user may record now, grouped, each with its way of working in short. The steps waiting for the
 * user are in Persetujuan (one list with the data change requests); the transactions still on
 * their way and everything recorded are under Riwayat ("Sedang diproses"). Works on a phone (F08-44).
 */
export function TransactionCenterPage() {
  const [params] = useSearchParams()
  const [recording, setRecording] = useState<CenterCard | 'any' | null>(null)
  const center = useQuery({ queryKey: ['/transaction-center'], queryFn: () => centerApi.get() })
  const meta = center.data?.meta

  // Former tabs; links saved before (emails, bookmarks) still arrive: the steps waiting are in
  // Persetujuan, the transactions on their way under Riwayat.
  if (params.get('tab') === 'tugas') return <Navigate to="/notifikasi?status=pending" replace />
  if (params.get('tab') === 'saya' || params.get('tab') === 'berjalan') return <Navigate to="/transaksi/riwayat?proses=1" replace />

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
      {center.isPending ? (
        <TableSkeleton rows={4} columns={3} label="Memuat transaksi…" />
      ) : center.isError ? (
        <ErrorState error={center.error} onRetry={() => center.refetch()} />
      ) : center.data.data.length === 0 ? (
        <Card>
          <EmptyState icon={Inbox} title="Belum ada transaksi yang dapat Anda lakukan" description="Role Anda belum diberi izin mencatat transaksi. Hubungi Supervisor atau Super Admin business unit Anda." />
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
      )}

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
