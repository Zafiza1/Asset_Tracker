import type { AttributeValue, CategoryAttribute } from './types'

/** Same formats as the web app (frontend/src/lib/format.ts). */
const dateTime = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
const dateOnly = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' })
const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })

export function formatDateTime(value: string | null | undefined): string {
  return value ? dateTime.format(new Date(value)) : '—'
}

export function formatDate(value: string | null | undefined): string {
  return value ? dateOnly.format(new Date(value)) : '—'
}

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return money.format(Number(value))
}

export function formatAttributeValue(value: unknown, options?: { value: string; label: string }[] | null): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  const label = (v: string) => options?.find((o) => o.value === v)?.label ?? v
  if (Array.isArray(value)) return value.map((v) => label(String(v))).join(', ')
  return label(String(value))
}

/** Category fields with their values, grouped by section (as attributeRowsBySection on the web). */
export function attributeRowsBySection(fields: CategoryAttribute[], values: Record<string, AttributeValue>) {
  const grouped: Record<string, { key: string; label: string; value: string }[]> = {}
  for (const f of fields) {
    const section = f.section || 'other'
    ;(grouped[section] ??= []).push({ key: f.key, label: f.label, value: formatAttributeValue(values[f.key], f.options) })
  }
  return grouped
}
