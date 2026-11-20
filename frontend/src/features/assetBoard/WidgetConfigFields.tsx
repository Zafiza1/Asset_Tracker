import { useQuery } from '@tanstack/react-query'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { assetStatusLabel, type AssetStatus } from '../assets/api'
import { useCategoryAttributes } from '../master/categoryAttributeData'
import { useOptions } from '../master/options'
import { templatesApi } from '../reportDesigner/api'
import { transactionTypeLabel, type TransactionType } from '../transactions/api'
import { typesApi } from '../transactionTypes/api'
import { OPTION_LABEL, type ConfigField } from './api'

/** System transaction codes a widget may filter by (custom rows are chosen by their own type). */
const SYSTEM_TYPES: TransactionType[] = ['registration', 'transfer', 'status_change', 'loan', 'return', 'note', 'archive', 'restore', 'outgoing', 'incoming']

const optionLabel = (o: string) => OPTION_LABEL[o] ?? assetStatusLabel[o as AssetStatus] ?? o

/**
 * Settings of one widget, built from its catalog schema (data, never code). The server
 * validates the result again; filters only narrow the member's own data scope.
 */
export function WidgetConfigFields({ fields, value, onChange, categoryId, errors = {} }: { fields: Record<string, ConfigField>; value: Record<string, unknown>; onChange: (next: Record<string, unknown>) => void; categoryId?: string | null; errors?: Record<string, string[]> }) {
  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v })
  const entries = Object.entries(fields)
  if (entries.length === 0) return <p className="text-sm text-slate-500">Widget ini tidak memiliki pengaturan.</p>

  return (
    <div className="space-y-4">
      {entries.map(([key, field]) => (
        <ConfigInput key={key} name={key} field={field} value={value[key] ?? field.default} onChange={(v) => set(key, v)} categoryId={categoryId} error={errors[`configuration.${key}`]?.[0]} />
      ))}
    </div>
  )
}

function ConfigInput({ name, field, value, onChange, categoryId, error }: { name: string; field: ConfigField; value: unknown; onChange: (v: unknown) => void; categoryId?: string | null; error?: string }) {
  switch (field.type) {
    case 'enum':
      return (
        <Field label={field.label} error={error}>
          {(p) => (
            <Select {...p} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value || null)}>
              {field.default === undefined && <option value="">—</option>}
              {field.options.map((o) => (
                <option key={o} value={o}>
                  {optionLabel(o)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      )
    case 'enum_list':
      return <CheckList label={field.label} options={field.options.map((o) => ({ value: o, label: optionLabel(o) }))} value={value} onChange={onChange} error={error} />
    case 'integer':
      return (
        <Field label={field.label} error={error} hint={field.min !== undefined && field.max !== undefined ? `${field.min}–${field.max}` : undefined}>
          {(p) => (
            <Input
              {...p}
              type="number"
              min={field.min}
              max={field.max}
              value={typeof value === 'number' ? value : ''}
              onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
              className={`${p.className} max-w-32`}
            />
          )}
        </Field>
      )
    case 'boolean':
      return (
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 rounded border-slate-300" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
          {field.label}
        </label>
      )
    case 'text':
      return (
        <Field label={field.label} error={error} hint={field.max ? `Maksimal ${field.max} karakter` : undefined}>
          {(p) => <Textarea {...p} rows={4} maxLength={field.max} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)} />}
        </Field>
      )
    case 'category_ids':
      return <MasterList label={field.label} endpoint="/tenant-masters/categories" value={value} onChange={onChange} error={error} />
    case 'location_ids':
      return <MasterList label={field.label} endpoint="/locations" value={value} onChange={onChange} error={error} />
    case 'transaction_types':
      return <TransactionTypes label={field.label} value={value} onChange={onChange} error={error} />
    case 'report_template':
      return <ReportTemplateSelect label={field.label} value={value} onChange={onChange} error={error} />
    case 'attribute':
      return <AttributeInput name={name} field={field} value={value} onChange={onChange} categoryId={categoryId} error={error} />
  }
}

function CheckList({ label, options, value, onChange, error }: { label: string; options: { value: string; label: string }[]; value: unknown; onChange: (v: string[]) => void; error?: string }) {
  const selected = Array.isArray(value) ? (value as string[]) : []
  const toggle = (v: string) => onChange(selected.includes(v) ? selected.filter((s) => s !== v) : [...selected, v])
  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-semibold text-slate-700">{label}</legend>
      {options.length === 0 ? (
        <p className="text-sm text-slate-500">Tidak ada pilihan.</p>
      ) : (
        <div className="grid max-h-44 gap-1.5 overflow-y-auto rounded-lg p-2 ring-1 ring-inset ring-slate-200 sm:grid-cols-2">
          {options.map((o) => (
            <label key={o.value} className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" className="size-4 rounded border-slate-300" checked={selected.includes(o.value)} onChange={() => toggle(o.value)} />
              <span className="truncate">{o.label}</span>
            </label>
          ))}
        </div>
      )}
      <p className={error ? 'mt-1.5 text-sm text-red-700' : 'mt-1.5 text-xs text-slate-500'}>{error ?? 'Kosongkan untuk semua dalam cakupan data Anda.'}</p>
    </fieldset>
  )
}

function MasterList({ label, endpoint, value, onChange, error }: { label: string; endpoint: string; value: unknown; onChange: (v: string[]) => void; error?: string }) {
  const rows = useOptions(endpoint).data ?? []
  return <CheckList label={label} options={rows.map((r) => ({ value: r.id, label: r.name }))} value={value} onChange={onChange} error={error} />
}

function TransactionTypes({ label, value, onChange, error }: { label: string; value: unknown; onChange: (v: string[]) => void; error?: string }) {
  const custom = useQuery({ queryKey: ['/transaction-types', 'active'], queryFn: () => typesApi.list({ status: 'active' }), staleTime: 30_000 })
  const options = [...SYSTEM_TYPES.map((t) => ({ value: t, label: transactionTypeLabel[t] })), ...(custom.data?.data ?? []).map((t) => ({ value: t.id, label: t.name }))]
  return <CheckList label={label} options={options} value={value} onChange={onChange} error={error} />
}

function ReportTemplateSelect({ label, value, onChange, error }: { label: string; value: unknown; onChange: (v: string | null) => void; error?: string }) {
  const templates = useQuery({ queryKey: ['/report-templates', 'widget-options'], queryFn: () => templatesApi.list({ status: 'published', per_page: 100 }), staleTime: 30_000 })
  return (
    <Field label={label} error={error}>
      {(p) => (
        <Select {...p} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value || null)}>
          <option value="">Tidak ada</option>
          {(templates.data?.data ?? []).map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </Select>
      )}
    </Field>
  )
}

/**
 * A category attribute: on a category dashboard, a choice among that category's attributes
 * of the right type; elsewhere the attribute's label, resolved per category by the server.
 */
function AttributeInput({ name, field, value, onChange, categoryId, error }: { name: string; field: Extract<ConfigField, { type: 'attribute' }>; value: unknown; onChange: (v: string | null) => void; categoryId?: string | null; error?: string }) {
  const attributes = useCategoryAttributes(categoryId).data ?? []
  const types = field.data_types ?? (field.data_type ? [field.data_type] : null)
  const usable = attributes.filter((a) => !types || types.includes(a.data_type))
  // `attribute` holds a key (one category); `attribute_label` a label that works across categories.
  const byKey = name === 'attribute'

  if (categoryId && byKey) {
    return (
      <Field label={field.label} error={error}>
        {(p) => (
          <Select {...p} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value || null)}>
            <option value="">—</option>
            {usable.map((a) => (
              <option key={a.key} value={a.key}>
                {a.label}
              </option>
            ))}
          </Select>
        )}
      </Field>
    )
  }
  if (byKey) return null
  return (
    <Field label={field.label} error={error} hint="Nama atribut seperti di kategori, mis. Merek. Berlaku untuk setiap kategori yang punya atribut ini.">
      {(p) => <Input {...p} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value || null)} />}
    </Field>
  )
}
