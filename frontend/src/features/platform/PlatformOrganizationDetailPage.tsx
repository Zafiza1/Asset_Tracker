import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { AdminOnboarding, EmailDelivery, Organization, OrganizationInvitation, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime, invitationStatusLabel, orgStatusLabel } from '../../lib/format'

type WithDelivery<T> = { data: T; meta: { email_delivery: EmailDelivery } }

/** Never claim an email was sent when the server only writes it to its log. */
function sentNotice(email: string, delivery: EmailDelivery): string {
  return delivery === 'mail'
    ? `Undangan dikirim ke ${email}.`
    : `Undangan untuk ${email} dibuat, tetapi email hanya ditulis ke log server dan tidak terkirim ke penerima.`
}

/**
 * The organization's first administrator is invited, never assigned: nobody has access until the
 * owner of the address accepts with a verified account (or signs up through the invitation).
 */
function AdminOnboardingCard({ organization }: { organization: Organization }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [notice, setNotice] = useState<string | null>(null)
  const [inviting, setInviting] = useState(false)
  const [email, setEmail] = useState('')
  const base = `/platform/organizations/${organization.id}/admin-invitation`
  const queryKey = ['platform', 'organizations', organization.id, 'admin-invitation']
  const query = useQuery({ queryKey, queryFn: () => api.get<WithDelivery<AdminOnboarding>>(base) })
  const mutation = useMutation({
    mutationFn: (action: 'invite' | 'resend' | 'revoke') =>
      api.post<WithDelivery<OrganizationInvitation>>(action === 'invite' ? base : `${base}/${action}`, action === 'invite' ? { email } : undefined),
    onSuccess: async (res, action) => {
      await queryClient.invalidateQueries({ queryKey })
      setInviting(false)
      setEmail('')
      setNotice(action === 'revoke' ? `Undangan untuk ${res.data.email} dicabut.` : sentNotice(res.data.email, res.meta.email_delivery))
    },
  })

  if (query.isLoading) return <Card title="Administrator pertama"><Spinner /></Card>
  if (query.isError) return <Card title="Administrator pertama"><ErrorState error={query.error} /></Card>
  const { data: onboarding, meta } = query.data!
  const invitation = onboarding.invitation
  const manageable = can('platform.organization.manage') && organization.status === 'active' && !onboarding.has_members
  const reopenable = invitation !== null && (invitation.status === 'pending' || invitation.status === 'expired')
  const run = (action: 'invite' | 'resend' | 'revoke') => (setNotice(null), mutation.mutate(action))

  return (
    <Card title="Administrator pertama">
      <div className="space-y-3 p-4 text-sm">
        {meta.email_delivery === 'log' && !onboarding.has_members && (
          <Alert tone="warning">Server belum mengirim email sungguhan: email undangan hanya ditulis ke log server dan tidak sampai ke penerima.</Alert>
        )}
        {notice && <Alert tone="success">{notice}</Alert>}
        {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
        {onboarding.has_members ? (
          <p className="text-slate-700">
            Administrator sudah bergabung{invitation?.status === 'accepted' ? ` (${invitation.email}, ${formatDateTime(invitation.accepted_at)})` : ''}. Pengguna lain diundang oleh
            Admin organisasi.
          </p>
        ) : (
          <>
            {invitation ? (
              <dl className="grid gap-3 sm:grid-cols-2">
                <div>
                  <dt className="text-slate-500">Email diundang</dt>
                  <dd className="break-all text-slate-800">{invitation.email}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">Status undangan</dt>
                  <dd>
                    <Badge tone={statusTone[invitation.status]}>{invitationStatusLabel[invitation.status]}</Badge>
                  </dd>
                </div>
                {invitation.status === 'pending' && (
                  <div>
                    <dt className="text-slate-500">Berlaku sampai</dt>
                    <dd className="text-slate-800">{formatDateTime(invitation.expires_at)}</dd>
                  </div>
                )}
              </dl>
            ) : (
              <p className="text-slate-700">Belum ada undangan administrator.</p>
            )}
            <p className="text-slate-600">
              Belum ada yang dapat mengakses organisasi ini. Penerima menjadi Admin organisasi setelah menerima undangan dengan akun yang emailnya terverifikasi,
              atau setelah mendaftar lewat tautan undangan.
            </p>
            {manageable && !inviting && (
              <div className="flex flex-wrap gap-2">
                {reopenable && (
                  <>
                    <Button variant="secondary" loading={mutation.isPending && mutation.variables === 'resend'} onClick={() => run('resend')}>
                      Kirim ulang
                    </Button>
                    <Button variant="danger" loading={mutation.isPending && mutation.variables === 'revoke'} onClick={() => run('revoke')}>
                      Cabut
                    </Button>
                  </>
                )}
                <Button variant={reopenable ? 'secondary' : 'primary'} onClick={() => (setNotice(null), setInviting(true))}>
                  {invitation ? 'Undang email lain' : 'Undang administrator'}
                </Button>
              </div>
            )}
            {manageable && inviting && (
              <form
                className="space-y-3"
                noValidate
                onSubmit={(e) => {
                  e.preventDefault()
                  if (email.trim()) run('invite')
                }}
              >
                <Field label="Email administrator" required hint={reopenable ? 'Undangan yang masih terbuka akan dicabut.' : undefined}>
                  {(p) => <Input {...p} type="email" autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />}
                </Field>
                <div className="flex justify-end gap-2">
                  <Button variant="secondary" onClick={() => setInviting(false)}>
                    Batal
                  </Button>
                  <Button type="submit" disabled={!email.trim()} loading={mutation.isPending}>
                    Kirim undangan
                  </Button>
                </div>
              </form>
            )}
          </>
        )}
      </div>
    </Card>
  )
}

export function PlatformOrganizationDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [action, setAction] = useState<'suspend' | 'activate' | null>(null)
  const [reason, setReason] = useState('')
  const query = useQuery({ queryKey: ['platform', 'organizations', id], queryFn: () => api.get<Resource<Organization>>(`/platform/organizations/${id}`) })
  const mutation = useMutation({
    mutationFn: () => api.post<Resource<Organization>>(`/platform/organizations/${id}/${action}`, { reason }),
    onSuccess: async (res) => {
      queryClient.setQueryData(['platform', 'organizations', id], res)
      await queryClient.invalidateQueries({ queryKey: ['platform', 'organizations'] })
      setAction(null)
      setReason('')
    },
  })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const org = query.data!.data

  return (
    <>
      <PageHeader
        title={org.name}
        description={org.code}
        actions={
          <>
            <Badge tone={statusTone[org.status]}>{orgStatusLabel[org.status]}</Badge>
            {can('platform.organization.manage') && org.status === 'active' && (
              <Button variant="danger" onClick={() => setAction('suspend')}>
                Tangguhkan
              </Button>
            )}
            {can('platform.organization.manage') && org.status === 'suspended' && <Button onClick={() => setAction('activate')}>Aktifkan</Button>}
          </>
        }
      />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Profil">
          <dl className="grid gap-3 p-4 text-sm sm:grid-cols-2">
            {(
              [
                ['Nama legal', org.legal_name],
                ['Email', org.email],
                ['Telepon', org.phone],
                ['Zona waktu', org.timezone],
                ['Mata uang', org.currency],
                ['Dibuat', formatDateTime(org.created_at)],
              ] as const
            ).map(([label, value]) => (
              <div key={label}>
                <dt className="text-slate-500">{label}</dt>
                <dd className="text-slate-800">{value || '—'}</dd>
              </div>
            ))}
          </dl>
        </Card>
        <AdminOnboardingCard organization={org} />
        <Card title="Audit organisasi">
          <p className="p-4 text-sm text-slate-600">
            Aktivitas organisasi ini dapat ditelusuri di{' '}
            <Link className="text-brand-700 underline" to={`/platform/audit?organization_id=${org.id}`}>
              audit platform
            </Link>
            . Data bisnis tenant tidak ditampilkan pada konsol platform.
          </p>
        </Card>
      </div>
      <Modal
        open={action !== null}
        title={action === 'suspend' ? 'Tangguhkan organisasi?' : 'Aktifkan organisasi?'}
        onClose={() => setAction(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>
              Batal
            </Button>
            <Button variant={action === 'suspend' ? 'danger' : 'primary'} disabled={!reason.trim()} loading={mutation.isPending} onClick={() => mutation.mutate()}>
              Konfirmasi
            </Button>
          </>
        }
      >
        {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
        {action === 'suspend' && <p className="text-sm text-slate-700">Seluruh pengguna organisasi ini tidak dapat mengakses aplikasi selama ditangguhkan.</p>}
        <Field label="Alasan" required hint="Dicatat di audit trail.">
          {(p) => <Textarea {...p} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </Modal>
    </>
  )
}
