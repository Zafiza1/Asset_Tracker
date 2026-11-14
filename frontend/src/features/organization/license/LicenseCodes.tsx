import { keepPreviousData, useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, Copy, KeyRound, SearchX } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Button } from '../../../components/ui/Button'
import { Card } from '../../../components/ui/Card'
import { EmptyState, ErrorState, TableSkeleton } from '../../../components/ui/Feedback'
import { ActiveFilters, FilterPanel, SearchForm } from '../../../components/ui/Filters'
import { searchChip } from '../../../components/ui/filterChips'
import { IconButton } from '../../../components/ui/IconButton'
import { DataTable, Pagination, type Column } from '../../../components/ui/Table'
import { PillTabs } from '../../../components/ui/Tabs'
import { api, qs } from '../../../lib/api/client'
import type { LicenseCode, LicenseCodeList, LicenseCodeStatus } from '../../../lib/api/types'
import { useAuth } from '../../../lib/auth/context'
import { formatDate, formatDateTime } from '../../../lib/format'
import { LicenseCodeDetailModal, ReleaseCodeModal, type CodeAction } from './LicenseCodeModals'
import { codeStatusLabel, daysUntil, inviteWithCodeHref, licenseStatusLabel } from './labels'
import { StatusBadge } from '../../../components/ui/Badge'

const TABS: { value: '' | LicenseCodeStatus; label: string }[] = [
  { value: '', label: 'Semua' },
  { value: 'available', label: codeStatusLabel.available },
  { value: 'assigned', label: codeStatusLabel.assigned },
]

function CopyCode({ code }: { code: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <IconButton
      label={copied ? `Kode ${code} disalin` : `Salin kode ${code}`}
      tooltip={copied ? 'Disalin' : 'Salin'}
      onClick={() => {
        void navigator.clipboard?.writeText(code).then(() => {
          setCopied(true)
          setTimeout(() => setCopied(false), 1500)
        })
      }}
    >
      {copied ? <Check className="size-4 text-green-600" aria-hidden /> : <Copy className="size-4" aria-hidden />}
    </IconButton>
  )
}

/** Validity of the code's license; flagged once it is close to its end or already over. */
function Validity({ code }: { code: LicenseCode }) {
  const l = code.license
  if (!l) return <span className="text-slate-400">—</span>
  if (l.status !== 'active') return <span className="text-slate-500">{licenseStatusLabel[l.status]}</span>
  const days = daysUntil(l.expires_at)
  return (
    <span className="tabular-nums">
      {formatDate(l.expires_at)}
      {days <= 30 && <span className={clsx('block text-xs font-medium', days <= 14 ? 'text-red-700' : 'text-amber-700')}>{days <= 0 ? 'Berakhir hari ini' : `${days} hari lagi`}</span>}
    </span>
  )
}

const columns: Column<LicenseCode>[] = [
  {
    key: 'code',
    header: 'Kode',
    sortKey: 'code',
    className: 'w-60',
    cell: (c) => (
      <div className="flex items-center gap-1">
        <span className="font-mono text-sm font-medium text-slate-900">{c.code}</span>
        <CopyCode code={c.code} />
      </div>
    ),
  },
  { key: 'status', header: 'Status', className: 'w-36', sortKey: 'status', cell: (c) => <StatusBadge status={c.status}>{codeStatusLabel[c.status]}</StatusBadge> },
  {
    key: 'holder',
    header: 'Pemegang',
    sortKey: 'holder',
    title: (c) => (c.assigned_user ? `${c.assigned_user.name} · ${c.assigned_user.email}` : c.pending_invitation ? `Diundang: ${c.pending_invitation.email}` : null),
    cell: (c) =>
      c.assigned_user ? (
        <div className="min-w-0">
          <p className="truncate font-medium text-slate-900">{c.assigned_user.name}</p>
          <p className="truncate text-xs text-slate-500">{c.assigned_user.email}</p>
        </div>
      ) : c.pending_invitation ? (
        // Still available, but an open invitation will give it to this invitee.
        <div className="min-w-0">
          <p className="truncate text-slate-700">{c.pending_invitation.email}</p>
          <p className="truncate text-xs text-amber-700">Menunggu undangan diterima</p>
        </div>
      ) : (
        <span className="text-slate-400">—</span>
      ),
  },
  { key: 'validity', header: 'Berlaku sampai', className: 'w-36', sortKey: 'validity', cell: (c) => <Validity code={c} />, hideOnMobile: true },
  { key: 'assigned_at', header: 'Ditugaskan', className: 'w-40', sortKey: 'assigned_at', cell: (c) => (c.assigned_at ? <span className="tabular-nums">{formatDateTime(c.assigned_at)}</span> : <span className="text-slate-400">—</span>), hideOnMobile: true },
]

export function LicenseCodes() {
  const { can } = useAuth()
  const manage = can('license.manage')
  // Codes go to people through the invitation form, which needs both permissions.
  const canInvite = manage && can('user.create') && can('role.manage')
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const status = (params.get('status') ?? '') as '' | LicenseCodeStatus
  const applied = params.get('search') ?? ''
  const page = Number(params.get('page') ?? 1)
  const sort = params.get('sort') ?? ''
  const [search, setSearch] = useState(applied)
  const [opened, setOpened] = useState<LicenseCode | null>(null)
  const [action, setAction] = useState<{ action: CodeAction; code: LicenseCode } | null>(null)

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    setParams(merged, { replace: true })
  }

  const query = useQuery({
    queryKey: ['license-codes', { status, search: applied, page, sort }],
    queryFn: () => api.get<LicenseCodeList>(`/license-codes${qs({ status, search: applied, page, per_page: 10, sort })}`),
    placeholderData: keepPreviousData,
  })
  const counts = query.data?.counts
  const total = counts ? counts.available + counts.assigned : undefined

  const act = (a: CodeAction, c: LicenseCode) => {
    setOpened(null)
    if (a === 'assign') navigate(inviteWithCodeHref(c))
    else setAction({ action: a, code: c })
  }
  const chips = searchChip(applied || null, () => (setSearch(''), update({ search: '', page: '' })))
  const meta = query.data?.meta

  return (
    <Card title="Kode lisensi">
      <FilterPanel>
        <PillTabs
          label="Status kode"
          value={status}
          onChange={(next) => update({ status: next, page: '' })}
          tabs={TABS.map((t) => ({ ...t, count: t.value === '' ? total : counts?.[t.value] }))}
        />
        <SearchForm
          value={search}
          onChange={setSearch}
          onSubmit={() => update({ search: search.trim(), page: '' })}
          onClear={() => applied && update({ search: '', page: '' })}
          placeholder="Cari kode, referensi, atau nama/email pemegang"
          label="Cari kode lisensi"
        />
        <ActiveFilters chips={chips} onReset={() => (setSearch(''), update({ search: '', status: '', page: '' }))} />
      </FilterPanel>
      {query.isLoading ? (
        <TableSkeleton rows={5} columns={5} label="Memuat kode lisensi…" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : query.data!.data.length === 0 ? (
        <EmptyState
          icon={applied || status ? SearchX : KeyRound}
          title={applied || status ? 'Tidak ada kode yang cocok' : 'Belum ada kode lisensi'}
          description={applied || status ? 'Ubah kata kunci atau pilih status lain.' : 'Kode lisensi dibuat otomatis untuk setiap lisensi yang dibeli.'}
          action={
            (applied || status) && (
              <Button variant="secondary" onClick={() => (setSearch(''), update({ search: '', status: '', page: '' }))}>
                Hapus pencarian dan filter
              </Button>
            )
          }
        />
      ) : (
        <>
          <DataTable columns={columns} rows={query.data!.data} onRowClick={setOpened} sort={sort} onSort={(next) => update({ sort: next, page: '' })} />
          {meta && meta.last_page > 1 ? (
            <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
          ) : (
            meta && <p className="border-t border-slate-200 px-4 py-3 text-sm tabular-nums text-slate-500">{meta.total} kode</p>
          )}
        </>
      )}
      {opened && <LicenseCodeDetailModal code={opened} canInvite={canInvite} onClose={() => setOpened(null)} onAction={act} />}
      {action?.action === 'release' && <ReleaseCodeModal code={action.code} onClose={() => setAction(null)} />}
    </Card>
  )
}
