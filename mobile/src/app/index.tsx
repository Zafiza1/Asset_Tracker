import { router, useFocusEffect, type Href } from 'expo-router'
import { useCallback, useState } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Alert, Button, Card, colors } from '@/components/ui'
import { useSession } from '@/lib/session'
import { canRecord } from '@/lib/transactions'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/**
 * Home after login: the account, the scanner, a manual lookup, and the actions the account's
 * role allows (register assets, categories and locations; follow or decide approval requests).
 */
export default function HomeScreen() {
  const { profile, logout, can, get } = useSession()
  const [manual, setManual] = useState('')
  const [leaving, setLeaving] = useState(false)
  const [pending, setPending] = useState<number | null>(null)
  const [notReturned, setNotReturned] = useState<number | null>(null)
  const canDecide = can('approval.manage')
  const canOut = canRecord(profile, can, 'outgoing')
  const canIn = canRecord(profile, can, 'incoming')
  const canViewTransactions = can('transaction.view')

  // Pending requests: every one for approvers, the user's own otherwise; refreshed on return.
  useFocusEffect(
    useCallback(() => {
      let live = true
      get<{ data: { pending: number } }>('/approvals/summary')
        .then((r) => live && setPending(r.data.pending))
        .catch(() => undefined)
      // Assets still outside the organization, for the reminder to record them coming back.
      if (canViewTransactions && canIn) {
        get<{ data: { not_returned: number } }>('/transactions/counts')
          .then((r) => live && setNotReturned(r.data.not_returned))
          .catch(() => undefined)
      }
      return () => {
        live = false
      }
    }, [get, canViewTransactions, canIn]),
  )

  const actions: { title: string; text: string; href: Href; badge?: number | null }[] = [
    ...(can('asset.create') ? [{ title: 'Tambah Aset', text: 'Daftarkan aset baru beserta atribut kategorinya.', href: '/new/asset' as const }] : []),
    ...(can('master.tenant.create')
      ? [
          { title: 'Tambah Kategori', text: 'Kategori atau subkategori aset baru.', href: '/new/category' as const },
          { title: 'Tambah Lokasi', text: 'Gedung, ruangan, atau gudang baru.', href: '/new/location' as const },
        ]
      : []),
    {
      title: canDecide ? 'Persetujuan' : 'Pengajuan Saya',
      text: canDecide ? 'Setujui atau tolak pengajuan aset, transaksi, dan master data.' : 'Pantau status pengajuan yang menunggu persetujuan.',
      href: '/approvals',
      badge: pending,
    },
  ]

  const search = () => {
    const value = manual.trim()
    if (!value) return
    router.push({ pathname: '/asset/[code]', params: { code: value } })
  }

  const signOut = async () => {
    setLeaving(true)
    await logout()
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <View style={styles.welcome}>
          <Text style={styles.greeting}>Halo, {profile?.user.name}</Text>
          <Text style={styles.muted}>{profile?.user.job_title ? `${profile.user.job_title} · ` : ''}{profile?.organization.name}</Text>
        </View>

        <Pressable
          style={({ pressed }) => [styles.scan, pressed && { backgroundColor: colors.brandDark }]}
          onPress={() => router.push('/scan')}
          accessibilityRole="button"
          accessibilityLabel="Scan aset"
        >
          <View style={styles.scanIcon}>
            <ScanIcon />
          </View>
          <View style={{ flex: 1, gap: 4 }}>
            <Text style={styles.scanTitle}>Scan Aset</Text>
            <Text style={styles.scanText}>Pindai barcode atau QR code pada label aset dengan kamera.</Text>
          </View>
        </Pressable>

        {(canOut || canIn) && (
          <View style={styles.tiles}>
            {canOut && (
              <Pressable
                style={({ pressed }) => [styles.tile, pressed && { backgroundColor: colors.background }]}
                onPress={() => router.push({ pathname: '/transaction/[type]', params: { type: 'outgoing' } })}
                accessibilityRole="button"
                accessibilityLabel="Catat aset keluar"
              >
                <View style={[styles.tileIcon, { backgroundColor: colors.warningSoft }]}>
                  <Text style={{ color: colors.warning, fontSize: 20, fontWeight: '800' }}>↗</Text>
                </View>
                <Text style={styles.actionTitle}>Aset Keluar</Text>
                <Text style={styles.muted}>Aset ke pihak luar</Text>
              </Pressable>
            )}
            {canIn && (
              <Pressable
                style={({ pressed }) => [styles.tile, pressed && { backgroundColor: colors.background }]}
                onPress={() => router.push({ pathname: '/transaction/[type]', params: { type: 'incoming' } })}
                accessibilityRole="button"
                accessibilityLabel="Catat aset masuk"
              >
                <View style={[styles.tileIcon, { backgroundColor: colors.okSoft }]}>
                  <Text style={{ color: colors.ok, fontSize: 20, fontWeight: '800' }}>↙</Text>
                </View>
                <Text style={styles.actionTitle}>Aset Masuk</Text>
                <Text style={styles.muted}>Aset kembali dari luar</Text>
              </Pressable>
            )}
          </View>
        )}

        {!!notReturned && (
          <Pressable onPress={() => router.push({ pathname: '/transaction/[type]', params: { type: 'incoming' } })} accessibilityRole="button">
            <Alert tone="warning" title={`${notReturned} aset belum kembali`}>
              Aset yang keluar sementara masih di pihak luar. Ketuk untuk mencatat yang sudah kembali.
            </Alert>
          </Pressable>
        )}

        <Card title="Cari nomor seri">
          <View style={styles.search}>
            <Text style={styles.muted}>Ketik nomor seri aset bila label rusak atau tidak terbaca.</Text>
            <TextInput
              style={styles.input}
              value={manual}
              onChangeText={setManual}
              placeholder="mis. SN-2024-0001"
              placeholderTextColor={colors.muted}
              autoCapitalize="characters"
              autoCorrect={false}
              returnKeyType="search"
              onSubmitEditing={search}
            />
            <Button title="Cari" onPress={search} disabled={!manual.trim()} />
          </View>
        </Card>

        <Card title="Menu">
          {actions.map((a, i) => (
            <Pressable
              key={a.title}
              onPress={() => router.push(a.href)}
              style={({ pressed }) => [styles.action, i > 0 && styles.actionDivider, pressed && { backgroundColor: colors.background }]}
              accessibilityRole="button"
              accessibilityLabel={a.badge ? `${a.title}, ${a.badge} menunggu` : a.title}
            >
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={styles.actionTitle}>{a.title}</Text>
                <Text style={styles.muted}>{a.text}</Text>
              </View>
              {!!a.badge && (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{a.badge > 99 ? '99+' : a.badge}</Text>
                </View>
              )}
              <Text style={styles.chevron}>›</Text>
            </Pressable>
          ))}
        </Card>

        <Button title="Keluar" variant="secondary" onPress={signOut} loading={leaving} />
      </ScrollView>
    </SafeAreaView>
  )
}

/** Viewfinder corners with a scan line, drawn with Views so no icon library is needed. */
function ScanIcon() {
  const corner = { position: 'absolute', width: 12, height: 12, borderColor: '#fff' } as const
  return (
    <View style={{ width: 32, height: 32 }}>
      <View style={[corner, { top: 0, left: 0, borderTopWidth: 3, borderLeftWidth: 3, borderTopLeftRadius: 4 }]} />
      <View style={[corner, { top: 0, right: 0, borderTopWidth: 3, borderRightWidth: 3, borderTopRightRadius: 4 }]} />
      <View style={[corner, { bottom: 0, left: 0, borderBottomWidth: 3, borderLeftWidth: 3, borderBottomLeftRadius: 4 }]} />
      <View style={[corner, { bottom: 0, right: 0, borderBottomWidth: 3, borderRightWidth: 3, borderBottomRightRadius: 4 }]} />
      <View style={{ position: 'absolute', top: 14.5, left: 4, right: 4, height: 3, borderRadius: 2, backgroundColor: '#fff' }} />
    </View>
  )
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16 },
  welcome: { gap: 4, paddingVertical: 8 },
  greeting: { fontSize: 22, fontWeight: '800', color: colors.text },
  muted: { color: colors.muted, fontSize: 14 },
  scan: { flexDirection: 'row', alignItems: 'center', gap: 16, padding: 20, borderRadius: 16, backgroundColor: colors.brand },
  scanIcon: { width: 56, height: 56, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(255,255,255,0.18)' },
  scanTitle: { color: '#fff', fontSize: 18, fontWeight: '700' },
  scanText: { color: 'rgba(255,255,255,0.85)', fontSize: 14 },
  search: { padding: 16, gap: 12 },
  action: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 14, minHeight: 64 },
  actionDivider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  actionTitle: { fontSize: 16, fontWeight: '700', color: colors.text },
  badge: { minWidth: 24, height: 24, borderRadius: 12, paddingHorizontal: 7, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.danger },
  badgeText: { color: '#fff', fontSize: 12, fontWeight: '700' },
  chevron: { fontSize: 24, color: colors.muted },
  tiles: { flexDirection: 'row', gap: 12 },
  tile: { flex: 1, gap: 6, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, minHeight: 104 },
  tileIcon: { width: 36, height: 36, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  input: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text },
})
