import * as SecureStore from 'expo-secure-store'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { AppState } from 'react-native'
import { ApiError } from './api'
import { canOperateDeliveries, deliveryMeta, distance, uuid, type DeliveryMeta } from './deliveries'
import { useSession } from './session'

/**
 * The trip this phone is on: while the app is open, its position is sent to the delivery now and
 * then, which fills the road between the scans at the start and at the end. Only the phone that
 * departed (or where it was switched on) does this, so the office opening a delivery does not
 * report its own position as the vehicle's.
 */
interface Trip {
  deliveryId: string
  userId: string
  organizationId: string
}

interface TripState {
  /** The delivery whose position this phone sends, or null. */
  deliveryId: string | null
  start: (deliveryId: string) => void
  stop: () => void
  /** When the last position reached the server. */
  sentAt: string | null
}

const KEY = 'trip'
const CHECK_MS = 60_000
/** A vehicle that stands still is reported this often only. */
const STANDING_MS = 10 * 60_000
const MOVED_METRES = 150
/** Positions kept while there is no signal, sent when it is back. */
const MAX_WAITING = 120

type Position = DeliveryMeta & { latitude: number; longitude: number; client_id: string }

const TripContext = createContext<TripState | null>(null)

export function TripProvider({ children }: { children: ReactNode }) {
  const { token, profile, post, can } = useSession()
  const [saved, setSaved] = useState<Trip | null>(null)
  const [sentAt, setSentAt] = useState<string | null>(null)
  const userId = profile?.user.id ?? null
  const organizationId = profile?.organization.id ?? null
  const operate = canOperateDeliveries(profile, can)

  useEffect(() => {
    SecureStore.getItemAsync(KEY)
      .then((raw) => raw && setSaved((current) => current ?? (JSON.parse(raw) as Trip)))
      .catch(() => undefined)
  }, [])

  // Another account or organization on the same phone is not on this trip.
  const deliveryId = token && operate && saved && saved.userId === userId && saved.organizationId === organizationId ? saved.deliveryId : null

  const start = useCallback(
    (id: string) => {
      if (!userId || !organizationId) return
      const trip: Trip = { deliveryId: id, userId, organizationId }
      setSaved(trip)
      setSentAt(null)
      SecureStore.setItemAsync(KEY, JSON.stringify(trip)).catch(() => undefined)
    },
    [userId, organizationId],
  )

  const stop = useCallback(() => {
    setSaved(null)
    setSentAt(null)
    SecureStore.deleteItemAsync(KEY).catch(() => undefined)
  }, [])

  // The request function changes with the session; the timer below must not restart for that.
  const send = useRef(post)
  useEffect(() => {
    send.current = post
  }, [post])

  useEffect(() => {
    if (!deliveryId) return
    let live = true
    let running = false
    let last: { at: number; latitude: number; longitude: number } | null = null
    const waiting: Position[] = []

    const tick = async () => {
      if (running || !live || AppState.currentState !== 'active') return
      running = true
      try {
        const meta = await deliveryMeta({ fresh: true })
        if (!live) return
        if (meta.latitude !== undefined && meta.longitude !== undefined) {
          const here = { latitude: meta.latitude, longitude: meta.longitude }
          const now = Date.now()
          if (!last || now - last.at >= STANDING_MS || distance(last, here) >= MOVED_METRES) {
            last = { at: now, ...here }
            waiting.push({ ...meta, ...here, client_id: uuid() })
            if (waiting.length > MAX_WAITING) waiting.shift()
          }
        }
        while (live && waiting.length > 0) {
          await send.current(`/deliveries/${deliveryId}/events`, { type: 'position', ...waiting[0] })
          const [{ recorded_at }] = waiting.splice(0, 1)
          if (live) setSentAt(recorded_at)
        }
      } catch (e) {
        // Ended, cancelled, or no longer this account's: the trip is over. Without signal it goes on.
        if (live && e instanceof ApiError && [403, 404, 409].includes(e.status)) stop()
        else if (e instanceof ApiError && e.status === 422) waiting.shift()
      } finally {
        running = false
      }
    }

    tick()
    const timer = setInterval(tick, CHECK_MS)
    const subscription = AppState.addEventListener('change', (state) => state === 'active' && tick())
    return () => {
      live = false
      clearInterval(timer)
      subscription.remove()
    }
  }, [deliveryId, stop])

  const value = useMemo<TripState>(() => ({ deliveryId, start, stop, sentAt }), [deliveryId, start, stop, sentAt])
  return <TripContext.Provider value={value}>{children}</TripContext.Provider>
}

export function useTrip(): TripState {
  const trip = useContext(TripContext)
  if (!trip) throw new Error('useTrip outside TripProvider')
  return trip
}
