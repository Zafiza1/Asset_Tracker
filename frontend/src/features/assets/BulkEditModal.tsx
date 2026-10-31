import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { Alert, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api, qs } from '../../lib/api/client'
import type { AttributeValue, CategoryAttribute, Paginated } from '../../lib/api/types'
import { AttributeValueInput } from '../master/attributes'
import { toOptions, useOptions } from '../master/options'
import type { Asset } from './api'

/** Fields that may be set on many assets at once; mirrors AssetController::BULK_EDITABLE. */
type Key = 'category_id' | 'acquisition_date' | 'acquisition_cost' | 'description'

const LABELS: Record<Key, string> = {
  category_id: 'Kategori',
  acquisition_date: 'Tanggal perolehan',
  acquisition_cost: 'Nilai perolehan (Rp)',
  description: 'Deskripsi & informasi tambahan',
}

/** Upper bound of one batch; the API accepts at most 1000 ids. */
const MAX_ASSETS = 1000

/**
 * Edit several assets at once: pick one or more categories (a parent also covers its
 * subcategories), untick the assets to leave out, then choose which fields to overwrite —
 * everything except name, serial number, location and status, including the category fields.
 * Only active assets are listed; all are saved or none.
 */
export function BulkEditModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const categories = toOptions(useOptions('/tenant-masters/categories').data)
  const [categoryIds, setCategoryIds] = useState<string[]>([])
  const [excluded, setExcluded] = useState<string[]>([])
  const [enabled, setEnabled] = useState<Key[]>([])
  const [values, setValues] = useState<Record<Key, string>>({ category_id: '', acquisition_date: '', acquisition_cost: '', description: '' })
  const [error, setError] = useState<string | null>(null)

  const filters = { category_ids: categoryIds.join(','), per_page: String(MAX_ASSETS), sort: 'asset_number' }
  const assets = useQuery({
    queryKey: ['/assets', 'bulk', filters],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs(filters)}`),
    enabled: categoryIds.length > 0,
  })
  const rows = categoryIds.length > 0 ? (assets.data?.data ?? []) : []
  const selected = rows.filter((a) => !excluded.includes(a.id))

  const toggle = <T,>(list: T[], item: T) => (list.includes(item) ? list.filter((x) => x !== item) : [...list, item])

  // Category fields: of the new category when it is being changed, else of every category among
  // the chosen assets (a field one asset's category lacks is ignored for that asset by the API).
  const [enabledAttributes, setEnabledAttributes] = useState<string[]>([])
  const [attributeValues, setAttributeValues] = useState<Record<string, AttributeValue>>({})
  const fieldCategories =
    enabled.includes('category_id') && values.category_id ? [values.category_id] : [...new Set(selected.map((a) => a.category_id))]
  const fieldQueries = useQueries({
    queries: fieldCategories.map((id) => ({
      queryKey: ['category-attributes', id],
      queryFn: async () => (await api.get<{ data: CategoryAttribute[] }>(`/tenant-masters/categories/${id}/attributes`)).data,
      staleTime: 30_000,
    })),
  })
  const fields = [...new Map(fieldQueries.flatMap((q) => q.data ?? []).map((f) => [f.key, f])).values()]
  const sentAttributes = fields.filter((f) => enabledAttributes.includes(f.key))

  const save = useMutation({
    mutationFn: () =>
      api.post<{ data: { updated: number } }>('/assets/bulk-update', {
        asset_ids: selected.map((a) => a.id),
        changes: {
          ...Object.fromEntries(enabled.map((k) => [k, values[k].trim() === '' ? null : values[k].trim()])),
          ...(sentAttributes.length > 0 && { attributes: Object.fromEntries(sentAttributes.map((f) => [f.key, attributeValues[f.key] ?? null])) }),
        },
      }),
    onSuccess: async () => {
      await Promise.all(['/assets', '/dashboard'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })))
      onClose()
    },
    onError: (e) => setError(e.message),
  })

  const submit = () => {
    setError(null)
    if (selected.length === 0) return setError('Pilih minimal satu aset.')
    if (enabled.length === 0 && sentAttributes.length === 0) return setError('Centang minimal satu data yang akan diubah.')
    if (enabled.includes('category_id') && !values.category_id) return setError('Pilih kategori baru.')
    save.mutate()
  }

  return (
    <Modal
      size="xl"
      open
      title="Edit massal aset"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={submit} loading={save.isPending} disabled={selected.length === 0 || (enabled.length === 0 && sentAttributes.length === 0)}>
            Simpan {selected.length > 0 && `(${selected.length} aset)`}
          </Button>
        </>
      }
    >
      <div className="space-y-6">
        {error && <Alert tone="error">{error}</Alert>}

        <fieldset>
          <legend className="mb-1.5 text-sm font-semibold text-slate-700">1. Pilih kategori (boleh lebih dari satu)</legend>
          <div className="grid max-h-48 gap-1 overflow-y-auto rounded-lg p-2 ring-1 ring-inset ring-slate-200 sm:grid-cols-2">
            {categories.length === 0 && <p className="p-2 text-sm text-slate-500">Belum ada kategori.</p>}
            {categories.map((o) => (
              <label key={o.value} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                <input type="checkbox" className="size-4 rounded border-slate-300" checked={categoryIds.includes(o.value)} onChange={() => setCategoryIds(toggle(categoryIds, o.value))} />
                <span className="whitespace-pre text-slate-800">{o.label}</span>
              </label>
            ))}
          </div>
        </fieldset>

        <fieldset>
          <legend className="mb-1.5 text-sm font-semibold text-slate-700">
            2. Aset yang diubah {rows.length > 0 && `(${selected.length} dari ${rows.length})`}
          </legend>
          {categoryIds.length === 0 ? (
            <p className="text-sm text-slate-500">Pilih kategori untuk menampilkan asetnya.</p>
          ) : assets.isLoading ? (
            <Spinner />
          ) : assets.isError ? (
            <Alert tone="error">{assets.error.message}</Alert>
          ) : rows.length === 0 ? (
            <p className="text-sm text-slate-500">Tidak ada aset aktif pada kategori yang dipilih.</p>
          ) : (
            <>
              {assets.data!.meta.total > rows.length && (
                <Alert tone="info">Hanya {rows.length} aset pertama yang ditampilkan. Persempit pilihan kategori untuk mengubah sisanya.</Alert>
              )}
              <label className="flex items-center gap-2 px-2 py-1.5 text-sm font-medium text-slate-700">
                <input
                  type="checkbox"
                  className="size-4 rounded border-slate-300"
                  checked={selected.length === rows.length}
                  onChange={() => setExcluded(selected.length === rows.length ? rows.map((a) => a.id) : [])}
                />
                Pilih semua
              </label>
              <div className="max-h-60 overflow-y-auto rounded-lg p-2 ring-1 ring-inset ring-slate-200">
                {rows.map((a) => (
                  <label key={a.id} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                    <input type="checkbox" className="size-4 rounded border-slate-300" checked={!excluded.includes(a.id)} onChange={() => setExcluded(toggle(excluded, a.id))} />
                    <span className="font-mono text-xs text-slate-500">{a.asset_number}</span>
                    <span className="truncate text-slate-900">{a.name}</span>
                    <span className="ml-auto shrink-0 text-xs text-slate-500">{a.category?.name}</span>
                  </label>
                ))}
              </div>
            </>
          )}
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="mb-1.5 text-sm font-semibold text-slate-700">3. Data yang diubah (centang yang ingin ditimpa)</legend>
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
            <legend className="mb-1.5 text-sm font-semibold text-slate-700">4. Isian tambahan kategori (centang yang ingin ditimpa)</legend>
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
