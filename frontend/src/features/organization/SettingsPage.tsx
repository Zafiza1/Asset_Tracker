import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { api } from '../../lib/api/client'
import type { OrganizationSettings, Resource } from '../../lib/api/types'
import { applyApiErrors } from '../../lib/forms'

type FormValues = Omit<OrganizationSettings, 'updated_at'>

export function SettingsPage() {
  const query = useQuery({ queryKey: ['settings'], queryFn: () => api.get<Resource<OrganizationSettings>>('/settings') })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />

  return (
    <>
      <PageHeader title="Pengaturan" description="Kebijakan umum organisasi." />
      <SettingsForm settings={query.data!.data} />
    </>
  )
}

function SettingsForm({ settings }: { settings: OrganizationSettings }) {
  const queryClient = useQueryClient()
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ defaultValues: settings })
  const mutation = useMutation({
    mutationFn: (values: FormValues) => api.put<Resource<OrganizationSettings>>('/settings', { ...values, max_upload_mb: Number(values.max_upload_mb) }),
    onSuccess: (res) => {
      queryClient.setQueryData(['settings'], res)
      setMessage({ tone: 'success', text: 'Pengaturan tersimpan.' })
    },
    onError: (e) => {
      const msg = applyApiErrors(e, setError)
      setMessage(msg ? { tone: 'error', text: msg } : null)
    },
  })

  return (
    <Card>
      <form onSubmit={handleSubmit((v) => (setMessage(null), mutation.mutate(v)))} noValidate className="grid gap-4 p-4 sm:grid-cols-2">
        {message && (
          <div className="sm:col-span-2">
            <Alert tone={message.tone}>{message.text}</Alert>
          </div>
        )}
        <Field label="Format nomor aset" error={formState.errors.asset_number_format?.message} hint="Token: {YYYY}, {SEQ:n} (wajib). Contoh: AST-{YYYY}-{SEQ:6}" required>
          {(p) => <Input {...p} {...register('asset_number_format')} />}
        </Field>
        <Field label="Format nomor transaksi" error={formState.errors.transaction_number_format?.message} hint="Token: {TYPE}, {YYYY}, {SEQ:n} (wajib)" required>
          {(p) => <Input {...p} {...register('transaction_number_format')} />}
        </Field>
        <Field label="Batas ukuran unggahan (MB)" error={formState.errors.max_upload_mb?.message} required>
          {(p) => <Input {...p} type="number" min={1} max={50} {...register('max_upload_mb')} />}
        </Field>
        <div className="flex items-start gap-2 pt-6">
          <input id="self-approval" type="checkbox" className="mt-1 size-4 rounded border-slate-300 text-brand-700" {...register('allow_self_approval_default')} />
          <label htmlFor="self-approval" className="text-sm text-slate-700">
            Izinkan pemohon menyetujui transaksinya sendiri (default workflow baru)
            <span className="block text-xs text-slate-500">Disarankan tetap nonaktif untuk kontrol independen.</span>
          </label>
        </div>
        <div className="flex justify-end sm:col-span-2">
          <Button type="submit" loading={mutation.isPending}>
            Simpan pengaturan
          </Button>
        </div>
      </form>
    </Card>
  )
}
