import { router } from 'expo-router'
import { useCallback, useEffect, useState } from 'react'
import { Alert } from 'react-native'
import type { Option } from '@/components/form'
import { ApiError, errorMessage, isApprovalPending, type ApprovalPending } from './api'
import { useSession } from './session'
import type { MasterOption } from './types'

/**
 * Active records of a master list for a picker (`all=1`, capped by the server), indented by
 * tree depth as on the web. Picking is UX only: the API re-validates every reference.
 */
export function useMasterOptions(endpoint: '/locations' | '/tenant-masters/categories', enabled = true) {
  const { get } = useSession()
  const [options, setOptions] = useState<Option[]>([])
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const rows = (await get<{ data: MasterOption[] }>(`${endpoint}?all=1&status=active`)).data
      setOptions(rows.map((r) => ({ value: r.id, label: `${r.name} (${r.code})`, depth: r.depth ?? 0 })))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }, [endpoint, get])

  useEffect(() => {
    if (enabled) load()
  }, [enabled, load])

  return { options, loading, error, reload: load }
}

/** First message per input of a 422, keyed by input name ("name", "attributes.kondisi"). */
export function fieldErrors(e: unknown): Record<string, string> {
  if (!(e instanceof ApiError)) return {}
  return Object.fromEntries(Object.entries(e.fields).map(([k, m]) => [k, m[0]]))
}

/** The form-level message of a failed submit; field errors are shown at their inputs instead. */
export function formError(e: unknown): string {
  if (e instanceof ApiError && Object.keys(e.fields).length > 0) return 'Periksa kembali isian yang ditandai.'
  return errorMessage(e)
}

/**
 * After a successful create: applied directly (approvers) or stored as a request waiting for
 * a Super Admin or Supervisor. Either way the user is told, then `done` runs.
 */
export function announce<T>(response: T | ApprovalPending, applied: { title: string; message: string; done: (record: T) => void }) {
  if (isApprovalPending(response)) {
    const id = response.data.id
    Alert.alert(
      'Menunggu persetujuan',
      `${response.meta.message}\n\nPantau statusnya di menu Pengajuan.`,
      [
        { text: 'Lihat pengajuan', onPress: () => router.replace({ pathname: '/approvals/[id]', params: { id } }) },
        { text: 'Selesai', style: 'cancel', onPress: () => router.back() },
      ],
      { cancelable: false },
    )
    return
  }
  Alert.alert(applied.title, applied.message, [{ text: 'OK', onPress: () => applied.done(response as T) }], { cancelable: false })
}
