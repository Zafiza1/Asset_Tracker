import { router, Stack, useFocusEffect, useLocalSearchParams } from 'expo-router'
import { useCallback, useEffect, useRef, useState } from 'react'
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Field, Input, Select } from '@/components/form'
import { Alert, Badge, Button, Card, colors, Row } from '@/components/ui'
import { ApiError, errorMessage } from '@/lib/api'
import { canOperateDeliveries, DELIVERY_STATUS_COLORS, deliveryMeta, REPORT_LABEL, sendOnce, type DeliveryMeta } from '@/lib/deliveries'
import { formatDate, formatDateTime } from '@/lib/format'
import { fieldErrors } from '@/lib/forms'
import { useSession } from '@/lib/session'
import { useTrip } from '@/lib/trip'
import type { Delivery, DeliveryOptions, DeliveryReportType, DeliveryStop } from '@/lib/types'

// A screen that throws keeps the header; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

const STOP_COLORS: Record<DeliveryStop['status'], [string, string]> = {
  pending: ['#f1f5f9', '#334155'],
  arrived: ['#fef3c7', '#92400e'],
  done: ['#dcfce7', '#166534'],
  skipped: ['#f1f5f9', '#64748b'],
}

const ITEM_COLORS: Record<string, [string, string]> = {
  planned: ['#f1f5f9', '#334155'],
  loaded: ['#dbeafe', '#1e40af'],
  unloaded: ['#dcfce7', '#166534'],
  not_loaded: ['#f1f5f9', '#64748b'],
  returned: ['#dcfce7', '#166534'],
  missing: ['#fee2e2', '#991b1b'],
  cancelled: ['#f1f5f9', '#64748b'],
}

interface NoteRequest {
  title: string
  hint?: string
  requireNote?: boolean
  /** Label of the name input, when the action asks for one. */
  receiver?: string
}

/**
 * One delivery on the phone: fill the crew, scan muat/bongkar, and report the trip
 * (depart, arrive, issues, return, complete). Every write carries the phone's time and position.
 */
export default function DeliveryScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { get, post, put, can, profile } = useSession()
  const { deliveryId: tripId, sentAt, start: startTrip, stop: stopTrip } = useTrip()
  const operate = canOperateDeliveries(profile, can)

  const [delivery, setDelivery] = useState<Delivery | null>(null)
  const [options, setOptions] = useState<DeliveryOptions | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  // Crew form (draft/loading only)
  const [crew, setCrew] = useState({ vehicle_asset_id: '', driver_id: '', helper_id: '' })
  const [crewErrors, setCrewErrors] = useState<Record<string, string>>({})
  const crewLoadedFor = useRef<string | null>(null)

  // Note sheet (issue, stop_done, completed)
  const [noteReq, setNoteReq] = useState<NoteRequest | null>(null)
  const [note, setNote] = useState('')
  const [receiver, setReceiver] = useState('')
  const noteResolver = useRef<((v: { note: string | null; receiver_name: string | null } | null) => void) | null>(null)

  const load = useCallback(async () => {
    try {
      const d = (await get<{ data: Delivery }>(`/deliveries/${id}`)).data
      setDelivery(d)
      setError(null)
      if (operate && (d.status === 'draft' || d.status === 'loading') && crewLoadedFor.current !== d.id) {
        const o = (await get<{ data: DeliveryOptions }>('/deliveries/options')).data
        crewLoadedFor.current = d.id
        setOptions(o)
        setCrew({
          vehicle_asset_id: d.vehicle?.id ?? '',
          driver_id: d.driver?.id ?? '',
          helper_id: d.helper?.id ?? '',
        })
      }
    } catch (e) {
      setError(errorMessage(e))
    }
  }, [get, id, operate])

  useFocusEffect(
    useCallback(() => {
      load()
    }, [load]),
  )

  // A trip that ended (here, on the web, or on another phone) needs no more positions.
  const ended = delivery !== null && delivery.status !== 'in_transit'
  useEffect(() => {
    if (ended && tripId === id) stopTrip()
  }, [ended, tripId, id, stopTrip])

  /** Runs a phone write and shows the delivery as the server answers it; resolves true when accepted. */
  const act = async (fn: (meta: DeliveryMeta) => Promise<{ data: Delivery }>, { fresh = false } = {}): Promise<boolean> => {
    setBusy(true)
    setActionError(null)
    try {
      setDelivery((await fn(await deliveryMeta({ fresh }))).data)
      return true
    } catch (e) {
      setActionError(Object.values(fieldErrors(e))[0] ?? errorMessage(e))
      // Rejected because it is no longer where this screen thinks it is.
      if (e instanceof ApiError && e.status === 409) await load()
      return false
    } finally {
      setBusy(false)
    }
  }

  const report = (type: DeliveryReportType, extra: { stop_id?: string; note?: string | null; receiver_name?: string | null } = {}) =>
    act(
      (meta) => sendOnce(JSON.stringify([id, type, extra]), (client_id) => post<{ data: Delivery }>(`/deliveries/${id}/events`, { type, ...extra, client_id, ...meta })),
      { fresh: type === 'position' },
    )

  const refresh = async () => {
    setRefreshing(true)
    await load()
    setRefreshing(false)
  }

  const askNote = (req: NoteRequest) =>
    new Promise<{ note: string | null; receiver_name: string | null } | null>((resolve) => {
      setNote('')
      setReceiver('')
      setNoteReq(req)
      noteResolver.current = resolve
    })

  const closeNote = (value: { note: string | null; receiver_name: string | null } | null) => {
    setNoteReq(null)
    noteResolver.current?.(value)
    noteResolver.current = null
  }

  const submitNote = () => {
    const text = note.trim()
    if (noteReq?.requireNote && !text) return
    closeNote({ note: text || null, receiver_name: receiver.trim() || null })
  }

  const saveCrew = async () => {
    setBusy(true)
    setActionError(null)
    setCrewErrors({})
    const chosen = { vehicle_asset_id: crew.vehicle_asset_id || null, driver_id: crew.driver_id, helper_id: crew.helper_id || null }
    try {
      const meta = await deliveryMeta()
      const r = await sendOnce(JSON.stringify([id, 'crew', chosen]), (client_id) => put<{ data: Delivery }>(`/deliveries/${id}/crew`, { ...chosen, client_id, ...meta }))
      setDelivery(r.data)
    } catch (e) {
      const fields = fieldErrors(e)
      const crewFields = Object.fromEntries(Object.entries(fields).filter(([k]) => k in crew))
      if (Object.keys(crewFields).length > 0) setCrewErrors(crewFields)
      else setActionError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const scan = (phase: 'load' | 'unload', stop?: DeliveryStop) =>
    router.push({
      pathname: '/delivery-scan',
      params: {
        id,
        phase,
        stop_id: stop?.id ?? '',
        customer: stop?.customer.name ?? 'Gudang',
      },
    })

  const unscan = (assetId: string) =>
    act((meta) => post<{ data: Delivery }>(`/deliveries/${id}/unscan`, { asset_id: assetId, ...meta }))

  const stopDone = async (stop: DeliveryStop) => {
    const own = (delivery?.items ?? []).filter((i) => i.stop_id === stop.id)
    const outgoing = delivery?.direction === 'outgoing'
    const left = outgoing ? own.filter((i) => i.state === 'loaded').length : 0
    const missed = outgoing ? 0 : own.filter((i) => i.state === 'planned').length
    const answer = await askNote({
      title: `Selesai di ${stop.customer.name}?`,
      hint:
        left > 0
          ? `${left} tabung belum di-scan bongkar. Scan dulu, atau isi alasan bila dibawa kembali.`
          : missed > 0
            ? `${missed} tabung yang direncanakan belum di-scan muat dan dianggap tidak dibawa.`
            : undefined,
      requireNote: left > 0,
      receiver: outgoing ? 'Nama penerima' : 'Diserahkan oleh',
    })
    if (answer) report('stop_done', { stop_id: stop.id, ...answer })
  }

  const depart = async () => {
    const planned = (delivery?.items ?? []).filter((i) => i.state === 'planned').length
    const answer = await askNote({
      title: 'Berangkat?',
      hint:
        delivery?.direction === 'outgoing' && planned > 0
          ? `${planned} tabung yang direncanakan belum di-scan muat dan tidak ikut dibawa.`
          : 'Setelah berangkat, posisi HP ini dikirim berkala selama aplikasi terbuka.',
    })
    if (answer && (await report('depart', { note: answer.note }))) startTrip(id)
  }

  const finish = async () => {
    const left = (delivery?.items ?? []).filter((i) => i.state === 'loaded').length
    const incoming = delivery?.direction === 'incoming'
    const answer = await askNote({
      title: 'Selesaikan transaksi?',
      hint:
        left > 0
          ? incoming
            ? `${left} tabung belum di-scan bongkar di gudang. Isi alasan selisihnya.`
            : `${left} tabung dibawa kembali ke gudang. Isi keterangannya.`
          : 'Transaksi akan ditutup.',
      requireNote: left > 0,
    })
    if (answer) report('completed', { note: answer.note })
  }

  const issue = async () => {
    const answer = await askNote({ title: 'Kendala di jalan', requireNote: true })
    if (answer) report('issue', { note: answer.note })
  }

  if (error && !delivery) {
    return (
      <SafeAreaView style={styles.center} edges={['bottom']}>
        <Stack.Screen options={{ title: 'Pengiriman' }} />
        <Alert title="Transaksi belum termuat">{error}</Alert>
        <Button title="Coba lagi" onPress={load} />
        <Button title="Kembali" variant="secondary" onPress={() => router.back()} />
      </SafeAreaView>
    )
  }
  if (!delivery) {
    return (
      <SafeAreaView style={styles.center} edges={['bottom']}>
        <Stack.Screen options={{ title: 'Pengiriman' }} />
        <ActivityIndicator size="large" color={colors.brand} />
      </SafeAreaView>
    )
  }

  const d = delivery
  const open = d.status === 'draft' || d.status === 'loading' || d.status === 'in_transit'
  const outgoing = d.direction === 'outgoing'
  const stops = d.stops ?? []
  const items = d.items ?? []
  const loadedCount = items.filter((i) => i.state === 'loaded').length
  const crewReady = !!d.vehicle && !!d.driver
  const stopsClosed = stops.length > 0 && stops.every((s) => s.status === 'done' || s.status === 'skipped')

  const scanFor = (stop: DeliveryStop): { phase: 'load' | 'unload'; label: string } | null => {
    if (!operate || !open) return null
    if (outgoing) {
      if (d.status === 'in_transit') return stop.status === 'done' || stop.status === 'skipped' ? null : { phase: 'unload', label: 'Scan Bongkar' }
      return { phase: 'load', label: 'Scan Muat' }
    }
    if (d.status !== 'in_transit' || d.returned_at !== null) return null
    return stop.status === 'done' || stop.status === 'skipped' ? null : { phase: 'load', label: 'Scan Muat' }
  }

  const canDepart = operate && open && (d.status === 'draft' || d.status === 'loading') && crewReady && (!outgoing || loadedCount > 0)
  const canReturn = operate && d.status === 'in_transit' && stopsClosed && !outgoing && d.returned_at === null
  const canComplete = operate && d.status === 'in_transit' && stopsClosed && (outgoing || d.returned_at !== null)

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <Stack.Screen options={{ title: d.number }} />
      <ScrollView
        contentContainerStyle={styles.page}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.brand} />}
      >
        {error && (
          <Alert tone="warning" title="Belum diperbarui">
            {error}
          </Alert>
        )}
        {can('delivery.operate') && !operate && open && (
          <Alert tone="warning" title="Keluar lalu masuk lagi">
            Izin scan dan lapor perjalanan baru berlaku di HP ini setelah Anda masuk ulang.
          </Alert>
        )}

        <Card>
          <View style={{ padding: 14, gap: 8 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Badge colors={DELIVERY_STATUS_COLORS[d.status]}>{d.status_label}</Badge>
              <Text style={styles.muted}>{d.direction_label}</Text>
            </View>
            <Row label="Tanggal kirim">{formatDate(d.planned_date)}</Row>
            {d.expected_return_date && <Row label="Perkiraan kembali">{formatDate(d.expected_return_date)}</Row>}
            {d.departed_at && <Row label="Berangkat">{formatDateTime(d.departed_at)}</Row>}
            {d.returned_at && <Row label="Tiba di gudang">{formatDateTime(d.returned_at)}</Row>}
            <Row label="Kendaraan">{d.vehicle ? `${d.vehicle.name}${d.vehicle.plate ? ` · ${d.vehicle.plate}` : ''}` : '—'}</Row>
            <Row label="Supir">{d.driver?.name ?? '—'}</Row>
            <Row label="Kernet">{d.helper?.name ?? '—'}</Row>
            {d.notes && <Row label="Keterangan">{d.notes}</Row>}
            {d.end_note && <Row label="Catatan akhir">{d.end_note}</Row>}
          </View>
        </Card>

        {operate && open && (d.status === 'draft' || d.status === 'loading') && (
          <Card title="Kendaraan & Kru">
            <View style={styles.form}>
              {options ? (
                <>
                  <Field label="Kendaraan" required error={crewErrors.vehicle_asset_id}>
                    <Select
                      title="Kendaraan"
                      value={crew.vehicle_asset_id || null}
                      onChange={(v) => setCrew({ ...crew, vehicle_asset_id: v ?? '' })}
                      options={options.vehicles.map((v) => ({ value: v.id, label: `${v.name}${v.plate ? ` · ${v.plate}` : ''}`, hint: v.on_trip ? 'Masih dalam perjalanan lain' : undefined }))}
                    />
                  </Field>
                  <Field label="Supir" required error={crewErrors.driver_id}>
                    <Select
                      title="Supir"
                      value={crew.driver_id || null}
                      onChange={(v) => setCrew({ ...crew, driver_id: v ?? '' })}
                      options={options.drivers.map((p) => ({ value: p.id, label: p.name, hint: p.on_trip ? 'Masih dalam perjalanan lain' : undefined }))}
                    />
                  </Field>
                  <Field label="Kernet" error={crewErrors.helper_id}>
                    <Select
                      title="Kernet"
                      value={crew.helper_id || null}
                      allowEmpty="Tanpa kernet"
                      onChange={(v) => setCrew({ ...crew, helper_id: v ?? '' })}
                      options={options.helpers.map((p) => ({ value: p.id, label: p.name, hint: [p.position === 'driver' ? 'Supir' : 'Kernet', p.on_trip ? 'Masih dalam perjalanan lain' : null].filter(Boolean).join(' · ') }))}
                    />
                  </Field>
                </>
              ) : (
                <ActivityIndicator color={colors.brand} />
              )}
              <Button title="Simpan kru" onPress={saveCrew} loading={busy} disabled={!options || !crew.vehicle_asset_id || !crew.driver_id} />
            </View>
          </Card>
        )}

        <Card title={outgoing ? 'Customer Tujuan' : 'Customer Penjemputan'}>
          {stops.length === 0 && <Text style={styles.empty}>Belum ada customer.</Text>}
          {stops.map((s) => {
            const scanInfo = scanFor(s)
            const count = items.filter((i) => i.stop_id === s.id)
            return (
              <View key={s.id} style={styles.stop}>
                <View style={{ gap: 4 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                    <Text style={styles.stopName}>{`${s.sequence}. ${s.customer.name}`}</Text>
                    <Badge colors={STOP_COLORS[s.status]}>{s.status_label}</Badge>
                  </View>
                  {s.customer.address ? <Text style={styles.muted}>{s.customer.address}</Text> : null}
                  {s.customer.phone ? <Text style={styles.muted}>{s.customer.phone}</Text> : null}
                  <Text style={styles.muted}>
                    {count.length > 0
                      ? `${count.filter((i) => i.state === 'loaded').length} termuat · ${count.filter((i) => i.state === 'unloaded').length} bongkar · ${count.length} tabung`
                      : 'Tanpa tabung terencana'}
                  </Text>
                  {s.receiver_name ? <Text style={styles.muted}>Penerima: {s.receiver_name}</Text> : null}
                  {s.note ? <Text style={styles.muted}>Catatan: {s.note}</Text> : null}
                </View>
                {operate && open && (d.status === 'in_transit' || outgoing) && (
                  <View style={styles.stopActions}>
                    {d.status === 'in_transit' && s.status === 'pending' && (
                      <Button title="Tiba" variant="secondary" onPress={() => report('arrive', { stop_id: s.id })} loading={busy} style={styles.smallBtn} />
                    )}
                    {scanInfo && (
                      <Button title={scanInfo.label} onPress={() => scan(scanInfo.phase, s)} style={styles.smallBtn} />
                    )}
                    {d.status === 'in_transit' && s.status === 'arrived' && (
                      <Button title="Selesai" variant="secondary" onPress={() => stopDone(s)} loading={busy} style={styles.smallBtn} />
                    )}
                  </View>
                )}
              </View>
            )
          })}
        </Card>

        {operate && open && (
          <Card title="Tindakan">
            <View style={styles.actions}>
              {canDepart && (
                <Button
                  title={outgoing ? 'Berangkat' : 'Berangkat ke customer'}
                  onPress={depart}
                  loading={busy}
                />
              )}
              {!crewReady && (d.status === 'draft' || d.status === 'loading') && (
                <Text style={styles.hint}>Isi kendaraan dan supir di atas sebelum berangkat.</Text>
              )}
              {outgoing && d.status === 'draft' && loadedCount === 0 && (
                <Text style={styles.hint}>Scan minimal satu tabung sebelum berangkat.</Text>
              )}
              {d.status === 'in_transit' && (
                <>
                  <View style={styles.tripRow}>
                    <View style={{ flex: 1, gap: 2 }}>
                      <Text style={styles.itemName}>Kirim posisi otomatis dari HP ini</Text>
                      <Text style={styles.hint}>
                        {tripId !== id
                          ? 'Nyalakan bila HP ini ikut di kendaraan.'
                          : sentAt
                            ? `Terakhir terkirim ${formatDateTime(sentAt)}. Berjalan selama aplikasi terbuka.`
                            : 'Berjalan selama aplikasi terbuka dan lokasi HP aktif.'}
                      </Text>
                    </View>
                    <Switch
                      value={tripId === id}
                      onValueChange={(on) => (on ? startTrip(id) : stopTrip())}
                      trackColor={{ true: colors.brand }}
                      accessibilityLabel="Kirim posisi otomatis dari HP ini"
                    />
                  </View>
                  <View style={{ flexDirection: 'row', gap: 8 }}>
                    <Button title={REPORT_LABEL.position} variant="secondary" onPress={() => report('position')} loading={busy} style={{ flex: 1 }} />
                    <Button title={REPORT_LABEL.issue} variant="secondary" onPress={issue} loading={busy} style={{ flex: 1 }} />
                  </View>
                  {(d.last_event?.type === 'issue' || (d.last_event?.type === 'stop_done' && !stopsClosed)) && (
                    <Button title={REPORT_LABEL.resume} variant="secondary" onPress={() => report('resume')} loading={busy} />
                  )}
                  {canReturn && <Button title="Tiba di gudang" variant="secondary" onPress={() => report('return')} loading={busy} />}
                  {canComplete && <Button title="Selesaikan transaksi" onPress={finish} loading={busy} />}
                </>
              )}
            </View>
          </Card>
        )}

        {!outgoing && operate && d.status === 'in_transit' && stopsClosed && loadedCount > 0 && <Button title="Scan Bongkar di Gudang" onPress={() => scan('unload')} />}

        {items.length > 0 && (
          <Card title={`Tabung (${items.length})`}>
            {items.map((i) => {
              const mayUnscan =
                operate &&
                open &&
                i.state === 'loaded' &&
                (outgoing ? d.status === 'loading' : d.status === 'in_transit' && !['done', 'skipped'].includes(stops.find((s) => s.id === i.stop_id)?.status ?? 'done'))
              return (
                <View key={i.id} style={styles.item}>
                  <View style={{ flex: 1, gap: 2 }}>
                    <Text style={styles.itemName}>{i.name}</Text>
                    <Text style={styles.muted}>{[i.serial_number, i.state_label, i.loaded_by ? `Muat: ${i.loaded_by}` : null, i.unloaded_by ? `Bongkar: ${i.unloaded_by}` : null].filter(Boolean).join(' · ')}</Text>
                  </View>
                  <Badge colors={ITEM_COLORS[i.state] ?? ITEM_COLORS.cancelled}>{i.state_label}</Badge>
                  {mayUnscan && (
                    <Pressable onPress={() => unscan(i.asset_id)} disabled={busy} hitSlop={8} accessibilityRole="button" accessibilityLabel={`Batalkan muat ${i.name}`}>
                      <Text style={{ color: colors.danger, fontWeight: '700' }}>↩</Text>
                    </Pressable>
                  )}
                </View>
              )
            })}
          </Card>
        )}

        {(d.events ?? []).length > 0 && (
          <Card title="Riwayat">
            {(d.events ?? [])
              .filter((e) => e.type !== 'position')
              .reverse()
              .slice(0, 30)
              .map((e) => (
              <View key={e.id} style={styles.event}>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={styles.itemName}>{e.type_label}</Text>
                  <Text style={styles.muted}>
                    {[e.customer, e.asset, e.actor].filter(Boolean).join(' · ')}
                  </Text>
                  {e.note ? <Text style={styles.muted}>{e.note}</Text> : null}
                </View>
                <Text style={styles.eventTime}>{formatDateTime(e.recorded_at)}</Text>
              </View>
            ))}
          </Card>
        )}
        <View style={{ height: 24 }} />
      </ScrollView>
      {actionError && (
        <Pressable onPress={() => setActionError(null)} style={{ padding: 12 }} accessibilityRole="button" accessibilityLabel="Tutup pesan">
          <Alert title="Tidak tercatat">{actionError}</Alert>
        </Pressable>
      )}

      <NoteSheet req={noteReq} note={note} receiver={receiver} onNote={setNote} onReceiver={setReceiver} onSubmit={submitNote} onClose={() => closeNote(null)} />
    </SafeAreaView>
  )
}

/** Bottom sheet asking for the note/receiver some actions require. */
function NoteSheet({
  req,
  note,
  receiver,
  onNote,
  onReceiver,
  onSubmit,
  onClose,
}: {
  req: NoteRequest | null
  note: string
  receiver: string
  onNote: (v: string) => void
  onReceiver: (v: string) => void
  onSubmit: () => void
  onClose: () => void
}) {
  return (
    <Modal visible={req !== null} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.sheetBackdrop} onPress={onClose} accessibilityLabel="Tutup" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.sheet}>
        <Text style={styles.sheetTitle}>{req?.title}</Text>
        {req?.hint && <Text style={styles.muted}>{req.hint}</Text>}
        <Field label={req?.requireNote ? 'Catatan' : 'Catatan (opsional)'} required={req?.requireNote}>
          <Input value={note} onChangeText={onNote} multiline maxLength={500} />
        </Field>
        {req?.receiver && (
          <Field label={req.receiver}>
            <Input value={receiver} onChangeText={onReceiver} maxLength={150} />
          </Field>
        )}
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <Button title="Batal" variant="secondary" onPress={onClose} style={{ flex: 1 }} />
          <Button title="OK" onPress={onSubmit} style={{ flex: 1 }} disabled={!!req?.requireNote && !note.trim()} />
        </View>
      </KeyboardAvoidingView>
    </Modal>
  )
}

const styles = StyleSheet.create({
  page: { padding: 16, gap: 12 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, padding: 24 },
  muted: { color: colors.muted, fontSize: 14 },
  hint: { color: colors.muted, fontSize: 13 },
  form: { padding: 14, gap: 12 },
  tripRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  stop: { gap: 10, paddingHorizontal: 14, paddingVertical: 12, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  stopName: { fontSize: 15, fontWeight: '700', color: colors.text, flexShrink: 1 },
  stopActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  smallBtn: { flexGrow: 1, paddingHorizontal: 14 },
  actions: { padding: 14, gap: 10 },
  empty: { color: colors.muted, padding: 14 },
  item: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 14, paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  itemName: { fontSize: 14, fontWeight: '600', color: colors.text },
  event: { flexDirection: 'row', gap: 8, paddingHorizontal: 14, paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
  eventTime: { fontSize: 12, color: colors.muted },
  sheetBackdrop: { flex: 1, backgroundColor: 'rgba(15,23,42,0.4)' },
  sheet: { backgroundColor: colors.surface, borderTopLeftRadius: 16, borderTopRightRadius: 16, padding: 16, gap: 10 },
  sheetTitle: { fontSize: 17, fontWeight: '700', color: colors.text },
})
