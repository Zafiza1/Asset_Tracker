const dateTime = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
const dateOnly = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' })

export function formatDateTime(value: string | null | undefined): string {
  return value ? dateTime.format(new Date(value)) : '—'
}

export function formatDate(value: string | null | undefined): string {
  return value ? dateOnly.format(new Date(value)) : '—'
}

export const userStatusLabel: Record<string, string> = {
  invited: 'Diundang',
  active: 'Aktif',
  suspended: 'Ditangguhkan',
  deactivated: 'Nonaktif',
}

export const invitationStatusLabel: Record<string, string> = {
  pending: 'Menunggu',
  accepted: 'Diterima',
  expired: 'Kedaluwarsa',
  revoked: 'Dicabut',
}

export const orgStatusLabel: Record<string, string> = {
  active: 'Aktif',
  suspended: 'Ditangguhkan',
  archived: 'Diarsipkan',
}

export const scopeTypeLabel: Record<string, string> = {
  organization: 'Seluruh organisasi',
  department: 'Departemen',
  location: 'Lokasi',
}

export const permissionGroupLabel: Record<string, string> = {
  organization: 'Organisasi',
  user: 'Pengguna',
  role: 'Role',
  master: 'Master Data',
  asset: 'Aset',
  document: 'Dokumen',
  transaction: 'Transaksi',
  report: 'Laporan',
  audit: 'Audit',
  workflow: 'Workflow',
  settings: 'Pengaturan',
  license: 'Lisensi',
  generic_master: 'Generic Master',
  platform: 'Platform',
}

export const masterStatusLabel: Record<string, string> = {
  active: 'Aktif',
  inactive: 'Nonaktif',
  archived: 'Diarsipkan',
}

export const versionStatusLabel: Record<string, string> = {
  draft: 'Draft',
  released: 'Dirilis',
  deprecated: 'Deprecated',
}

export const dataTypeLabel: Record<string, string> = {
  string: 'Teks singkat',
  text: 'Teks panjang',
  integer: 'Bilangan bulat',
  decimal: 'Desimal',
  boolean: 'Ya/Tidak',
  date: 'Tanggal',
  enum: 'Pilihan tunggal',
  multi_enum: 'Pilihan ganda',
}

export const overridableLabel: Record<string, string> = {
  label: 'Label',
  help_text: 'Teks bantuan',
  required: 'Wajib (hanya diperketat)',
  options: 'Opsi (subset)',
  validation: 'Validasi (hanya dipersempit)',
  default_value: 'Nilai default',
}

/** Audit action codes grouped by module, each with the sentence a user reads in the audit trail. */
export const auditActionGroups: { label: string; actions: Record<string, string> }[] = [
  {
    label: 'Akun & Login',
    actions: {
      'auth.login': 'Masuk ke aplikasi',
      'auth.login_failed': 'Gagal masuk',
      'auth.logout': 'Keluar dari aplikasi',
      'auth.registered': 'Mendaftar akun baru',
      'auth.organization_created': 'Membuat organisasi baru',
      'auth.organization_switched': 'Berpindah organisasi',
      'auth.password_changed': 'Mengganti kata sandi',
      'auth.email_verified': 'Memverifikasi email',
    },
  },
  {
    label: 'Organisasi',
    actions: {
      'organization.created': 'Membuat organisasi',
      'organization.updated': 'Mengubah profil organisasi',
      'organization.status_changed': 'Mengubah status organisasi',
      'settings.updated': 'Mengubah pengaturan organisasi',
    },
  },
  {
    label: 'Pengguna',
    actions: {
      'user.created': 'Menambah pengguna baru',
      'user.member_added': 'Menambahkan pengguna ke organisasi',
      'user.updated': 'Mengubah data pengguna',
      'user.status_changed': 'Mengubah status pengguna',
      'user.roles_changed': 'Mengubah role pengguna',
      'user.scopes_changed': 'Mengubah cakupan akses pengguna',
      'user.password_reset': 'Mereset kata sandi pengguna',
      'invitation.created': 'Mengundang pengguna',
      'invitation.resent': 'Mengirim ulang undangan',
      'invitation.revoked': 'Mencabut undangan',
      'invitation.accepted': 'Menerima undangan',
    },
  },
  {
    label: 'Role',
    actions: {
      'role.created': 'Membuat role',
      'role.updated': 'Mengubah role',
      'role.deleted': 'Menghapus role',
    },
  },
  {
    label: 'Departemen',
    actions: {
      'department.created': 'Menambah departemen',
      'department.updated': 'Mengubah departemen',
      'department.archived': 'Mengarsipkan departemen',
      'department.restored': 'Memulihkan departemen',
    },
  },
  {
    label: 'Lokasi',
    actions: {
      'location.created': 'Menambah lokasi',
      'location.updated': 'Mengubah lokasi',
      'location.archived': 'Mengarsipkan lokasi',
      'location.restored': 'Memulihkan lokasi',
    },
  },
  {
    label: 'Jenis Lokasi',
    actions: {
      'location_type.created': 'Menambah jenis lokasi',
      'location_type.updated': 'Mengubah jenis lokasi',
      'location_type.archived': 'Mengarsipkan jenis lokasi',
      'location_type.restored': 'Memulihkan jenis lokasi',
    },
  },
  {
    label: 'Aset',
    actions: {
      'asset.created': 'Mendaftarkan aset',
      'asset.updated': 'Mengubah data aset',
      'asset.archived': 'Mengarsipkan aset',
      'asset.restored': 'Memulihkan aset',
      'asset_transaction.created': 'Mencatat transaksi aset',
    },
  },
  {
    label: 'Kategori Aset',
    actions: {
      'tenant_category.created': 'Menambah kategori aset',
      'tenant_category.updated': 'Mengubah kategori aset',
      'tenant_category.archived': 'Mengarsipkan kategori aset',
      'tenant_category.restored': 'Memulihkan kategori aset',
    },
  },
  {
    label: 'Jenis Aset',
    actions: {
      'tenant_definition.adopted': 'Mengadopsi jenis aset dari katalog',
      'tenant_definition.updated': 'Mengubah jenis aset',
      'tenant_definition.archived': 'Mengarsipkan jenis aset',
      'tenant_definition.restored': 'Memulihkan jenis aset',
      'tenant_definition_version.created': 'Membuat draft versi jenis aset',
      'tenant_definition_version.updated': 'Mengubah draft versi jenis aset',
      'tenant_definition_version.discarded': 'Membuang draft versi jenis aset',
      'tenant_definition_version.released': 'Merilis versi jenis aset',
      'tenant_definition_version.deprecated': 'Menghentikan versi jenis aset',
      'tenant_attribute.created': 'Menambah atribut jenis aset',
      'tenant_attribute.updated': 'Mengubah atribut jenis aset',
      'tenant_attribute.deleted': 'Menghapus atribut jenis aset',
      'tenant_overrides.updated': 'Mengubah penyesuaian atribut katalog',
    },
  },
  {
    label: 'Katalog Generik',
    actions: {
      'generic_category.created': 'Menambah kategori katalog',
      'generic_category.updated': 'Mengubah kategori katalog',
      'generic_category.archived': 'Mengarsipkan kategori katalog',
      'generic_category.restored': 'Memulihkan kategori katalog',
      'generic_definition.created': 'Menambah definisi katalog',
      'generic_definition.updated': 'Mengubah definisi katalog',
      'generic_definition.archived': 'Mengarsipkan definisi katalog',
      'generic_definition.restored': 'Memulihkan definisi katalog',
      'generic_version.created': 'Membuat draft versi katalog',
      'generic_version.updated': 'Mengubah draft versi katalog',
      'generic_version.discarded': 'Membuang draft versi katalog',
      'generic_version.released': 'Merilis versi katalog',
      'generic_version.deprecated': 'Menghentikan versi katalog',
      'generic_attribute.created': 'Menambah atribut katalog',
      'generic_attribute.updated': 'Mengubah atribut katalog',
      'generic_attribute.deleted': 'Menghapus atribut katalog',
    },
  },
]

const auditActionLabels: Record<string, string> = Object.assign({}, ...auditActionGroups.map((g) => g.actions))

/** Readable label for an audit action code; unknown codes fall back to the code itself. */
export function auditActionLabel(code: string): string {
  return auditActionLabels[code] ?? code
}

/** Human-readable attribute value for tables and previews. */
export function formatAttributeValue(value: unknown, options?: { value: string; label: string }[] | null): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak'
  const label = (v: string) => options?.find((o) => o.value === v)?.label ?? v
  if (Array.isArray(value)) return value.map((v) => label(String(v))).join(', ')
  return label(String(value))
}
