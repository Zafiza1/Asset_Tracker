import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MailX } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Alert, EmptyState, Spinner } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { api, ApiError } from '../../lib/api/client'
import type { InvitationPreview, OrganizationSummary, Profile, Resource } from '../../lib/api/types'
import { landingPath, ME_KEY, useAuth } from '../../lib/auth/context'
import { applyApiErrors, errorMessage, passwordRule } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import { AuthShell } from './AuthShell'
import { InlineLoginForm, ResendVerification } from './InlineLogin'

/** The token arrives in the URL fragment (#token=...), which browsers never send to a server. */
function tokenFromHash(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token')
  return token && token.length <= 128 ? token : null
}

/** Why an invitation link cannot be used, by API error code. */
const closedReasons: Record<string, { title: string; description: string }> = {
  INVITATION_INVALID: { title: 'Tautan undangan tidak valid', description: 'Periksa kembali tautan di email undangan, atau minta administrator mengirim undangan baru.' },
  INVITATION_EXPIRED: { title: 'Undangan sudah kedaluwarsa', description: 'Minta Admin organisasi untuk mengirim ulang undangan.' },
  INVITATION_REVOKED: { title: 'Undangan sudah dicabut', description: 'Admin organisasi telah membatalkan undangan ini.' },
  INVITATION_USED: { title: 'Undangan sudah digunakan', description: 'Undangan ini sudah diterima. Masuk untuk membuka organisasi tersebut.' },
  INVITATION_UNAVAILABLE: { title: 'Undangan tidak dapat diterima', description: 'Undangan ini tidak berlaku lagi. Minta Admin organisasi mengirim undangan baru.' },
  INVITATION_CLOSED: { title: 'Undangan tidak dapat diterima', description: 'Undangan ini tidak berlaku lagi. Minta Admin organisasi mengirim undangan baru.' },
}

/**
 * Opens an invitation from its email. The token lives only in this page's memory: it is taken
 * out of the address bar (and history) at once and is never stored in the browser. Login and
 * sign-up therefore happen here, without navigating away.
 */
export function InvitationPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const [token] = useState(() => tokenFromHash(location.hash))
  const [preview, setPreview] = useState<InvitationPreview | null>(null)
  const [error, setError] = useState<unknown>(null)

  // Drop the token from the address bar; this page keeps it in state.
  useEffect(() => {
    if (location.hash) navigate({ pathname: location.pathname, search: location.search }, { replace: true })
  }, [location.hash, location.pathname, location.search, navigate])

  useEffect(() => {
    if (!token) return
    let active = true
    api
      .post<Resource<InvitationPreview>>('/auth/invitation/inspect', { token })
      .then((res) => active && setPreview(res.data))
      .catch((e: unknown) => active && setError(e))
    return () => {
      active = false
    }
  }, [token])

  const code = !token ? 'INVITATION_INVALID' : error instanceof ApiError ? error.code : null
  const closed = code ? closedReasons[code] : undefined

  return (
    <AuthShell wide subtitle="Undangan bergabung dengan organisasi.">
      {closed ? (
        <ClosedInvitation {...closed} />
      ) : error ? (
        <div className="space-y-4">
          <Alert tone="error">{errorMessage(error)}</Alert>
          <Button variant="secondary" className="w-full" onClick={() => window.location.reload()}>
            Coba lagi
          </Button>
        </div>
      ) : !preview ? (
        <Spinner label="Memeriksa undangan…" />
      ) : (
        <InvitationDetails token={token!} preview={preview} />
      )}
    </AuthShell>
  )
}

function ClosedInvitation({ title, description }: { title: string; description: string }) {
  return (
    <EmptyState
      icon={MailX}
      title={title}
      description={description}
      action={
        <Link to="/login" className="text-sm font-medium text-brand-700 hover:underline">
          Ke halaman masuk
        </Link>
      }
    />
  )
}

function InvitationDetails({ token, preview }: { token: string; preview: InvitationPreview }) {
  const { profile, isLoading } = useAuth()
  const [joined, setJoined] = useState<OrganizationSummary | null>(null)

  return (
    <div className="space-y-5">
      <div className="space-y-2 text-sm text-slate-700">
        <p>
          <span className="font-medium text-slate-900">{preview.invited_by}</span> mengundang <span className="font-medium text-slate-900">{preview.email}</span> bergabung dengan{' '}
          <span className="font-medium text-slate-900">{preview.organization.name}</span> ({preview.organization.code}).
        </p>
        <dl className="grid gap-2 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-slate-500">Role</dt>
            <dd className="text-slate-800">{preview.roles.map((r) => r.name).join(', ') || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-slate-500">Berlaku sampai</dt>
            <dd className="text-slate-800">{formatDateTime(preview.expires_at)}</dd>
          </div>
        </dl>
      </div>
      {joined ? (
        <Joined organization={joined} />
      ) : isLoading ? (
        <Spinner />
      ) : !profile ? (
        <SignInOrSignUp token={token} preview={preview} onJoined={setJoined} />
      ) : (
        <AcceptAsProfile token={token} preview={preview} profile={profile} onJoined={setJoined} />
      )}
    </div>
  )
}

/** Not logged in: the address may or may not have an account; the page does not know, nor tell. */
function SignInOrSignUp({ token, preview, onJoined }: { token: string; preview: InvitationPreview; onJoined: (o: OrganizationSummary) => void }) {
  const [mode, setMode] = useState<'login' | 'signup'>('login')
  const tab = (value: typeof mode, label: string) => (
    <button
      type="button"
      role="tab"
      aria-selected={mode === value}
      onClick={() => setMode(value)}
      className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium ${mode === value ? 'bg-white text-slate-900 shadow-card' : 'text-slate-600 hover:text-slate-900'}`}
    >
      {label}
    </button>
  )

  return (
    <div className="space-y-4 border-t border-slate-200 pt-4">
      <div role="tablist" aria-label="Cara menerima undangan" className="flex gap-1 rounded-lg bg-slate-100 p-1">
        {tab('login', 'Saya sudah punya akun')}
        {tab('signup', 'Saya belum punya akun')}
      </div>
      {mode === 'login' ? (
        <>
          <p className="text-sm text-slate-600">Masuk dengan akun {preview.email}, lalu terima undangan.</p>
          <InlineLoginForm defaultEmail={preview.email} />
        </>
      ) : (
        <SignUpForm token={token} preview={preview} onJoined={onJoined} />
      )}
    </div>
  )
}

const signUpSchema = z
  .object({
    name: z.string().trim().min(1, 'Nama wajib diisi.').max(150),
    license_code: z.string().min(1, 'Kode lisensi wajib diisi.').max(19),
    password: z.string().refine(passwordRule.test, passwordRule.message),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi password tidak sama.' })
type SignUpValues = z.infer<typeof signUpSchema>

/** An address without an account signs up through the invitation and joins in one step. */
function SignUpForm({ token, preview, onJoined }: { token: string; preview: InvitationPreview; onJoined: (o: OrganizationSummary) => void }) {
  const { setProfile } = useAuth()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<SignUpValues>({ resolver: zodResolver(signUpSchema) })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const res = await api.post<{ data: { organization: OrganizationSummary } }>('/auth/invitation/register', { ...values, token })
      setProfile((await api.get<Resource<Profile>>('/auth/me')).data)
      onJoined(res.data.organization)
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      {formError && <Alert tone="error">{formError}</Alert>}
      <Field label="Email">{(p) => <Input {...p} type="email" value={preview.email} readOnly disabled />}</Field>
      <Field label="Nama lengkap" error={formState.errors.name?.message} required>
        {(p) => <Input {...p} autoComplete="name" {...register('name')} />}
      </Field>
      <Field label="Kode Lisensi" error={formState.errors.license_code?.message} hint="Masukkan kode lisensi yang Anda terima dari administrator. Format: LIC-XXXX-XXXX-XXXX" required>
        {(p) => <Input {...p} placeholder="LIC-XXXX-XXXX-XXXX" className={p.className} {...register('license_code')} />}
      </Field>
      <Field label="Password" error={formState.errors.password?.message} hint={passwordRule.message} required>
        {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
      </Field>
      <Field label="Ulangi password" error={formState.errors.password_confirmation?.message} required>
        {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password_confirmation')} />}
      </Field>
      <Button type="submit" className="w-full" loading={formState.isSubmitting}>
        Daftar dan terima undangan
      </Button>
    </form>
  )
}

/** Logged in: only the owner of the invited, verified address can accept. */
function AcceptAsProfile({ token, preview, profile, onJoined }: { token: string; preview: InvitationPreview; profile: Profile; onJoined: (o: OrganizationSummary) => void }) {
  const { logout } = useAuth()
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: () => api.post<{ data: { organization: OrganizationSummary } }>('/auth/invitation/accept', { token }),
    onSuccess: async (res) => {
      // The account gained an organization: the profile lists it in the switcher.
      await queryClient.invalidateQueries({ queryKey: ME_KEY })
      onJoined(res.data.organization)
    },
  })
  const { user } = profile
  const logoutButton = (
    <Button variant="secondary" className="w-full" onClick={() => void logout()}>
      Keluar dan masuk dengan akun lain
    </Button>
  )

  if (user.user_type !== 'tenant') {
    return <Alert tone="warning">Akun platform tidak dapat bergabung dengan organisasi.</Alert>
  }
  if (user.email.toLowerCase() !== preview.email.toLowerCase()) {
    return (
      <div className="space-y-3">
        <Alert tone="warning" title="Undangan untuk email lain">
          Anda masuk sebagai {user.email}. Undangan ini hanya dapat diterima oleh akun {preview.email}.
        </Alert>
        {logoutButton}
      </div>
    )
  }
  if (!user.email_verified_at) {
    return (
      <div className="space-y-3">
        <Alert tone="warning" title="Verifikasi email terlebih dahulu">
          Undangan hanya dapat diterima setelah email {user.email} diverifikasi. Setelah verifikasi, buka kembali tautan undangan dari email.
        </Alert>
        <ResendVerification email={user.email} />
      </div>
    )
  }
  if (user.must_change_password) {
    return (
      <Alert tone="warning" title="Ganti password terlebih dahulu">
        Akun Anda memakai password sementara.{' '}
        <Link to="/ubah-password" className="font-medium underline">
          Ganti password
        </Link>
        , lalu buka kembali tautan undangan dari email.
      </Alert>
    )
  }
  return (
    <div className="space-y-3 border-t border-slate-200 pt-4">
      {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
      <p className="text-sm text-slate-600">Anda masuk sebagai {user.email}.</p>
      <Button className="w-full" loading={mutation.isPending} onClick={() => mutation.mutate()}>
        Terima undangan
      </Button>
    </div>
  )
}

function Joined({ organization }: { organization: OrganizationSummary }) {
  const { profile, switchOrganization } = useAuth()
  const navigate = useNavigate()
  const switching = useMutation({ mutationFn: () => switchOrganization(organization.id), onSuccess: () => navigate('/', { replace: true }) })

  return (
    <div className="space-y-3 border-t border-slate-200 pt-4">
      <Alert tone="success" title="Undangan diterima">
        Anda sekarang anggota {organization.name}.
      </Alert>
      {switching.isError && <Alert tone="error">{errorMessage(switching.error)}</Alert>}
      <Button className="w-full" loading={switching.isPending} onClick={() => switching.mutate()}>
        Buka {organization.name}
      </Button>
      {profile && (
        <Button variant="secondary" className="w-full" onClick={() => navigate(landingPath(profile), { replace: true })}>
          Nanti saja
        </Button>
      )}
    </div>
  )
}
