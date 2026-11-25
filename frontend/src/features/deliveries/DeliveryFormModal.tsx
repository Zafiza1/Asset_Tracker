import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert, ErrorState, FormSkeleton } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { IconButton } from '../../components/ui/IconButton'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { errorMessage } from '../../lib/forms'
import { formatDate } from '../../lib/format'
import { deliveriesApi, DELIVERIES_KEY, type Cylinder, type DeliveryDetail, type DeliveryInput, type Direction } from './api'
import { CylinderPicker } from './CylinderPicker'

interface StopDraft {
  key: number
  customerId: string
  cylinders: Cylinder[]
}

const pad = (n: number) => String(n).padStart(2, '0')
const localDate = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const addDays = (date: string, days: number) => {
  const d = new Date(`${date}T00:00:00`)
  d.setDate(d.getDate() + days)
  return localDate(d)
}

/** Plan an outgoing or incoming transaction: customers, cylinders and vehicle. Driver and helper follow on the phone. */
export function DeliveryFormModal({ direction, delivery, onClose, onSaved }: { direction: Direction; delivery?: DeliveryDetail; onClose: () => void; onSaved: (d: DeliveryDetail) => void }) {
  const queryClient = useQueryClient()
  const outgoing = direction === 'outgoing'
  const options = useQuery({ queryKey: [...DELIVERIES_KEY, 'options'], queryFn: () => deliveriesApi.options() })
  const [plannedDate, setPlannedDate] = useState(() => delivery?.planned_date ?? localDate(new Date()))
  // Empty until changed: the server then counts return_days from the planned date.
  const [returnDate, setReturnDate] = useState(delivery?.expected_return_date ?? '')
  const [vehicleId, setVehicleId] = useState(delivery?.vehicle?.id ?? '')
  const [notes, setNotes] = useState(delivery?.notes ?? '')
  const [nextKey, setNextKey] = useState(1)
  const [stops, setStops] = useState<StopDraft[]>(() =>
    delivery
      ? delivery.stops.map((s, i) => ({
          key: -i - 1,
          customerId: s.customer.id,
          cylinders: delivery.items.filter((it) => it.stop_id === s.id).map((it) => ({ id: it.asset_id, name: it.name, serial_number: it.serial_number, details: it.details })),
        }))
      : [{ key: 0, customerId: '', cylinders: [] }],
  )
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)

  const patch = (key: number, change: Partial<StopDraft>) => setStops((all) => all.map((s) => (s.key === key ? { ...s, ...change } : s)))
  const total = stops.reduce((n, s) => n + s.cylinders.length, 0)

  const save = useMutation({
    mutationFn: () => {
      const input: DeliveryInput = {
        planned_date: plannedDate,
        expected_return_date: outgoing ? returnDate || null : null,
        vehicle_asset_id: vehicleId || null,
        notes: notes.trim() || null,
        stops: stops.map((s) => ({ customer_id: s.customerId, asset_ids: s.cylinders.map((c) => c.id) })),
      }
      return delivery ? deliveriesApi.update(delivery.id, input) : deliveriesApi.create(direction, input)
    },
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries({ queryKey: DELIVERIES_KEY })
      toast.success(delivery ? 'Transaksi diperbarui' : 'Transaksi dibuat', data.number)
      onSaved(data)
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? Object.fromEntries(Object.entries(e.fields).map(([k, m]) => [k, m[0]])) : {})
      setFormError(errorMessage(e))
    },
  })

  const submit = () => {
    const found: Record<string, string> = {}
    if (!plannedDate) found.planned_date = 'Tanggal wajib diisi.'
    stops.forEach((s, i) => {
      if (!s.customerId) found[`stops.${i}.customer_id`] = 'Customer wajib dipilih.'
      else if (outgoing && s.cylinders.length === 0) found[`stops.${i}.asset_ids`] = 'Pilih minimal satu tabung untuk customer ini.'
    })
    setErrors(found)
    setFormError(Object.keys(found).length > 0 ? 'Lengkapi isian yang ditandai.' : null)
    if (Object.keys(found).length === 0) save.mutate()
  }

  const title = delivery ? `Ubah ${delivery.number}` : outgoing ? 'Buat transaksi keluar' : 'Buat transaksi masuk'
  const chosenCustomers = new Set(stops.map((s) => s.customerId))

  return (
    <Modal
      open
      size="xl"
      title={title}
      onClose={onClose}
      footer={
        <>
          <span className="mr-auto self-center text-sm text-slate-600">{total} tabung dipilih</span>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={submit} loading={save.isPending} disabled={!options.data}>
            Simpan
          </Button>
        </>
      }
    >
      {options.isPending ? (
        <FormSkeleton />
      ) : options.isError ? (
        <ErrorState error={options.error} onRetry={() => void options.refetch()} />
      ) : (
        <div className="space-y-5">
          {formError && <Alert tone="error">{formError}</Alert>}
          <p className="text-sm text-slate-600">
            {outgoing
              ? 'Tabung di-scan muat di gudang, lalu di-scan bongkar di customer lewat aplikasi mobile. Supir dan kernet diisi di mobile sebelum berangkat.'
              : 'Tabung di-scan muat di customer, lalu di-scan bongkar di gudang lewat aplikasi mobile. Supir dan kernet diisi di mobile sebelum berangkat.'}
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={outgoing ? 'Tanggal kirim' : 'Tanggal jemput'} required error={errors.planned_date}>
              {(p) => <Input {...p} type="date" value={plannedDate} onChange={(e) => setPlannedDate(e.target.value)} />}
            </Field>
            <Field label="Kendaraan" error={errors.vehicle_asset_id} hint="Dari Data Aset kategori Kendaraan. Bisa juga dipilih di mobile.">
              {(p) => (
                <Select {...p} value={vehicleId} onChange={(e) => setVehicleId(e.target.value)}>
                  <option value="">— Dipilih nanti di mobile —</option>
                  {options.data.data.vehicles.map((v) => (
                    <option key={v.id} value={v.id}>
                      {[v.plate, v.name].filter(Boolean).join(' · ')}
                      {v.on_trip ? ' (sedang jalan)' : ''}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            {outgoing && (
              <Field label="Perkiraan tabung kembali" error={errors.expected_return_date} hint={returnDate || !plannedDate ? undefined : `Kosong: ${options.data.data.return_days} hari setelah tanggal kirim (${formatDate(addDays(plannedDate, options.data.data.return_days))}).`}>
                {(p) => <Input {...p} type="date" min={plannedDate} value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />}
              </Field>
            )}
          </div>

          {errors.stops && <Alert tone="error">{errors.stops}</Alert>}
          <ol className="space-y-4">
            {stops.map((stop, i) => (
              <li key={stop.key} className="space-y-3 rounded-lg p-4 ring-1 ring-slate-200">
                <div className="flex items-end gap-2">
                  <div className="min-w-0 flex-1">
                    <Field label={stops.length > 1 ? `Customer ${i + 1}` : 'Customer'} required error={errors[`stops.${i}.customer_id`]}>
                      {(p) => (
                        <Select {...p} value={stop.customerId} onChange={(e) => patch(stop.key, { customerId: e.target.value, cylinders: outgoing ? stop.cylinders : [] })}>
                          <option value="">— Pilih customer —</option>
                          {options.data.data.customers
                            .filter((c) => c.id === stop.customerId || !chosenCustomers.has(c.id))
                            .map((c) => (
                              <option key={c.id} value={c.id}>
                                {c.name}
                              </option>
                            ))}
                        </Select>
                      )}
                    </Field>
                  </div>
                  {stops.length > 1 && <IconButton label={`Hapus customer ${i + 1}`} onClick={() => setStops((all) => all.filter((s) => s.key !== stop.key))}><Trash2 className="size-4" aria-hidden /></IconButton>}
                </div>
                {stop.customerId && (
                  <div>
                    <p className="mb-1.5 text-sm font-semibold text-slate-700">
                      Tabung {outgoing ? <span className="text-red-600">*</span> : <span className="font-normal text-slate-500">(boleh kosong, di-scan saat dijemput)</span>}
                    </p>
                    <CylinderPicker
                      direction={direction}
                      customerId={stop.customerId}
                      deliveryId={delivery?.id}
                      value={stop.cylinders}
                      taken={new Set(stops.filter((s) => s.key !== stop.key).flatMap((s) => s.cylinders.map((c) => c.id)))}
                      onChange={(cylinders) => patch(stop.key, { cylinders })}
                    />
                    {errors[`stops.${i}.asset_ids`] && <p className="mt-1.5 text-sm text-red-700">{errors[`stops.${i}.asset_ids`]}</p>}
                  </div>
                )}
              </li>
            ))}
          </ol>
          <Button variant="secondary" onClick={() => (setStops((all) => [...all, { key: nextKey, customerId: '', cylinders: [] }]), setNextKey(nextKey + 1))} disabled={stops.length >= 20}>
            <Plus className="size-4" aria-hidden /> Tambah customer
          </Button>
          <Field label="Catatan" error={errors.notes}>
            {(p) => <Textarea {...p} rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} />}
          </Field>
        </div>
      )}
    </Modal>
  )
}
