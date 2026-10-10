import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { Resource, User } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage, passwordRule } from '../../lib/forms'
import { formatDateTime, scopeTypeLabel, userStatusLabel } from '../../lib/format'
import { useRoles } from './api'

export function UserDetailPage() {
  const { id } = useParams()
  const { can, profile } = useAuth()
  const query = useQuery({ queryKey: ['users', id], queryFn: () => api.get<Resource<User>>(`/users/${id}`) })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const user = query.data!.data
  const isSelf = user.id === profile?.user.id

  return (
    <>
      <PageHeader
        title={user.name}
        description={user.email}
        actions={
          <>
            <Badge tone={statusTone[user.status]}>{userStatusLabel[user.status]}</Badge>
            {can('user.deactivate') && !isSelf && <StatusActions user={user} />}
            {can('user.update') && <ResetPasswordButton user={user} />}
          </>
        }
      />
      <div className="grid gap-4 lg:grid-cols-2">
        <ProfileCard user={user} editable={can('user.update')} />
        <div className="space-y-4">
          <RolesCard user={user} editable={can('role.manage')} />
          <ScopesCard user={user} editable={can('user.update')} />
        </div>
      </div>
    </>
  )
}

function useUserMutation<TBody>(user: User, method: 'patch' | 'put' | 'post', path: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (body: TBody) => api[method]<Resource<User>>(`/users/${user.id}${path}`, body),
    onSuccess: (res) => {
      if (res) queryClient.setQueryData(['users', user.id], res)
      void queryClient.invalidateQueries({ queryKey: ['users'], exact: false })
    },
  })
}

type ProfileValues = Pick<User, 'name' | 'email' | 'employee_number' | 'job_title' | 'phone'>

function ProfileCard({ user, editable }: { user: User; editable: boolean }) {
  const [editing, setEditing] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState, reset } = useForm<ProfileValues>({ defaultValues: user })
  const mutation = useUserMutation<ProfileValues>(user, 'patch', '')

  const submit = handleSubmit((values) =>
    mutation.mutate(values, {
      onSuccess: (res) => {
        reset(res.data)
        setEditing(false)
      },
      onError: (e) => setFormError(applyApiErrors(e, setError)),
    }),
  )

  return (
    <Card title="Profil" actions={editable && !editing && <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>Ubah</Button>}>
      {editing ? (
        <form onSubmit={submit} noValidate className="space-y-3 p-4">
          {formError && <Alert tone="error">{formError}</Alert>}
          <Field label="Nama" error={formState.errors.name?.message} required>
            {(p) => <Input {...p} {...register('name')} />}
          </Field>
          <Field label="Email" error={formState.errors.email?.message} required>
            {(p) => <Input {...p} type="email" {...register('email')} />}
          </Field>
          <Field label="Nomor induk karyawan" error={formState.errors.employee_number?.message}>
            {(p) => <Input {...p} {...register('employee_number')} />}
          </Field>
          <Field label="Jabatan" error={formState.errors.job_title?.message}>
            {(p) => <Input {...p} {...register('job_title')} />}
          </Field>
          <Field label="Telepon" error={formState.errors.phone?.message}>
            {(p) => <Input {...p} {...register('phone')} />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => (reset(user), setEditing(false))}>
              Batal
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              Simpan
            </Button>
          </div>
        </form>
      ) : (
        <dl className="grid gap-3 p-4 text-sm sm:grid-cols-2">
          {(
            [
              ['Nomor induk karyawan', user.employee_number],
              ['Jabatan', user.job_title],
              ['Telepon', user.phone],
              ['Login terakhir', formatDateTime(user.last_login_at)],
              ['Dibuat', formatDateTime(user.created_at)],
              ['Wajib ganti password', user.must_change_password ? 'Ya' : 'Tidak'],
            ] as const
          ).map(([label, value]) => (
            <div key={label}>
              <dt className="text-slate-500">{label}</dt>
              <dd className="text-slate-800">{value || '—'}</dd>
            </div>
          ))}
        </dl>
      )}
    </Card>
  )
}

function RolesCard({ user, editable }: { user: User; editable: boolean }) {
  const [editing, setEditing] = useState(false)
  const [selected, setSelected] = useState<string[]>(user.roles?.map((r) => r.id) ?? [])
  const roles = useRoles(editing)
  const mutation = useUserMutation<{ role_ids: string[] }>(user, 'put', '/roles')

  return (
    <Card title="Role" actions={editable && !editing && <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>Ubah role</Button>}>
      <div className="p-4">
        {mutation.isError && (
          <div className="mb-3">
            <Alert tone="error">{errorMessage(mutation.error)}</Alert>
          </div>
        )}
        {!editing ? (
          <div className="flex flex-wrap gap-2">
            {user.roles?.length ? user.roles.map((r) => <Badge key={r.id} tone="blue">{r.name}</Badge>) : <p className="text-sm text-slate-500">Belum memiliki role.</p>}
          </div>
        ) : roles.isLoading ? (
          <Spinner />
        ) : (
          <div className="space-y-3">
            <div className="grid gap-2 sm:grid-cols-2">
              {roles.data?.map((role) => (
                <label key={role.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-slate-300 text-brand-700"
                    checked={selected.includes(role.id)}
                    onChange={(e) => setSelected((s) => (e.target.checked ? [...s, role.id] : s.filter((x) => x !== role.id)))}
                  />
                  {role.name}
                </label>
              ))}
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setEditing(false)}>
                Batal
              </Button>
              <Button loading={mutation.isPending} onClick={() => mutation.mutate({ role_ids: selected }, { onSuccess: () => setEditing(false) })}>
                Simpan role
              </Button>
            </div>
          </div>
        )}
      </div>
    </Card>
  )
}

function ScopesCard({ user, editable }: { user: User; editable: boolean }) {
  const mutation = useUserMutation<{ scopes: { scope_type: string }[] }>(user, 'put', '/scopes')
  const scopes = user.data_scopes ?? []
  const organizationWide = scopes.some((s) => s.scope_type === 'organization')

  return (
    <Card title="Cakupan data">
      <div className="space-y-3 p-4 text-sm">
        {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
        {scopes.length === 0 ? (
          <p className="text-slate-500">Tidak ada cakupan data: pengguna tidak dapat melihat data aset dan transaksi.</p>
        ) : (
          <ul className="list-inside list-disc text-slate-700">
            {scopes.map((s) => (
              <li key={`${s.scope_type}:${s.ref_id}`}>
                {scopeTypeLabel[s.scope_type]}
                {s.ref_id && <span className="text-slate-500"> ({s.ref_id.slice(0, 8)}…)</span>}
              </li>
            ))}
          </ul>
        )}
        <p className="text-xs text-slate-500">Pembatasan per cabang, departemen, atau lokasi dapat diatur setelah data master tersebut dibuat.</p>
        {editable && (
          <div className="flex justify-end">
            {organizationWide ? (
              <Button variant="secondary" size="sm" loading={mutation.isPending} onClick={() => mutation.mutate({ scopes: [] })}>
                Cabut akses seluruh organisasi
              </Button>
            ) : (
              <Button variant="secondary" size="sm" loading={mutation.isPending} onClick={() => mutation.mutate({ scopes: [{ scope_type: 'organization' }] })}>
                Beri akses seluruh organisasi
              </Button>
            )}
          </div>
        )}
      </div>
    </Card>
  )
}

const statusActions = {
  suspend: { label: 'Tangguhkan', confirm: 'Pengguna tidak dapat login sampai diaktifkan kembali. Sesi aktif akan diakhiri.', variant: 'secondary' as const },
  activate: { label: 'Aktifkan', confirm: 'Pengguna dapat login kembali.', variant: 'secondary' as const },
  deactivate: { label: 'Nonaktifkan permanen', confirm: 'Akun dinonaktifkan permanen dan tidak dapat diaktifkan kembali. Riwayat tetap tersimpan.', variant: 'danger' as const },
}

function StatusActions({ user }: { user: User }) {
  const [action, setAction] = useState<keyof typeof statusActions | null>(null)
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: (a: keyof typeof statusActions) => api.post<Resource<User>>(`/users/${user.id}/${a}`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      setAction(null)
    },
  })
  if (user.status === 'deactivated') return null

  const available: (keyof typeof statusActions)[] = user.status === 'active' ? ['suspend', 'deactivate'] : ['activate', 'deactivate']

  return (
    <>
      {available.map((a) => (
        <Button key={a} variant={statusActions[a].variant} onClick={() => (mutation.reset(), setAction(a))}>
          {statusActions[a].label}
        </Button>
      ))}
      <Modal
        open={action !== null}
        title={action ? `${statusActions[action].label} pengguna?` : ''}
        onClose={() => setAction(null)}
        footer={
          action && (
            <>
              <Button variant="secondary" onClick={() => setAction(null)}>
                Batal
              </Button>
              <Button variant={statusActions[action].variant === 'danger' ? 'danger' : 'primary'} loading={mutation.isPending} onClick={() => mutation.mutate(action)}>
                {statusActions[action].label}
              </Button>
            </>
          )
        }
      >
        {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
        <p className="text-sm text-slate-700">{action && statusActions[action].confirm}</p>
      </Modal>
    </>
  )
}

function ResetPasswordButton({ user }: { user: User }) {
  const [open, setOpen] = useState(false)
  const [done, setDone] = useState(false)
  const { register, handleSubmit, setError, formState, reset } = useForm<{ password: string }>()
  const [formError, setFormError] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: (v: { password: string }) => api.post(`/users/${user.id}/reset-password`, v),
    onSuccess: () => (setDone(true), reset()),
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <>
      <Button variant="secondary" onClick={() => (setOpen(true), setDone(false), setFormError(null))}>
        Reset password
      </Button>
      <Modal open={open} title="Reset password" onClose={() => setOpen(false)}>
        {done ? (
          <Alert tone="success">Password sementara disimpan. Pengguna wajib menggantinya saat login berikutnya.</Alert>
        ) : (
          <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate className="space-y-4">
            {formError && <Alert tone="error">{formError}</Alert>}
            <Field label="Password sementara" error={formState.errors.password?.message} hint={passwordRule.message} required>
              {(p) => (
                <Input {...p} type="password" autoComplete="new-password" {...register('password', { validate: (v) => passwordRule.test(v) || passwordRule.message })} />
              )}
            </Field>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setOpen(false)}>
                Batal
              </Button>
              <Button type="submit" loading={mutation.isPending}>
                Simpan
              </Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}
