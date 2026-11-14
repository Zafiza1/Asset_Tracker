import { useMutation, useQueries, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { api, isApprovalPending } from '../../lib/api/client'
import type { AttributeValue, CategoryAttribute } from '../../lib/api/types'
import { errorMessage } from '../../lib/forms'
import { AttributeValueInput } from '../master/attributes'
import { useAssetFields } from '../master/categoryAttributeData'
import { toOptions, useOptions } from '../master/options'
import type { Asset } from './api'

/** Fields that may be set on many assets at once; mirrors AssetController::BULK_EDITABLE. */
type Key = 'category_id' | 'acquisition_date' | 'acquisition_cost' | 'description'

const LABELS: Record<Key, string> = {
  category_id: 'Kategori',
  acquisition_date: 'Tanggal perolehan',
  acquisition_cost: 'Harga perolehan (Rp)',
  description: 'Deskripsi',
}

const toggle = <T,>(list: T[], item: T) => (list.includes(item) ? list.filter((x) => x !== item) : [...list, item])

/**
 * Multiple Edit: overwrite the ticked fields on every asset checked in the list (active ones
 * only). Fields left unticked keep each asset's own value. All assets are saved or none.
 */
export function BulkEditModal({ assets, onClose, onDone }: { assets: Asset[]; onClose: () => void; onDone: () => void }) {
  const queryClient = useQueryClient()
  const categories = toOptions(useOptions('/tenant-masters/categories').data)
  const [enabled, setEnabled] = useState<Key[]>([])
  const [values, setValues] = useState<Record<Key, string>>({ category_id: '', acquisition_date: '', acquisition_cost: '', description: '' })
  const [error, setError] = useState<string | null>(null)

  // Category fields: of the new category when it is being changed, else of every category among
  // the chosen assets (a field one asset's category lacks is ignored for that asset by the API).
  const [enabledAttributes, setEnabledAttributes] = useState<string[]>([])
  const [attributeValues, setAttributeValues] = useState<Record<string, AttributeValue>>({})
  const fieldCategories =
    enabled.includes('category_id') && values.category_id ? [values.category_id] : [...new Set(assets.map((a) => a.category_id))]
  const fieldQueries = useQueries({
    queries: fieldCategories.map((id) => ({
      queryKey: ['category-attributes', id],
      queryFn: async () => (await api.get<{ data: CategoryAttribute[] }>(`/tenant-masters/categories/${id}/attributes`)).data,
      staleTime: 30_000,
    })),
  })
  const fields = useAssetFields([...new Map(fieldQueries.flatMap((q) => q.data ?? []).map((f) => [f.key, f])).values()])
  const sentAttributes = fields.filter((f) => enabledAttributes.includes(f.key))
  const nothingTicked = enabled.length === 0 && sentAttributes.length === 0

  const save = useMutation({
    mutationFn: () =>
      api.post<{ data: { updated: number } }>('/assets/bulk-update', {
        asset_ids: assets.map((a) => a.id),
        changes: {
          ...Object.fromEntries(enabled.map((k) => [k, values[k].trim() === '' ? null : values[k].trim()])),
          ...(sentAttributes.length > 0 && { attributes: Object.fromEntries(sentAttributes.map((f) => [f.key, attributeValues[f.key] ?? null])) }),
        },
      }),
    onSuccess: async (response) => {
      await Promise.all(['/assets', '/dashboard'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
      // Submitted for approval: the app announces that on its own.
      if (!isApprovalPending(response)) toast.success('Multiple Edit disimpan', `${response.data.updated} aset diperbarui`)
      onDone()
      onClose()
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const submit = () => {
    setError(null)
    if (nothingTicked) return setError('Centang minimal satu data yang akan diubah.')
    if (enabled.includes('category_id') && !values.category_id) return setError('Pilih kategori baru.')
    save.mutate()
  }

  return (
    <Modal
      size="xl"
      open
      title={`Multiple Edit ${assets.length} aset`}
      onClose={save.isPending ? () => {} : onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={save.isPending}>
            Batal
          </Button>
          <Button onClick={submit} loading={save.isPending} disabled={nothingTicked}>
            Simpan ({assets.length} aset)
          </Button>
        </>
      }
    >
      <div className="space-y-6">
        {error && <Alert tone="error">{error}</Alert>}

        <section>
          <h3 className="mb-1.5 text-sm font-semibold text-slate-700">Aset yang diubah</h3>
          <ul className="max-h-40 divide-y divide-slate-100 overflow-y-auto rounded-md text-sm ring-1 ring-slate-200">
            {assets.map((a) => (
              <li key={a.id} className="flex gap-2 px-3 py-1.5">
                <span className="shrink-0 font-mono text-xs leading-5 text-slate-500">{a.asset_number}</span>
                <span className="truncate text-slate-900" title={a.name}>{a.name}</span>
                <span className="ml-auto shrink-0 text-xs leading-5 text-slate-500">{a.category?.name}</span>
              </li>
            ))}
          </ul>
        </section>

        <fieldset className="space-y-3">
          <legend className="mb-1.5 text-sm font-semibold text-slate-700">Data yang diubah (centang yang ingin ditimpa)</legend>
          <p className="text-xs text-slate-500">Data yang tidak dicentang tetap seperti semula di masing-masing aset. Nama, No Seri, lokasi, dan status diubah per aset.</p>
          {(Object.keys(LABELS) as Key[]).map((key) => (
            <div key={key} className="flex items-start gap-3">
              <input
                type="checkbox"
                className="mt-9 size-4 rounded border-slate-300"
                aria-label={`Ubah ${LABELS[key]}`}
                checked={enabled.includes(key)}
                onChange={() => setEnabled(toggle(enabled, key))}
              />
              <div className="flex-1">
                <Field label={LABELS[key]} hint={key === 'category_id' && enabled.includes(key) ? 'Isian tambahan kategori lama yang tidak berlaku di kategori baru akan dihapus.' : undefined}>
                  {(p) => {
                    const common = { ...p, disabled: !enabled.includes(key), value: values[key], onChange: (e: { target: { value: string } }) => setValues({ ...values, [key]: e.target.value }) }
                    if (key === 'category_id')
                      return (
                        <Select {...common}>
                          <option value="">— Pilih —</option>
                          {categories.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </Select>
                      )
                    if (key === 'description') return <Textarea {...common} rows={2} placeholder="Kosongkan untuk menghapus deskripsi" />
                    if (key === 'acquisition_date') return <Input {...common} type="date" />
                    return <Input {...common} type="number" min={0} step="1" inputMode="numeric" />
                  }}
                </Field>
              </div>
            </div>
          ))}
        </fieldset>

        {fields.length > 0 && (
          <fieldset className="space-y-3">
            <legend className="mb-1.5 text-sm font-semibold text-slate-700">Isian tambahan kategori (centang yang ingin ditimpa)</legend>
            <p className="text-xs text-slate-500">Isian yang tidak dimiliki kategori suatu aset dilewati untuk aset tersebut.</p>
            {fields.map((f) => (
              <div key={f.key} className="flex items-start gap-3">
                <input
                  type="checkbox"
                  className="mt-9 size-4 rounded border-slate-300"
                  aria-label={`Ubah ${f.label}`}
                  checked={enabledAttributes.includes(f.key)}
                  onChange={() => setEnabledAttributes(toggle(enabledAttributes, f.key))}
                />
                <div className="flex-1">
                  <Field label={f.label} required={f.is_required}>
                    {(p) => (
                      <AttributeValueInput
                        id={p.id}
                        spec={f}
                        disabled={!enabledAttributes.includes(f.key)}
                        value={attributeValues[f.key] ?? null}
                        onChange={(v) => setAttributeValues({ ...attributeValues, [f.key]: v })}
                      />
                    )}
                  </Field>
                </div>
              </div>
            ))}
          </fieldset>
        )}
      </div>
    </Modal>
  )
}
