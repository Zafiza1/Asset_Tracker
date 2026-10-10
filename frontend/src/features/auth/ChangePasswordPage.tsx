import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { api } from '../../lib/api/client'
import type { Profile, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, passwordRule } from '../../lib/forms'

const schema = z
  .object({
    current_password: z.string().min(1, 'Password saat ini wajib diisi.'),
    password: z.string().refine(passwordRule.test, passwordRule.message),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi password tidak sama.' })
  .refine((v) => v.password !== v.current_password, { path: ['password'], message: 'Password baru harus berbeda dari password saat ini.' })
type FormValues = z.infer<typeof schema>

export function ChangePasswordPage() {
  const { profile, setProfile } = useAuth()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })
  const forced = profile?.user.must_change_password

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const updated = (await api.put<Resource<Profile>>('/auth/password', values)).data
      setProfile(updated)
      navigate(updated.user.user_type === 'platform' ? '/platform/organisasi' : '/', { replace: true })
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  })

  const form = (
    <form onSubmit={onSubmit} noValidate className="space-y-4 p-4">
      {forced && <Alert tone="warning">Anda menggunakan password sementara. Silakan buat password baru untuk melanjutkan.</Alert>}
      {formError && <Alert tone="error">{formError}</Alert>}
      <Field label="Password saat ini" error={formState.errors.current_password?.message} required>
        {(p) => <Input {...p} type="password" autoComplete="current-password" {...register('current_password')} />}
      </Field>
      <Field label="Password baru" error={formState.errors.password?.message} hint={passwordRule.message} required>
        {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
      </Field>
      <Field label="Konfirmasi password baru" error={formState.errors.password_confirmation?.message} required>
        {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password_confirmation')} />}
      </Field>
      <div className="flex justify-end">
        <Button type="submit" loading={formState.isSubmitting}>
          Simpan password
        </Button>
      </div>
    </form>
  )

  return (
    <div className="max-w-lg">
      <PageHeader title="Ubah Password" description="Sesi lain pada akun ini akan diakhiri setelah password diganti." />
      <Card>{form}</Card>
    </div>
  )
}
