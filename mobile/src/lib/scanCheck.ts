import type { Delivery, DeliveryItem } from './types'

export type ScanPhase = 'load' | 'unload'

/** The item a scanned label (asset id) or typed No Manufaktur points at, if it is in this transaction. */
export function findItem(delivery: Delivery | null, code: string): DeliveryItem | undefined {
  const key = code.trim().toLowerCase()
  return (delivery?.items ?? []).find((i) => i.asset_id.toLowerCase() === key || (i.serial_number ?? '').toLowerCase() === key)
}

/** Whether the item already counts for this phase: loaded (or further) for muat, unloaded for bongkar. */
export function isScanned(item: DeliveryItem, phase: ScanPhase): boolean {
  return phase === 'load' ? item.state === 'loaded' || item.state === 'unloaded' : item.state === 'unloaded'
}

/**
 * The cylinders this scan screen is about: those of the chosen customer, or all of them at the
 * warehouse; for bongkar only what is on the vehicle (or already unloaded).
 */
export function scanTargets(delivery: Delivery | null, phase: ScanPhase, stopId?: string): DeliveryItem[] {
  const states = phase === 'load' ? ['planned', 'loaded', 'unloaded'] : ['loaded', 'unloaded']
  return (delivery?.items ?? []).filter((i) => (!stopId || i.stop_id === stopId) && states.includes(i.state))
}

export function itemText(item: DeliveryItem): string {
  return [item.serial_number, item.name].filter(Boolean).join(' · ')
}
