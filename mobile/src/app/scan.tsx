import { CameraView, useCameraPermissions, type BarcodeScanningResult, type BarcodeType } from 'expo-camera'
import { router, useFocusEffect } from 'expo-router'
import { useCallback, useRef, useState } from 'react'
import { Linking, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button, colors } from '@/components/ui'
import { useSession } from '@/lib/session'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/**
 * Printed labels hold the asset number as Code 128 (1D) and QR (2D); the other 1D and 2D
 * formats are accepted too, so labels from other printers or suppliers also scan.
 */
const BARCODE_TYPES: BarcodeType[] = [
  // 1D
  'code128', 'code39', 'code93', 'codabar', 'ean13', 'ean8', 'upc_a', 'upc_e', 'itf14',
  // 2D
  'qr', 'datamatrix', 'pdf417', 'aztec',
]

export default function ScanScreen() {
  const { profile } = useSession()
  const [permission, requestPermission] = useCameraPermissions()
  const [focused, setFocused] = useState(false)
  const [torch, setTorch] = useState(false)
  const [manual, setManual] = useState('')
  // onBarcodeScanned fires for every frame; only the first one opens the detail.
  const handled = useRef(false)

  useFocusEffect(
    useCallback(() => {
      handled.current = false
      setFocused(true)
      return () => setFocused(false)
    }, []),
  )

  const open = (code: string) => {
    const value = code.trim()
    if (!value || handled.current) return
    handled.current = true
    router.push({ pathname: '/asset/[code]', params: { code: value } })
  }

  if (!permission) return null

  if (!permission.granted) {
    return (
      <SafeAreaView style={styles.center} edges={['bottom']}>
        <Text style={styles.title}>Izin kamera diperlukan</Text>
        <Text style={styles.muted}>Kamera dipakai untuk memindai barcode aset.</Text>
        {permission.canAskAgain ? (
          <Button title="Izinkan kamera" onPress={requestPermission} />
        ) : (
          <Button title="Buka pengaturan" onPress={() => Linking.openSettings()} />
        )}
      </SafeAreaView>
    )
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: '#000' }} edges={['bottom']}>
      <View style={{ flex: 1 }}>
        {/* Unmounted while another screen is open, so the camera is released. */}
        {focused && (
          <CameraView
            style={StyleSheet.absoluteFill}
            facing="back"
            enableTorch={torch}
            barcodeScannerSettings={{ barcodeTypes: BARCODE_TYPES }}
            onBarcodeScanned={(result: BarcodeScanningResult) => open(result.data)}
          />
        )}
        <View style={styles.overlay} pointerEvents="box-none">
          <Text style={styles.org}>{profile?.organization.name}</Text>
          <View style={styles.frame} />
          <Text style={styles.hint}>Arahkan kamera ke barcode atau QR code aset</Text>
          <Pressable style={styles.torch} onPress={() => setTorch((t) => !t)} accessibilityRole="button">
            <Text style={styles.torchText}>{torch ? 'Matikan senter' : 'Nyalakan senter'}</Text>
          </Pressable>
        </View>
      </View>

      <View style={styles.manual}>
        <TextInput
          style={styles.input}
          value={manual}
          onChangeText={setManual}
          placeholder="Atau ketik nomor aset"
          placeholderTextColor={colors.muted}
          autoCapitalize="characters"
          autoCorrect={false}
          returnKeyType="search"
          onSubmitEditing={() => open(manual)}
        />
        <Button title="Cari" onPress={() => open(manual)} disabled={!manual.trim()} />
      </View>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 12, backgroundColor: colors.background },
  title: { fontSize: 18, fontWeight: '700', color: colors.text },
  muted: { color: colors.muted, textAlign: 'center' },
  overlay: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', gap: 20, padding: 24 },
  org: { position: 'absolute', top: 16, color: '#fff', fontWeight: '600', backgroundColor: 'rgba(0,0,0,0.45)', paddingHorizontal: 12, paddingVertical: 6, borderRadius: 999 },
  frame: { width: '80%', aspectRatio: 1.4, borderWidth: 3, borderColor: '#fff', borderRadius: 16 },
  hint: { color: '#fff', fontSize: 15, textAlign: 'center', textShadowColor: '#000', textShadowRadius: 4 },
  torch: { position: 'absolute', bottom: 16, backgroundColor: 'rgba(0,0,0,0.55)', paddingHorizontal: 16, paddingVertical: 10, borderRadius: 999 },
  torchText: { color: '#fff', fontWeight: '600' },
  manual: { flexDirection: 'row', gap: 8, padding: 12, backgroundColor: colors.surface },
  input: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text },
})
