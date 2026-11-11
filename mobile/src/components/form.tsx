import { useMemo, useState, type ReactNode } from 'react'
import { FlatList, Modal, Pressable, StyleSheet, Switch, Text, TextInput, View, type TextInputProps } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Button, colors } from './ui'
import type { AttributeValue, CategoryAttribute } from '@/lib/types'
import { SECTION_LABELS } from '@/lib/types'

/** Label, hint and error around one input, as the Field of the web forms. */
export function Field({ label, required, hint, error, children }: { label: string; required?: boolean; hint?: string; error?: string; children: ReactNode }) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>
        {label}
        {required && <Text style={{ color: colors.danger }}> *</Text>}
      </Text>
      {children}
      {hint && !error && <Text style={styles.hint}>{hint}</Text>}
      {error && <Text style={styles.error}>{error}</Text>}
    </View>
  )
}

export function Input({ invalid, multiline, style, ...props }: TextInputProps & { invalid?: boolean }) {
  return (
    <TextInput
      placeholderTextColor={colors.muted}
      multiline={multiline}
      textAlignVertical={multiline ? 'top' : 'center'}
      style={[styles.input, multiline && { minHeight: 96, paddingTop: 12 }, invalid && { borderColor: colors.danger }, style]}
      {...props}
    />
  )
}

export interface Option {
  value: string
  label: string
  /** Indent level in a tree (subcategory, sublocation). */
  depth?: number
  hint?: string
}

/**
 * A dropdown for a phone: the field opens a full-screen list with search, so long master lists
 * (locations, categories) stay usable. `allowEmpty` adds a "— Tidak ada —" choice.
 */
export function Select({
  value,
  options,
  onChange,
  placeholder = '— Pilih —',
  title,
  invalid,
  allowEmpty,
  loading,
}: {
  value: string | null
  options: Option[]
  onChange: (value: string | null) => void
  placeholder?: string
  title: string
  invalid?: boolean
  allowEmpty?: string
  loading?: boolean
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const selected = options.find((o) => o.value === value)
  const shown = useMemo(() => {
    const term = search.trim().toLowerCase()
    // While searching the tree indent means nothing; show the matches flat.
    return term ? options.filter((o) => o.label.toLowerCase().includes(term)).map((o) => ({ ...o, depth: 0 })) : options
  }, [options, search])

  const choose = (v: string | null) => {
    onChange(v)
    setOpen(false)
    setSearch('')
  }

  return (
    <>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={title}
        onPress={() => setOpen(true)}
        style={({ pressed }) => [styles.input, styles.select, invalid && { borderColor: colors.danger }, pressed && { backgroundColor: colors.background }]}
      >
        <Text style={[styles.selectText, !selected && { color: colors.muted }]} numberOfLines={1}>
          {loading ? 'Memuat…' : (selected?.label ?? placeholder)}
        </Text>
        <Text style={styles.chevron}>▾</Text>
      </Pressable>
      <Modal visible={open} animationType="slide" onRequestClose={() => setOpen(false)}>
        <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }}>
          <View style={styles.sheetHeader}>
            <Text style={styles.sheetTitle}>{title}</Text>
            <Pressable onPress={() => setOpen(false)} accessibilityRole="button" hitSlop={12}>
              <Text style={{ color: colors.brand, fontSize: 16, fontWeight: '600' }}>Tutup</Text>
            </Pressable>
          </View>
          {options.length > 8 && (
            <View style={{ paddingHorizontal: 16, paddingBottom: 8 }}>
              <Input value={search} onChangeText={setSearch} placeholder="Cari…" autoCorrect={false} returnKeyType="search" />
            </View>
          )}
          <FlatList
            data={allowEmpty && !search ? [{ value: '', label: allowEmpty }, ...shown] : shown}
            keyExtractor={(o) => o.value || '__empty'}
            keyboardShouldPersistTaps="handled"
            ListEmptyComponent={<Text style={styles.empty}>{loading ? 'Memuat…' : 'Tidak ada pilihan.'}</Text>}
            renderItem={({ item }) => {
              const active = (item.value || null) === (value || null)
              return (
                <Pressable
                  onPress={() => choose(item.value || null)}
                  style={({ pressed }) => [styles.option, { paddingLeft: 16 + (item.depth ?? 0) * 18 }, active && { backgroundColor: colors.brandSoft }, pressed && { opacity: 0.7 }]}
                  accessibilityRole="button"
                  accessibilityState={{ selected: active }}
                >
                  <View style={{ flex: 1 }}>
                    <Text style={[styles.optionText, active && { color: colors.brand, fontWeight: '700' }]}>{item.label}</Text>
                    {item.hint && <Text style={styles.hint}>{item.hint}</Text>}
                  </View>
                  {active && <Text style={{ color: colors.brand, fontSize: 16 }}>✓</Text>}
                </Pressable>
              )
            }}
          />
        </SafeAreaView>
      </Modal>
    </>
  )
}

/** Small set of choices shown side by side (e.g. asset status). */
export function Chips({ value, options, onChange, multiple }: { value: string[]; options: Option[]; onChange: (value: string[]) => void; multiple?: boolean }) {
  return (
    <View style={styles.chips}>
      {options.map((o) => {
        const active = value.includes(o.value)
        return (
          <Pressable
            key={o.value}
            onPress={() => onChange(multiple ? (active ? value.filter((v) => v !== o.value) : [...value, o.value]) : [o.value])}
            style={[styles.chip, active && styles.chipActive]}
            accessibilityRole={multiple ? 'checkbox' : 'radio'}
            accessibilityState={{ checked: active }}
          >
            <Text style={[styles.chipText, active && { color: '#fff' }]}>{o.label}</Text>
          </Pressable>
        )
      })}
    </View>
  )
}

/** One category field, by its data type (as AttributeValueInput on the web). */
export function AttributeInput({ field, value, onChange, invalid }: { field: CategoryAttribute; value: AttributeValue; onChange: (v: AttributeValue) => void; invalid?: boolean }) {
  const options = (field.options ?? []).map((o) => ({ value: o.value, label: o.label }))
  switch (field.data_type) {
    case 'boolean':
      return (
        <View style={styles.switchRow}>
          <Text style={{ color: colors.text }}>{value === true ? 'Ya' : 'Tidak'}</Text>
          <Switch value={value === true} onValueChange={onChange} trackColor={{ true: colors.brand }} />
        </View>
      )
    case 'enum':
      return options.length <= 5 ? (
        <Chips value={typeof value === 'string' ? [value] : []} options={options} onChange={(v) => onChange(v[0] === value ? null : (v[0] ?? null))} />
      ) : (
        <Select title={field.label} value={typeof value === 'string' ? value : null} options={options} onChange={onChange} allowEmpty="— Kosong —" invalid={invalid} />
      )
    case 'multi_enum':
      return <Chips multiple value={Array.isArray(value) ? value : []} options={options} onChange={(v) => onChange(v.length ? v : null)} />
    case 'integer':
    case 'decimal':
      return (
        <Input
          invalid={invalid}
          keyboardType={field.data_type === 'integer' ? 'number-pad' : 'decimal-pad'}
          value={value === null || value === undefined ? '' : String(value)}
          onChangeText={(t) => onChange(t.trim() === '' ? null : t.replace(',', '.'))}
        />
      )
    case 'date':
      return <DateInput value={typeof value === 'string' ? value : ''} onChange={(v) => onChange(v || null)} invalid={invalid} />
    default:
      return (
        <Input
          invalid={invalid}
          multiline={field.data_type === 'text'}
          value={typeof value === 'string' ? value : value == null ? '' : String(value)}
          onChangeText={(t) => onChange(t === '' ? null : t)}
        />
      )
  }
}

/** Date as YYYY-MM-DD (the API's format), with a "Hari ini" shortcut; no native picker needed. */
export function DateInput({ value, onChange, invalid }: { value: string; onChange: (v: string) => void; invalid?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', gap: 8 }}>
      <Input
        style={{ flex: 1 }}
        invalid={invalid}
        value={value}
        onChangeText={(t) => onChange(t.replace(/[^0-9-]/g, '').slice(0, 10))}
        placeholder="TTTT-BB-HH"
        keyboardType="numbers-and-punctuation"
        maxLength={10}
      />
      <Button title="Hari ini" variant="secondary" onPress={() => onChange(new Date().toISOString().slice(0, 10))} />
    </View>
  )
}

/** The category's fields grouped by section, each with its error. */
export function AttributeFields({
  fields,
  values,
  errors,
  onChange,
}: {
  fields: CategoryAttribute[]
  values: Record<string, AttributeValue>
  errors: Record<string, string>
  onChange: (key: string, value: AttributeValue) => void
}) {
  const sections: Record<string, CategoryAttribute[]> = {}
  for (const f of fields) (sections[f.section || 'other'] ??= []).push(f)

  return Object.entries(sections).map(([section, list]) => (
    <View key={section} style={styles.group}>
      <Text style={styles.groupTitle}>{SECTION_LABELS[section] || section}</Text>
      {list.map((f) => (
        <Field key={f.key} label={f.label} required={f.is_required} error={errors[f.key]}>
          <AttributeInput field={f} value={values[f.key] ?? null} onChange={(v) => onChange(f.key, v)} invalid={!!errors[f.key]} />
        </Field>
      ))}
    </View>
  ))
}

const styles = StyleSheet.create({
  field: { gap: 6 },
  label: { fontSize: 14, fontWeight: '600', color: colors.text },
  hint: { fontSize: 12, color: colors.muted },
  error: { fontSize: 13, color: colors.danger },
  input: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text, backgroundColor: colors.surface },
  select: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  selectText: { flex: 1, fontSize: 16, color: colors.text },
  chevron: { color: colors.muted, fontSize: 14 },
  sheetHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: 16 },
  sheetTitle: { fontSize: 18, fontWeight: '700', color: colors.text, flex: 1 },
  option: { flexDirection: 'row', alignItems: 'center', minHeight: 52, paddingRight: 16, paddingVertical: 10, backgroundColor: colors.surface, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  optionText: { fontSize: 16, color: colors.text },
  empty: { padding: 16, color: colors.muted, textAlign: 'center' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { minHeight: 40, justifyContent: 'center', paddingHorizontal: 14, borderRadius: 999, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  chipActive: { backgroundColor: colors.brand, borderColor: colors.brand },
  chipText: { fontSize: 14, fontWeight: '600', color: colors.text },
  switchRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 48 },
  group: { gap: 12, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  groupTitle: { fontSize: 15, fontWeight: '700', color: colors.text },
})
