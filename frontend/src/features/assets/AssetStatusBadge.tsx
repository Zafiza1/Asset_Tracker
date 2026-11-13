import { Archive, CheckCircle2, CircleDashed, PowerOff, SearchX, UserRoundCheck, Wrench, type LucideIcon } from 'lucide-react'
import { Badge } from '../../components/ui/Badge'
import { assetStatusLabel, assetStatusTone, type AssetStatus } from './api'

/** One icon per asset status, so the status reads without its color. */
const assetStatusIcon: Record<AssetStatus, LucideIcon> = {
  in_use: CheckCircle2,
  available: CircleDashed,
  under_maintenance: Wrench,
  on_loan: UserRoundCheck,
  lost: SearchX,
  disposed: Archive,
}

/** The status of an asset, the same everywhere it appears; `archived` adds the "Nonaktif" marker. */
export function AssetStatusBadge({ status, archived = false }: { status: AssetStatus; archived?: boolean }) {
  return (
    <>
      <Badge tone={assetStatusTone[status]} icon={assetStatusIcon[status]}>
        {assetStatusLabel[status]}
      </Badge>
      {archived && (
        <Badge tone="slate" icon={PowerOff}>
          Nonaktif
        </Badge>
      )}
    </>
  )
}
