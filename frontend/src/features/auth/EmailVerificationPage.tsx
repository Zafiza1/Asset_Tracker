import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MailCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { Button } from '../../components/ui/Button'
import { Alert, EmptyState, Spinner } from '../../components/ui/Feedback'
import { api, ApiError } from '../../lib/api/client'
import { landingPath, ME_KEY, useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { AuthShell } from './AuthShell'
import { InlineLoginForm, ResendVerification } from './InlineLogin'

const SIGNED_PARAMS = ['id', 'hash', 'expires', 'signature']

/**
 * Target of the verification email: /verifikasi-email?id&hash&expires&signature. The signed
 * query is passed to the API unchanged (the signature covers it) while logged in as the
 * account it was made for, and is removed from the address bar at once. Without a link the
 * page shows the account's status and sends a new link.
 */
export function EmailVerificationPage() {
  const location = useLocation()
  const navigate = useNavigate()
  const { profile, isLoading } = useAuth()
  const [signedQuery] = useState(() => {
    const params = new URLSearchParams(location.search)
    return SIGNED_PARAMS.every((p) => params.has(p)) ? location.search.replace(/^\?/, '') : null
  })

  useEffect(() => {
    if (location.search) navigate({ pathname: location.pathname }, { replace: true })
  }, [location.pathname, location.search, navigate])

  return (
    <AuthShell subtitle="Verifikasi alamat email akun Anda.">
      {isLoading ? (
        <Spinner />
      ) : !profile ? (
        <div className="space-y-4">
          <Alert tone="info">Masuk dengan akun yang ingin diverifikasi{signedQuery ? ' untuk menyelesaikan verifikasi' : ''}.</Alert>
          <InlineLoginForm />
        </div>
      ) : signedQuery ? (
        <VerifyLink key={profile.user.id} signedQuery={signedQuery} email={profile.user.email} />
      ) : profile.user.email_verified_at ? (
        <Verified />
      ) : (
        <div className="space-y-4">
          <Alert tone="warning" title="Email belum diverifikasi">
            Verifikasi {profile.user.email} agar dapat menerima undangan bergabung dengan organisasi.
          </Alert>
          <ResendVerification email={profile.user.email} />
        </div>
      )}
    </AuthShell>
  )
}

/** Confirms the link once for the logged-in account. */
function VerifyLink({ signedQuery, email }: { signedQuery: string; email: string }) {
  const queryClient = useQueryClient()
  const { logout } = useAuth()
  const started = useRef(false)
  const mutation = useMutation({
    mutationFn: () => api.post<{ data: { verified: boolean } }>(`/auth/email/verify?${signedQuery}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ME_KEY }),
  })
  const { mutate } = mutation

  useEffect(() => {
    if (started.current) return
    started.current = true
    mutate()
  }, [mutate])

  if (mutation.isSuccess) return <Verified />
  if (mutation.isError) {
    const code = mutation.error instanceof ApiError ? mutation.error.code : null
    return (
      <div className="space-y-3">
        <Alert tone="error">{errorMessage(mutation.error)}</Alert>
        {code === 'VERIFICATION_ACCOUNT_MISMATCH' ? (
          <Button variant="secondary" className="w-full" onClick={() => void logout()}>
            Keluar dan masuk dengan akun lain
          </Button>
        ) : (
          <ResendVerification email={email} />
        )}
      </div>
    )
  }
  return <Spinner label="Memverifikasi email…" />
}

function Verified() {
  const { profile } = useAuth()
  return (
    <EmptyState
      icon={MailCheck}
      title="Email terverifikasi"
      description="Anda sekarang dapat menerima undangan organisasi. Buka kembali tautan undangan dari email bila ada."
      action={
        profile && (
          <Link to={landingPath(profile)} className="text-sm font-medium text-brand-700 hover:underline">
            Lanjut ke aplikasi
          </Link>
        )
      }
    />
  )
}
