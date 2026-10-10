import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { Permission, Resource, Role } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage } from '../../lib/forms'
import { permissionGroupLabel } from '../../lib/format'

interface FormValues {
  code: string
  name: string
  description: string
}

/** Create (/role/baru) or view/edit (/role/:id) a role. */
export function RoleFormPage() {
  const { id } = useParams()
  const isNew = id === undefined
  const role = useQuery({ queryKey: ['roles', id], queryFn: () => api.get<Resource<Role>>(`/roles/${id}`), enabled: !isNew })
  const permissions = useQuery({ queryKey: ['permissions'], queryFn: async () => (await api.get<{ data: Permission[] }>('/permissions')).data })

  if (role.isLoading || permissions.isLoading) return <Spinner />
  if (role.isError) return <ErrorState error={role.error} />
  if (permissions.isError) return <ErrorState error={permissions.error} />

  return <RoleForm role={role.data?.data} catalog={permissions.data!} />
}

function RoleForm({ role, catalog }: { role?: Role; catalog: Permission[] }) {
  const { can, profile } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const editable = can('role.manage')
  const permissionsEditable = editable && !role?.is_locked
  const [selected, setSelected] = useState<Set<string>>(new Set(role?.permissions ?? []))
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const own = useMemo(() => new Set(profile?.permissions ?? []), [profile])
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({
    defaultValues: { code: role?.code ?? '', name: role?.name ?? '', description: role?.description ?? '' },
  })

  const groups = useMemo(() => {
    const map = new Map<string, Permission[]>()
    for (const p of catalog) map.set(p.group, [...(map.get(p.group) ?? []), p])
    return [...map.entries()]
  }, [catalog])

  const save = useMutation({
    mutationFn: (v: FormValues) => {
      const body = { name: v.name, description: v.description || null, ...(permissionsEditable ? { permissions: [...selected] } : {}) }
      return role ? api.patch<Resource<Role>>(`/roles/${role.id}`, body) : api.post<Resource<Role>>('/roles', { ...body, code: v.code })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      navigate('/role')
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })
  const remove = useMutation({
    mutationFn: () => api.delete(`/roles/${role!.id}`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      navigate('/role')
    },
  })

  const toggle = (code: string, on: boolean) =>
    setSelected((s) => {
      const next = new Set(s)
      if (on) next.add(code)
      else next.delete(code)
      return next
    })

  return (
    <>
      <PageHeader
        title={role ? role.name : 'Tambah Role'}
        description={role?.is_locked ? 'Role terkunci: permission tidak dapat diubah agar organisasi selalu memiliki administrator.' : undefined}
        actions={
          role &&
          editable &&
          !role.is_locked && (
            <Button variant="danger" onClick={() => setConfirmDelete(true)} disabled={(role.users_count ?? 0) > 0} title={(role.users_count ?? 0) > 0 ? 'Role masih digunakan pengguna' : undefined}>
              Hapus role
            </Button>
          )
        }
      />
      <form onSubmit={handleSubmit((v) => (setFormError(null), save.mutate(v)))} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <Card title="Informasi role">
          <div className="grid gap-4 p-4 sm:grid-cols-3">
            <Field label="Kode" error={formState.errors.code?.message} hint={role ? 'Kode tidak dapat diubah.' : 'Huruf, angka, garis bawah.'} required>
              {(p) => <Input {...p} disabled={!!role || !editable} {...register('code', { required: role ? false : 'Kode wajib diisi.' })} />}
            </Field>
            <Field label="Nama" error={formState.errors.name?.message} required>
              {(p) => <Input {...p} disabled={!editable} {...register('name', { required: 'Nama wajib diisi.' })} />}
            </Field>
            <Field label="Deskripsi" error={formState.errors.description?.message}>
              {(p) => <Input {...p} disabled={!editable} {...register('description')} />}
            </Field>
          </div>
        </Card>
        <Card title={`Permission (${selected.size})`}>
          <div className="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3">
            {groups.map(([group, items]) => (
              <fieldset key={group} className="rounded-md p-3 ring-1 ring-slate-200">
                <legend className="px-1 text-sm font-semibold text-slate-700">{permissionGroupLabel[group] ?? group}</legend>
                <div className="space-y-1.5">
                  {items.map((p) => {
                    // Mirrors the server's anti-escalation rule: only permissions you hold can be granted.
                    const grantable = permissionsEditable && own.has(p.code)
                    return (
                      <label key={p.code} className="flex items-start gap-2 text-sm">
                        <input
                          type="checkbox"
                          className="mt-0.5 size-4 rounded border-slate-300 text-brand-700 disabled:opacity-50"
                          checked={selected.has(p.code)}
                          disabled={!grantable}
                          onChange={(e) => toggle(p.code, e.target.checked)}
                        />
                        <span>
                          <span className="text-slate-800">{p.description}</span>
                          <span className="block font-mono text-xs text-slate-400">{p.code}</span>
                        </span>
                      </label>
                    )
                  })}
                </div>
              </fieldset>
            ))}
          </div>
        </Card>
        {editable && (
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => navigate('/role')}>
              Batal
            </Button>
            <Button type="submit" loading={save.isPending}>
              Simpan role
            </Button>
          </div>
        )}
      </form>
      <Modal
        open={confirmDelete}
        title="Hapus role?"
        onClose={() => setConfirmDelete(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirmDelete(false)}>
              Batal
            </Button>
            <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
              Hapus
            </Button>
          </>
        }
      >
        {remove.isError && <Alert tone="error">{errorMessage(remove.error)}</Alert>}
        <p className="text-sm text-slate-700">Role “{role?.name}” akan dihapus. Tindakan ini tercatat di audit trail.</p>
      </Modal>
    </>
  )
}
