import { Stack } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { ActivityIndicator, View } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { colors } from '@/components/ui'
import { SessionProvider, useSession } from '@/lib/session'

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <StatusBar style="dark" />
        <Screens />
      </SessionProvider>
    </SafeAreaProvider>
  )
}

/** The scanner and asset detail need a session; without one only the login is reachable. */
function Screens() {
  const { ready, token } = useSession()
  if (!ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.background }}>
        <ActivityIndicator size="large" color={colors.brand} />
      </View>
    )
  }

  return (
    <Stack screenOptions={{ headerTintColor: colors.brand, headerTitleStyle: { color: colors.text }, contentStyle: { backgroundColor: colors.background } }}>
      <Stack.Protected guard={!token}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
      <Stack.Protected guard={!!token}>
        <Stack.Screen name="index" options={{ title: 'Pindai Barcode' }} />
        <Stack.Screen name="asset/[code]" options={{ title: 'Detail Aset' }} />
      </Stack.Protected>
    </Stack>
  )
}
