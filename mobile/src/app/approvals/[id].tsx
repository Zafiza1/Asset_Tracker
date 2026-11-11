import { Stack, useLocalSearchParams } from 'expo-router'
import { useCallback, useEffect, useState } from 'react'
import { ActivityIndicator, Alert as Dialog, KeyboardAvoidingView, Platform, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Field, Input } from '@/components/form'
import { Alert, Badge, Button, Card, colors, Row } from '@/components/ui'
import { ApiError, errorMessage } from '@/lib/api'
import { fieldErrors } from '@/lib/forms'
import { formatDateTime } from '@/lib/format'
import { useSession } from '@/lib/session'
import { approvalStatusColors, approvalStatusLabel, type ApprovalRequest } from '@/lib/types'

export { ErrorBoundary } from '@/components/ErrorBoundary'

type Decision = 'approve' | 'reject' | 'cancel'

/**
 * One approval request: what was submitted, what approving does, and its history. Super Admin
 * and Supervisor approve or reject a pending request (a rejection needs its reason); the
 * requester may withdraw their own pending request.
 */
export default function ApprovalDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { get, post, can, profile } = useSession()
  const [approval, setApproval] = useState<ApprovalRequest | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const [note, setNote] = useState('')
  const [noteError, setNoteError] = useState<string | null>(null)
  const [busy, setBusy] = useState<Decision | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setApproval((await get<{ data: ApprovalRequest }>(`/approvals/${id}`)).data)
    } catch (e) {
      setError(e)
    } finally {
      setLoading(false)
    }
  }, [get, id])

  useEffect(() => {
    load()
  }, [load])

  if (loading && !approval) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={colors.brand} />
      </View>
    )
  }
  if (!approval) {
    const notFound = error instanceof ApiError && error.status === 404
    return (
      <View style={[styles.center, { gap: 12 }]}>
        <Text style={styles.title}>{notFound ? 'Pengajuan tidak ditemukan' : 'Gagal memuat pengajuan'}</Text>
        <Text style={styles.muted}>{notFound ? 'Pengajuan ini tidak ada atau bukan milik Anda.' : errorMessage(error)}</Text>
        {!notFound && <Button title="Coba lagi" variant="secondary" onPress={load} />}
      </View>
    )
  }

  const pending = approval.status === 'pending'
  const canDecide = can('approval.manage') && pending
  const canCancel = pending && approval.requester?.id === profile?.user.id

  const decide = async (decision: Decision) => {
    setNoteError(null)
    setActionError(null)
    if (decision === 'reject' && note.trim().length < 5) {
      setNoteError('Alasan penolakan wajib diisi, minimal 5 karakter.')
      return
    }
    setBusy(decision)
    try {
      const body = decision === 'cancel' ? {} : { note: note.trim() || null }
      setApproval((await post<{ data: ApprovalRequest }>(`/approvals/${approval.id}/${decision}`, body)).data)
      setNote('')
    } catch (e) {
      setNoteError(fieldErrors(e).note ?? null)
      setActionError(errorMessage(e))
      // Decided meanwhile by someone else (409) or changed: show the current state.
      if (e instanceof ApiError && e.status !== 422) load()
    } finally {
      setBusy(null)
    }
  }

  const confirm = (decision: Decision) => {
    if (decision === 'reject') return decide('reject')
    const [title, message, ok] =
      decision === 'approve'
        ? ['Setujui pengajuan?', approval.impact?.text ?? 'Perubahan akan diterapkan.', 'Setujui']
        : ['Batalkan pengajuan?', 'Pengajuan ditarik dan tidak akan diproses.', 'Batalkan pengajuan']
    Dialog.alert(title, message, [
      { text: 'Kembali', style: 'cancel' },
      { text: ok, style: decision === 'cancel' || approval.impact?.destructive ? 'destructive' : 'default', onPress: () => decide(decision) },
    ])
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <Stack.Screen options={{ title: approval.action_label }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled" refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}>
          <View style={{ gap: 6 }}>
            <Badge colors={approvalStatusColors[approval.status]}>{approvalStatusLabel[approval.status]}</Badge>
            <Text style={styles.name}>{approval.summary}</Text>
            <Text style={styles.muted}>
              {`${approval.category_label} · diajukan ${approval.requester?.name ? `oleh ${approval.requester.name} ` : ''}${formatDateTime(approval.created_at)}`}
            </Text>
          </View>

          {approval.impact && pending && (
            <Alert tone={approval.impact.destructive ? 'error' : 'warning'} title="Bila disetujui">
              {approval.impact.text}
            </Alert>
          )}

          {!pending && (
            <Card title="Keputusan">
              <Row label="Status">{approvalStatusLabel[approval.status]}</Row>
              {approval.decider && <Row label="Oleh">{approval.decider.name}</Row>}
              <Row label="Waktu">{formatDateTime(approval.decided_at)}</Row>
              {approval.decision_note && <Row label="Catatan">{approval.decision_note}</Row>}
            </Card>
          )}

          <Card title="Data yang diajukan">
            {(approval.details ?? []).length === 0 ? (
              <Text style={styles.empty}>Tidak ada rincian.</Text>
            ) : (
              approval.details!.map((d, i) => (
                <Row key={`${d.label}-${i}`} label={d.label}>
                  {d.current !== undefined ? (
                    <View style={{ gap: 2 }}>
                      <Text style={styles.current}>{`Saat ini: ${d.current || '—'}`}</Text>
                      <Text style={styles.value}>{d.value || '—'}</Text>
                    </View>
                  ) : (
                    d.value || '—'
                  )}
                </Row>
              ))
            )}
          </Card>

          {(approval.history ?? []).length > 0 && (
            <Card title="Riwayat">
              {approval.history!.map((h, i) => (
                <View key={`${h.action}-${i}`} style={styles.history}>
                  <Text style={styles.value}>{`${h.label}${h.actor ? ` oleh ${h.actor}` : ''}`}</Text>
                  <Text style={styles.current}>{formatDateTime(h.at)}</Text>
                  {h.note && <Text style={styles.value}>{`“${h.note}”`}</Text>}
                </View>
              ))}
            </Card>
          )}

          {actionError && <Alert>{actionError}</Alert>}

          {canDecide && (
            <View style={styles.actions}>
              <Field label="Catatan" hint="Opsional saat menyetujui, wajib saat menolak." error={noteError ?? undefined}>
                <Input value={note} onChangeText={setNote} multiline maxLength={1000} invalid={!!noteError} placeholder="mis. alasan penolakan" />
              </Field>
              <Button title="Setujui" onPress={() => confirm('approve')} loading={busy === 'approve'} disabled={busy !== null} />
              <Button title="Tolak" variant="secondary" onPress={() => confirm('reject')} loading={busy === 'reject'} disabled={busy !== null} />
            </View>
          )}
          {canCancel && !canDecide && <Button title="Batalkan pengajuan" variant="secondary" onPress={() => confirm('cancel')} loading={busy === 'cancel'} />}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16, paddingBottom: 40 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: colors.background },
  title: { fontSize: 18, fontWeight: '700', color: colors.text },
  name: { fontSize: 20, fontWeight: '800', color: colors.text },
  muted: { color: colors.muted, fontSize: 13 },
  empty: { padding: 16, color: colors.muted },
  current: { color: colors.muted, fontSize: 12 },
  value: { color: colors.text, fontSize: 14 },
  history: { paddingHorizontal: 16, paddingVertical: 10, gap: 2, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  actions: { gap: 12 },
})
