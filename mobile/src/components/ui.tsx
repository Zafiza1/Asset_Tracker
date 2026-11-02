import type { ReactNode } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, View, type ViewStyle } from 'react-native'

/** Colors of the web app (frontend/src/index.css brand + Tailwind slate). */
export const colors = {
  brand: '#1d4ed8',
  brandDark: '#1e40af',
  brandSoft: '#eff6ff',
  text: '#1e293b',
  muted: '#64748b',
  border: '#e2e8f0',
  surface: '#ffffff',
  background: '#f8fafc',
  danger: '#b91c1c',
  dangerSoft: '#fef2f2',
  warning: '#92400e',
  warningSoft: '#fffbeb',
}

export function Button({
  title,
  onPress,
  loading,
  disabled,
  variant = 'primary',
  style,
}: {
  title: string
  onPress: () => void
  loading?: boolean
  disabled?: boolean
  variant?: 'primary' | 'secondary'
  style?: ViewStyle
}) {
  const primary = variant === 'primary'
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        primary ? styles.primary : styles.secondary,
        pressed && { opacity: 0.8 },
        (disabled || loading) && { opacity: 0.5 },
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={primary ? '#fff' : colors.brand} /> : <Text style={[styles.buttonText, { color: primary ? '#fff' : colors.text }]}>{title}</Text>}
    </Pressable>
  )
}

export function Alert({ tone = 'error', title, children }: { tone?: 'error' | 'warning'; title?: string; children: ReactNode }) {
  const [bg, fg] = tone === 'error' ? [colors.dangerSoft, colors.danger] : [colors.warningSoft, colors.warning]
  return (
    <View style={[styles.alert, { backgroundColor: bg, borderColor: fg }]} accessibilityRole="alert">
      {title && <Text style={[styles.alertTitle, { color: fg }]}>{title}</Text>}
      <Text style={{ color: fg }}>{children}</Text>
    </View>
  )
}

export function Badge({ colors: [bg, fg], children }: { colors: [string, string]; children: ReactNode }) {
  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <Text style={[styles.badgeText, { color: fg }]}>{children}</Text>
    </View>
  )
}

export function Card({ title, children }: { title?: string; children: ReactNode }) {
  return (
    <View style={styles.card}>
      {title && <Text style={styles.cardTitle}>{title}</Text>}
      {children}
    </View>
  )
}

/** Label/value row, as the Row of the web asset detail. */
export function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <View style={styles.rowValue}>{typeof children === 'string' || typeof children === 'number' ? <Text style={styles.rowText}>{children}</Text> : children}</View>
    </View>
  )
}

export function SectionHeader({ title }: { title: string }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionText}>{title}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  button: { minHeight: 48, borderRadius: 10, paddingHorizontal: 16, alignItems: 'center', justifyContent: 'center' },
  primary: { backgroundColor: colors.brand },
  secondary: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  buttonText: { fontSize: 16, fontWeight: '600' },
  alert: { borderWidth: 1, borderRadius: 10, padding: 12, gap: 4 },
  alertTitle: { fontWeight: '700' },
  badge: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText: { fontSize: 13, fontWeight: '600' },
  card: { backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  cardTitle: { fontSize: 17, fontWeight: '700', color: colors.text, padding: 16, borderBottomWidth: 1, borderBottomColor: colors.border },
  row: { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  rowLabel: { flex: 1, color: colors.muted, fontSize: 14 },
  rowValue: { flex: 2 },
  rowText: { color: colors.text, fontSize: 14 },
  section: { backgroundColor: colors.background, paddingHorizontal: 16, paddingVertical: 8, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  sectionText: { fontSize: 15, fontWeight: '700', color: colors.text },
})
