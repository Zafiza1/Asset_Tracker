import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { api } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage } from '../../lib/forms'

const schema = z.object({
  email: z.email('Masukkan email yang valid.'),
  password: z.string().min(1, 'Password wajib diisi.'),
})
type FormValues = z.infer<typeof schema>

/**
 * Login without leaving the page, for pages that hold a one-time secret (an invitation token,
 * a verification link) only in memory: navigating to /login would lose it.
 */
export function InlineLoginForm({ defaultEmail, submitLabel = 'Masuk' }: { defaultEmail?: string; submitLabel?: string }) {
  const { login } = useAuth()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: defaultEmail ?? '' } })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await login(values.email, values.password)
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="error">{formError}</Alert>}
      <Field label="Email" error={formState.errors.email?.message} required>
        {(p) => <Input {...p} type="email" autoComplete="username" {...register('email')} />}
      </Field>
      <Field label="Password" error={formState.errors.password?.message} required>
        {(p) => <Input {...p} type="password" autoComplete="current-password" {...register('password')} />}
      </Field>
      <Button type="submit" className="w-full" loading={formState.isSubmitting}>
        {submitLabel}
      </Button>
    </form>
  )
}

/** Sends a new verification link to the logged-in account's address (rate limited by the API). */
export function ResendVerification({ email }: { email: string }) {
  const mutation = useMutation({ mutationFn: () => api.post<{ data: { verified: boolean; sent?: boolean } }>('/auth/email/verification-notification') })

  if (mutation.isSuccess) {
    return mutation.data.data.verified ? (
      <Alert tone="success">Email Anda sudah terverifikasi. Muat ulang halaman ini.</Alert>
    ) : (
      <Alert tone="success">Tautan verifikasi dikirim ke {email}. Buka tautan tersebut saat masuk dengan akun ini.</Alert>
    )
  }
  return (
    <div className="space-y-2">
      {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
      <Button variant="secondary" className="w-full" loading={mutation.isPending} onClick={() => mutation.mutate()}>
        Kirim tautan verifikasi
      </Button>
    </div>
  )
}
