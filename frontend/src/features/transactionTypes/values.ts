import type { AttachmentSummary, TypeField } from './api'

export type FieldValues = Record<string, unknown>

/** A field key from its label: lower case, letters/digits/underscore, starting with a letter. */
export function keyFromLabel(label: string, taken: string[]): string {
  let base = label
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 36)
  if (!/^[a-z]/.test(base)) base = `f_${base}`.slice(0, 36)
  if (base === 'f_') base = 'field'
  let key = base
  for (let i = 2; taken.includes(key); i++) key = `${base}_${i}`
  return key
}

/** Values as the API takes them: attachment summaries become their id, blank strings null. */
export function toPayload(fields: TypeField[], values: FieldValues): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const f of fields) {
    if (!(f.key in values)) continue
    let v = values[f.key]
    if (f.data_type === 'attachment' && v && typeof v === 'object') v = (v as AttachmentSummary).id
    if (typeof v === 'string' && v.trim() === '') v = null
    out[f.key] = typeof v === 'string' ? v.trim() : v
  }
  return out
}
