import { CameraView, useCameraPermissions, type BarcodeScanningResult, type BarcodeType } from 'expo-camera'
import { router, Stack, useFocusEffect, useLocalSearchParams } from 'expo-router'
import { useCallback, useEffect, useRef, useState } from 'react'
import { ActivityIndicator, Linking, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Chips, DateInput, Field, Input, Select } from '@/components/form'
import { Alert, Badge, Button, colors } from '@/components/ui'
import { ApiError, errorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { fieldErrors, useMasterOptions } from '@/lib/forms'
import { useSession } from '@/lib/session'
import {
  canRecord,
  daysBetween,
  emptyIncoming,
  emptyOutgoing,
  localToday,
  OUTGOING_REASONS,
  payload,
  rejectReason,
  TYPE_LABEL,
  validateOutgoing,
  type IncomingForm,
  type MobileTransactionType,
  type OutgoingForm,
} from '@/lib/transactions'
import { assetStatusLabel, type Asset, type AssetDetail, type AssetTransaction, type Paginated } from '@/lib/types'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/** Same formats as the scanner (scan.tsx). */
const BARCODE_TYPES: BarcodeType[] = ['code128', 'code39', 'code93', 'codabar', 'ean13', 'ean8', 'upc_a', 'upc_e', 'itf14', 'qr', 'datamatrix', 'pdf417', 'aztec']

/** One scanned (or typed) code and what it turned out to be. */
interface Item {
  key: string
  code: string
  state: 'loading' | 'ready' | 'rejected' | 'error'
  asset?: Asset
  reason?: string
  /** Incoming: the outgoing transaction that sent the asset out. */
  outgoing?: AssetTransaction | null
}

type Step = 'scan' | 'form' | 'done'

/**
 * Outgoing / incoming from the phone (F-03, mockup docs/design/mockups/F-03-mobile-keluar-masuk.html):
 * scan 1..n assets, fill a short form, confirm, then show the transaction number. Online only (D-9).
 */
export default function TransactionScreen() {
  const params = useLocalSearchParams<{ type: string; code?: string }>()
  const type: MobileTransactionType = params.type === 'incoming' ? 'incoming' : 'outgoing'
  const { profile, can, get, post } = useSession()
  const allowed = canRecord(profile, can, type)

  const [step, setStep] = useState<Step>('scan')
  const [items, setItems] = useState<Item[]>([])
  const [outgoingForm, setOutgoingForm] = useState<OutgoingForm>(emptyOutgoing)
  const [incomingForm, setIncomingForm] = useState<IncomingForm>(emptyIncoming)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<{ title: string; text: string } | null>(null)
  const [confirming, setConfirming] = useState(false)
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState<{ number: string; count: number } | null>(null)
  // Codes already scanned (the camera reports a label many times per second) and assets already listed.
  const seen = useRef(new Set<string>())
  const assetIds = useRef(new Set<string>())

  const ready = items.filter((i) => i.state === 'ready')
  const title = `${TYPE_LABEL[type]}${step === 'scan' ? ' · Pindai aset' : step === 'form' ? ` · ${ready.length} aset` : ''}`

  const add = useCallback(
    async (raw: string) => {
      const code = raw.trim()
      if (!code || seen.current.has(code.toLowerCase())) return
      seen.current.add(code.toLowerCase())
      const key = `${Date.now()}-${code}`
      setItems((prev) => [{ key, code, state: 'loading' }, ...prev])
      const patch = (next: Partial<Item>) => setItems((prev) => prev.map((i) => (i.key === key ? { ...i, ...next } : i)))
      try {
        const { data: asset } = await get<AssetDetail>(`/assets/lookup?code=${encodeURIComponent(code)}`)
        // The same asset scanned by its QR and typed by its serial number counts once.
        if (assetIds.current.has(asset.id)) {
          setItems((prev) => prev.filter((i) => i.key !== key))
          return
        }
        assetIds.current.add(asset.id)
        const reason = rejectReason(asset, type)
        let outgoing: AssetTransaction | null = null
        if (!reason && type === 'incoming' && can('transaction.view')) {
          outgoing = (await get<Paginated<AssetTransaction>>(`/transactions?asset_id=${asset.id}&type=outgoing&per_page=1`).catch(() => null))?.data[0] ?? null
        }
        patch({ asset, outgoing, state: reason ? 'rejected' : 'ready', reason: reason ?? undefined })
      } catch (e) {
        const notFound = e instanceof ApiError && e.status === 404
        // A failed lookup may be retried by scanning again.
        if (!notFound) seen.current.delete(code.toLowerCase())
        patch({ state: notFound ? 'rejected' : 'error', reason: notFound ? 'Aset tidak ditemukan' : errorMessage(e) })
      }
    },
    [get, can, type],
  )

  // Opened from an asset's detail: that asset is the first one.
  useEffect(() => {
    if (params.code) add(params.code)
  }, [params.code, add])

  const remove = (item: Item) => {
    seen.current.delete(item.code.toLowerCase())
    if (item.asset) assetIds.current.delete(item.asset.id)
    setItems((prev) => prev.filter((i) => i.key !== item.key))
  }

  const review = () => {
    setFormError(null)
    const found = type === 'outgoing' ? validateOutgoing(outgoingForm) : {}
    setErrors(found)
    if (Object.keys(found).length === 0) setConfirming(true)
  }

  const send = async () => {
    setSending(true)
    setFormError(null)
    try {
      const ids = ready.map((i) => i.asset!.id)
      const r = await post<{ data: AssetTransaction[] }>('/transactions', payload(type, ids, type === 'outgoing' ? outgoingForm : incomingForm))
      setResult({ number: r.data[0]?.transaction_number ?? '—', count: r.data.length })
      setConfirming(false)
      setStep('done')
    } catch (e) {
      setConfirming(false)
      const fields = fieldErrors(e)
      setErrors(fields)
      // Nothing was recorded on any failure: the transaction is all or nothing.
      if (e instanceof ApiError && e.status === 0) {
        setFormError({ title: 'Transaksi belum terkirim', text: `${e.message} Belum ada yang dicatat; status aset tidak berubah. Kirim ulang setelah online.` })
      } else if (e instanceof ApiError && e.status === 403) {
        setFormError({ title: 'Izin Anda berubah', text: 'Akun ini tidak lagi dapat mencatat transaksi ini. Keluar lalu masuk ulang untuk memperbarui izin.' })
      } else if (Object.keys(fields).length === 0) {
        setFormError({ title: 'Transaksi tidak dapat dicatat', text: errorMessage(e) })
      }
    } finally {
      setSending(false)
    }
  }

  const restart = () => {
    seen.current.clear()
    assetIds.current.clear()
    setItems([])
    setOutgoingForm(emptyOutgoing())
    setIncomingForm(emptyIncoming())
    setErrors({})
    setFormError(null)
    setResult(null)
    setStep('scan')
  }

  if (!allowed) {
    return (
      <SafeAreaView style={styles.center} edges={['bottom']}>
        <Stack.Screen options={{ title: TYPE_LABEL[type] }} />
        <Text style={styles.title}>Tidak dapat mencatat transaksi</Text>
        <Text style={styles.mutedCenter}>Akun ini tidak memiliki izin transaksi {TYPE_LABEL[type].toLowerCase()}, atau izin baru diberikan setelah Anda masuk. Keluar lalu masuk ulang untuk memperbarui izin.</Text>
        <Button title="Kembali" variant="secondary" onPress={() => router.back()} />
      </SafeAreaView>
    )
  }

  if (step === 'done' && result) {
    return (
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
        <Stack.Screen options={{ title: 'Transaksi dicatat', headerBackVisible: false }} />
        <View style={styles.center}>
          <View style={styles.okIcon}>
            <Text style={{ fontSize: 30, color: colors.ok }}>✓</Text>
          </View>
          <Text style={styles.doneTitle}>{`${result.count} aset dicatat ${type === 'outgoing' ? 'keluar' : 'masuk'}`}</Text>
          <Text style={styles.muted}>Nomor transaksi</Text>
          <Text style={styles.number} selectable>
            {result.number}
          </Text>
          {type === 'outgoing' && (
            <Text style={styles.mutedCenter}>
              {[outgoingForm.counterparty_name.trim(), outgoingForm.outgoing_nature === 'temporary' && outgoingForm.due_date ? `kembali ${formatDate(outgoingForm.due_date)}` : null].filter(Boolean).join(' · ')}
            </Text>
          )}
        </View>
        <View style={styles.footer}>
          <Button title="Selesai" onPress={() => router.back()} />
          <Button title="Catat transaksi lain" variant="secondary" onPress={restart} />
        </View>
      </SafeAreaView>
    )
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <Stack.Screen options={{ title }} />
      {step === 'scan' ? (
        <ScanStep type={type} items={items} onCode={add} onRemove={remove} />
      ) : type === 'outgoing' ? (
        <OutgoingFields form={outgoingForm} onChange={setOutgoingForm} errors={errors} formError={formError} canDispose={can('asset.dispose')} />
      ) : (
        <IncomingFields form={incomingForm} onChange={setIncomingForm} errors={errors} formError={formError} />
      )}

      <View style={styles.footer}>
        {step === 'scan' ? (
          <Button title={ready.length ? `Lanjut · ${ready.length} aset` : 'Lanjut'} onPress={() => setStep('form')} disabled={ready.length === 0 || items.some((i) => i.state === 'loading')} />
        ) : (
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Button title="Ubah aset" variant="secondary" onPress={() => setStep('scan')} style={{ flex: 1 }} />
            <Button title={formError?.title === 'Transaksi belum terkirim' ? 'Kirim ulang' : 'Periksa'} onPress={review} style={{ flex: 2 }} />
          </View>
        )}
      </View>

      <Confirm
        visible={confirming}
        type={type}
        items={ready}
        outgoing={outgoingForm}
        incoming={incomingForm}
        sending={sending}
        onCancel={() => setConfirming(false)}
        onConfirm={send}
      />
    </SafeAreaView>
  )
}

function ScanStep({ type, items, onCode, onRemove }: { type: MobileTransactionType; items: Item[]; onCode: (code: string) => void; onRemove: (item: Item) => void }) {
  const [permission, requestPermission] = useCameraPermissions()
  const [focused, setFocused] = useState(false)
  const [torch, setTorch] = useState(false)
  const [manual, setManual] = useState('')
  const ready = items.filter((i) => i.state === 'ready').length

  useFocusEffect(
    useCallback(() => {
      setFocused(true)
      return () => setFocused(false)
    }, []),
  )

  const submitManual = () => {
    onCode(manual)
    setManual('')
  }

  return (
    <View style={{ flex: 1 }}>
      <View style={styles.camera}>
        {permission?.granted ? (
          <>
            {focused && (
              <CameraView
                style={StyleSheet.absoluteFill}
                facing="back"
                enableTorch={torch}
                barcodeScannerSettings={{ barcodeTypes: BARCODE_TYPES }}
                onBarcodeScanned={(r: BarcodeScanningResult) => onCode(r.data)}
              />
            )}
            <View style={styles.cameraOverlay} pointerEvents="box-none">
              <View style={styles.frame} />
              <Text style={styles.cameraHint}>Arahkan ke barcode atau QR pada label</Text>
              <Pressable style={styles.torch} onPress={() => setTorch((t) => !t)} accessibilityRole="button">
                <Text style={{ color: '#fff', fontWeight: '600' }}>{torch ? 'Matikan senter' : 'Senter'}</Text>
              </Pressable>
            </View>
          </>
        ) : (
          <View style={[styles.cameraOverlay, { gap: 10 }]}>
            <Text style={{ color: '#fff', textAlign: 'center' }}>Kamera dipakai untuk memindai label aset. Anda juga dapat mengetik nomor seri di bawah.</Text>
            {permission && (permission.canAskAgain ? <Button title="Izinkan kamera" onPress={requestPermission} /> : <Button title="Buka pengaturan" variant="secondary" onPress={() => Linking.openSettings()} />)}
          </View>
        )}
      </View>

      <View style={styles.manual}>
        <TextInput
          style={styles.manualInput}
          value={manual}
          onChangeText={setManual}
          placeholder="Ketik nomor seri"
          placeholderTextColor={colors.muted}
          autoCapitalize="characters"
          autoCorrect={false}
          returnKeyType="done"
          onSubmitEditing={submitManual}
          accessibilityLabel="Nomor seri"
        />
        <Button title="Tambah" variant="secondary" onPress={submitManual} disabled={!manual.trim()} />
      </View>

      <ScrollView contentContainerStyle={styles.list} keyboardShouldPersistTaps="handled">
        {items.length === 0 ? (
          <View style={{ alignItems: 'center', gap: 6, paddingVertical: 24 }}>
            <Text style={styles.title}>Belum ada aset</Text>
            <Text style={styles.mutedCenter}>
              {type === 'outgoing' ? 'Pindai label aset satu per satu. Beberapa aset dapat dicatat dalam satu transaksi.' : 'Pindai label aset yang kembali dari pihak luar.'}
            </Text>
          </View>
        ) : (
          <>
            <Text style={styles.counter}>{`${items.length} dipindai · ${ready} siap`}</Text>
            {items.map((i) => (
              <ItemRow key={i.key} item={i} onRemove={() => onRemove(i)} />
            ))}
          </>
        )}
      </ScrollView>
    </View>
  )
}

function ItemRow({ item, onRemove }: { item: Item; onRemove: () => void }) {
  const bad = item.state === 'rejected' || item.state === 'error'
  const late = item.outgoing?.due_date ? daysBetween(item.outgoing.due_date, localToday()) : 0
  return (
    <View style={[styles.item, bad && styles.itemBad]}>
      {item.state === 'loading' ? (
        <ActivityIndicator color={colors.brand} />
      ) : (
        <Badge colors={bad ? [colors.dangerSoft, colors.danger] : [colors.okSoft, colors.ok]}>{bad ? 'Ditolak' : 'Siap'}</Badge>
      )}
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={styles.itemName}>{item.asset?.name ?? item.code}</Text>
        {item.asset && (
          <Text style={styles.muted}>
            {[item.asset.serial_number, assetStatusLabel[item.asset.status], item.asset.location?.name].filter(Boolean).join(' · ')}
          </Text>
        )}
        {item.outgoing && (
          <Text style={styles.muted}>
            {`${item.outgoing.counterparty_name ?? 'Pihak luar'} · ${item.outgoing.transaction_number}`}
          </Text>
        )}
        {item.outgoing && late > 0 && <Badge colors={[colors.dangerSoft, colors.danger]}>{`Terlambat ${late} hari`}</Badge>}
        {bad && item.reason && <Text style={{ color: colors.danger, fontSize: 14 }}>{item.reason}</Text>}
      </View>
      {item.state !== 'loading' && (
        <Pressable onPress={onRemove} hitSlop={10} accessibilityRole="button" accessibilityLabel={`Hapus ${item.asset?.name ?? item.code} dari daftar`} style={styles.remove}>
          <Text style={{ fontSize: 18, color: colors.muted }}>✕</Text>
        </Pressable>
      )}
    </View>
  )
}

function FormAlert({ formError, errors }: { formError: { title: string; text: string } | null; errors: Record<string, string> }) {
  const count = Object.keys(errors).length
  if (formError) {
    return <Alert title={formError.title}>{formError.text}</Alert>
  }
  return count > 0 ? <Alert title={`Periksa ${count} isian`}>Isian yang ditandai merah belum benar.</Alert> : null
}

function OutgoingFields({
  form,
  onChange,
  errors,
  formError,
  canDispose,
}: {
  form: OutgoingForm
  onChange: (f: OutgoingForm) => void
  errors: Record<string, string>
  formError: { title: string; text: string } | null
  canDispose: boolean
}) {
  const set = (patch: Partial<OutgoingForm>) => onChange({ ...form, ...patch })
  const natures = [{ value: 'temporary', label: 'Sementara' }, ...(canDispose ? [{ value: 'permanent', label: 'Permanen' }] : [])]
  return (
    <ScrollView contentContainerStyle={styles.form} keyboardShouldPersistTaps="handled">
      <FormAlert formError={formError} errors={errors} />
      <Field label="Sifat" required hint={form.outgoing_nature === 'permanent' ? 'Status aset menjadi Dihapuskan.' : 'Aset akan kembali; statusnya Di Luar Organisasi.'} error={errors.outgoing_nature}>
        {/* Reasons differ per nature, so the previous one no longer applies. */}
        <Chips value={[form.outgoing_nature]} options={natures} onChange={([v]) => v && set({ outgoing_nature: v as OutgoingForm['outgoing_nature'], reason_code: '', due_date: '' })} />
      </Field>
      <Field label="Alasan" required error={errors.reason_code}>
        <Select title="Alasan keluar" value={form.reason_code || null} options={OUTGOING_REASONS[form.outgoing_nature]} onChange={(v) => set({ reason_code: v ?? '' })} invalid={!!errors.reason_code} />
      </Field>
      <Field label="Nama pihak luar" required error={errors.counterparty_name}>
        <Input value={form.counterparty_name} onChangeText={(t) => set({ counterparty_name: t })} maxLength={150} placeholder="mis. CV Sinar Servis" invalid={!!errors.counterparty_name} />
      </Field>
      <Field label="Instansi" error={errors.counterparty_org}>
        <Input value={form.counterparty_org} onChangeText={(t) => set({ counterparty_org: t })} maxLength={150} />
      </Field>
      <Field label="Kontak" hint="Data pribadi; hanya tampil bagi yang berizin." error={errors.counterparty_contact}>
        <Input value={form.counterparty_contact} onChangeText={(t) => set({ counterparty_contact: t })} maxLength={150} />
      </Field>
      {form.outgoing_nature === 'temporary' && (
        <Field label="Rencana tanggal kembali" required error={errors.due_date}>
          <DateInput value={form.due_date} onChange={(v) => set({ due_date: v })} invalid={!!errors.due_date} />
        </Field>
      )}
      <Field label="Nomor dokumen" hint="Surat jalan, BAST, atau faktur." error={errors.document_number}>
        <Input value={form.document_number} onChangeText={(t) => set({ document_number: t })} maxLength={100} autoCapitalize="characters" />
      </Field>
      <Field label="Keterangan" required={form.reason_code === 'other'} error={errors.notes}>
        <Input value={form.notes} onChangeText={(t) => set({ notes: t })} maxLength={1000} multiline invalid={!!errors.notes} />
      </Field>
      <Text style={styles.muted}>{`Tanggal keluar: hari ini (${formatDate(localToday())}). Tanggal mundur dicatat lewat web.`}</Text>
    </ScrollView>
  )
}

function IncomingFields({
  form,
  onChange,
  errors,
  formError,
}: {
  form: IncomingForm
  onChange: (f: IncomingForm) => void
  errors: Record<string, string>
  formError: { title: string; text: string } | null
}) {
  const set = (patch: Partial<IncomingForm>) => onChange({ ...form, ...patch })
  const locations = useMasterOptions('/locations')
  return (
    <ScrollView contentContainerStyle={styles.form} keyboardShouldPersistTaps="handled">
      <FormAlert formError={formError} errors={errors} />
      <Field label="Kondisi saat kembali" required hint="Baik → Tersedia. Perlu perbaikan → Dalam Perbaikan." error={errors.to_status}>
        <Chips
          value={[form.to_status]}
          options={[
            { value: 'available', label: 'Baik' },
            { value: 'under_maintenance', label: 'Perlu perbaikan' },
          ]}
          onChange={([v]) => v && set({ to_status: v as IncomingForm['to_status'] })}
        />
      </Field>
      <Field label="Lokasi masuk" hint={locations.error ?? 'Kosongkan bila kembali ke lokasi asal.'} error={errors.to_location_id}>
        <Select title="Lokasi masuk" value={form.to_location_id || null} options={locations.options} loading={locations.loading} allowEmpty="— Lokasi asal —" onChange={(v) => set({ to_location_id: v ?? '' })} />
      </Field>
      <Field label="Diterima dari" hint="Kosongkan untuk memakai pihak luar pada transaksi keluar." error={errors.counterparty_name}>
        <Input value={form.counterparty_name} onChangeText={(t) => set({ counterparty_name: t })} maxLength={150} />
      </Field>
      <Field label="Nomor dokumen" hint="mis. BAST pengembalian." error={errors.document_number}>
        <Input value={form.document_number} onChangeText={(t) => set({ document_number: t })} maxLength={100} autoCapitalize="characters" />
      </Field>
      <Field label="Keterangan" error={errors.notes}>
        <Input value={form.notes} onChangeText={(t) => set({ notes: t })} maxLength={1000} multiline />
      </Field>
      <Text style={styles.muted}>{`Tanggal masuk: hari ini (${formatDate(localToday())}).`}</Text>
    </ScrollView>
  )
}

/** Summary before sending: says what changes and that it cannot be edited afterwards. */
function Confirm({
  visible,
  type,
  items,
  outgoing,
  incoming,
  sending,
  onCancel,
  onConfirm,
}: {
  visible: boolean
  type: MobileTransactionType
  items: Item[]
  outgoing: OutgoingForm
  incoming: IncomingForm
  sending: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  const reason = OUTGOING_REASONS[outgoing.outgoing_nature].find((r) => r.value === outgoing.reason_code)?.label
  const rows: [string, string][] =
    type === 'outgoing'
      ? [
          ['Sifat', outgoing.outgoing_nature === 'temporary' ? 'Sementara' : 'Permanen'],
          ['Alasan', reason ?? '—'],
          ['Pihak luar', outgoing.counterparty_name.trim()],
          ...(outgoing.outgoing_nature === 'temporary' ? ([['Rencana kembali', formatDate(outgoing.due_date)]] as [string, string][]) : []),
        ]
      : [
          ['Kondisi', incoming.to_status === 'available' ? 'Baik · Tersedia' : 'Perlu perbaikan · Dalam Perbaikan'],
          ['Lokasi', incoming.to_location_id ? 'Lokasi dipilih' : 'Lokasi asal'],
        ]
  const effect =
    type === 'incoming'
      ? `Status ${items.length > 1 ? 'aset-aset ini' : 'aset ini'} menjadi ${incoming.to_status === 'available' ? 'Tersedia' : 'Dalam Perbaikan'}.`
      : outgoing.outgoing_nature === 'permanent'
        ? `Status ${items.length > 1 ? 'aset-aset ini' : 'aset ini'} menjadi Dihapuskan.`
        : `Status ${items.length > 1 ? 'aset-aset ini' : 'aset ini'} menjadi Di Luar Organisasi.`

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onCancel}>
      <View style={styles.backdrop}>
        <View style={styles.sheet} accessibilityViewIsModal>
          <View style={styles.grab} />
          <Text style={styles.sheetTitle}>{`Catat ${items.length} aset ${type === 'outgoing' ? 'keluar' : 'masuk'}?`}</Text>
          {rows.map(([k, v]) => (
            <View key={k} style={styles.kv}>
              <Text style={styles.muted}>{k}</Text>
              <Text style={styles.kvValue}>{v}</Text>
            </View>
          ))}
          <ScrollView style={{ maxHeight: 160 }}>
            {items.map((i) => (
              <Text key={i.key} style={styles.sheetItem}>{`• ${i.asset?.name}${i.asset?.serial_number ? ` (${i.asset.serial_number})` : ''}`}</Text>
            ))}
          </ScrollView>
          <View style={styles.info}>
            <Text style={{ color: colors.brandDark }}>{`${effect} Transaksi langsung berlaku, tercatat di audit trail, dan tidak dapat diubah.`}</Text>
          </View>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Button title="Kembali" variant="secondary" onPress={onCancel} disabled={sending} style={{ flex: 1 }} />
            <Button title={type === 'outgoing' ? 'Catat keluar' : 'Catat masuk'} onPress={onConfirm} loading={sending} style={{ flex: 1 }} />
          </View>
        </View>
      </View>
    </Modal>
  )
}

const mono = Platform.select({ ios: 'Menlo', default: 'monospace' })

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 10, backgroundColor: colors.background },
  title: { fontSize: 16, fontWeight: '700', color: colors.text },
  muted: { color: colors.muted, fontSize: 14 },
  mutedCenter: { color: colors.muted, fontSize: 14, textAlign: 'center' },
  footer: { gap: 8, padding: 12, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  camera: { height: 240, backgroundColor: '#111827' },
  cameraOverlay: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 16 },
  frame: { width: '70%', aspectRatio: 1.7, borderWidth: 3, borderColor: '#fff', borderRadius: 14 },
  cameraHint: { color: '#fff', fontSize: 14, textShadowColor: '#000', textShadowRadius: 4 },
  torch: { position: 'absolute', top: 10, right: 10, backgroundColor: 'rgba(0,0,0,0.55)', paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999 },
  manual: { flexDirection: 'row', gap: 8, padding: 12, backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border },
  manualInput: { flex: 1, minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text },
  list: { padding: 16, gap: 8 },
  counter: { fontWeight: '700', color: colors.text },
  item: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  itemBad: { borderColor: '#fecaca', backgroundColor: '#fffafa' },
  itemName: { fontSize: 15, fontWeight: '700', color: colors.text },
  remove: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  form: { padding: 16, gap: 16, paddingBottom: 32 },
  okIcon: { width: 64, height: 64, borderRadius: 20, backgroundColor: colors.okSoft, alignItems: 'center', justifyContent: 'center' },
  doneTitle: { fontSize: 20, fontWeight: '800', color: colors.text, textAlign: 'center' },
  number: { fontFamily: mono, fontSize: 22, fontWeight: '700', color: colors.text },
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(15,23,42,0.4)' },
  sheet: { backgroundColor: colors.surface, borderTopLeftRadius: 18, borderTopRightRadius: 18, padding: 16, paddingBottom: 28, gap: 12 },
  grab: { alignSelf: 'center', width: 40, height: 4, borderRadius: 4, backgroundColor: colors.border },
  sheetTitle: { fontSize: 18, fontWeight: '800', color: colors.text },
  kv: { flexDirection: 'row', justifyContent: 'space-between', gap: 12 },
  kvValue: { flexShrink: 1, textAlign: 'right', fontWeight: '600', color: colors.text },
  sheetItem: { color: colors.text, paddingVertical: 2 },
  info: { backgroundColor: colors.brandSoft, borderRadius: 10, padding: 12 },
})
