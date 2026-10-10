import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Textarea } from '../../components/ui/Field'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { api } from '../../lib/api/client'
import type { Organization, Resource } from '../../lib/api/types'
import { useAuth, ME_KEY } from '../../lib/auth/context'
import { applyApiErrors } from '../../lib/forms'
import { orgStatusLabel } from '../../lib/format'

type FormValues = Pick<Organization, 'name' | 'legal_name' | 'email' | 'phone' | 'tax_id' | 'address' | 'timezone' | 'currency'>

export function OrganizationPage() {
  const { can } = useAuth()
  const query = useQuery({ queryKey: ['organization'], queryFn: () => api.get<Resource<Organization>>('/organization') })
  const [editing, setEditing] = useState(false)

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const org = query.data!.data

  return (
    <>
      <PageHeader
        title="Profil Organisasi"
        description="Identitas perusahaan yang digunakan pada seluruh modul."
        actions={can('organization.manage') && !editing && <Button onClick={() => setEditing(true)}>Ubah profil</Button>}
      />
      {editing ? (
        <OrganizationForm org={org} onDone={() => setEditing(false)} />
      ) : (
        <Card>
          <dl className="grid gap-4 p-4 text-sm sm:grid-cols-2">
            <Item label="Kode" value={org.code} />
            <Item label="Status" value={<Badge tone={statusTone[org.status]}>{orgStatusLabel[org.status]}</Badge>} />
            <Item label="Nama" value={org.name} />
            <Item label="Nama legal" value={org.legal_name} />
            <Item label="Email" value={org.email} />
            <Item label="Telepon" value={org.phone} />
            <Item label="NPWP" value={org.tax_id} />
            <Item label="Zona waktu" value={org.timezone} />
            <Item label="Mata uang" value={org.currency} />
            <Item label="Alamat" value={org.address} />
          </dl>
        </Card>
      )}
    </>
  )
}

function Item({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-0.5 whitespace-pre-line text-slate-800">{value || '—'}</dd>
    </div>
  )
}

function OrganizationForm({ org, onDone }: { org: Organization; onDone: () => void }) {
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ defaultValues: org })
  const mutation = useMutation({
    mutationFn: (values: FormValues) => api.put<Resource<Organization>>('/organization', values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['organization'] })
      await queryClient.invalidateQueries({ queryKey: ME_KEY })
      onDone()
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  return (
    <Card>
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate className="grid gap-4 p-4 sm:grid-cols-2">
        {formError && (
          <div className="sm:col-span-2">
            <Alert tone="error">{formError}</Alert>
          </div>
        )}
        <Field label="Nama" error={formState.errors.name?.message} required>
          {(p) => <Input {...p} {...register('name', { required: 'Nama wajib diisi.' })} />}
        </Field>
        <Field label="Nama legal" error={formState.errors.legal_name?.message}>
          {(p) => <Input {...p} {...register('legal_name')} />}
        </Field>
        <Field label="Email" error={formState.errors.email?.message}>
          {(p) => <Input {...p} type="email" {...register('email')} />}
        </Field>
        <Field label="Telepon" error={formState.errors.phone?.message}>
          {(p) => <Input {...p} {...register('phone')} />}
        </Field>
        <Field label="NPWP" error={formState.errors.tax_id?.message}>
          {(p) => <Input {...p} {...register('tax_id')} />}
        </Field>
        <Field label="Zona waktu" error={formState.errors.timezone?.message} hint="Contoh: Asia/Jakarta" required>
          {(p) => <Input {...p} {...register('timezone')} />}
        </Field>
        <Field label="Mata uang" error={formState.errors.currency?.message} hint="Kode ISO 4217, contoh: IDR" required>
          {(p) => <Input {...p} maxLength={3} {...register('currency')} />}
        </Field>
        <div className="sm:col-span-2">
          <Field label="Alamat" error={formState.errors.address?.message}>
            {(p) => <Textarea {...p} rows={3} {...register('address')} />}
          </Field>
        </div>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button variant="secondary" onClick={onDone}>
            Batal
          </Button>
          <Button type="submit" loading={mutation.isPending}>
            Simpan
          </Button>
        </div>
      </form>
    </Card>
  )
}
