import { router } from 'expo-router'
import { useState } from 'react'
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Field, Input, Select } from './form'
import { Alert, Button, colors } from './ui'
import { announce, fieldErrors, formError, useMasterOptions } from '@/lib/forms'
import { useSession } from '@/lib/session'
import type { MasterOption } from '@/lib/types'

const KINDS = {
  location: {
    endpoint: '/locations',
    noun: 'lokasi',
    parentLabel: 'Lokasi induk',
    parentHint: 'Kosongkan untuk lokasi utama, misalnya gedung. Pilih induk untuk ruangan atau lantai di dalamnya.',
    extra: { key: 'address', label: 'Alamat' },
  },
  category: {
    endpoint: '/tenant-masters/categories',
    noun: 'kategori',
    parentLabel: 'Kategori induk',
    parentHint: 'Kosongkan untuk kategori utama. Subkategori mewarisi atribut kategori induknya.',
    extra: { key: 'description', label: 'Deskripsi' },
  },
} as const

/**
 * New location or category (POST, same rules as the web's Master Data). Users without
 * approval.manage submit it for approval; it can be picked in forms once approved.
 */
export function MasterForm({ kind }: { kind: keyof typeof KINDS }) {
  const config = KINDS[kind]
  const { post, can } = useSession()
  const parents = useMasterOptions(config.endpoint)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [parentId, setParentId] = useState<string | null>(null)
  const [extra, setExtra] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    const local: Record<string, string> = {}
    if (!name.trim()) local.name = `Nama ${config.noun} wajib diisi.`
    setErrors(local)
    setError(null)
    if (Object.keys(local).length) return

    setSaving(true)
    try {
      const response = await post<{ data: MasterOption }>(config.endpoint, {
        name: name.trim(),
        code: code.trim() || null,
        parent_id: parentId,
        [config.extra.key]: extra.trim() || null,
      })
      announce(response, {
        title: `${config.noun[0].toUpperCase()}${config.noun.slice(1)} ditambahkan`,
        message: `${name.trim()} sudah dapat dipilih di form aset.`,
        done: () => router.back(),
      })
    } catch (e) {
      setErrors(fieldErrors(e))
      setError(formError(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }} edges={['bottom']}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
          {error && <Alert>{error}</Alert>}
          <Field label={`Nama ${config.noun}`} required error={errors.name}>
            <Input value={name} onChangeText={setName} invalid={!!errors.name} maxLength={150} />
          </Field>
          <Field label="Kode" error={errors.code} hint="Opsional. Bila kosong, kode dibuat otomatis dari nama.">
            <Input value={code} onChangeText={(t) => setCode(t.toUpperCase())} autoCapitalize="characters" autoCorrect={false} invalid={!!errors.code} />
          </Field>
          <Field label={config.parentLabel} error={errors.parent_id ?? parents.error ?? undefined} hint={config.parentHint}>
            <Select
              title={config.parentLabel}
              value={parentId}
              options={parents.options}
              onChange={setParentId}
              placeholder="— Tidak ada —"
              allowEmpty="— Tidak ada —"
              loading={parents.loading}
              invalid={!!errors.parent_id}
            />
          </Field>
          <Field label={config.extra.label} error={errors[config.extra.key]}>
            <Input value={extra} onChangeText={setExtra} multiline maxLength={1000} invalid={!!errors[config.extra.key]} />
          </Field>
          {!can('approval.manage') && <Text style={styles.note}>Data baru diajukan dulu dan aktif setelah disetujui Super Admin atau Supervisor.</Text>}
          <Button title="Simpan" onPress={submit} loading={saving} />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16, paddingBottom: 40 },
  note: { color: colors.muted, fontSize: 13 },
})
