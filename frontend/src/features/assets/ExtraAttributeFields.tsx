import { Plus, Trash2 } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select } from '../../components/ui/Field'
import { IconButton } from '../../components/ui/IconButton'
import type { AttributeValue } from '../../lib/api/types'
import { AttributeValueInput } from '../master/attributes'
import { EXTRA_TYPE_LABEL, type ExtraAttribute, type ExtraAttributeType } from './api'

/**
 * Fields only this asset has (a keyboard's "Jenis konektor"), next to its category's fields:
 * a name, a type and a value each. The category does not change.
 */
export function ExtraAttributeFields({
  values,
  errors,
  onChange,
}: {
  values: ExtraAttribute[]
  /** Server messages keyed "{index}.label" / "{index}.value" / "{index}.data_type". */
  errors: Record<string, string>
  onChange: (values: ExtraAttribute[]) => void
}) {
  const set = (index: number, patch: Partial<ExtraAttribute>) => onChange(values.map((v, i) => (i === index ? { ...v, ...patch } : v)))

  return (
    <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
      <legend className="px-1 text-sm font-semibold text-slate-700">Atribut tambahan aset ini</legend>
      {values.length === 0 ? (
        <p className="text-sm text-slate-500">Tambahkan atribut yang hanya dimiliki aset ini, misalnya jenis konektor untuk keyboard. Kategori tidak berubah.</p>
      ) : (
        <ul className="space-y-3">
          {values.map((attr, i) => (
            <li key={i} className="grid items-start gap-3 sm:grid-cols-[minmax(0,1fr)_9rem_minmax(0,1fr)_auto]">
              <Field label="Nama atribut" required error={errors[`${i}.label`]}>
                {(p) => <Input {...p} value={attr.label} maxLength={100} onChange={(e) => set(i, { label: e.target.value })} placeholder="Mis. Jenis konektor" />}
              </Field>
              <Field label="Tipe" error={errors[`${i}.data_type`]}>
                {(p) => (
                  // A new type starts empty: a value of the old type rarely fits the new one.
                  <Select {...p} value={attr.data_type} onChange={(e) => set(i, { data_type: e.target.value as ExtraAttributeType, value: null })}>
                    {(Object.keys(EXTRA_TYPE_LABEL) as ExtraAttributeType[]).map((t) => (
                      <option key={t} value={t}>
                        {EXTRA_TYPE_LABEL[t]}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Nilai" error={errors[`${i}.value`]}>
                {(p) => <AttributeValueInput id={p.id} spec={{ data_type: attr.data_type, options: null }} value={attr.value} onChange={(v: AttributeValue) => set(i, { value: v })} />}
              </Field>
              <IconButton label={`Hapus atribut ${attr.label || 'tambahan'}`} className="sm:mt-7" onClick={() => onChange(values.filter((_, j) => j !== i))}>
                <Trash2 className="size-4" aria-hidden />
              </IconButton>
            </li>
          ))}
        </ul>
      )}
      <Button variant="secondary" size="sm" className="mt-3" onClick={() => onChange([...values, { label: '', data_type: 'string', value: null }])}>
        <Plus className="size-4" aria-hidden /> Tambah atribut
      </Button>
    </fieldset>
  )
}
