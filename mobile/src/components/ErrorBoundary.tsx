import type { ErrorBoundaryProps } from 'expo-router'
import { useEffect } from 'react'
import { StyleSheet, Text } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button, colors } from './ui'

/**
 * Expo Router ErrorBoundary: an exception thrown while rendering a screen shows this message
 * instead of a crash screen. Exported from the root layout and from each screen, as the
 * web app's RouteError.
 */
export function ErrorBoundary({ error, retry }: ErrorBoundaryProps) {
  useEffect(() => console.error(error), [error])
  return (
    <SafeAreaView style={styles.center}>
      <Text style={styles.icon}>⚠️</Text>
      <Text style={styles.title}>Terjadi kesalahan</Text>
      <Text style={styles.muted}>Layar ini gagal ditampilkan. Coba lagi, atau hubungi admin jika masalah berlanjut.</Text>
      {__DEV__ && <Text style={styles.detail}>{error.message}</Text>}
      <Button title="Coba lagi" onPress={retry} style={{ alignSelf: 'stretch' }} />
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 8, padding: 24, backgroundColor: colors.background },
  icon: { fontSize: 40 },
  title: { fontSize: 18, fontWeight: '700', color: colors.text },
  muted: { color: colors.muted, textAlign: 'center' },
  detail: { color: colors.danger, fontSize: 12, textAlign: 'center' },
})
