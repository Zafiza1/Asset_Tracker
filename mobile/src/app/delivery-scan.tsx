import { CameraView, useCameraPermissions, type BarcodeScanningResult, type BarcodeType } from 'expo-camera'
import { router, Stack, useLocalSearchParams } from 'expo-router'
import { useEffect, useRef, useState } from 'react'
import { ActivityIndicator, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, Vibration, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Badge, Button, colors } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { deliveryMeta } from '@/lib/deliveries'
import { useSession } from '@/lib/session'
import type { Delivery, DeliveryItem } from '@/lib/types'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/** Same formats as the scanner (scan.tsx). */
const BARCODE_TYPES: BarcodeType[] = ['code128', 'code39', 'code93', 'codabar', 'ean13', 'ean8', 'upc_a', 'upc_e', 'itf14', 'qr', 'datamatrix', 'pdf417', 'aztec']

/** The camera reports a label many times per second; it counts again after being out of view this long. */
const OUT_OF_VIEW_MS = 3_000

type Phase = 'load' | 'unload'

interface ScanResult {
  /** The label, or the typed No Manufaktur, in lower case: one line per code. */
  key: string
  text: string
  state: 'sending' | 'ok' | 'error'
  message?: string
}

/**
 * Scan muat/bongkar of one delivery: every label is sent to the server at once, with the phone's
 * time and position, and is recorded only once the server answered (no offline mode, D-9).
 */
export default function DeliveryScanScreen() {
  const params = useLocalSearchParams<{ id: string; phase: string; stop_id?: string; customer?: string }>()
  const phase: Phase = params.phase === 'unload' ? 'unload' : 'load'
  const { post, get } = useSession()

  const [permission, requestPermission] = useCameraPermissions()
  const [torch, setTorch] = useState(false)
  const [manual, setManual] = useState('')
  const [results, setResults] = useState<ScanResult[]>([])
  const [delivery, setDelivery] = useState<Delivery | null>(null)
  const lastSeen = useRef(new Map<string, number>())
  const sending = useRef(new Set<string>())
  // One scan after the other, in the order they were made.
  const queue = useRef<Promise<void>>(Promise.resolve())

  useEffect(() => {
    get<{ data: Delivery }>(`/deliveries/${params.id}`)
      .then((r) => setDelivery(r.data))
      .catch(() => undefined)
  }, [get, params.id])

  const put = (result: ScanResult) => setResults((prev) => [result, ...prev.filter((r) => r.key !== result.key)].slice(0, 100))

  const send = async (code: string, key: string) => {
    try {
      const r = await post<{ data: Delivery; meta: { asset_id: string; already: boolean } }>(`/deliveries/${params.id}/scan`, {
        phase,
        code,
        ...(params.stop_id ? { stop_id: params.stop_id } : {}),
        ...(await deliveryMeta()),
      })
      setDelivery(r.data)
      const item = r.data.items?.find((i) => i.asset_id === r.meta.asset_id)
      put({ key, text: item ? itemText(item) : code, state: 'ok', message: r.meta.already ? 'Sudah di-scan sebelumnya' : undefined })
      Vibration.vibrate(80)
    } catch (e) {
      put({ key, text: code, state: 'error', message: errorMessage(e) })
      Vibration.vibrate([0, 120, 100, 120])
    } finally {
      sending.current.delete(key)
    }
  }

  const scan = (raw: string) => {
    const code = raw.trim()
    const key = code.toLowerCase()
    if (!code || sending.current.has(key)) return
    sending.current.add(key)
    put({ key, text: code, state: 'sending' })
    queue.current = queue.current.then(() => send(code, key))
  }

  const onCamera = ({ data }: BarcodeScanningResult) => {
    const key = data.trim().toLowerCase()
    const now = Date.now()
    const before = lastSeen.current.get(key) ?? 0
    lastSeen.current.set(key, now)
    if (now - before > OUT_OF_VIEW_MS) scan(data)
  }

  const submitManual = () => {
    scan(manual)
    setManual('')
  }

  // What this screen is about: the cylinders of the chosen customer, or all of them at the warehouse.
  const own = (delivery?.items ?? []).filter((i) => (!params.stop_id || i.stop_id === params.stop_id) && (phase === 'load' ? ['planned', 'loaded', 'unloaded'] : ['loaded', 'unloaded']).includes(i.state))
  const isDone = (i: DeliveryItem) => i.state === 'unloaded' || (phase === 'load' && i.state === 'loaded')
  const left = own.filter((i) => !isDone(i))
  const phaseLabel = phase === 'load' ? 'MUAT' : 'BONGKAR'

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <Stack.Screen options={{ title: delivery ? `${phaseLabel} · ${delivery.number}` : 'Pindai Tabung' }} />
      <View style={styles.camera}>
        {permission?.granted ? (
          <>
            <CameraView style={StyleSheet.absoluteFill} facing="back" enableTorch={torch} barcodeScannerSettings={{ barcodeTypes: BARCODE_TYPES }} onBarcodeScanned={onCamera} />
            <View style={styles.cameraOverlay} pointerEvents="box-none">
              <View style={styles.frame} />
              <Text style={styles.cameraHint}>{`${phaseLabel}${params.customer ? ` · ${params.customer}` : ''}`}</Text>
              <Pressable style={styles.torch} onPress={() => setTorch((t) => !t)} accessibilityRole="button">
                <Text style={{ color: '#fff', fontWeight: '600' }}>{torch ? 'Matikan senter' : 'Nyalakan senter'}</Text>
              </Pressable>
            </View>
          </>
        ) : (
          <View style={[styles.cameraOverlay, { padding: 24 }]}>
            <Text style={{ color: '#fff', textAlign: 'center' }}>Kamera dipakai untuk memindai label tabung. Anda juga dapat mengetik No Manufaktur di bawah.</Text>
            {permission && (permission.canAskAgain ? <Button title="Izinkan kamera" onPress={requestPermission} /> : <Button title="Buka pengaturan" variant="secondary" onPress={() => Linking.openSettings()} />)}
          </View>
        )}
      </View>

      <View style={styles.manual}>
        <TextInput
          style={styles.manualInput}
          value={manual}
          onChangeText={setManual}
          placeholder="Atau ketik No Manufaktur"
          placeholderTextColor={colors.muted}
          autoCapitalize="characters"
          autoCorrect={false}
          returnKeyType="done"
          onSubmitEditing={submitManual}
          accessibilityLabel="No Manufaktur"
        />
        <Button title="Catat" variant="secondary" onPress={submitManual} disabled={!manual.trim()} />
      </View>

      <View style={styles.summary} accessibilityLiveRegion="polite">
        <Text style={styles.summaryText}>{`${own.length - left.length} dari ${own.length} tabung ${phase === 'load' ? 'dimuat' : 'dibongkar'}`}</Text>
        {left.length > 0 && (
          <Text style={styles.muted} numberOfLines={2}>
            {`Belum: ${left.map((i) => i.serial_number ?? i.name).join(', ')}`}
          </Text>
        )}
      </View>

      <ScrollView contentContainerStyle={styles.list} keyboardShouldPersistTaps="handled">
        {results.length === 0 ? (
          <Text style={[styles.muted, { paddingVertical: 24 }]}>Arahkan kamera ke label tabung. Setiap scan langsung tercatat di server.</Text>
        ) : (
          results.map((r) => (
            <View key={r.key} style={[styles.item, r.state === 'error' && styles.itemBad]}>
              {r.state === 'sending' ? (
                <ActivityIndicator color={colors.brand} />
              ) : (
                <Badge colors={r.state === 'ok' ? [colors.okSoft, colors.ok] : [colors.dangerSoft, colors.danger]}>{r.state === 'ok' ? (phase === 'load' ? 'Muat' : 'Bongkar') : 'Gagal'}</Badge>
              )}
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={styles.itemText}>{r.text}</Text>
                {r.message && <Text style={{ color: r.state === 'error' ? colors.danger : colors.warning, fontSize: 13 }}>{r.message}</Text>}
              </View>
              {r.state === 'error' && (
                <Pressable onPress={() => scan(r.key)} hitSlop={8} accessibilityRole="button" accessibilityLabel={`Kirim ulang ${r.text}`}>
                  <Text style={{ color: colors.brand, fontWeight: '700' }}>Ulangi</Text>
                </Pressable>
              )}
            </View>
          ))
        )}
      </ScrollView>

      <View style={styles.footer}>
        <Button title="Selesai" variant={left.length > 0 ? 'secondary' : 'primary'} onPress={() => router.back()} />
      </View>
    </SafeAreaView>
  )
}

function itemText(item: DeliveryItem): string {
  return [item.serial_number, item.name].filter(Boolean).join(' · ')
}

const styles = StyleSheet.create({
  camera: { height: 260, backgroundColor: '#000' },
  cameraOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center', gap: 12 },
  frame: { width: 220, height: 130, borderWidth: 2, borderColor: '#fff', borderRadius: 12, opacity: 0.9 },
  cameraHint: { color: '#fff', fontSize: 15, fontWeight: '700', textAlign: 'center', paddingHorizontal: 24 },
  torch: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: 8, backgroundColor: 'rgba(0,0,0,0.45)' },
  manual: { flexDirection: 'row', gap: 8, padding: 12 },
  manualInput: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text, backgroundColor: colors.surface },
  summary: { alignItems: 'center', gap: 2, paddingHorizontal: 16, paddingBottom: 8 },
  summaryText: { fontSize: 16, fontWeight: '800', color: colors.text },
  list: { padding: 12, gap: 8, paddingBottom: 24 },
  muted: { color: colors.muted, fontSize: 13, textAlign: 'center' },
  item: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  itemBad: { borderColor: colors.danger },
  itemText: { fontSize: 15, fontWeight: '600', color: colors.text },
  footer: { padding: 12, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
})
