import { router, Stack, useFocusEffect } from 'expo-router'
import { useCallback, useRef, useState } from 'react'
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { Alert, Badge, Button, colors } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { useSession } from '@/lib/session'
import { approvalStatusColors, approvalStatusLabel, type ApprovalCounts, type ApprovalRequest, type ApprovalStatus, type Paginated } from '@/lib/types'

export { ErrorBoundary } from '@/components/ErrorBoundary'

const TABS: (ApprovalStatus | 'all')[] = ['pending', 'approved', 'rejected', 'cancelled', 'all']
const PER_PAGE = 20

/**
 * Approval requests, as the Notifications menu of the web: Super Admin and Supervisor see every
 * request of the organization and decide on pending ones; other users follow their own.
 */
export default function ApprovalsScreen() {
  const { get, can } = useSession()
  const canDecide = can('approval.manage')
  const [status, setStatus] = useState<ApprovalStatus | 'all'>('pending')
  const [mine, setMine] = useState(false)
  const [rows, setRows] = useState<ApprovalRequest[]>([])
  const [counts, setCounts] = useState<ApprovalCounts | null>(null)
  const [page, setPage] = useState({ current: 0, last: 1 })
  const [loading, setLoading] = useState(true)
  const [more, setMore] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Ignores answers of an older filter that arrive after a newer one.
  const latest = useRef(0)

  const query = useCallback(
    (p: number) => `status=${status === 'all' ? '' : status}&mine=${mine ? 1 : 0}&per_page=${PER_PAGE}&page=${p}`,
    [status, mine],
  )

  const load = useCallback(async () => {
    const ticket = ++latest.current
    setLoading(true)
    setError(null)
    try {
      const [list, c] = await Promise.all([
        get<Paginated<ApprovalRequest>>(`/approvals?${query(1)}`),
        get<{ data: ApprovalCounts }>(`/approvals/counts?mine=${mine ? 1 : 0}`),
      ])
      if (ticket !== latest.current) return
      setRows(list.data)
      setPage({ current: 1, last: list.meta?.last_page ?? 1 })
      setCounts(c.data)
    } catch (e) {
      if (ticket === latest.current) setError(errorMessage(e))
    } finally {
      if (ticket === latest.current) setLoading(false)
    }
  }, [get, query, mine])

  // Also after coming back from a detail, where the request may have been decided.
  useFocusEffect(
    useCallback(() => {
      load()
    }, [load]),
  )

  const loadMore = async () => {
    if (more || loading || page.current >= page.last) return
    const ticket = latest.current
    setMore(true)
    try {
      const next = await get<Paginated<ApprovalRequest>>(`/approvals?${query(page.current + 1)}`)
      if (ticket !== latest.current) return
      setRows((r) => [...r, ...next.data.filter((n) => !r.some((x) => x.id === n.id))])
      setPage({ current: page.current + 1, last: next.meta?.last_page ?? page.last })
    } catch {
      // The list stays as it is; scrolling again retries.
    } finally {
      setMore(false)
    }
  }

  const title = canDecide ? 'Persetujuan' : 'Pengajuan Saya'

  return (
    <View style={{ flex: 1, backgroundColor: colors.background }}>
      <Stack.Screen options={{ title }} />
      <View style={styles.filters}>
        {canDecide && (
          <View style={styles.segment}>
            {[
              { value: false, label: 'Semua pengajuan' },
              { value: true, label: 'Pengajuan saya' },
            ].map((o) => (
              <Pressable key={o.label} onPress={() => setMine(o.value)} style={[styles.segmentItem, mine === o.value && styles.segmentActive]} accessibilityRole="tab" accessibilityState={{ selected: mine === o.value }}>
                <Text style={[styles.segmentText, mine === o.value && { color: colors.brand }]}>{o.label}</Text>
              </Pressable>
            ))}
          </View>
        )}
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
          {TABS.map((t) => {
            const active = t === status
            return (
              <Pressable key={t} onPress={() => setStatus(t)} style={[styles.tab, active && styles.tabActive]} accessibilityRole="tab" accessibilityState={{ selected: active }}>
                <Text style={[styles.tabText, active && { color: '#fff' }]}>
                  {t === 'all' ? 'Semua' : approvalStatusLabel[t]}
                  {counts ? ` (${counts[t]})` : ''}
                </Text>
              </Pressable>
            )
          })}
        </ScrollView>
      </View>

      {error && rows.length === 0 ? (
        <View style={{ padding: 16, gap: 12 }}>
          <Alert title="Gagal memuat pengajuan">{error}</Alert>
          <Button title="Coba lagi" variant="secondary" onPress={load} />
        </View>
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(r) => r.id}
          contentContainerStyle={{ padding: 16, gap: 12, paddingBottom: 40 }}
          refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
          onEndReached={loadMore}
          onEndReachedThreshold={0.4}
          ListEmptyComponent={
            loading ? undefined : (
              <Text style={styles.empty}>
                {status === 'pending' ? (canDecide && !mine ? 'Tidak ada pengajuan yang menunggu keputusan.' : 'Tidak ada pengajuan Anda yang sedang menunggu.') : 'Belum ada pengajuan.'}
              </Text>
            )
          }
          ListFooterComponent={more ? <ActivityIndicator color={colors.brand} /> : undefined}
          renderItem={({ item }) => <ApprovalCard item={item} showRequester={canDecide} />}
        />
      )}
    </View>
  )
}

function ApprovalCard({ item, showRequester }: { item: ApprovalRequest; showRequester: boolean }) {
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/approvals/[id]', params: { id: item.id } })}
      style={({ pressed }) => [styles.card, pressed && { opacity: 0.8 }]}
      accessibilityRole="button"
      accessibilityLabel={`${item.action_label}, ${item.summary}, ${approvalStatusLabel[item.status]}`}
    >
      <View style={styles.cardTop}>
        <Text style={styles.action}>{item.action_label}</Text>
        <Badge colors={approvalStatusColors[item.status]}>{approvalStatusLabel[item.status]}</Badge>
      </View>
      <Text style={styles.summary} numberOfLines={2}>
        {item.summary}
      </Text>
      <Text style={styles.meta}>
        {[item.category_label, showRequester && item.requester?.name, formatDateTime(item.created_at)].filter(Boolean).join(' · ')}
      </Text>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  filters: { backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border, paddingTop: 12, gap: 12 },
  segment: { flexDirection: 'row', marginHorizontal: 16, borderRadius: 10, backgroundColor: colors.background, padding: 4 },
  segmentItem: { flex: 1, minHeight: 40, borderRadius: 8, alignItems: 'center', justifyContent: 'center' },
  segmentActive: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  segmentText: { fontWeight: '600', color: colors.muted },
  tabs: { paddingHorizontal: 16, paddingBottom: 12, gap: 8 },
  tab: { minHeight: 36, justifyContent: 'center', paddingHorizontal: 14, borderRadius: 999, borderWidth: 1, borderColor: colors.border },
  tabActive: { backgroundColor: colors.brand, borderColor: colors.brand },
  tabText: { fontSize: 14, fontWeight: '600', color: colors.text },
  card: { backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 14, gap: 6 },
  cardTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8 },
  action: { flex: 1, fontSize: 16, fontWeight: '700', color: colors.text },
  summary: { fontSize: 14, color: colors.text },
  meta: { fontSize: 12, color: colors.muted },
  empty: { textAlign: 'center', color: colors.muted, paddingVertical: 32 },
})
