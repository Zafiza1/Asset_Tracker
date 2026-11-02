import { router } from 'expo-router'
import { useState } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button, Card, colors } from '@/components/ui'
import { useSession } from '@/lib/session'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/** Home after login: the account, a button that opens the scanner, and a manual lookup. */
export default function HomeScreen() {
  const { profile, logout } = useSession()
  const [manual, setManual] = useState('')
  const [leaving, setLeaving] = useState(false)

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

        <Card title="Cari nomor aset">
          <View style={styles.search}>
            <Text style={styles.muted}>Ketik nomor aset bila label rusak atau tidak terbaca.</Text>
            <TextInput
              style={styles.input}
              value={manual}
              onChangeText={setManual}
              placeholder="mis. AST-0001"
              placeholderTextColor={colors.muted}
              autoCapitalize="characters"
              autoCorrect={false}
              returnKeyType="search"
              onSubmitEditing={search}
            />
            <Button title="Cari" onPress={search} disabled={!manual.trim()} />
          </View>
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
  input: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text },
})
