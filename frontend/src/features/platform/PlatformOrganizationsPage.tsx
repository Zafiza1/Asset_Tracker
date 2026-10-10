import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate, useSearchParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, EmptyState, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { DataTable, Pagination, type Column } from '../../components/ui/Table'
import { api, qs } from '../../lib/api/client'
import type { Organization, Paginated, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, passwordRule } from '../../lib/forms'
import { formatDate, orgStatusLabel } from '../../lib/format'

const columns: Column<Organization>[] = [
  { key: 'code', header: 'Kode', cell: (o) => <span className="font-mono text-xs">{o.code}</span> },
  { key: 'name', header: 'Nama', cell: (o) => o.name },
  { key: 'status', header: 'Status', cell: (o) => <Badge tone={statusTone[o.status]}>{orgStatusLabel[o.status]}</Badge> },
  { key: 'created', header: 'Dibuat', cell: (o) => formatDate(o.created_at), hideOnMobile: true },
]

export function PlatformOrganizationsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const [creating, setCreating] = useState(false)
  const filters = { page: params.get('page'), search: params.get('search') }
  const query = useQuery({
    queryKey: ['platform', 'organizations', filters],
    queryFn: () => api.get<Paginated<Organization>>(`/platform/organizations${qs(filters)}`),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Organisasi"
        description="Seluruh organisasi (tenant) pada platform."
        actions={
          can('platform.organization.manage') && (
            <Button onClick={() => setCreating(true)}>
              <Plus className="size-4" aria-hidden /> Organisasi baru
            </Button>
          )
        }
      />
      <Card>
        <form
          role="search"
          className="flex gap-2 border-b border-slate-200 p-3"
          onSubmit={(e) => {
            e.preventDefault()
            setParams(search ? { search } : {})
          }}
        >
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari kode atau nama" aria-label="Cari organisasi" />
          <Button type="submit" variant="secondary">
            Cari
          </Button>
        </form>
        {query.isLoading ? (
          <Spinner />
        ) : query.isError ? (
          <ErrorState error={query.error} />
        ) : query.data!.data.length === 0 ? (
          <EmptyState title="Belum ada organisasi" />
        ) : (
          <>
            <DataTable columns={columns} rows={query.data!.data} onRowClick={(o) => navigate(`/platform/organisasi/${o.id}`)} />
            <Pagination
              meta={query.data!.meta}
              onPage={(p) => {
                const next = new URLSearchParams(params)
                next.set('page', String(p))
                setParams(next)
              }}
            />
          </>
        )}
      </Card>
      <CreateOrganizationModal open={creating} onClose={() => setCreating(false)} />
    </>
  )
}

interface CreateValues {
  code: string
  name: string
  admin: { name: string; email: string; password: string }
}

function CreateOrganizationModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState, reset } = useForm<CreateValues>()
  const mutation = useMutation({
    mutationFn: (v: CreateValues) => api.post<Resource<Organization>>('/platform/organizations', v),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'organizations'] })
      reset()
      onClose()
      navigate(`/platform/organisasi/${res.data.id}`)
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <Modal open={open} title="Organisasi baru" onClose={onClose}>
      <form onSubmit={handleSubmit((v) => (setFormError(null), mutation.mutate(v)))} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <Field label="Kode" error={formState.errors.code?.message} hint="Huruf kapital/angka, unik, tidak dapat diubah." required>
          {(p) => <Input {...p} {...register('code', { required: 'Kode wajib diisi.' })} />}
        </Field>
        <Field label="Nama organisasi" error={formState.errors.name?.message} required>
          {(p) => <Input {...p} {...register('name', { required: 'Nama wajib diisi.' })} />}
        </Field>
        <fieldset className="space-y-3 rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">Administrator pertama</legend>
          <Field label="Nama" error={formState.errors.admin?.name?.message} required>
            {(p) => <Input {...p} {...register('admin.name', { required: 'Nama wajib diisi.' })} />}
          </Field>
          <Field label="Email" error={formState.errors.admin?.email?.message} required>
            {(p) => <Input {...p} type="email" {...register('admin.email', { required: 'Email wajib diisi.' })} />}
          </Field>
          <Field label="Password sementara" error={formState.errors.admin?.password?.message} hint={passwordRule.message} required>
            {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('admin.password', { validate: (v) => passwordRule.test(v) || passwordRule.message })} />}
          </Field>
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" loading={mutation.isPending}>
            Buat organisasi
          </Button>
        </div>
      </form>
    </Modal>
  )
}
