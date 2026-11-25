import { router, useFocusEffect } from 'expo-router'
import { useCallback, useState } from 'react'
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Alert, Badge, Card, colors } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { DELIVERY_STATUS_COLORS } from '@/lib/deliveries'
import { useSession } from '@/lib/session'
import { formatDate, formatDateTime } from '@/lib/format'
import type { Delivery, Paginated } from '@/lib/types'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/**
 * Open deliveries (draft, loading, in transit) for the phone: the trips a driver or helper
 * scans and reports. Completed and cancelled ones stay on the web's tracking page.
 */
export default function DeliveriesScreen() {
  const { get } = useSession()
  const [items, setItems] = useState<Delivery[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [refreshing, setRefreshing] = useState(false)

  const load = useCallback(async () => {
    try {
      const r = await get<Paginated<Delivery>>('/deliveries?status=open&per_page=50')
      setItems(r.data)
      setError(null)
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [get])

  useFocusEffect(
    useCallback(() => {
      load()
    }, [load]),
  )

  const refresh = async () => {
    setRefreshing(true)
    await load()
    setRefreshing(false)
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <ScrollView
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.brand} />}
      >
        {error && <Alert title="Daftar belum termuat">{error}</Alert>}
        {items === null && !error && (
          <View style={styles.center}>
            <ActivityIndicator size="large" color={colors.brand} />
          </View>
        )}
        {items?.length === 0 && (
          <View style={styles.center}>
            <Text style={styles.title}>Tidak ada perjalanan</Text>
            <Text style={styles.muted}>Transaksi masuk/keluar tabung yang masih berjalan akan tampil di sini. Tarik ke bawah untuk memuat ulang.</Text>
          </View>
        )}
        {items?.map((d) => (
          <Pressable key={d.id} onPress={() => router.push({ pathname: '/delivery/[id]', params: { id: d.id } })} accessibilityRole="button" accessibilityLabel={`Buka ${d.number}`}>
            <Card>
              <View style={styles.row}>
                <View style={{ flex: 1, gap: 4 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                    <Text style={styles.number}>{d.number}</Text>
                    <Badge colors={DELIVERY_STATUS_COLORS[d.status]}>{d.status_label}</Badge>
                  </View>
                  <Text style={styles.muted}>
                    {[d.direction_label, `Tanggal ${formatDate(d.planned_date)}`, d.vehicle?.plate ? `Kendaraan ${d.vehicle.plate}` : null].filter(Boolean).join(' · ')}
                  </Text>
                  <Text style={styles.muted} numberOfLines={1}>
                    {(d.stops ?? []).map((s) => s.customer.name).join(' → ') || 'Belum ada customer'}
                  </Text>
                  {d.last_event && (
                    <Text style={styles.lastEvent} numberOfLines={1}>
                      {`${d.last_event.type_label}${d.last_event.customer ? ` · ${d.last_event.customer}` : ''} · ${formatDateTime(d.last_event.recorded_at)}`}
                    </Text>
                  )}
                </View>
                {d.status !== 'draft' && (
                  <View style={styles.counts}>
                    <Text style={styles.count}>{`${d.counts?.loaded ?? 0} termuat`}</Text>
                    <Text style={styles.count}>{`${d.counts?.unloaded ?? 0} bongkar`}</Text>
                  </View>
                )}
              </View>
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  list: { padding: 16, gap: 12 },
  center: { alignItems: 'center', gap: 8, paddingVertical: 48 },
  title: { fontSize: 17, fontWeight: '700', color: colors.text },
  muted: { color: colors.muted, fontSize: 14 },
  number: { fontSize: 16, fontWeight: '800', color: colors.text, fontFamily: 'monospace' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14 },
  lastEvent: { color: colors.brand, fontSize: 13, marginTop: 2 },
  counts: { alignItems: 'flex-end', gap: 2 },
  count: { fontSize: 12, color: colors.muted },
})
