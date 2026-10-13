import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors } from '../../lib/forms'
import { AuthShell } from './AuthShell'

const schema = z.object({
  email: z.email('Masukkan email yang valid.'),
  password: z.string().min(1, 'Password wajib diisi.'),
})
type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const { profile, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })

  if (profile) return <Navigate to="/" replace />

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const p = await login(values.email, values.password)
      const from = (location.state as { from?: string } | null)?.from
      navigate(p.user.must_change_password ? '/ubah-password' : (from ?? (p.user.user_type === 'platform' ? '/platform/organisasi' : '/')), { replace: true })
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  })

  return (
    <AuthShell
      subtitle="Masuk untuk mengelola aset organisasi Anda."
      footer={
        <>
          Belum punya akun?{' '}
          <Link to="/daftar" className="font-medium text-brand-700 hover:underline">
            Daftarkan organisasi
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <Field label="Email" error={formState.errors.email?.message} required>
          {(p) => <Input {...p} type="email" autoComplete="username" autoFocus {...register('email')} />}
        </Field>
        <Field label="Password" error={formState.errors.password?.message} required>
          {(p) => <Input {...p} type="password" autoComplete="current-password" {...register('password')} />}
        </Field>
        <Button type="submit" className="w-full" loading={formState.isSubmitting}>
          Masuk
        </Button>
      </form>
    </AuthShell>
  )
}
