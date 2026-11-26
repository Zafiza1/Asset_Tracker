import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Phone, XCircle } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, ErrorState } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDate, formatDateTime } from '../../lib/format'
import { deliveriesApi, DELIVERIES_KEY, REFRESH_MS, type DeliveryDetail } from './api'
import { DeliveryFormModal } from './DeliveryFormModal'
import { DeliveryTabs } from './DeliveryTabs'
import { DeliveryProgress, DeliveryStatusBadge, DirectionBadge } from './DeliveryParts'

/** One transaction: how far the trip is, its crew, cylinders per customer, route and timeline. */
export function DeliveryDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const [editing, setEditing] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const query = useQuery({
    queryKey: [...DELIVERIES_KEY, id],
    queryFn: () => deliveriesApi.get(id),
    refetchInterval: (q) => (['completed', 'cancelled'].includes(q.state.data?.data.status ?? '') ? false : REFRESH_MS),
  })
  if (query.isPending) return <DetailSkeleton label="Memuat transaksi…" />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  const d = query.data.data
  const manage = can('delivery.manage') && can(d.direction === 'outgoing' ? 'asset.outgoing' : 'asset.incoming')
  const cancellable = d.status === 'draft' || d.status === 'loading' || (d.status === 'in_transit' && d.direction === 'incoming' && d.counts.loaded === 0)
  const unclear = d.items.filter((i) => i.state === 'missing' || i.state === 'returned')

  return (
    <>
      <PageHeader
        title={d.number}
        badges={
          <>
            <DirectionBadge delivery={d} />
            <DeliveryStatusBadge delivery={d} />
          </>
        }
        description={d.stops.map((s) => s.customer.name).join(', ')}
        actions={
          manage && (
            <>
              {d.status === 'draft' && (
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  <Pencil className="size-4" aria-hidden /> Ubah
                </Button>
              )}
              {cancellable && (
                <Button variant="secondary" onClick={() => setCancelling(true)}>
                  <XCircle className="size-4" aria-hidden /> Batalkan
                </Button>
              )}
            </>
          )
        }
      />
      <div className="space-y-6">
        {d.status === 'cancelled' && <Alert tone="warning" title="Transaksi dibatalkan">{d.end_note}</Alert>}
        {d.last_event?.type === 'issue' && d.status === 'in_transit' && (
          <Alert tone="warning" title="Kendala di jalan">
            {d.last_event.note} ({formatDateTime(d.last_event.recorded_at)})
          </Alert>
        )}
        {unclear.length > 0 && (
          <Alert tone="warning" title={d.direction === 'incoming' ? `${unclear.length} tabung selisih` : `${unclear.length} tabung dibawa kembali ke gudang`}>
            {unclear.map((i) => i.serial_number ?? i.name).join(', ')}
            {d.end_note && `. Keterangan: ${d.end_note}`}
          </Alert>
        )}
        <Card className="p-5">
          <DeliveryProgress delivery={d} />
          <dl className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Fact label="Kendaraan" value={d.vehicle ? <Link className="hover:text-brand-700" to={`/aset/${d.vehicle.id}`}>{d.vehicle.name}</Link> : null} empty="Belum dipilih" />
            <Fact label="No Polisi" value={d.vehicle?.plate} />
            <Fact label="Supir" value={d.driver && <PersonText name={d.driver.name} phone={d.driver.phone} />} empty="Diisi di mobile" />
            <Fact label="Kernet" value={d.helper && <PersonText name={d.helper.name} phone={d.helper.phone} />} empty={d.driver ? '—' : 'Diisi di mobile'} />
            <Fact label={d.direction === 'outgoing' ? 'Tanggal kirim' : 'Tanggal jemput'} value={formatDate(d.planned_date)} />
            {d.expected_return_date && <Fact label="Perkiraan tabung kembali" value={formatDate(d.expected_return_date)} />}
            <Fact label="Berangkat" value={d.departed_at && formatDateTime(d.departed_at)} />
            {d.returned_at && <Fact label="Tiba di gudang" value={formatDateTime(d.returned_at)} />}
            <Fact label="Selesai" value={d.ended_at && formatDateTime(d.ended_at)} />
            <Fact label="Tabung" value={`${d.counts.total} (muat ${d.counts.loaded}, bongkar ${d.counts.unloaded})`} />
            <Fact label="Dibuat oleh" value={d.created_by} />
            {d.notes && <Fact label="Catatan" value={d.notes} />}
          </dl>
        </Card>
        <DeliveryTabs delivery={d} />
      </div>
      {editing && <DeliveryFormModal direction={d.direction} delivery={d} onClose={() => setEditing(false)} onSaved={() => setEditing(false)} />}
      {cancelling && <CancelModal delivery={d} onClose={() => setCancelling(false)} />}
    </>
  )
}

function Fact({ label, value, empty = '—' }: { label: string; value: ReactNode; empty?: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-sm font-medium text-slate-900">{value || <span className="font-normal text-slate-400">{empty}</span>}</dd>
    </div>
  )
}

function PersonText({ name, phone }: { name: string; phone: string | null }) {
  return (
    <>
      {name}
      {phone && (
        <a href={`tel:${phone}`} className="mt-0.5 flex items-center gap-1 text-xs font-normal text-slate-500 hover:text-brand-700">
          <Phone className="size-3" aria-hidden /> {phone}
        </a>
      )}
    </>
  )
}

function CancelModal({ delivery, onClose }: { delivery: DeliveryDetail; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => deliveriesApi.cancel(delivery.id, reason.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: DELIVERIES_KEY })
      toast.success('Transaksi dibatalkan', delivery.number)
      onClose()
    },
  })
  const tooShort = reason.trim().length < 5
  return (
    <Modal
      open
      title={`Batalkan ${delivery.number}?`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Kembali
          </Button>
          <Button variant="danger" onClick={() => cancel.mutate()} loading={cancel.isPending} disabled={tooShort}>
            Batalkan transaksi
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {cancel.isError && <Alert tone="error">{errorMessage(cancel.error)}</Alert>}
        <p className="text-sm text-slate-600">Semua tabung di transaksi ini dilepas dan bisa dipakai transaksi lain.</p>
        <Field label="Alasan" required hint="Minimal 5 karakter.">
          {(p) => <Textarea {...p} rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </div>
    </Modal>
  )
}
