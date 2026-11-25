import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useEffect, useRef } from 'react'

export interface MapMarker {
  id: string
  latitude: number
  longitude: number
  /** Plain text; shown in the marker's popup. */
  label: string
  kind: 'vehicle' | 'event' | 'issue' | 'start'
  selected?: boolean
}

const COLORS: Record<MapMarker['kind'], string> = { vehicle: '#1d4ed8', event: '#16a34a', issue: '#dc2626', start: '#475569' }

/**
 * Map of vehicle positions and the route of a trip: Leaflet with OpenStreetMap tiles (no key, no
 * cost). Without internet the tiles stay empty, the markers and the route still show.
 */
export default function DeliveryMap({ markers, route = [], onSelect, className = 'h-96' }: { markers: MapMarker[]; route?: [number, number][]; onSelect?: (id: string) => void; className?: string }) {
  const container = useRef<HTMLDivElement>(null)
  const map = useRef<L.Map | null>(null)
  const layer = useRef<L.LayerGroup | null>(null)
  const fitted = useRef('')
  const select = useRef(onSelect)
  useEffect(() => {
    select.current = onSelect
  })

  useEffect(() => {
    if (!container.current) return
    const m = L.map(container.current, { scrollWheelZoom: false }).setView([-7.2575, 112.7521], 10)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(m)
    map.current = m
    layer.current = L.layerGroup().addTo(m)
    return () => {
      m.remove()
      map.current = null
      fitted.current = ''
    }
  }, [])

  useEffect(() => {
    const group = layer.current
    if (!group || !map.current) return
    group.clearLayers()
    if (route.length > 1) L.polyline(route, { color: COLORS.vehicle, weight: 4, opacity: 0.6 }).addTo(group)
    for (const marker of markers) {
      const radius = marker.kind === 'vehicle' ? (marker.selected ? 12 : 9) : 6
      const popup = document.createElement('span')
      popup.textContent = marker.label
      L.circleMarker([marker.latitude, marker.longitude], { radius, color: '#fff', weight: 2, fillColor: COLORS[marker.kind], fillOpacity: 1 })
        .bindPopup(popup)
        .on('click', () => select.current?.(marker.id))
        .addTo(group)
    }
    // Moves the view only when other things are shown, not on every refreshed position.
    const points: [number, number][] = [...markers.map((m): [number, number] => [m.latitude, m.longitude]), ...route]
    const shown = markers.map((m) => m.id).join()
    if (points.length > 0 && fitted.current !== shown) {
      fitted.current = shown
      map.current.fitBounds(L.latLngBounds(points), { padding: [32, 32], maxZoom: 15 })
    }
  }, [markers, route])

  return <div ref={container} role="application" aria-label="Peta posisi kendaraan" className={`isolate z-0 w-full rounded-lg bg-slate-100 ring-1 ring-slate-200 ${className}`} />
}
