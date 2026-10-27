import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Key, Plus } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { LicenseCode, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'

type Action = { kind: 'release' | 'revoke'; code: LicenseCode }

export function LicenseCodesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('')
  const [action, setAction] = useState<Action | null>(null)
  const [creating, setCreating] = useState(false)
  const [page, setPage] = useState(1)
  const canManage = can('user.create') && can('role.manage')

  const query = useQuery({
    queryKey: ['license-codes', { page, status }],
    queryFn: () => api.get<Paginated<LicenseCode>>(`/license-codes${qs({ page, status })}`),
    placeholderData: keepPreviousData,
  })

  const mutation = useMutation({
    mutationFn: ({ kind, code }: Action) => api.post<Resource<LicenseCode>>(`/license-codes/${code.id}/${kind}`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['license-codes'] })
      setAction(null)
    },
  })
  const confirm = (next: Action) => (mutation.reset(), setAction(next))

  const columns: Column<LicenseCode>[] = [
    {
      key: 'code',
      header: 'Kode',
      cell: (c) => (
        <div>
          <p className="font-mono font-medium text-slate-900">{c.code}</p>
          {c.notes && <p className="text-xs text-slate-500">{c.notes}</p>}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (c) => (
        <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
          c.status === 'available' ? 'bg-green-100 text-green-800' :
          c.status === 'assigned' ? 'bg-blue-100 text-blue-800' :
          'bg-red-100 text-red-800'
        }`}>
          {c.status === 'available' ? 'Tersedia' :
           c.status === 'assigned' ? 'Ditugaskan' :
           'Dicabut'}
        </span>
      ),
    },
    {
      key: 'assigned_user',
      header: 'Ditugaskan ke',
      cell: (c) => c.assigned_user ? (
        <div>
          <p className="font-medium text-slate-900">{c.assigned_user.name}</p>
          <p className="text-xs text-slate-500">{c.assigned_user.email}</p>
        </div>
      ) : '—',
      hideOnMobile: true,
    },
    {
      key: 'assigned_at',
      header: 'Ditugaskan pada',
      cell: (c) => c.assigned_at ? formatDateTime(c.assigned_at) : '—',
      hideOnMobile: true,
    },
    {
      key: 'created_at',
      header: 'Dibuat pada',
      cell: (c) => formatDateTime(c.created_at),
      hideOnMobile: true,
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: 'Tindakan',
            cell: (c: LicenseCode) =>
              c.status === 'revoked' ? null : (
                <div className="flex gap-2">
                  {c.status === 'assigned' && (
                    <Button variant="secondary" size="sm" onClick={() => confirm({ kind: 'release', code: c })}>
                      Lepaskan
                    </Button>
                  )}
                  <Button variant="secondary" size="sm" onClick={() => confirm({ kind: 'revoke', code: c })}>
                    Cabut
                  </Button>
                </div>
              ),
          },
        ]
      : []),
  ]

  return (
    <>
      <PageHeader
        title="Kode Lisensi"
        actions={
          canManage && (
            <button
              type="button"
              className="inline-flex items-center gap-2 rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
              onClick={() => setCreating(true)}
            >
              <Plus className="size-4" aria-hidden />
              Buat Kode Lisensi
            </button>
          )
        }
      />
      <Card>
        <div className="mb-4 flex gap-4">
          <Select value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))} aria-label="Filter status">
            <option value="">Status: semua</option>
            <option value="available">Tersedia</option>
            <option value="assigned">Ditugaskan</option>
            <option value="revoked">Dicabut</option>
          </Select>
        </div>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState
            icon={Key}
            title="Belum ada kode lisensi"
            description="Buat kode lisensi untuk mulai mengelola akses pengguna."
          />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} />
            <Pagination meta={query.data!.meta} onPage={setPage} />
          </>
        )}
      </Card>
      <CreateModal open={creating} onClose={() => setCreating(false)} onSuccess={() => queryClient.invalidateQueries({ queryKey: ['license-codes'] })} />
      <ActionModal
        open={action !== null}
        action={action}
        onClose={() => setAction(null)}
        onConfirm={() => action && mutation.mutate(action)}
        pending={mutation.isPending}
        error={mutation.isError ? errorMessage(mutation.error) : null}
      />
    </>
  )
}

function CreateModal({ open, onClose, onSuccess }: { open: boolean; onClose: () => void; onSuccess: () => void }) {
  const [formError, setFormError] = useState<string | null>(null)
  const [count, setCount] = useState(1)
  const [notes, setNotes] = useState('')

  const mutation = useMutation({
    mutationFn: (data: { count?: number; notes?: string }) => api.post('/license-codes', data),
    onSuccess: () => {
      onSuccess()
      onClose()
      setCount(1)
      setNotes('')
    },
    onError: (e) => setFormError(errorMessage(e)),
  })

  const onSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setFormError(null)
    mutation.mutate({ count, notes: notes || undefined })
  }

  return (
    <Modal
      size="full"
      open={open}
      title="Buat Kode Lisensi"
      onClose={onClose}
      footer={
        <>
          <button type="button" className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" onClick={onClose}>
            Batal
          </button>
          <button type="button" className="rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" onClick={onSubmit} disabled={mutation.isPending}>
            Buat
          </button>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <Field label="Jumlah Kode" required>
          {(p) => <Input {...p} type="number" min="1" max="100" value={count} onChange={(e) => setCount(Number(e.target.value))} />}
        </Field>
        <Field label="Catatan">
          {(p) => <Input {...p} value={notes} onChange={(e) => setNotes(e.target.value)} />}
        </Field>
      </form>
    </Modal>
  )
}

function ActionModal({
  open,
  action,
  onClose,
  onConfirm,
  pending,
  error,
}: {
  open: boolean
  action: Action | null
  onClose: () => void
  onConfirm: () => void
  pending: boolean
  error: string | null
}) {
  if (!action) return null

  return (
    <Modal
      open={open}
      title={action.kind === 'revoke' ? 'Cabut kode lisensi' : 'Lepaskan kode lisensi'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button variant={action.kind === 'revoke' ? 'danger' : 'primary'} loading={pending} onClick={onConfirm}>
            {action.kind === 'revoke' ? 'Cabut kode' : 'Lepaskan kode'}
          </Button>
        </>
      }
    >
      {error && <Alert tone="error">{error}</Alert>}
      <p className="text-sm text-slate-700">
        {action.kind === 'revoke'
          ? `Kode lisensi ${action.code.code} akan dicabut permanen dan tidak dapat digunakan lagi.`
          : `Kode lisensi ${action.code.code} akan dilepas dari ${action.code.assigned_user?.name ?? 'penggunanya'} dan dapat dipakai kembali untuk pengguna lain.`}
      </p>
    </Modal>
  )
}
