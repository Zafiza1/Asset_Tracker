import { router, Stack, useLocalSearchParams } from 'expo-router'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { ActivityIndicator, Image, Platform, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Alert, Badge, Button, Card, colors, Row, SectionHeader } from '@/components/ui'
import { ApiError, errorMessage } from '@/lib/api'
import { attributeRowsBySection, formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { useSession } from '@/lib/session'
import { assetStatusColors, assetStatusLabel, SECTION_LABELS, type AssetDetail, type AssetTransaction, type Paginated } from '@/lib/types'

// A screen that throws keeps the header and back button; only the content shows the error.
export { ErrorBoundary } from '@/components/ErrorBoundary'

/** The asset a scanned code encodes, laid out as the web app's asset detail (Pendataan Aset). */
export default function AssetDetailScreen() {
  const { code } = useLocalSearchParams<{ code: string }>()
  const { get, can, server, token } = useSession()
  const [detail, setDetail] = useState<AssetDetail | null>(null)
  const [history, setHistory] = useState<AssetTransaction[] | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [loading, setLoading] = useState(true)
  const canViewHistory = can('transaction.view')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const found = await get<AssetDetail>(`/assets/lookup?code=${encodeURIComponent(code)}`)
      setDetail(found)
      if (canViewHistory) {
        setHistory((await get<Paginated<AssetTransaction>>(`/assets/${found.data.id}/transactions?per_page=100`)).data)
      }
    } catch (e) {
      setError(e)
    } finally {
      setLoading(false)
    }
  }, [code, get, canViewHistory])

  useEffect(() => {
    load()
  }, [load])

  if (loading && !detail) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={colors.brand} />
      </View>
    )
  }

  if (error && !detail) {
    const notFound = error instanceof ApiError && error.status === 404
    return (
      <SafeAreaView style={styles.center} edges={['bottom']}>
        <Text style={styles.title}>{notFound ? 'Aset tidak ditemukan' : 'Gagal memuat aset'}</Text>
        <Text style={styles.code}>{code}</Text>
        <Text style={styles.muted}>{notFound ? 'Kode yang dipindai tidak cocok dengan nomor aset mana pun di organisasi atau cakupan data Anda.' : errorMessage(error)}</Text>
        <View style={{ gap: 8, alignSelf: 'stretch' }}>
          <Button title="Pindai lagi" onPress={() => router.replace('/scan')} />
          {!notFound && <Button title="Coba lagi" variant="secondary" onPress={load} />}
        </View>
      </SafeAreaView>
    )
  }

  const a = detail!.data
  const today = new Date().toISOString().slice(0, 10)
  const overdue = a.status === 'on_loan' && a.loan_due_date !== null && a.loan_due_date < today
  const groupedAttrs = attributeRowsBySection(detail!.attribute_fields ?? [], a.attributes ?? {})
  // Built-in fields lead their section; the category's attributes follow.
  const builtIn: Record<string, ReactNode> = {
    basic_information: (
      <>
        <Row label="Kategori">{a.category?.name ?? '—'}</Row>
        <Row label="Tanggal perolehan">{formatDate(a.acquisition_date)}</Row>
        <Row label="Nilai perolehan">{formatMoney(a.acquisition_cost)}</Row>
      </>
    ),
    location_ownership: <Row label="Lokasi">{a.location?.name ?? 'Tanpa lokasi'}</Row>,
    status_condition: (
      <>
        <Row label="Status">
          <Badge colors={assetStatusColors[a.status]}>{assetStatusLabel[a.status]}</Badge>
        </Row>
        {a.status === 'on_loan' && <Row label="Peminjam">{`${a.borrower_name ?? '—'}${a.loan_due_date ? ` (kembali ${formatDate(a.loan_due_date)})` : ''}`}</Row>}
      </>
    ),
  }
  const sections = [...Object.keys(builtIn), ...Object.keys(groupedAttrs).filter((s) => !(s in builtIn))]

  return (
    <ScrollView contentContainerStyle={styles.container} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
      <Stack.Screen options={{ title: a.name }} />

      <View style={{ gap: 2 }}>
        <Text style={styles.name}>{a.name}</Text>
        <Text style={styles.code}>{`${a.asset_number}${a.serial_number ? ` · No Seri ${a.serial_number}` : ''}`}</Text>
      </View>

      {a.is_archived && (
        <Alert tone="warning" title="Aset nonaktif">
          {`Aset ini tidak termasuk inventaris aktif sejak ${formatDateTime(a.archived_at)}. Data dan riwayat tetap tersimpan.`}
        </Alert>
      )}
      {overdue && (
        <Alert tone="warning" title="Peminjaman melewati batas">
          {`Dipinjam oleh ${a.borrower_name}, seharusnya kembali ${formatDate(a.loan_due_date)}.`}
        </Alert>
      )}

      <Card title="Data aset">
        {a.image_url && (
          <View style={styles.imageBox}>
            <Image
              source={{ uri: `${server}${a.image_url}`, headers: { Authorization: `Bearer ${token}` } }}
              style={styles.image}
              resizeMode="contain"
              accessibilityLabel={`Gambar ${a.name}`}
            />
          </View>
        )}
        {sections.map((section) => (
          <View key={section}>
            <SectionHeader title={SECTION_LABELS[section] || section} />
            {builtIn[section]}
            {(groupedAttrs[section] ?? []).map((r) => (
              <Row key={r.key} label={r.label}>
                {r.value}
              </Row>
            ))}
          </View>
        ))}
        <Row label="Deskripsi">{a.description || '—'}</Row>
        <Row label="Ditambahkan">{`${formatDateTime(a.created_at)}${a.created_by ? ` oleh ${a.created_by}` : ''}`}</Row>
      </Card>

      <Card title="Riwayat transaksi">
        {!canViewHistory ? (
          <Text style={styles.empty}>Anda tidak memiliki izin melihat riwayat transaksi.</Text>
        ) : !history || history.length === 0 ? (
          <Text style={styles.empty}>Belum ada riwayat</Text>
        ) : (
          // Too wide for a phone: the table scrolls sideways inside the card.
          <ScrollView horizontal showsHorizontalScrollIndicator>
            <View>
              <View style={[styles.tr, styles.thead]}>
                {TRX_COLUMNS.map((c) => (
                  <Text key={c.label} style={[styles.th, { width: c.width }]}>
                    {c.label}
                  </Text>
                ))}
              </View>
              {history.map((t, i) => (
                <View key={t.id} style={[styles.tr, i % 2 === 1 && styles.trAlt]}>
                  <Text style={[styles.td, styles.tdMuted, { width: TRX_COLUMNS[0].width }]}>{i + 1}</Text>
                  <Text style={[styles.td, { width: TRX_COLUMNS[1].width }]}>{formatDate(t.transaction_date)}</Text>
                  <Text style={[styles.td, styles.tdStrong, { width: TRX_COLUMNS[2].width }]}>{t.type_label}</Text>
                  <Text style={[styles.td, styles.tdMono, { width: TRX_COLUMNS[3].width }]}>{t.transaction_number}</Text>
                  <Text style={[styles.td, { width: TRX_COLUMNS[4].width }]}>{t.notes || '—'}</Text>
                  <Text style={[styles.td, styles.tdMuted, { width: TRX_COLUMNS[5].width }]}>{t.created_by || '—'}</Text>
                </View>
              ))}
            </View>
          </ScrollView>
        )}
      </Card>

      <Button title="Pindai aset lain" onPress={() => router.replace('/scan')} />
    </ScrollView>
  )
}

const mono = Platform.select({ ios: 'Menlo', default: 'monospace' })

const TRX_COLUMNS = [
  { label: 'No', width: 40 },
  { label: 'Tanggal', width: 104 },
  { label: 'Jenis', width: 128 },
  { label: 'No Transaksi', width: 168 },
  { label: 'Catatan', width: 200 },
  { label: 'Oleh', width: 128 },
]

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16, paddingBottom: 40 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 12, backgroundColor: colors.background },
  title: { fontSize: 18, fontWeight: '700', color: colors.text },
  muted: { color: colors.muted, textAlign: 'center' },
  name: { fontSize: 22, fontWeight: '800', color: colors.text },
  code: { fontFamily: mono, color: colors.muted, fontSize: 13 },
  imageBox: { backgroundColor: colors.background, borderBottomWidth: 1, borderBottomColor: colors.border },
  image: { width: '100%', height: 240 },
  empty: { padding: 16, color: colors.muted },
  tr: { flexDirection: 'row', borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  thead: { backgroundColor: colors.background, borderBottomWidth: 1 },
  trAlt: { backgroundColor: colors.background },
  th: { paddingHorizontal: 12, paddingVertical: 10, fontSize: 12, fontWeight: '700', color: colors.muted, textTransform: 'uppercase' },
  td: { paddingHorizontal: 12, paddingVertical: 10, fontSize: 13, color: colors.text },
  tdStrong: { fontWeight: '600' },
  tdMono: { fontFamily: mono, fontSize: 12, color: colors.muted },
  tdMuted: { color: colors.muted },
})
