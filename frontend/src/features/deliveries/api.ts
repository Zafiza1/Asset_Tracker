import type { BadgeTone } from '../../components/ui/badgeTones'
import { api, qs } from '../../lib/api/client'
import type { Paginated, Resource } from '../../lib/api/types'

/** Deliveries of cylinders: API shapes and labels. Statuses mirror App\Domain\Deliveries\Models. */

export type Direction = 'outgoing' | 'incoming'
export type DeliveryStatus = 'draft' | 'loading' | 'in_transit' | 'completed' | 'cancelled'
export type StopStatus = 'pending' | 'arrived' | 'done' | 'skipped'
export type ItemState = 'planned' | 'loaded' | 'unloaded' | 'not_loaded' | 'returned' | 'missing' | 'cancelled'

export interface Position {
  latitude: number
  longitude: number
  accuracy: number | null
  type: string
  recorded_at: string
}

export interface DeliveryEvent {
  id: string
  type: string
  type_label: string
  note: string | null
  stop_id: string | null
  customer: string | null
  asset: string | null
  actor: string | null
  position: Position | null
  recorded_at: string
}

export interface DeliveryStop {
  id: string
  sequence: number
  customer: { id: string; name: string; address: string | null; phone: string | null }
  status: StopStatus
  status_label: string
  arrived_at: string | null
  done_at: string | null
  receiver_name: string | null
  note: string | null
}

export interface DeliveryItem {
  id: string
  stop_id: string
  asset_id: string
  name: string
  serial_number: string | null
  details: Record<string, string>
  planned: boolean
  state: ItemState
  state_label: string
  loaded_at: string | null
  loaded_by: string | null
  unloaded_at: string | null
  unloaded_by: string | null
}

export interface Person {
  id: string
  name: string
  phone: string | null
}

export interface Delivery {
  id: string
  number: string
  direction: Direction
  direction_label: string
  status: DeliveryStatus
  status_label: string
  planned_date: string
  expected_return_date: string | null
  notes: string | null
  vehicle: { id: string; name: string; plate: string | null } | null
  driver: Person | null
  helper: Person | null
  created_by: string | null
  created_at: string
  departed_at: string | null
  returned_at: string | null
  ended_at: string | null
  end_note: string | null
  stops: DeliveryStop[]
  counts: { total: number; loaded: number; unloaded: number }
  last_event: DeliveryEvent | null
  last_position: Position | null
}

export interface DeliveryDetail extends Delivery {
  items: DeliveryItem[]
  events: DeliveryEvent[]
  route: Position[]
}

export interface Cylinder {
  id: string
  name: string
  serial_number: string | null
  details: Record<string, string>
  position?: string | null
}

export interface DeliveryOptions {
  vehicles: { id: string; name: string; plate: string | null; on_trip: boolean }[]
  drivers: { id: string; name: string; on_trip: boolean }[]
  helpers: { id: string; name: string; on_trip: boolean }[]
  customers: { id: string; code: string; name: string; address: string | null }[]
  return_days: number
}

export interface DeliveryInput {
  planned_date: string
  expected_return_date: string | null
  vehicle_asset_id: string | null
  notes: string | null
  stops: { customer_id: string; asset_ids: string[] }[]
}

export const directionTone: Record<Direction, BadgeTone> = { outgoing: 'blue', incoming: 'green' }

export const deliveryStatusLabel: Record<DeliveryStatus, string> = {
  draft: 'Disiapkan',
  loading: 'Sedang Muat',
  in_transit: 'Dalam Perjalanan',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
}

export const deliveryStatusTone: Record<DeliveryStatus, BadgeTone> = {
  draft: 'slate',
  loading: 'amber',
  in_transit: 'blue',
  completed: 'green',
  cancelled: 'red',
}

export const stopTone: Record<StopStatus, BadgeTone> = { pending: 'slate', arrived: 'amber', done: 'green', skipped: 'slate' }

export const itemTone: Record<ItemState, BadgeTone> = {
  planned: 'slate',
  loaded: 'blue',
  unloaded: 'green',
  not_loaded: 'slate',
  returned: 'amber',
  missing: 'red',
  cancelled: 'slate',
}

export const DELIVERIES_KEY = ['/deliveries']
/** Pages about open deliveries refresh by themselves while they are on screen. */
export const REFRESH_MS = 20_000

export const deliveriesApi = {
  list: (params: Record<string, string | null | undefined>) => api.get<Paginated<Delivery>>(`/deliveries${qs(params)}`),
  tracking: () => api.get<{ data: Delivery[] }>('/deliveries/tracking'),
  options: () => api.get<Resource<DeliveryOptions>>('/deliveries/options'),
  cylinders: (params: Record<string, string | null | undefined>) => api.get<{ data: Cylinder[]; meta: { total: number } }>(`/deliveries/cylinders${qs(params)}`),
  get: (id: string) => api.get<Resource<DeliveryDetail>>(`/deliveries/${id}`),
  create: (direction: Direction, input: DeliveryInput) => api.post<Resource<DeliveryDetail>>('/deliveries', { direction, ...input }),
  update: (id: string, input: DeliveryInput) => api.put<Resource<DeliveryDetail>>(`/deliveries/${id}`, input),
  cancel: (id: string, reason: string) => api.post<Resource<DeliveryDetail>>(`/deliveries/${id}/cancel`, { reason }),
}

/** "12 m3 · Oksigen · Medis": what tells cylinders with the same name apart. */
export function cylinderSummary(details: Record<string, string>): string {
  const volume = [details['Volume Tabung'], details['Satuan Volume']].filter(Boolean).join(' ')
  return [details['Jenis Gas'], details['Grade Gas'], volume, details['Warna']].filter(Boolean).join(' · ')
}

/** Google Maps needs no key for a plain link to a point. */
export const mapLink = (p: Position) => `https://www.google.com/maps?q=${p.latitude},${p.longitude}`

/** "3 menit lalu"; older than a day is shown as a date by the caller. */
export function ago(iso: string, now = Date.now()): string {
  const minutes = Math.max(0, Math.round((now - new Date(iso).getTime()) / 60_000))
  if (minutes < 1) return 'baru saja'
  if (minutes < 60) return `${minutes} menit lalu`
  const hours = Math.floor(minutes / 60)
  return hours < 24 ? `${hours} jam lalu` : `${Math.floor(hours / 24)} hari lalu`
}

/** Step the trip is at, 0–4: prepared, loading, on the way, at the last unloading, done. */
export function progressStep(d: Delivery): number {
  if (d.status === 'completed') return 4
  if (d.status === 'draft' || d.status === 'cancelled') return 0
  if (d.status === 'loading') return 1
  const unloading = d.direction === 'incoming' ? d.returned_at !== null : d.stops.some((s) => s.status === 'arrived')
  return unloading ? 3 : 2
}

export const progressLabels: Record<Direction, string[]> = {
  outgoing: ['Disiapkan', 'Muat di gudang', 'Perjalanan', 'Bongkar di customer', 'Selesai'],
  incoming: ['Disiapkan', 'Muat di customer', 'Perjalanan', 'Bongkar di gudang', 'Selesai'],
}

/** "L 9123 UA · Truk Engkel", or what is still missing. */
export function vehicleText(d: Delivery): string {
  if (!d.vehicle) return 'Kendaraan belum dipilih'
  return [d.vehicle.plate, d.vehicle.name].filter(Boolean).join(' · ')
}

export function crewText(d: Delivery): string {
  if (!d.driver) return 'Supir & kernet belum diisi di mobile'
  return [`Supir ${d.driver.name}`, d.helper && `Kernet ${d.helper.name}`].filter(Boolean).join(' · ')
}
