import * as Location from 'expo-location'
import { ApiError } from './api'
import type { Delivery, DeliveryReportType, Profile } from './types'

/** Token ability issued at login to accounts with delivery.operate (MobileAccessToken::DELIVERY_WRITE). */
const DELIVERY_WRITE = 'delivery:write'

/** Whether this login may scan and report: the permission plus the ability of its token. */
export function canOperateDeliveries(profile: Profile | null, can: (p: string) => boolean): boolean {
  return can('delivery.operate') && !!profile?.abilities?.includes(DELIVERY_WRITE)
}

/** When and where something happened; the position is left out when the phone has none. */
export interface DeliveryMeta {
  recorded_at: string
  latitude?: number
  longitude?: number
  accuracy?: number
}

/** A scan waits at most this long for a position; the scan itself matters more. */
const FIX_TIMEOUT_MS = 6_000
/** A position this recent is used as is, so scanning many cylinders does not wait for GPS each time. */
const RECENT_MS = 20_000
/** Older than this, a position would put the vehicle in the wrong place. */
const STALE_MS = 60_000

let asked = false

async function permitted(): Promise<boolean> {
  if ((await Location.getForegroundPermissionsAsync()).granted) return true
  // Asked once per launch; a refusal is not asked again on every scan.
  if (asked) return false
  asked = true
  return (await Location.requestForegroundPermissionsAsync()).granted
}

async function currentPosition(fresh: boolean): Promise<Location.LocationObject | null> {
  try {
    if (!(await permitted())) return null
    if (!fresh) {
      const recent = await Location.getLastKnownPositionAsync({ maxAge: RECENT_MS })
      if (recent) return recent
    }
    let timer: ReturnType<typeof setTimeout> | undefined
    const timeout = new Promise<null>((resolve) => {
      timer = setTimeout(() => resolve(null), FIX_TIMEOUT_MS)
    })
    const fix = await Promise.race([Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }), timeout]).finally(() => clearTimeout(timer))
    return fix ?? (await Location.getLastKnownPositionAsync({ maxAge: STALE_MS }))
  } catch {
    // No fix (indoors, location switched off): the report goes through without a position.
    return null
  }
}

/** Where and when the phone is, for a scan or report. `fresh` does not settle for a recent position. */
export async function deliveryMeta({ fresh = false } = {}): Promise<DeliveryMeta> {
  const recorded_at = new Date().toISOString()
  const position = await currentPosition(fresh)
  if (!position) return { recorded_at }
  const { latitude, longitude, accuracy } = position.coords
  return { recorded_at, latitude, longitude, ...(accuracy !== null && { accuracy }) }
}

/** crypto.randomUUID is not in every Hermes build; a client_id only has to be unique per report. */
export function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

const unanswered = new Map<string, string>()

/**
 * Sends a report with a client_id. One that got no answer may have been recorded all the same, so
 * the same report sent again carries the same id and the server records it once.
 */
export async function sendOnce<T>(key: string, send: (clientId: string) => Promise<T>): Promise<T> {
  const id = unanswered.get(key) ?? uuid()
  unanswered.set(key, id)
  try {
    const answer = await send(id)
    unanswered.delete(key)
    return answer
  } catch (e) {
    if (e instanceof ApiError && e.status > 0 && e.status < 500) unanswered.delete(key)
    throw e
  }
}

/** Metres between two positions (haversine). */
export function distance(a: { latitude: number; longitude: number }, b: { latitude: number; longitude: number }): number {
  const rad = (deg: number) => (deg * Math.PI) / 180
  const h =
    Math.sin(rad(b.latitude - a.latitude) / 2) ** 2 +
    Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(rad(b.longitude - a.longitude) / 2) ** 2
  return 2 * 6_371_000 * Math.asin(Math.sqrt(h))
}

export const REPORT_LABEL: Record<DeliveryReportType, string> = {
  depart: 'Berangkat',
  position: 'Lapor posisi',
  issue: 'Kendala di jalan',
  resume: 'Lanjut perjalanan',
  arrive: 'Tiba di customer',
  stop_done: 'Selesai di customer',
  return: 'Tiba di gudang',
  completed: 'Selesaikan transaksi',
}

export const DELIVERY_STATUS_COLORS: Record<Delivery['status'], [string, string]> = {
  draft: ['#f1f5f9', '#334155'],
  loading: ['#fef3c7', '#92400e'],
  in_transit: ['#dbeafe', '#1e40af'],
  completed: ['#dcfce7', '#166534'],
  cancelled: ['#f1f5f9', '#334155'],
}
