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
  ok: '#15803d',
  okSoft: '#f0fdf4',
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
  variant?: 'primary' | 'secondary' | 'danger'
  style?: ViewStyle
}) {
  const primary = variant === 'primary'
  const danger = variant === 'danger'
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        primary ? styles.primary : danger ? styles.danger : styles.secondary,
        pressed && { opacity: 0.8 },
        (disabled || loading) && { opacity: 0.5 },
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={primary ? '#fff' : colors.brand} /> : <Text style={[styles.buttonText, { color: primary ? '#fff' : danger ? colors.danger : colors.text }]}>{title}</Text>}
    </Pressable>
  )
}

const ALERT_TONES = {
  error: [colors.dangerSoft, colors.danger],
  warning: [colors.warningSoft, colors.warning],
  info: [colors.brandSoft, colors.brandDark],
  success: [colors.okSoft, colors.ok],
} as const

export function Alert({ tone = 'error', title, children }: { tone?: keyof typeof ALERT_TONES; title?: string; children: ReactNode }) {
  const [bg, fg] = ALERT_TONES[tone]
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

export function Card({ title, right, children }: { title?: string; right?: ReactNode; children: ReactNode }) {
  return (
    <View style={styles.card}>
      {title && (
        <View style={styles.cardHeader}>
          <Text style={styles.cardTitle}>{title}</Text>
          {right}
        </View>
      )}
      {children}
    </View>
  )
}

/** A thin bar for "x of y" progress (cylinders loaded/unloaded); value from 0 to 1. */
export function ProgressBar({ value, tone = 'brand' }: { value: number; tone?: 'brand' | 'ok' }) {
  const pct = Math.max(0, Math.min(1, value)) * 100
  return (
    <View style={styles.progress} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: Math.round(pct) }}>
      <View style={[styles.progressFill, { width: `${pct}%`, backgroundColor: tone === 'ok' ? colors.ok : colors.brand }]} />
    </View>
  )
}

/** The stages of a delivery as dots on a line; the current one is highlighted. */
export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <View style={styles.stepper} accessibilityLabel={`Tahap ${current + 1} dari ${steps.length}: ${steps[current] ?? ''}`}>
      {steps.map((label, i) => {
        const reached = i <= current
        return (
          <View key={label} style={styles.step}>
            <View style={styles.stepTrack}>
              <View style={[styles.stepLine, i === 0 && { opacity: 0 }, reached && { backgroundColor: colors.brand }]} />
              <View style={[styles.stepDot, reached && { backgroundColor: colors.brand, borderColor: colors.brand }, i === current && styles.stepDotCurrent]} />
              <View style={[styles.stepLine, i === steps.length - 1 && { opacity: 0 }, i < current && { backgroundColor: colors.brand }]} />
            </View>
            <Text style={[styles.stepLabel, i === current && { color: colors.text, fontWeight: '700' }]} numberOfLines={2}>
              {label}
            </Text>
          </View>
        )
      })}
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
  danger: { backgroundColor: colors.dangerSoft, borderWidth: 1, borderColor: '#fecaca' },
  buttonText: { fontSize: 16, fontWeight: '600' },
  alert: { borderWidth: 1, borderRadius: 10, padding: 12, gap: 4 },
  alertTitle: { fontWeight: '700' },
  badge: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText: { fontSize: 13, fontWeight: '600' },
  card: { backgroundColor: colors.surface, borderRadius: 12, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  cardHeader: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.border },
  cardTitle: { flex: 1, fontSize: 17, fontWeight: '700', color: colors.text },
  progress: { height: 8, borderRadius: 4, backgroundColor: colors.border, overflow: 'hidden' },
  progressFill: { height: 8, borderRadius: 4 },
  stepper: { flexDirection: 'row' },
  step: { flex: 1, alignItems: 'center', gap: 6 },
  stepTrack: { flexDirection: 'row', alignItems: 'center', alignSelf: 'stretch' },
  stepLine: { flex: 1, height: 2, backgroundColor: colors.border },
  stepDot: { width: 12, height: 12, borderRadius: 6, borderWidth: 2, borderColor: colors.border, backgroundColor: colors.surface },
  stepDotCurrent: { width: 16, height: 16, borderRadius: 8, borderWidth: 4, borderColor: '#bfdbfe' },
  stepLabel: { fontSize: 11, color: colors.muted, textAlign: 'center', paddingHorizontal: 2 },
  row: { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  rowLabel: { flex: 1, color: colors.muted, fontSize: 14 },
  rowValue: { flex: 2 },
  rowText: { color: colors.text, fontSize: 14 },
  section: { backgroundColor: colors.background, paddingHorizontal: 16, paddingVertical: 8, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  sectionText: { fontSize: 15, fontWeight: '700', color: colors.text },
})
