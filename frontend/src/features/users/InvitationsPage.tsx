import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MailPlus, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { ActiveFilters, FilterGrid, FilterPanel, SearchForm } from '../../components/ui/Filters'
import { searchChip, type FilterChip } from '../../components/ui/filterChips'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { OrganizationInvitation, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
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
      setNotice(kind === 'resend' ? `Undangan baru dikirim ke ${res.data.email}. Tautan sebelumnya tidak berlaku lagi.` : `Undangan untuk ${res.data.email} dicabut.`)
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
    { key: 'status', header: 'Status', cell: (i) => <Badge tone={statusTone[i.status]}>{invitationStatusLabel[i.status]}</Badge> },
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
                  <Button variant="secondary" size="sm" onClick={() => confirm({ kind: 'revoke', invitation: i })}>
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
            <Link to="/pengguna/baru">
              <Button>
                <Plus className="size-4" aria-hidden /> Undang pengguna
              </Button>
            </Link>
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
          <FilterGrid>
            <Select value={status} onChange={(e) => update({ status: e.target.value, page: '' })} aria-label="Filter status">
              <option value="">Status: semua</option>
              {Object.entries(invitationStatusLabel).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </FilterGrid>
          <ActiveFilters chips={chips} onReset={() => (setSearch(''), setParams(new URLSearchParams(), { replace: true }))} />
        </FilterPanel>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={MailPlus}
            title={chips.length > 0 ? 'Tidak ada undangan yang cocok' : 'Belum ada undangan'}
            description={chips.length > 0 ? 'Ubah kata kunci atau filter pencarian.' : 'Undang pengguna untuk memberi mereka akses ke organisasi ini.'}
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} />
            <Pagination meta={query.data!.meta} onPage={(p) => update({ page: String(p) })} />
          </>
        )}
      </Card>
      <Modal
        open={action !== null}
        title={action?.kind === 'revoke' ? 'Cabut undangan' : 'Kirim ulang undangan'}
        onClose={() => setAction(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>
              Batal
            </Button>
            <Button variant={action?.kind === 'revoke' ? 'danger' : 'primary'} loading={mutation.isPending} onClick={() => action && mutation.mutate(action)}>
              {action?.kind === 'revoke' ? 'Cabut undangan' : 'Kirim ulang'}
            </Button>
          </>
        }
      >
        {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
        <p className="text-sm text-slate-700">
          {action?.kind === 'revoke'
            ? `Tautan undangan untuk ${action.invitation.email} tidak dapat dipakai lagi. Anda dapat mengundang email ini kembali kapan saja.`
            : `Email undangan baru dikirim ke ${action?.invitation.email} dan masa berlakunya dimulai ulang. Tautan yang dikirim sebelumnya tidak berlaku lagi.`}
        </p>
      </Modal>
    </>
  )
}
