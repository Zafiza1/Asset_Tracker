import type { NamedRule } from './api'

/**
 * Plain helpers of the Transaction Designer (F-08e): operators per condition subject, rule order
 * and a quick rule sentence while it is built, and a code suggested from a name.
 */

export const OPERATORS: { value: string; label: string }[] = [
  { value: 'eq', label: 'sama dengan' },
  { value: 'neq', label: 'tidak sama dengan' },
  { value: 'gt', label: 'lebih dari' },
  { value: 'gte', label: 'minimal' },
  { value: 'lt', label: 'kurang dari' },
  { value: 'lte', label: 'maksimal' },
  { value: 'in', label: 'termasuk salah satu' },
  { value: 'not_in', label: 'bukan salah satu' },
  { value: 'empty', label: 'kosong' },
  { value: 'not_empty', label: 'terisi' },
]

/** Operators a subject takes (FlowGraph::SUBJECT_OPERATORS), with words that read for it. */
export function operatorsFor(subject: string, numeric: boolean): { value: string; label: string }[] {
  if (subject === 'asset.custodian') return [{ value: 'not_empty', label: 'ada' }, { value: 'empty', label: 'belum ada' }]
  if (subject === 'starter.is_custodian') return [{ value: 'eq', label: 'adalah' }, { value: 'neq', label: 'bukan' }]
  if (subject === 'target.department_differs') return [{ value: 'eq', label: 'berbeda dengan departemen aset' }, { value: 'neq', label: 'sama dengan departemen aset' }]
  if (subject === 'core.outgoing_nature') return OPERATORS.filter((o) => ['eq', 'neq', 'in', 'not_in'].includes(o.value))
  return OPERATORS.filter((o) => numeric || !['gt', 'gte', 'lt', 'lte'].includes(o.value))
}

/** Priorities 1..n in list order. */
export function renumber(rules: NamedRule[]): NamedRule[] {
  return rules.map((r, i) => ({ ...r, priority: i + 1 }))
}

/** By priority, then the order given. */
export function byPriority(rules: NamedRule[]): NamedRule[] {
  return rules.map((r, i) => [r, i] as const).sort((a, b) => a[0].priority - b[0].priority || a[1] - b[1]).map(([r]) => r)
}

const SUBJECT_LABELS: Record<string, string> = {
  'asset.acquisition_cost': 'nilai perolehan',
  'asset.status': 'status aset',
  'asset.condition': 'kondisi aset',
  'asset.category': 'kategori aset',
  'asset.location': 'lokasi aset',
  'asset.department': 'departemen aset',
  'asset.custodian': 'penanggung jawab aset',
  'starter.is_custodian': 'pencatat adalah penanggung jawab',
  'target.department_differs': 'departemen asal penerima',
  'starter.role': 'role pencatat',
  'core.outgoing_nature': 'sifat transaksi keluar',
}

export function subjectLabel(subject: string, fields: { key: string; label: string }[]): string {
  return SUBJECT_LABELS[subject] ?? fields.find((f) => `field.${f.key}` === subject)?.label ?? ''
}

/**
 * A quick sentence while the rule is built; the saved rule is told by the server with labels
 * for every value (FlowDescriber), which the list shows.
 */
export function rulePreview(rule: NamedRule, fields: { key: string; label: string }[]): string {
  const ops = Object.fromEntries(OPERATORS.map((o) => [o.value, o.label]))
  const value = (v: unknown) => (Array.isArray(v) ? v.join(', ') : typeof v === 'number' ? v.toLocaleString('id-ID') : v === undefined || v === null ? '…' : String(v))
  const parts = rule.conditions.map((c) => {
    const name = SUBJECT_LABELS[c.subject] ?? fields.find((f) => f.key === c.subject)?.label ?? c.subject
    return c.op === 'empty' || c.op === 'not_empty' ? `${name} ${ops[c.op]}` : `${name} ${ops[c.op] ?? c.op} ${value(c.value)}`
  })
  const when = parts.length === 0 ? 'Selalu' : `Bila ${parts.join(rule.match === 'any' ? ' atau ' : ' dan ')},`
  const then = { reject: 'transaksi ditolak', approval: 'perlu persetujuan', skip_approval: 'langkah persetujuan dilewati' }[rule.outcome.kind]
  return `${when} ${then}.`
}

/** A number prefix from a name: initials ("Kirim Kalibrasi" → "KK"), else its first letters ("Kalibrasi" → "KAL"). */
export function codeFromName(name: string): string {
  const words = name.toUpperCase().replace(/[^A-Z ]+/g, ' ').split(/\s+/).filter(Boolean)
  const initials = words.map((w) => w[0]).join('').slice(0, 6)
  return initials.length >= 2 ? initials : (words[0] ?? '').slice(0, 3)
}
