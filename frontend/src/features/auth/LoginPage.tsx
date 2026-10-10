import { zodResolver } from '@hookform/resolvers/zod'
import { Boxes } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors } from '../../lib/forms'

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
    <div className="flex min-h-dvh items-center justify-center bg-gradient-to-br from-brand-50 to-white px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center gap-2 text-center">
          <span className="rounded-xl bg-brand-700 p-2.5 text-white">
            <Boxes className="size-7" aria-hidden />
          </span>
          <h1 className="text-xl font-semibold text-slate-900">Asset Tracker Enterprise</h1>
          <p className="text-sm text-slate-600">Masuk untuk mengelola aset organisasi Anda.</p>
        </div>
        <form onSubmit={onSubmit} noValidate className="space-y-4 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
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
      </div>
    </div>
  )
}
