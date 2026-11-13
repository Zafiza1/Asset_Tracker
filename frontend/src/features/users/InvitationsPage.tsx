import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MailPlus, Plus, SearchX } from 'lucide-react'
import { useState } from 'react'
import { useLocation, useSearchParams } from 'react-router'
import { StatusBadge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, EmptyState, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, AdvancedFilters, FilterPanel, SearchForm, useFilterDraft } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { toast } from '../../components/ui/toast'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { OrganizationInvitation, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDateTime, invitationStatusLabel } from '../../lib/format'
import { UserAdminTabs } from './UserAdminTabs'
import type { InvitationSentState } from './UserCreatePage'

type Action = { kind: 'resend' | 'revoke'; invitation: OrganizationInvitation }

/** Invitations of the current organization: who was invited, their state, resend or revoke. */
export function InvitationsPage() {
  const { can } = useAuth()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [action, setAction] = useState<Action | null>(null)
  const [notice, setNotice] = useState<string | null>(() => {
    const invited = (location.state as InvitationSentState | null)?.invited
    return invited ? `Undangan dikirim ke ${invited}. Akses berlaku setelah undangan diterima.` : null
  })
  const page = Number(params.get('page') ?? 1)
  const status = params.get('status') ?? ''
  const advanced = useFilterDraft(params, ['status'])
  const canManage = can('user.create') && can('role.manage')

  const query = useQuery({
    queryKey: ['invitations', { page, status, search: params.get('search') }],
    queryFn: () => api.get<Paginated<OrganizationInvitation>>(`/invitations${qs({ page, status, search: params.get('search') })}`),
    placeholderData: keepPreviousData,
  })

  const mutation = useMutation({
    mutationFn: ({ kind, invitation }: Action) => api.post<Resource<OrganizationInvitation>>(`/invitations/${invitation.id}/${kind}`),
    onSuccess: async (res, { kind }) => {
      await queryClient.invalidateQueries({ queryKey: ['invitations'] })
      setNotice(null)
      if (kind === 'resend') toast.success('Undangan dikirim ulang', `Email baru dikirim ke ${res.data.email}. Tautan sebelumnya tidak berlaku lagi.`)
      else toast.success('Undangan dicabut', res.data.email)
      setAction(null)
    },
  })
  const confirm = (next: Action) => (mutation.reset(), setAction(next))

  const update = (next: Record<string, string>) => {
    const merged = new URLSearchParams(params)
    for (const [k, v] of Object.entries(next)) {
      if (v) merged.set(k, v)
      else merged.delete(k)
    }
    setParams(merged, { replace: true })
  }
  const resetAll = () => (setSearch(''), setParams(new URLSearchParams(), { replace: true }))
  const chips: FilterChip[] = [
    ...searchChip(params.get('search'), () => (setSearch(''), update({ search: '', page: '' }))),
    ...(status ? [{ key: 'status', label: `Status: ${invitationStatusLabel[status] ?? status}`, clear: () => update({ status: '', page: '' }) }] : []),
  ]

  const columns: Column<OrganizationInvitation>[] = [
    {
      key: 'email',
      header: 'Email',
      cell: (i) => (
        <div>
          <p className="font-medium text-slate-900">{i.email}</p>
          <p className="text-xs text-slate-500">{i.job_title ?? ''}</p>
        </div>
      ),
    },
    { key: 'roles', header: 'Role', cell: (i) => i.roles?.map((r) => r.name).join(', ') || '—', hideOnMobile: true },
    { key: 'status', header: 'Status', cell: (i) => <StatusBadge status={i.status}>{invitationStatusLabel[i.status]}</StatusBadge> },
    { key: 'invited_by', header: 'Diundang oleh', cell: (i) => i.invited_by?.name ?? '—', hideOnMobile: true },
    {
      key: 'expires',
      header: 'Berlaku sampai',
      cell: (i) => (i.status === 'accepted' ? `Diterima ${formatDateTime(i.accepted_at)}` : i.status === 'revoked' ? `Dicabut ${formatDateTime(i.revoked_at)}` : formatDateTime(i.expires_at)),
      hideOnMobile: true,
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: 'Tindakan',
            cell: (i: OrganizationInvitation) =>
              i.status === 'pending' || i.status === 'expired' ? (
                <div className="flex justify-end gap-2">
                  <Button variant="secondary" size="sm" onClick={() => confirm({ kind: 'resend', invitation: i })}>
                    Kirim ulang
                  </Button>
                  <Button variant="ghost" size="sm" className="text-red-700 hover:bg-red-50 hover:text-red-800" onClick={() => confirm({ kind: 'revoke', invitation: i })}>
                    Cabut
                  </Button>
                </div>
              ) : null,
          },
        ]
      : []),
  ]

  return (
    <>
      <PageHeader
        title="Pengguna"
        actions={
          canManage && (
            <ButtonLink to="/pengguna/baru">
                <Plus className="size-4" aria-hidden /> Undang pengguna
              </ButtonLink>
          )
        }
      />
      <UserAdminTabs />
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}
      <Card>
        <FilterPanel>
          <SearchForm value={search} onChange={setSearch} onSubmit={() => update({ search, page: '' })} placeholder="Cari email" label="Cari undangan" />
          <AdvancedFilters activeCount={advanced.activeCount} dirty={advanced.dirty} onApply={() => update({ ...advanced.draft, page: '' })} onClear={() => advanced.set(advanced.empty)}>
            <Select value={advanced.draft.status} onChange={(e) => advanced.set({ status: e.target.value })} aria-label="Filter status">
              <option value="">Status: semua</option>
              {Object.entries(invitationStatusLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </AdvancedFilters>
          <ActiveFilters chips={chips} onReset={resetAll} />
        </FilterPanel>
        {query.isLoading ? (
          <TableSkeleton columns={5} label="Memuat undangan…" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={chips.length > 0 ? SearchX : MailPlus}
            title={chips.length > 0 ? 'Tidak ada undangan yang cocok' : 'Belum ada undangan'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : 'Undang pengguna untuk memberi mereka akses ke organisasi ini.'}
            action={
              chips.length > 0 ? (
                <Button variant="secondary" onClick={resetAll}>
                  Hapus pencarian dan filter
                </Button>
              ) : (
                canManage && (
                  <ButtonLink to="/pengguna/baru">
                      <Plus className="size-4" aria-hidden /> Undang pengguna
                    </ButtonLink>
                )
              )
            }
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      <ConfirmDialog
        open={action !== null}
        tone={action?.kind === 'revoke' ? 'danger' : 'primary'}
        title={action?.kind === 'revoke' ? 'Cabut undangan' : 'Kirim ulang undangan'}
        confirmLabel={action?.kind === 'revoke' ? 'Cabut undangan' : 'Kirim ulang'}
        loading={mutation.isPending}
        error={mutation.error}
        onConfirm={() => action && mutation.mutate(action)}
        onClose={() => setAction(null)}
      >
        <p>
          {action?.kind === 'revoke'
            ? `Tautan undangan untuk ${action.invitation.email} tidak dapat dipakai lagi. Anda dapat mengundang email ini kembali kapan saja.`
            : `Email undangan baru dikirim ke ${action?.invitation.email} dan masa berlakunya dimulai ulang. Tautan yang dikirim sebelumnya tidak berlaku lagi.`}
        </p>
      </ConfirmDialog>
    </>
  )
}
