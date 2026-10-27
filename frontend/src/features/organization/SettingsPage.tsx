import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '../../components/ui/Card'
import { Spinner } from '../../components/ui/Feedback'
import { api } from '../../lib/api/client'
import type { License, Resource } from '../../lib/api/types'
import { LicenseCard } from './LicenseCard'

/** Organization settings (number formats, upload limit, self-approval) are system defaults; only the license is shown. */
export function SettingsPage() {
  const licenseQuery = useQuery({ queryKey: ['license'], queryFn: () => api.get<Resource<License>>('/license') })

  if (licenseQuery.isLoading) return <Spinner />

  return (
    <>
      <PageHeader title="Pengaturan" />
      <div className="grid gap-6">
        <LicenseCard license={licenseQuery.data?.data} />
      </div>
    </>
  )
}
