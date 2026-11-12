import type { Organization } from '../../lib/api/types'

export type ProfileField = 'name' | 'legal_name' | 'tax_id' | 'email' | 'phone' | 'address' | 'timezone' | 'currency'

/** The profile fields, grouped as the page shows them. Completeness is counted over all of them. */
export const PROFILE_SECTIONS: { key: 'identity' | 'contact' | 'regional'; fields: { key: ProfileField; label: string }[] }[] = [
  {
    key: 'identity',
    fields: [
      { key: 'name', label: 'Nama' },
      { key: 'legal_name', label: 'Nama legal' },
      { key: 'tax_id', label: 'NPWP' },
    ],
  },
  {
    key: 'contact',
    fields: [
      { key: 'email', label: 'Email' },
      { key: 'phone', label: 'Telepon' },
      { key: 'address', label: 'Alamat' },
    ],
  },
  {
    key: 'regional',
    fields: [
      { key: 'timezone', label: 'Zona waktu' },
      { key: 'currency', label: 'Mata uang' },
    ],
  },
]

export const isFilled = (value: unknown) => typeof value === 'string' && value.trim() !== ''

export function profileCompleteness(org: Pick<Organization, ProfileField>) {
  const fields = PROFILE_SECTIONS.flatMap((s) => s.fields)
  const missing = fields.filter((f) => !isFilled(org[f.key]))
  const filled = fields.length - missing.length
  return { total: fields.length, filled, missing, percent: Math.round((filled / fields.length) * 100) }
}

/** Shapes the 15-digit NPWP as 99.999.999.9-999.999 and the 16-digit one in groups of four. */
export function formatNpwp(value: string | null | undefined): string {
  if (!value) return ''
  const d = value.replace(/\D/g, '')
  if (d.length === 15) return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}.${d.slice(8, 9)}-${d.slice(9, 12)}.${d.slice(12)}`
  if (d.length === 16) return d.replace(/(\d{4})(?=\d)/g, '$1 ')
  return value
}

export const npwpDigits = (value: string) => value.replace(/[\s.-]/g, '')
export const phoneDigits = (value: string) => value.replace(/[\s.\-()]/g, '')

/** Same rules as the server (OrganizationProfileController), on the separator-free value. */
export const NPWP_PATTERN = /^(\d{15}|\d{16})$/
export const PHONE_PATTERN = /^(0\d{8,13}|\+[1-9]\d{7,14})$/
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Indonesian zones by their local abbreviation; the rest are shown with their UTC offset. */
export const INDONESIAN_TIMEZONES: { zone: string; label: string }[] = [
  { zone: 'Asia/Jakarta', label: 'WIB' },
  { zone: 'Asia/Pontianak', label: 'WIB' },
  { zone: 'Asia/Makassar', label: 'WITA' },
  { zone: 'Asia/Jayapura', label: 'WIT' },
]

function utcOffset(zone: string): string | null {
  try {
    const part = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'shortOffset' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')
    return part ? part.value.replace('GMT', 'UTC') : null
  } catch {
    return null
  }
}

/** "Asia/Jakarta (WIB)", or "Europe/Berlin (UTC+1)" outside Indonesia. */
export function describeTimezone(zone: string): string {
  const local = INDONESIAN_TIMEZONES.find((z) => z.zone === zone)?.label ?? utcOffset(zone)
  return local ? `${zone} (${local})` : zone
}

export function allTimezones(): string[] {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return INDONESIAN_TIMEZONES.map((z) => z.zone)
  }
}

/** Currencies offered first in the form; any other ISO code the organization already has stays selectable. */
export const COMMON_CURRENCIES = ['IDR', 'USD', 'SGD', 'MYR', 'EUR', 'JPY', 'CNY', 'AUD', 'GBP']

export function currencyName(code: string): string | null {
  try {
    const name = new Intl.DisplayNames(['id'], { type: 'currency' }).of(code)
    return name && name !== code ? name : null
  } catch {
    return null
  }
}

/** "IDR (Rupiah Indonesia)". */
export function describeCurrency(code: string): string {
  const name = currencyName(code)
  return name ? `${code} (${name})` : code
}
