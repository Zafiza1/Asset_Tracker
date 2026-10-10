import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { api } from '../../lib/api/client'
import type { Resource, User } from '../../lib/api/types'
import { applyApiErrors, passwordRule } from '../../lib/forms'
import { useRoles } from './api'

const schema = z.object({
  name: z.string().min(1, 'Nama wajib diisi.').max(150),
  email: z.email('Masukkan email yang valid.'),
  employee_number: z.string().max(50).optional(),
  job_title: z.string().max(100).optional(),
  phone: z.string().max(30).optional(),
  password: z.string().refine(passwordRule.test, passwordRule.message),
  role_ids: z.array(z.string()).min(1, 'Pilih minimal satu role.'),
})
type FormValues = z.infer<typeof schema>

export function UserCreatePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const roles = useRoles()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { role_ids: [] } })

  const mutation = useMutation({
    // New users start with organization-wide scope; it can be narrowed on the detail page.
    mutationFn: (v: FormValues) => api.post<Resource<User>>('/users', { ...v, scopes: [{ scope_type: 'organization' }] }),
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      navigate(`/pengguna/${res.data.id}`, { replace: true })
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  if (roles.isLoading) return <Spinner />
  if (roles.isError) return <ErrorState error={roles.error} />

  return (
    <>
      <PageHeader title="Tambah Pengguna" description="Pengguna wajib mengganti password sementara saat login pertama." />
      <Card>
        <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate className="grid gap-4 p-4 sm:grid-cols-2">
          {formError && (
            <div className="sm:col-span-2">
              <Alert tone="error">{formError}</Alert>
            </div>
          )}
          <Field label="Nama lengkap" error={formState.errors.name?.message} required>
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
          <Field label="Password sementara" error={formState.errors.password?.message} hint={passwordRule.message} required>
            {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
          </Field>
          <fieldset className="sm:col-span-2">
            <legend className="mb-2 text-sm font-medium text-slate-700">
              Role <span className="text-red-600">*</span>
            </legend>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {roles.data!.map((role) => (
                <label key={role.id} className="flex items-start gap-2 rounded-md p-2 ring-1 ring-slate-200 hover:bg-slate-50">
                  <input type="checkbox" value={role.id} className="mt-0.5 size-4 rounded border-slate-300 text-brand-700" {...register('role_ids')} />
                  <span className="text-sm">
                    <span className="font-medium text-slate-800">{role.name}</span>
                    {role.description && <span className="block text-xs text-slate-500">{role.description}</span>}
                  </span>
                </label>
              ))}
            </div>
            {formState.errors.role_ids && <p className="mt-1 text-sm text-red-600">{formState.errors.role_ids.message}</p>}
          </fieldset>
          <div className="flex justify-end gap-2 sm:col-span-2">
            <Button variant="secondary" onClick={() => navigate('/pengguna')}>
              Batal
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              Simpan pengguna
            </Button>
          </div>
        </form>
      </Card>
    </>
  )
}
