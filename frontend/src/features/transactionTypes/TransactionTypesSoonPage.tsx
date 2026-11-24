import { Workflow } from 'lucide-react'
import { Badge } from '../../components/ui/Badge'
import { ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { EmptyState } from '../../components/ui/Feedback'

/** Stands in for every page of Jenis Transaksi while it is closed (availability.ts). */
export function TransactionTypesSoonPage() {
  return (
    <>
      <PageHeader title="Jenis Transaksi" badges={<Badge tone="amber">Soon</Badge>} />
      <Card>
        <EmptyState
          icon={Workflow}
          title="Segera hadir"
          description="Pengaturan jenis transaksi sedang kami siapkan supaya lebih mudah dipakai. Mencatat transaksi tetap bisa dilakukan seperti biasa."
          action={
            <ButtonLink to="/transaksi" variant="secondary">
              Buka Transaksi
            </ButtonLink>
          }
        />
      </Card>
    </>
  )
}
