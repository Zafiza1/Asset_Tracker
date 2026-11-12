import type { BadgeTone } from '../../../components/ui/badgeTones'
import type { LicenseCodeStatus, LicenseEntry } from '../../../lib/api/types'

/**
 * Words used on the license page. Seats and codes are different things, so they never share a
 * word: a seat is "terpakai"/"tersisa", a code is "siap ditugaskan"/"ditugaskan", and
 * a license (the purchase) is "berlaku"/"terjadwal"/"kedaluwarsa"/"dicabut".
 */
export const codeStatusLabel: Record<LicenseCodeStatus, string> = {
  available: 'Siap ditugaskan',
  assigned: 'Ditugaskan',
}

export const codeStatusHint: Record<LicenseCodeStatus, string> = {
  available: 'Belum dipegang siapa pun; bisa ditugaskan ke pengguna atau dipakai saat mengundang.',
  assigned: 'Sedang dipegang oleh seorang pengguna; lepaskan agar bisa dipakai orang lain.',
}

export const codeStatusTone: Record<LicenseCodeStatus, BadgeTone> = {
  available: 'blue',
  assigned: 'green',
}

export const licenseStatusLabel: Record<LicenseEntry['status'], string> = {
  active: 'Berlaku',
  scheduled: 'Terjadwal',
  expired: 'Kedaluwarsa',
  revoked: 'Dicabut',
}

export const licenseStatusTone: Record<LicenseEntry['status'], BadgeTone> = {
  active: 'green',
  scheduled: 'blue',
  expired: 'slate',
  revoked: 'red',
}

export const licenseSourceLabel: Record<string, string> = {
  trial: 'Trial',
  purchase: 'Pembelian',
  migration: 'Migrasi',
}

export function licenseKindLabel(license: Pick<LicenseEntry, 'kind' | 'reference'>): string {
  if (license.reference === 'SUPER_ADMIN_FREE') return 'Lisensi Super Admin'
  return license.kind === 'seats' ? 'Paket seat' : 'Lisensi pengguna'
}

/** The invitation form with this code already picked: a code goes to a person by inviting them. */
export const inviteWithCodeHref = (code: { id: string }) => `/pengguna/baru?license_code=${encodeURIComponent(code.id)}`

/** Whole days from now until `iso` (negative once past). */
export function daysUntil(iso: string, now = Date.now()): number {
  return Math.ceil((new Date(iso).getTime() - now) / 86_400_000)
}

export interface Batch {
  key: string
  kind: string
  source: string
  status: LicenseEntry['status']
  startsAt: string
  expiresAt: string
  revokedAt: string | null
  references: string[]
}

/**
 * Licenses bought together (same kind, source, period and status) as one row: a purchase of 20
 * users is one line with "20 lisensi", not twenty identical ones.
 */
export function groupLicenses(licenses: LicenseEntry[]): Batch[] {
  const batches = new Map<string, Batch>()
  for (const l of licenses) {
    const kind = licenseKindLabel(l)
    const key = [kind, l.source, l.status, l.starts_at.slice(0, 10), l.expires_at.slice(0, 10)].join('|')
    const batch = batches.get(key) ?? { key, kind, source: l.source, status: l.status, startsAt: l.starts_at, expiresAt: l.expires_at, revokedAt: l.revoked_at, references: [] }
    if (l.reference) batch.references.push(l.reference)
    batches.set(key, batch)
  }
  return [...batches.values()]
}
