import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleDot, KeyRound, MailCheck, Undo2, UserCheck } from 'lucide-react'
import { Badge } from '../../../components/ui/Badge'
import { Button } from '../../../components/ui/Button'
import { Alert, Spinner } from '../../../components/ui/Feedback'
import { Modal } from '../../../components/ui/Modal'
import { api } from '../../../lib/api/client'
import type { LicenseCode, LicenseCodeDetail, LicenseCodeHistoryEntry, Resource } from '../../../lib/api/types'
import { useAuth } from '../../../lib/auth/context'
import { formatDate, formatDateTime } from '../../../lib/format'
import { errorMessage } from '../../../lib/forms'
import { codeStatusHint, codeStatusLabel, codeStatusTone, licenseKindLabel, licenseSourceLabel, licenseStatusLabel, licenseStatusTone } from './labels'

export type CodeAction = 'assign' | 'release'

/** After any change the list, the counts, the seats and the opened code are all stale. */
function useInvalidateLicense() {
  const queryClient = useQueryClient()
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['license-codes'] }),
      queryClient.invalidateQueries({ queryKey: ['license-code'] }),
      queryClient.invalidateQueries({ queryKey: ['license'] }),
    ])
}

const historyText = (h: LicenseCodeHistoryEntry): string => {
  switch (h.action) {
    case 'license_code.assigned':
      return `Ditugaskan ke ${h.user_name ?? 'pengguna'}`
    case 'license_code.released':
      return h.reason === 'user_deactivated' ? `Dilepas otomatis karena ${h.user_name ?? 'pengguna'} dinonaktifkan` : `Dilepas dari ${h.user_name ?? 'pengguna'}`
    default:
      return h.action
  }
}

const historyIcon = (action: string) =>
  action === 'license_code.assigned' ? <UserCheck className="size-3.5" aria-hidden /> : <Undo2 className="size-3.5" aria-hidden />

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[8.5rem_1fr] gap-3 py-2 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="min-w-0 text-slate-900">{children}</dd>
    </div>
  )
}

/** One code: who holds it, the license behind it, and everything that happened to it. */
export function LicenseCodeDetailModal({ code, canInvite, onClose, onAction }: { code: LicenseCode; canInvite: boolean; onClose: () => void; onAction: (action: CodeAction, code: LicenseCode) => void }) {
  const { can } = useAuth()
  const manage = can('license.manage')
  const query = useQuery({ queryKey: ['license-code', code.id], queryFn: () => api.get<Resource<LicenseCodeDetail>>(`/license-codes/${code.id}`) })
  const c = query.data?.data ?? { ...code, history: [] }
  const license = c.license

  return (
    <Modal
      open
      title={`Kode lisensi ${c.code}`}
      onClose={onClose}
      footer={
        <>
          {canInvite && c.status === 'available' && !c.pending_invitation && (
            <Button onClick={() => onAction('assign', c)}>
              <UserCheck className="size-4" aria-hidden /> Tugaskan ke pengguna
            </Button>
          )}
          {manage && c.status === 'assigned' && (
            <Button variant="secondary" onClick={() => onAction('release', c)}>
              <Undo2 className="size-4" aria-hidden /> Lepaskan
            </Button>
          )}
          <Button variant="secondary" onClick={onClose}>
            Tutup
          </Button>
        </>
      }
    >
      <div className="flex items-start gap-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200/80">
        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200">
          <KeyRound className="size-4" aria-hidden />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-sm font-semibold text-slate-900">{c.code}</span>
            <Badge tone={codeStatusTone[c.status]}>{codeStatusLabel[c.status]}</Badge>
          </div>
          <p className="mt-0.5 text-xs text-slate-500">{codeStatusHint[c.status]}</p>
        </div>
      </div>

      <dl className="divide-y divide-slate-100">
        <Row label="Pemegang">
          {c.assigned_user ? (
            <>
              <p className="font-medium">{c.assigned_user.name}</p>
              <p className="text-xs text-slate-500">{c.assigned_user.email}</p>
            </>
          ) : c.pending_invitation ? (
            <>
              <p className="flex items-center gap-1.5 font-medium">
                <MailCheck className="size-4 text-amber-600" aria-hidden /> {c.pending_invitation.email}
              </p>
              <p className="text-xs text-slate-500">Menunggu undangan diterima (berlaku sampai {formatDateTime(c.pending_invitation.expires_at)}). Kode otomatis ditugaskan saat undangan diterima.</p>
            </>
          ) : (
            <span className="text-slate-400">Belum ditugaskan</span>
          )}
        </Row>
        {c.assigned_at && <Row label="Ditugaskan sejak">{formatDateTime(c.assigned_at)}</Row>}
        <Row label="Lisensi">
          {license ? (
            <div className="flex flex-wrap items-center gap-2">
              <span>
                {licenseKindLabel({ kind: license.kind, reference: c.reference })} · {licenseSourceLabel[license.source] ?? license.source}
              </span>
              <Badge tone={licenseStatusTone[license.status]}>{licenseStatusLabel[license.status]}</Badge>
            </div>
          ) : (
            <span className="text-slate-400">Tidak ditemukan</span>
          )}
        </Row>
        {license && (
          <Row label="Masa berlaku">
            <span className="tabular-nums">
              {formatDate(license.starts_at)} – {formatDate(license.expires_at)}
            </span>
          </Row>
        )}
        {c.reference && (
          <Row label="Referensi">
            <span className="font-mono text-xs">{c.reference}</span>
          </Row>
        )}
        {c.notes && <Row label="Catatan">{c.notes}</Row>}
      </dl>

      <section aria-labelledby="license-code-history">
        <h3 id="license-code-history" className="mb-2 text-sm font-semibold text-slate-900">
          Riwayat
        </h3>
        {query.isLoading ? (
          <Spinner label="Memuat riwayat…" />
        ) : (
          <ol className="relative space-y-3 border-l border-slate-200 pl-5">
            {[...c.history, null].map((h) => (
              <li key={h?.id ?? 'created'} className="relative">
                <span className="absolute -left-[1.85rem] top-0.5 grid size-5 place-items-center rounded-full bg-white text-slate-500 ring-1 ring-slate-200">
                  {h ? historyIcon(h.action) : <CircleDot className="size-3.5" aria-hidden />}
                </span>
                <p className="text-sm text-slate-900">{h ? historyText(h) : 'Kode dibuat'}</p>
                <p className="text-xs text-slate-500">
                  {h ? `${h.actor ? `oleh ${h.actor.name} · ` : ''}${formatDateTime(h.created_at)}` : formatDateTime(c.created_at)}
                </p>
              </li>
            ))}
          </ol>
        )}
      </section>
    </Modal>
  )
}

/** Takes a code back from its holder; it becomes available for someone else. */
export function ReleaseCodeModal({ code, onClose }: { code: LicenseCode; onClose: () => void }) {
  const invalidate = useInvalidateLicense()
  const mutation = useMutation({
    mutationFn: () => api.post<Resource<LicenseCode>>(`/license-codes/${code.id}/release`),
    onSuccess: async () => {
      await invalidate()
      onClose()
    },
  })

  return (
    <Modal
      open
      title="Lepaskan kode lisensi?"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={mutation.isPending} onClick={() => mutation.mutate()}>
            Lepaskan
          </Button>
        </>
      }
    >
      {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
      <p className="text-sm text-slate-700">
        Kode <span className="font-mono font-semibold text-slate-900">{code.code}</span> dilepas dari {code.assigned_user?.name ?? 'pemegangnya'} dan kembali siap ditugaskan ke pengguna lain. Akun pengguna tetap aktif dan jumlah seat tidak berubah.
      </p>
    </Modal>
  )
}
