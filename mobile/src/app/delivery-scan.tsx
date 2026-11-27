import { CameraView, useCameraPermissions, type BarcodeScanningResult, type BarcodeType } from 'expo-camera'
import { router, Stack, useLocalSearchParams } from 'expo-router'
import { useEffect, useRef, useState } from 'react'
import { ActivityIndicator, Alert as NativeAlert, Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, Vibration, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button, colors, ProgressBar } from '@/components/ui'
import { ApiError, errorMessage } from '@/lib/api'
import { deliveryMeta } from '@/lib/deliveries'
import { findItem, isScanned, itemText, scanTargets, type ScanPhase } from '@/lib/scanCheck'
import { useSession } from '@/lib/session'
import type { Delivery } from '@/lib/types'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/** Same formats as the scanner (scan.tsx). */
const BARCODE_TYPES: BarcodeType[] = ['code128', 'code39', 'code93', 'codabar', 'ean13', 'ean8', 'upc_a', 'upc_e', 'itf14', 'qr', 'datamatrix', 'pdf417', 'aztec']

/** The camera reports a label many times per second; it counts again after being out of view this long. */
const OUT_OF_VIEW_MS = 3_000
/** How long the banner over the camera stays. */
const BANNER_MS = 3_500
const MAX_RESULTS = 60

/** ok: counted · duplicate: scanned before, not counted again · wrong: not a cylinder of this transaction · error: not recorded. */
type Outcome = 'sending' | 'ok' | 'duplicate' | 'wrong' | 'error'

interface ScanResult {
  id: number
  /** The label or typed No Manufaktur, in lower case. */
  key: string
  code: string
  text: string
  outcome: Outcome
  message?: string
}

const OUTCOME: Record<Exclude<Outcome, 'sending'>, { label: string; fg: string; bg: string; vibrate: number | number[] }> = {
  ok: { label: '✓', fg: colors.ok, bg: colors.okSoft, vibrate: 80 },
  duplicate: { label: '!', fg: colors.warning, bg: colors.warningSoft, vibrate: [0, 60, 80, 60] },
  wrong: { label: '✕', fg: colors.danger, bg: colors.dangerSoft, vibrate: [0, 300, 120, 300] },
  error: { label: '✕', fg: colors.danger, bg: colors.dangerSoft, vibrate: [0, 120, 100, 120] },
}

/**
 * Scan muat/bongkar of one delivery: every label is sent to the server at once, with the phone's
 * time and position, and is recorded only once the server answered (no offline mode, D-9).
 * A cylinder that is not in the transaction is refused with an alert; one scanned before is
 * reported as such and not counted again.
 */
export default function DeliveryScanScreen() {
  const params = useLocalSearchParams<{ id: string; phase: string; stop_id?: string; customer?: string }>()
  const phase: ScanPhase = params.phase === 'unload' ? 'unload' : 'load'
  const { post, get } = useSession()

  const [permission, requestPermission] = useCameraPermissions()
  const [torch, setTorch] = useState(false)
  const [manual, setManual] = useState('')
  const [results, setResults] = useState<ScanResult[]>([])
  const [banner, setBanner] = useState<ScanResult | null>(null)
  const [showLeft, setShowLeft] = useState(false)
  const [delivery, setDelivery] = useState<Delivery | null>(null)
  const deliveryRef = useRef<Delivery | null>(null)
  const lastSeen = useRef(new Map<string, number>())
  const sending = useRef(new Set<string>())
  const nextId = useRef(1)
  // While an alert is open the camera keeps reporting the same label; those reports are ignored.
  const paused = useRef(false)
  const bannerTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  // One scan after the other, in the order they were made.
  const queue = useRef<Promise<void>>(Promise.resolve())

  const keepDelivery = (d: Delivery) => {
    deliveryRef.current = d
    setDelivery(d)
  }

  useEffect(() => {
    get<{ data: Delivery }>(`/deliveries/${params.id}`)
      .then((r) => keepDelivery(r.data))
      .catch(() => undefined)
    return () => {
      if (bannerTimer.current) clearTimeout(bannerTimer.current)
    }
  }, [get, params.id])

  const show = (result: ScanResult) => {
    setResults((prev) => [result, ...prev.filter((r) => r.id !== result.id)].slice(0, MAX_RESULTS))
    if (result.outcome === 'sending') return
    Vibration.vibrate(OUTCOME[result.outcome].vibrate)
    setBanner(result)
    if (bannerTimer.current) clearTimeout(bannerTimer.current)
    bannerTimer.current = setTimeout(() => setBanner(null), BANNER_MS)
    if (result.outcome === 'wrong') {
      paused.current = true
      NativeAlert.alert('Tabung tidak sesuai', `${result.message ?? result.text}\n\nKeluarkan tabung ini dari muatan. Tabung tidak dihitung.`, [
        { text: 'Mengerti', onPress: () => (paused.current = false) },
      ], { cancelable: false })
    }
  }

  const duplicateMessage = () => `Sudah di-scan ${phase === 'load' ? 'muat' : 'bongkar'} sebelumnya. Tidak dihitung lagi.`

  const send = async (base: ScanResult) => {
    try {
      const r = await post<{ data: Delivery; meta: { asset_id: string; already: boolean } }>(`/deliveries/${params.id}/scan`, {
        phase,
        code: base.code,
        ...(params.stop_id ? { stop_id: params.stop_id } : {}),
        ...(await deliveryMeta()),
      })
      keepDelivery(r.data)
      const item = r.data.items?.find((i) => i.asset_id === r.meta.asset_id)
      const text = item ? itemText(item) : base.code
      show(r.meta.already ? { ...base, text, outcome: 'duplicate', message: duplicateMessage() } : { ...base, text, outcome: 'ok' })
    } catch (e) {
      const wrong = e instanceof ApiError && (e.code === 'NOT_IN_DELIVERY' || e.code === 'NOT_LOADED' || e.code === 'OTHER_CUSTOMER' || e.code === 'CYLINDER_NOT_FOUND')
      show({ ...base, outcome: wrong ? 'wrong' : 'error', message: errorMessage(e) })
    } finally {
      sending.current.delete(base.key)
    }
  }

  const scan = (raw: string) => {
    const code = raw.trim()
    const key = code.toLowerCase()
    if (!code || sending.current.has(key)) return
    const base: ScanResult = { id: nextId.current++, key, code, text: code, outcome: 'sending' }
    // Already counted here: told at once, without asking the server again.
    const known = findItem(deliveryRef.current, code)
    if (known && isScanned(known, phase)) {
      show({ ...base, text: itemText(known), outcome: 'duplicate', message: duplicateMessage() })
      return
    }
    sending.current.add(key)
    show({ ...base, text: known ? itemText(known) : code })
    queue.current = queue.current.then(() => send(base))
  }

  const onCamera = ({ data }: BarcodeScanningResult) => {
    const key = data.trim().toLowerCase()
    const now = Date.now()
    const before = lastSeen.current.get(key) ?? 0
    lastSeen.current.set(key, now)
    if (!paused.current && now - before > OUT_OF_VIEW_MS) scan(data)
  }

  const submitManual = () => {
    scan(manual)
    setManual('')
  }

  const own = scanTargets(delivery, phase, params.stop_id || undefined)
  const left = own.filter((i) => !isScanned(i, phase))
  const done = own.length - left.length
  const phaseLabel = phase === 'load' ? 'MUAT' : 'BONGKAR'
  const verb = phase === 'load' ? 'dimuat' : 'dibongkar'
  const complete = own.length > 0 && left.length === 0

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <Stack.Screen options={{ title: delivery ? `${phaseLabel} · ${delivery.number}` : 'Pindai Tabung' }} />
      <View style={styles.camera}>
        {permission?.granted ? (
          <>
            <CameraView style={StyleSheet.absoluteFill} facing="back" enableTorch={torch} barcodeScannerSettings={{ barcodeTypes: BARCODE_TYPES }} onBarcodeScanned={onCamera} />
            <View style={styles.cameraOverlay} pointerEvents="box-none">
              <View style={[styles.frame, banner && banner.outcome !== 'sending' && { borderColor: OUTCOME[banner.outcome].fg }]} />
              <Text style={styles.cameraHint}>{`${phaseLabel}${params.customer ? ` · ${params.customer}` : ''}`}</Text>
            </View>
            <Pressable style={styles.torch} onPress={() => setTorch((t) => !t)} accessibilityRole="button" accessibilityLabel={torch ? 'Matikan senter' : 'Nyalakan senter'}>
              <Text style={styles.torchText}>{torch ? 'Senter: nyala' : 'Senter'}</Text>
            </Pressable>
          </>
        ) : (
          <View style={[styles.cameraOverlay, { padding: 24 }]}>
            <Text style={{ color: '#fff', textAlign: 'center' }}>Kamera dipakai untuk memindai label tabung. Anda juga dapat mengetik No Manufaktur di bawah.</Text>
            {permission && (permission.canAskAgain ? <Button title="Izinkan kamera" onPress={requestPermission} /> : <Button title="Buka pengaturan" variant="secondary" onPress={() => Linking.openSettings()} />)}
          </View>
        )}
        {banner && banner.outcome !== 'sending' && (
          <View style={[styles.banner, { backgroundColor: OUTCOME[banner.outcome].fg }]} accessibilityLiveRegion="assertive" accessibilityRole="alert">
            <Text style={styles.bannerTitle} numberOfLines={1}>
              {banner.outcome === 'ok' ? `${banner.text} ${verb}` : banner.outcome === 'duplicate' ? 'Tabung sudah di-scan' : banner.outcome === 'wrong' ? 'Tabung tidak sesuai' : 'Belum tercatat'}
            </Text>
            <Text style={styles.bannerText} numberOfLines={2}>
              {banner.outcome === 'ok' ? `${done} dari ${own.length} tabung` : banner.outcome === 'duplicate' ? `${banner.text} · tidak dihitung lagi` : banner.message}
            </Text>
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

      <Pressable style={styles.summary} onPress={() => setShowLeft((v) => !v)} disabled={left.length === 0} accessibilityRole="button" accessibilityLiveRegion="polite" accessibilityLabel={`${done} dari ${own.length} tabung ${verb}`}>
        <View style={styles.summaryRow}>
          <Text style={styles.summaryCount}>
            {done}
            <Text style={styles.summaryOf}>{` / ${own.length}`}</Text>
          </Text>
          <Text style={[styles.summaryText, complete && { color: colors.ok }]}>{complete ? `Semua tabung ${verb}` : `tabung ${verb}`}</Text>
          {left.length > 0 && <Text style={styles.link}>{showLeft ? 'Tutup' : `Belum (${left.length})`}</Text>}
        </View>
        <ProgressBar value={own.length ? done / own.length : 0} tone={complete ? 'ok' : 'brand'} />
        {showLeft && left.length > 0 && (
          <View style={styles.chips}>
            {left.map((i) => (
              <Text key={i.id} style={styles.chip}>
                {i.serial_number ?? i.name}
              </Text>
            ))}
          </View>
        )}
      </Pressable>

      <ScrollView contentContainerStyle={styles.list} keyboardShouldPersistTaps="handled">
        {results.length === 0 ? (
          <Text style={[styles.muted, { paddingVertical: 24 }]}>Arahkan kamera ke label tabung. Setiap scan langsung tercatat di server.</Text>
        ) : (
          results.map((r) => (
            <View key={r.id} style={[styles.item, r.outcome !== 'sending' && r.outcome !== 'ok' && { borderColor: OUTCOME[r.outcome].fg, backgroundColor: OUTCOME[r.outcome].bg }]}>
              {r.outcome === 'sending' ? (
                <ActivityIndicator color={colors.brand} style={styles.mark} />
              ) : (
                <View style={[styles.mark, { backgroundColor: OUTCOME[r.outcome].fg }]}>
                  <Text style={styles.markText}>{OUTCOME[r.outcome].label}</Text>
                </View>
              )}
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={styles.itemText}>{r.text}</Text>
                <Text style={{ color: r.outcome === 'ok' || r.outcome === 'sending' ? colors.muted : OUTCOME[r.outcome].fg, fontSize: 13 }}>
                  {r.outcome === 'sending' ? 'Mengirim…' : r.outcome === 'ok' ? `${phase === 'load' ? 'Termuat' : 'Terbongkar'} · dihitung` : r.message}
                </Text>
              </View>
              {r.outcome === 'error' && (
                <Pressable onPress={() => scan(r.code)} hitSlop={8} accessibilityRole="button" accessibilityLabel={`Kirim ulang ${r.text}`}>
                  <Text style={{ color: colors.brand, fontWeight: '700' }}>Ulangi</Text>
                </Pressable>
              )}
            </View>
          ))
        )}
      </ScrollView>

      <View style={styles.footer}>
        <Button title={complete ? 'Selesai' : 'Kembali'} variant={complete ? 'primary' : 'secondary'} onPress={() => router.back()} />
      </View>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  camera: { height: 280, backgroundColor: '#000' },
  cameraOverlay: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, alignItems: 'center', justifyContent: 'center', gap: 12 },
  frame: { width: 230, height: 130, borderWidth: 3, borderColor: '#fff', borderRadius: 14, opacity: 0.95 },
  cameraHint: { color: '#fff', fontSize: 15, fontWeight: '700', textAlign: 'center', paddingHorizontal: 24, textShadowColor: 'rgba(0,0,0,0.6)', textShadowRadius: 4 },
  torch: { position: 'absolute', right: 12, bottom: 12, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 999, backgroundColor: 'rgba(0,0,0,0.55)' },
  torchText: { color: '#fff', fontWeight: '600' },
  banner: { position: 'absolute', top: 12, left: 12, right: 12, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 10, gap: 2 },
  bannerTitle: { color: '#fff', fontSize: 16, fontWeight: '800' },
  bannerText: { color: 'rgba(255,255,255,0.92)', fontSize: 13 },
  manual: { flexDirection: 'row', gap: 8, padding: 12 },
  manualInput: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text, backgroundColor: colors.surface },
  summary: { marginHorizontal: 12, padding: 12, gap: 8, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  summaryRow: { flexDirection: 'row', alignItems: 'baseline', gap: 8 },
  summaryCount: { fontSize: 26, fontWeight: '800', color: colors.text },
  summaryOf: { fontSize: 18, fontWeight: '600', color: colors.muted },
  summaryText: { flex: 1, fontSize: 15, fontWeight: '600', color: colors.text },
  link: { color: colors.brand, fontWeight: '700' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { fontSize: 13, color: colors.text, backgroundColor: colors.background, borderWidth: 1, borderColor: colors.border, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
  list: { padding: 12, gap: 8, paddingBottom: 24 },
  muted: { color: colors.muted, fontSize: 13, textAlign: 'center' },
  item: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  mark: { width: 28, height: 28, borderRadius: 14, alignItems: 'center', justifyContent: 'center' },
  markText: { color: '#fff', fontWeight: '800', fontSize: 15 },
  itemText: { fontSize: 15, fontWeight: '600', color: colors.text },
  footer: { padding: 12, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border, backgroundColor: colors.surface },
})
