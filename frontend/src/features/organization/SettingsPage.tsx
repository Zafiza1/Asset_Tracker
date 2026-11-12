import { useQuery } from '@tanstack/react-query'
import { Badge } from '../../components/ui/Badge'
import { PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import type { License, Resource } from '../../lib/api/types'
import { formatDate } from '../../lib/format'
import { daysUntil } from './license/labels'
import { LicenseCodes } from './license/LicenseCodes'
import { LicenseHistory } from './license/LicenseHistory'
import { SeatUsage } from './license/SeatUsage'

/** The organization's license: seat usage, the license codes people hold, and the licenses bought. */
export function SettingsPage() {
  const query = useQuery({ queryKey: ['license'], queryFn: () => api.get<Resource<License>>('/license') })

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const license = query.data!.data

  // The organization works until its last base license in force ends.
  const validUntil = license.licenses
    .filter((l) => l.status === 'active' && l.kind === 'base')
    .map((l) => l.expires_at)
    .sort()
    .at(-1)
  const days = validUntil ? daysUntil(validUntil) : null

  return (
    <>
      <PageHeader
        title="Lisensi"
        badges={<Badge tone={license.active ? 'green' : 'red'}>{license.active ? 'Lisensi aktif' : 'Lisensi tidak aktif'}</Badge>}
        description="Pantau pemakaian seat dan kelola kode lisensi yang dipegang pengguna."
        meta={validUntil && <span>Berlaku sampai {formatDate(validUntil)}{days !== null && days <= 60 && ` · ${days <= 0 ? 'berakhir hari ini' : `${days} hari lagi`}`}</span>}
      />
      <div className="space-y-6">
        {!license.active && (
          <Alert tone="error" title="Organisasi dalam mode hanya-baca">
            Tidak ada lisensi yang sedang berlaku. Data tetap bisa dilihat, tetapi tidak bisa diubah sampai lisensi diperpanjang.
          </Alert>
        )}
        {license.active && license.over_limit && (
          <Alert tone="warning" title="Pemakaian seat melebihi kapasitas">
            Nonaktifkan pengguna atau batalkan undangan yang tidak diperlukan, atau tambah lisensi, agar pengguna baru bisa diundang lagi.
          </Alert>
        )}
        <SeatUsage license={license} />
        <LicenseCodes />
        <LicenseHistory licenses={license.licenses} />
      </div>
    </>
  )
}
