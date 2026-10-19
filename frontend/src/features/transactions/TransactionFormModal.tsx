import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { ApiError, api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { toOptions, useOptions } from '../master/options'
import { assetStatusLabel, type Asset, type AssetStatus } from '../assets/api'
import { allowedTypes, transactionTypeLabel, typePermission, type ManualTransactionType } from './api'

const selectableStatuses: AssetStatus[] = ['available', 'in_use', 'under_maintenance', 'disposed']

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

interface Props {
  /** Pre-selected asset (from the asset detail page); otherwise the user searches for one. */
  asset?: Asset
  initialType?: ManualTransactionType
  onClose: () => void
}

/** Record one manual transaction. Each type shows only the fields it needs. */
export function TransactionFormModal({ asset: fixedAsset, initialType, onClose }: Props) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<Asset | null>(fixedAsset ?? null)
  const asset = fixedAsset ?? picked
  const types = allowedTypes(asset?.status).filter((t) => !typePermission[t] || can(typePermission[t]!))
  const [type, setType] = useState<ManualTransactionType | ''>(initialType && types.includes(initialType) ? initialType : '')
  const [values, setValues] = useState<Record<string, string>>({ transaction_date: today() })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const locations = toOptions(useOptions('/locations').data)
  const candidates = useQuery({
    queryKey: ['/assets', 'picker', search],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs({ search, per_page: 10 })}`),
    enabled: !fixedAsset && !picked,
  })

  const set = (name: string) => (e: { target: { value: string } }) => setValues((v) => ({ ...v, [name]: e.target.value }))
  const err = (name: string) => fieldErrors[name]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = { asset_id: asset!.id, type }
      for (const [k, v] of Object.entries(values)) payload[k] = v.trim() === '' ? null : v.trim()
      return api.post('/transactions', payload)
    },
    onSuccess: async () => {
      await Promise.all(['/transactions', '/assets', '/dashboard'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
      onClose()
    },
    onError: (e) => setFieldErrors(e instanceof ApiError ? e.fields : {}),
  })

  return (
    <Modal
      open
      title="Catat transaksi aset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button type="submit" form="transaction-form" loading={save.isPending} disabled={!asset || !type}>
            Simpan transaksi
          </Button>
        </>
      }
    >
      <form
        id="transaction-form"
        noValidate
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setFieldErrors({})
          save.mutate()
        }}
      >
        {save.isError && <Alert tone="error">{errorMessage(save.error)}</Alert>}

        {asset ? (
          <div className="rounded-md bg-slate-50 p-3 text-sm ring-1 ring-slate-200">
            <p className="font-medium text-slate-900">{asset.name}</p>
            <p className="text-slate-600">
              {asset.asset_number} · {assetStatusLabel[asset.status]}
              {asset.location ? ` · ${asset.location.name}` : ''}
            </p>
            {!fixedAsset && (
              <button type="button" className="mt-1 text-xs font-medium text-brand-700 hover:underline" onClick={() => (setPicked(null), setType(''))}>
                Ganti aset
              </button>
            )}
          </div>
        ) : (
          <Field label="Aset" required error={err('asset_id')} hint="Cari berdasarkan nama, nomor aset, atau nomor seri.">
            {(p) => (
              <div className="space-y-2">
                <Input {...p} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ketik untuk mencari aset" />
                <ul className="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-md ring-1 ring-slate-200" aria-label="Hasil pencarian aset">
                  {candidates.data?.data.map((a) => (
                    <li key={a.id}>
                      <button type="button" className="w-full px-3 py-2 text-left text-sm hover:bg-brand-50" onClick={() => setPicked(a)}>
                        <span className="font-medium text-slate-900">{a.name}</span>
                        <span className="block text-xs text-slate-500">
                          {a.asset_number} · {assetStatusLabel[a.status]}
                        </span>
                      </button>
                    </li>
                  ))}
                  {candidates.data?.data.length === 0 && <li className="px-3 py-2 text-sm text-slate-500">Aset tidak ditemukan.</li>}
                </ul>
              </div>
            )}
          </Field>
        )}

        {asset && (
          <Field label="Jenis transaksi" required error={err('type')}>
            {(p) => (
              <Select
                {...p}
                value={type}
                onChange={(e) => {
                  setType(e.target.value as ManualTransactionType)
                  // Fields of the previous type must not be sent with the new one.
                  setValues((v) => ({ transaction_date: v.transaction_date ?? today(), notes: v.notes ?? '' }))
                  setFieldErrors({})
                }}
              >
                <option value="">— Pilih —</option>
                {types.map((t) => (
                  <option key={t} value={t}>
                    {transactionTypeLabel[t]}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'transfer' && (
          <Field label="Lokasi tujuan" required error={err('to_location_id')}>
            {(p) => (
              <Select {...p} value={values.to_location_id ?? ''} onChange={set('to_location_id')}>
                <option value="">— Pilih —</option>
                {locations.map((o) => (
                  <option key={o.value} value={o.value} disabled={o.value === asset?.location_id}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'status_change' && (
          <Field label="Status baru" required error={err('to_status')}>
            {(p) => (
              <Select {...p} value={values.to_status ?? ''} onChange={set('to_status')}>
                <option value="">— Pilih —</option>
                {selectableStatuses
                  .filter((s) => s !== asset?.status && (s !== 'disposed' || can('asset.dispose')))
                  .map((s) => (
                    <option key={s} value={s}>
                      {assetStatusLabel[s]}
                    </option>
                  ))}
              </Select>
            )}
          </Field>
        )}

        {type === 'loan' && (
          <>
            <Field label="Nama peminjam" required error={err('borrower_name')}>
              {(p) => <Input {...p} value={values.borrower_name ?? ''} onChange={set('borrower_name')} placeholder="mis. Budi (Divisi Operasional)" />}
            </Field>
            <Field label="Rencana tanggal kembali" error={err('due_date')} hint="Aset yang melewati tanggal ini ditandai di dashboard.">
              {(p) => <Input {...p} type="date" value={values.due_date ?? ''} onChange={set('due_date')} />}
            </Field>
          </>
        )}

        {type === 'return' && (
          <Field label="Dikembalikan ke lokasi" error={err('to_location_id')} hint="Kosongkan bila kembali ke lokasi semula.">
            {(p) => (
              <Select {...p} value={values.to_location_id ?? ''} onChange={set('to_location_id')}>
                <option value="">— Lokasi semula —</option>
                {locations.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}

        {type && (
          <>
            <Field label="Tanggal transaksi" required error={err('transaction_date')}>
              {(p) => <Input {...p} type="date" max={today()} value={values.transaction_date ?? ''} onChange={set('transaction_date')} />}
            </Field>
            <Field label={type === 'note' ? 'Keterangan aktivitas' : 'Catatan'} required={type === 'note'} error={err('notes')}>
              {(p) => <Textarea {...p} rows={3} value={values.notes ?? ''} onChange={set('notes')} />}
            </Field>
          </>
        )}
      </form>
    </Modal>
  )
}
