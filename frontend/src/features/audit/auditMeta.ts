import type { BadgeTone } from '../../components/ui/badgeTones'
import type { AuditLog } from '../../lib/api/types'
import { auditActionGroups, auditActionLabel } from '../../lib/format'

/** A module is one group of the action list; its codes share these prefixes ("asset", "asset_transaction"). */
export interface AuditModule {
  key: string
  label: string
  prefixes: string[]
}

const prefixOf = (code: string) => code.split('.')[0]

export const auditModules: AuditModule[] = auditActionGroups.map((g) => ({
  key: [...new Set(Object.keys(g.actions).map(prefixOf))].join(','),
  label: g.label,
  prefixes: [...new Set(Object.keys(g.actions).map(prefixOf))],
}))

export function auditModuleOf(code: string): AuditModule | null {
  const prefix = prefixOf(code)
  return auditModules.find((m) => m.prefixes.includes(prefix)) ?? null
}

export type AuditCategory = 'create' | 'update' | 'delete' | 'access' | 'approval' | 'export'

export const auditCategories: Record<AuditCategory, { label: string; tone: BadgeTone }> = {
  create: { label: 'Tambah', tone: 'green' },
  update: { label: 'Ubah', tone: 'blue' },
  delete: { label: 'Hapus / Arsip', tone: 'red' },
  access: { label: 'Akses & Login', tone: 'slate' },
  approval: { label: 'Persetujuan', tone: 'amber' },
  export: { label: 'Ekspor', tone: 'slate' },
}

const CREATE = /(created|added|registered|adopted|granted|assigned|accepted)$/
const DELETE = /(deleted|removed|archived|revoked|discarded|deprecated)$/

/** What kind of change an action is, for the badge and the "Kategori aktivitas" filter. */
export function auditCategoryOf(code: string): AuditCategory {
  const prefix = prefixOf(code)
  if (prefix === 'auth') return code === 'auth.registered' || code === 'auth.organization_created' ? 'create' : 'access'
  if (prefix === 'approval') return 'approval'
  if (prefix === 'report') return 'export'
  // A license code being released goes back to the pool: it is taken away from someone.
  if (code === 'license_code.released') return 'delete'
  if (CREATE.test(code)) return 'create'
  if (DELETE.test(code)) return 'delete'
  return 'update'
}

const knownCodes = auditActionGroups.flatMap((g) => Object.keys(g.actions))

/** The known action codes in a category, sent to the API as `actions`. */
export function auditCodesOf(category: AuditCategory): string[] {
  return knownCodes.filter((c) => auditCategoryOf(c) === category)
}

/**
 * Action codes whose readable label (or module name) contains the search term, so typing
 * "foto" or "arsip" finds rows whose code is in English. Empty when the term is too broad
 * to be useful as a code list.
 */
export function auditCodesMatching(term: string): string[] {
  const t = term.trim().toLowerCase()
  if (t.length < 3) return []
  const codes = knownCodes.filter((c) => auditActionLabel(c).toLowerCase().includes(t) || auditModuleOf(c)?.label.toLowerCase().includes(t))
  return codes.join(',').length > 3500 ? [] : codes
}

const entityTypeLabels: Record<string, string> = {
  Asset: 'Aset',
  AssetTransaction: 'Transaksi aset',
  ApprovalRequest: 'Pengajuan',
  User: 'Pengguna',
  Department: 'Departemen',
  Location: 'Lokasi',
  Role: 'Role',
  Organization: 'Organisasi',
  OrganizationInvitation: 'Undangan',
  OrganizationLicense: 'Lisensi',
  LicenseCode: 'Kode lisensi',
  TenantAssetCategory: 'Kategori aset',
  TenantCategoryAttribute: 'Atribut kategori',
  TenantAssetDefinition: 'Jenis aset',
  TenantDefinitionVersion: 'Versi jenis aset',
  TenantAttributeDefinition: 'Atribut jenis aset',
  GenericAssetCategory: 'Kategori katalog',
  GenericAssetDefinition: 'Definisi katalog',
  GenericDefinitionVersion: 'Versi katalog',
  GenericAttributeDefinition: 'Atribut katalog',
}

export function entityTypeLabel(type: string | null): string | null {
  if (!type) return null
  return entityTypeLabels[type] ?? type.replace(/([a-z])([A-Z])/g, '$1 $2')
}

const fieldLabels: Record<string, string> = {
  name: 'Nama',
  code: 'Kode',
  status: 'Status',
  email: 'Email',
  phone: 'Telepon',
  address: 'Alamat',
  description: 'Deskripsi',
  notes: 'Catatan',
  reason: 'Alasan',
  asset_number: 'Nomor aset',
  serial_number: 'Nomor seri',
  category_id: 'Kategori',
  location_id: 'Lokasi',
  department_id: 'Departemen',
  home_department_id: 'Departemen',
  condition_id: 'Kondisi',
  acquisition_date: 'Tanggal perolehan',
  acquisition_cost: 'Harga perolehan',
  borrower_name: 'Peminjam',
  attributes: 'Atribut',
  label: 'Label',
  key: 'Kunci',
  employee_number: 'NIK',
  job_title: 'Jabatan',
  roles: 'Role',
  role_ids: 'Role',
  permissions: 'Izin akses',
  scopes: 'Cakupan akses',
  timezone: 'Zona waktu',
  module: 'Modul',
  transaction_number: 'Nomor transaksi',
  type: 'Jenis',
  summary: 'Ringkasan',
  decision_note: 'Catatan keputusan',
  parent_id: 'Induk',
}

export function auditFieldLabel(key: string): string {
  if (fieldLabels[key]) return fieldLabels[key]
  const words = key.replace(/_id$/, '').replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** A recorded value as text: scalars as-is, lists joined, objects as compact JSON. */
export function auditValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  if (Array.isArray(value) && value.every((v) => typeof v !== 'object' || v === null)) return value.length ? value.join(', ') : '—'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}

const fullDateTime = new Intl.DateTimeFormat('id-ID', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  timeZoneName: 'short',
})

export const formatFullDateTime = (value: string) => fullDateTime.format(new Date(value))

/** Who did it: the user's name, or "Sistem" for console jobs. */
export function actorName(log: AuditLog): string {
  return log.actor?.name ?? (log.actor_type === 'system' ? 'Sistem' : 'Tidak dikenal')
}

