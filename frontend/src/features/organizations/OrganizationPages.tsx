import { zodResolver } from '@hookform/resolvers/zod'
import { Building2, ChevronRight, Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useNavigate } from 'react-router'
import { z } from 'zod'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, organizationCodeRule, suggestCode } from '../../lib/forms'
import { AuthShell } from '../auth/AuthShell'

/** Shown after login when the account belongs to several organizations and none is active yet. */
export function SelectOrganizationPage() {
  const { profile, switchOrganization, logout } = useAuth()
  const navigate = useNavigate()
  const [pending, setPending] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (!profile || profile.user.user_type !== 'tenant') return <Navigate to="/" replace />

  const choose = async (id: string) => {
    if (id === profile.organization?.id) {
      navigate('/', { replace: true })
      return
    }
    setPending(id)
    setError(null)
    try {
      await switchOrganization(id)
      navigate('/', { replace: true })
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Terjadi kesalahan yang tidak terduga.')
    } finally {
      setPending(null)
    }
  }

  return (
    <AuthShell
      subtitle={`Masuk sebagai ${profile.user.email}. Pilih organisasi yang ingin Anda kelola.`}
      footer={
        <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => void logout().then(() => navigate('/login', { replace: true }))}>
          Keluar
        </button>
      }
    >
      <div className="space-y-3">
        {error && <Alert tone="error">{error}</Alert>}
        {profile.organizations.length === 0 && <Alert tone="info">Akun Anda belum terdaftar di organisasi aktif mana pun.</Alert>}
        <ul className="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200">
          {profile.organizations.map((o) => (
            <li key={o.id}>
              <button
                type="button"
                disabled={pending !== null}
                onClick={() => void choose(o.id)}
                className="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50 disabled:opacity-60"
              >
                <Building2 className="size-5 shrink-0 text-slate-400" aria-hidden />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-900">{o.name}</span>
                  <span className="block text-xs text-slate-500">{o.code}</span>
                </span>
                {o.id === profile.organization?.id && <Badge tone="blue">Terakhir digunakan</Badge>}
                <ChevronRight className="size-4 text-slate-400" aria-hidden />
              </button>
            </li>
          ))}
        </ul>
        <Link to="/organisasi/baru" className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
          <Plus className="size-4" aria-hidden />
          Buat organisasi baru
        </Link>
      </div>
    </AuthShell>
  )
}

const schema = z.object({
  organization_name: z.string().trim().min(1, 'Nama organisasi wajib diisi.').max(150),
  organization_code: organizationCodeRule,
})
type FormValues = z.infer<typeof schema>

/** A logged-in user creates another organization with the same account and becomes its administrator. */
export function CreateOrganizationPage() {
  const { profile, createOrganization } = useAuth()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [codeEdited, setCodeEdited] = useState(false)
  const { register, handleSubmit, setError, setValue, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })

  if (!profile || profile.user.user_type !== 'tenant') return <Navigate to="/" replace />

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await createOrganization(values)
      navigate('/', { replace: true })
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  })
  const err = formState.errors

  return (
    <AuthShell
      subtitle="Buat organisasi baru dengan akun yang sama. Anda menjadi administratornya dan dapat berpindah antar organisasi kapan saja."
      footer={
        <Link to={profile.organization ? '/' : '/pilih-organisasi'} className="font-medium text-brand-700 hover:underline">
          Batal
        </Link>
      }
    >
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <Field label="Nama organisasi" error={err.organization_name?.message} required>
          {(p) => (
            <Input
              {...p}
              autoFocus
              autoComplete="organization"
              placeholder="mis. PT Maju Jaya"
              {...register('organization_name', { onChange: (e) => !codeEdited && setValue('organization_code', suggestCode(e.target.value)) })}
            />
          )}
        </Field>
        <Field label="Kode organisasi" error={err.organization_code?.message} hint="Singkatan unik, dipakai sebagai identitas organisasi. Tidak dapat diubah." required>
          {(p) => <Input {...p} className={`${p.className} uppercase`} placeholder="mis. MAJU" {...register('organization_code', { onChange: () => setCodeEdited(true) })} />}
        </Field>
        <Button type="submit" className="w-full" loading={formState.isSubmitting}>
          Buat organisasi
        </Button>
      </form>
    </AuthShell>
  )
}
