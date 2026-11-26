import { MapPin, PowerOff, Truck, Warehouse } from 'lucide-react'
import { Link } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import type { BadgeTone } from '../../components/ui/badgeTones'
import { AssetStatusBadge } from './AssetStatusBadge'
import type { Asset, AssetStatus, LocationStatusKey } from './api'

const tone: Record<LocationStatusKey, BadgeTone> = { warehouse: 'blue', in_transit: 'amber', at_customer: 'green' }
const icon = { warehouse: Warehouse, in_transit: Truck, at_customer: MapPin }

/** Statuses that say more than where a cylinder is; shown next to its Lokasi Status. */
const NOTABLE: AssetStatus[] = ['under_maintenance', 'on_loan', 'lost', 'disposed']

/**
 * Lokasi Status of an asset (Di Gudang, Dalam Perjalanan, Di Customer), set by the system from its
 * transactions, with where exactly (customer, delivery) underneath. A status like Dalam Perbaikan and
 * the Nonaktif marker follow when they apply.
 */
export function LocationStatusBadge({ asset, compact = false }: { asset: Asset; compact?: boolean }) {
  const location = asset.location_status
  return (
    <div className="min-w-0">
      <div className="flex flex-wrap gap-1">
        <Badge tone={tone[location.key]} icon={icon[location.key]}>
          {location.label}
        </Badge>
        {NOTABLE.includes(asset.status) && <AssetStatusBadge status={asset.status} cylinder />}
        {asset.is_archived && (
          <Badge tone="slate" icon={PowerOff}>
            Nonaktif
          </Badge>
        )}
      </div>
      {!compact &&
        location.detail &&
        (location.delivery_id ? (
          <Link to={`/transaksi/${location.delivery_id}`} title={location.delivery_number ?? undefined} className="mt-0.5 line-clamp-2 block whitespace-normal text-xs text-slate-500 hover:text-brand-700">
            {location.detail}
          </Link>
        ) : (
          <span className="mt-0.5 block text-xs text-slate-500">{location.detail}</span>
        ))}
    </div>
  )
}
