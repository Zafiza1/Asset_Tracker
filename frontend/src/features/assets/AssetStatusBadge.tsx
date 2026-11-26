import { Archive, CheckCircle2, CircleDashed, PowerOff, SearchX, Truck, UserRoundCheck, Wrench, type LucideIcon } from 'lucide-react'
import { Badge } from '../../components/ui/Badge'
import { assetStatusTone, statusLabel, type AssetStatus } from './api'

/** One icon per asset status, so the status reads without its color. */
const assetStatusIcon: Record<AssetStatus, LucideIcon> = {
  in_use: CheckCircle2,
  available: CircleDashed,
  under_maintenance: Wrench,
  on_loan: UserRoundCheck,
  off_site: Truck,
  lost: SearchX,
  disposed: Archive,
}

/** The status of an asset, the same everywhere it appears; `archived` adds the "Nonaktif" marker, `cylinder` uses the labels of a cylinder. */
export function AssetStatusBadge({ status, archived = false, cylinder = false }: { status: AssetStatus; archived?: boolean; cylinder?: boolean }) {
  return (
    <>
      <Badge tone={assetStatusTone[status]} icon={assetStatusIcon[status]}>
        {statusLabel(status, cylinder)}
      </Badge>
      {archived && (
        <Badge tone="slate" icon={PowerOff}>
          Nonaktif
        </Badge>
      )}
    </>
  )
}
