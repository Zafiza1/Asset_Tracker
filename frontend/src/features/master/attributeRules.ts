import type { AttributeDataType, AttributeOption, AttributeValidation } from '../../lib/api/types'

/** Mirrors App\Domain\GenericMaster\Attributes\AttributeType::VALIDATION_KEYS. */
export const validationKeys: Record<AttributeDataType, (keyof AttributeValidation)[]> = {
  string: ['min_length', 'max_length', 'pattern'],
  text: ['min_length', 'max_length'],
  integer: ['min', 'max'],
  decimal: ['min', 'max', 'decimals'],
  date: ['min', 'max'],
  boolean: [],
  enum: [],
  multi_enum: [],
}

export const validationLabel: Record<keyof AttributeValidation, string> = {
  min: 'Minimum',
  max: 'Maksimum',
  min_length: 'Panjang minimum',
  max_length: 'Panjang maksimum',
  pattern: 'Pola (regex)',
  decimals: 'Angka di belakang koma',
}

export const hasOptions = (type: AttributeDataType) => type === 'enum' || type === 'multi_enum'

/** Validation keys a tenant may narrow (pattern is fixed). */
export const narrowableKeys = (type: AttributeDataType) => validationKeys[type].filter((k) => k !== 'pattern')

/** Converts the string from an input into the typed value the API expects. */
export function parseValidationValue(type: AttributeDataType, key: keyof AttributeValidation, raw: string): number | string | undefined {
  if (raw.trim() === '') return undefined
  if (key === 'pattern' || type === 'date') return raw
  return type === 'decimal' && (key === 'min' || key === 'max') ? Number(raw) : parseInt(raw, 10)
}

/** Compact description of an attribute's rules for tables. */
export function describeRules(a: { validation: AttributeValidation; options: AttributeOption[] | null; unit?: { symbol: string } | null }): string {
  const parts = Object.entries(a.validation ?? {}).map(([k, v]) => `${validationLabel[k as keyof AttributeValidation] ?? k}: ${v}`)
  if (a.options) parts.push(`${a.options.length} opsi`)
  if (a.unit) parts.push(`satuan ${a.unit.symbol}`)
  return parts.join(' · ') || '—'
}
