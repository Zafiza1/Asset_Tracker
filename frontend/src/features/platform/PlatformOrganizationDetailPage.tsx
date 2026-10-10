import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api } from '../../lib/api/client'
import type { Organization, Resource } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime, orgStatusLabel } from '../../lib/format'

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
