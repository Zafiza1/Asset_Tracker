import { CheckCircle2, AlertCircle, Clock, XCircle, Users, UserPlus, Calendar, CreditCard, Key } from 'lucide-react'
import { Card } from '../../components/ui/Card'
import type { License, LicenseCode } from '../../lib/api/types'

interface LicenseCardProps {
  license?: License
  licenseCodes?: LicenseCode[]
}

export function LicenseCard({ license, licenseCodes = [] }: LicenseCardProps) {
  console.log('LicenseCard received:', license, licenseCodes)
  console.log('License codes references:', licenseCodes.map(c => ({ code: c.code, reference: c.reference })))
  console.log('License references:', license.licenses.map(l => ({ id: l.id, reference: l.reference })))
  if (!license) return null

  const { active, over_limit, seats, licenses } = license

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'active':
        return 'text-green-600 bg-green-50'
      case 'scheduled':
        return 'text-blue-600 bg-blue-50'
      case 'expired':
        return 'text-red-600 bg-red-50'
      case 'revoked':
        return 'text-gray-600 bg-gray-50'
      default:
        return 'text-gray-600 bg-gray-50'
    }
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'active':
        return <CheckCircle2 className="size-4" />
      case 'scheduled':
        return <Clock className="size-4" />
      case 'expired':
        return <XCircle className="size-4" />
      case 'revoked':
        return <XCircle className="size-4" />
      default:
        return <AlertCircle className="size-4" />
    }
  }

  const getSourceLabel = (source: string) => {
    switch (source) {
      case 'trial':
        return 'Trial'
      case 'purchase':
        return 'Pembelian'
      case 'migration':
        return 'Migrasi'
      default:
        return source
    }
  }

  const getKindLabel = (kind: string, reference?: string | null) => {
    // Check if this is SUPER_ADMIN_FREE license
    if (reference === 'SUPER_ADMIN_FREE') {
      return 'Lisensi Super Admin'
    }

    switch (kind) {
      case 'base':
        return 'Lisensi Dasar'
      case 'seats':
        return 'Paket Seat'
      default:
        return kind
    }
  }

  const formatDate = (dateString: string) => {
    const date = new Date(dateString)
    if (isNaN(date.getTime())) return dateString
    return date.toLocaleDateString('id-ID', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  }

  return (
    <Card>
      <div className="p-4">
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-lg font-semibold text-slate-900">Status Lisensi</h3>
          <div className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium ${active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
            {active ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />}
            {active ? 'Aktif' : 'Tidak Aktif'}
          </div>
        </div>

        {/* Seat Usage */}
        <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="mb-1 flex items-center gap-2 text-sm text-slate-600">
              <Users className="size-4" />
              <span>Kapasitas</span>
            </div>
            <div className="text-2xl font-bold text-slate-900">{seats.capacity}</div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="mb-1 flex items-center gap-2 text-sm text-slate-600">
              <CheckCircle2 className="size-4" />
              <span>Aktif</span>
            </div>
            <div className="text-2xl font-bold text-slate-900">{seats.active_members}</div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <div className="mb-1 flex items-center gap-2 text-sm text-slate-600">
              <UserPlus className="size-4" />
              <span>Pending</span>
            </div>
            <div className="text-2xl font-bold text-slate-900">{seats.pending_invitations}</div>
          </div>
          <div className={`rounded-lg border p-3 ${over_limit ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-slate-50'}`}>
            <div className="mb-1 flex items-center gap-2 text-sm text-slate-600">
              <AlertCircle className={`size-4 ${over_limit ? 'text-red-600' : ''}`} />
              <span>Tersedia</span>
            </div>
            <div className={`text-2xl font-bold ${over_limit ? 'text-red-600' : 'text-slate-900'}`}>{seats.available}</div>
          </div>
        </div>

        {over_limit && (
          <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            <AlertCircle className="mb-1 inline-block size-4" />
            Organisasi melebihi batas seat. Silakan hubungi Super Admin untuk menambah lisensi atau menonaktifkan anggota.
          </div>
        )}

        {/* License List */}
        <div>
          <h4 className="mb-3 text-sm font-medium text-slate-700">Detail Lisensi</h4>
          <div className="space-y-2">
            {licenses.map((license) => {
              // Find license code for this license by matching reference
              const licenseCode = licenseCodes.find(code => code.reference === license.reference)
              console.log(`License ${license.id} (ref: ${license.reference}) -> matched code:`, licenseCode?.code || 'NOT FOUND')
              return (
                <div key={license.id} className="rounded-lg border border-slate-200 bg-white p-3">
                  <div className="mb-2 flex items-start justify-between">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-slate-900">{getKindLabel(license.kind, license.reference)}</span>
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${getStatusColor(license.status)}`}>
                          {getStatusIcon(license.status)}
                          {license.status === 'active' ? 'Aktif' : license.status === 'scheduled' ? 'Terjadwal' : license.status === 'expired' ? 'Kadaluarsa' : 'Dicabut'}
                        </span>
                      </div>
                      <div className="mt-1 text-sm text-slate-600">
                        {license.reference === 'SUPER_ADMIN_FREE' ? '' :
                         license.kind === 'base' ? '1 seat' : `${license.seats} seat`}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-xs text-slate-500">{getSourceLabel(license.source)}</div>
                      {license.reference && <div className="text-xs text-slate-500">{license.reference}</div>}
                    </div>
                  </div>
                  <div className="flex items-center gap-4 text-xs text-slate-500">
                    <div className="flex items-center gap-1">
                      <Calendar className="size-3" />
                      <span>Mulai: {formatDate(license.starts_at)}</span>
                    </div>
                    <div className="flex items-center gap-1">
                      <Clock className="size-3" />
                      <span>Berakhir: {formatDate(license.expires_at)}</span>
                    </div>
                  </div>
                  {licenseCode && (
                    <div className="mt-3 rounded-md bg-slate-50 p-2">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <Key className="size-3.5 text-slate-500" />
                          <span className="font-mono text-sm font-medium text-slate-900">{licenseCode.code}</span>
                          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                            licenseCode.status === 'available' ? 'bg-green-100 text-green-800' :
                            licenseCode.status === 'assigned' ? 'bg-blue-100 text-blue-800' :
                            'bg-red-100 text-red-800'
                          }`}>
                            {licenseCode.status === 'available' ? 'Tersedia' :
                             licenseCode.status === 'assigned' ? 'Ditugaskan' :
                             'Dicabut'}
                          </span>
                        </div>
                        {licenseCode.assigned_user && (
                          <div className="text-right">
                            <div className="text-xs font-medium text-slate-900">{licenseCode.assigned_user.name}</div>
                            <div className="text-xs text-slate-500">{licenseCode.assigned_user.email}</div>
                          </div>
                        )}
                      </div>
                    </div>
                  )}
                  {license.revoked_at && (
                    <div className="mt-2 text-xs text-red-600">
                      Dicabut pada {formatDate(license.revoked_at)}: {license.revoke_reason}
                    </div>
                  )}
                  {license.notes && (
                    <div className="mt-2 text-xs text-slate-600 italic">{license.notes}</div>
                  )}
                </div>
              )
            })}
          </div>
        </div>

        {!active && (
          <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <AlertCircle className="mb-1 inline-block size-4" />
            Lisensi tidak aktif. Organisasi dalam mode read-only. Silakan hubungi Super Admin untuk memperbarui lisensi.
          </div>
        )}
      </div>
    </Card>
  )
}
