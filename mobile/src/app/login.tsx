import { useState } from 'react'
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { Alert, Button, colors } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { OrganizationChoiceRequired, useSession } from '@/lib/session'
import type { Organization } from '@/lib/types'

/** Server address + account login; an account in several organizations picks one. */
export default function LoginScreen() {
  const session = useSession()
  const [server, setServer] = useState(session.server)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [organizations, setOrganizations] = useState<Organization[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (organizationId?: string) => {
    if (!server.trim() || !email.trim() || !password) {
      setError('Isi alamat server, email, dan password.')
      return
    }
    setLoading(true)
    setError(null)
    try {
      await session.login({ server, email, password, organizationId })
    } catch (e) {
      if (e instanceof OrganizationChoiceRequired) setOrganizations(e.organizations)
      else setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.background }}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
          <View style={styles.header}>
            <Text style={styles.title}>Asset Tracker</Text>
            <Text style={styles.subtitle}>Pindai barcode 1D atau 2D untuk melihat detail aset.</Text>
          </View>

          {error && <Alert>{error}</Alert>}

          {organizations ? (
            <View style={styles.form}>
              <Text style={styles.label}>Pilih organisasi</Text>
              {organizations.map((o) => (
                <Pressable key={o.id} style={({ pressed }) => [styles.org, pressed && { backgroundColor: colors.brandSoft }]} onPress={() => submit(o.id)} disabled={loading}>
                  <Text style={styles.orgName}>{o.name}</Text>
                  <Text style={styles.orgCode}>{o.code}</Text>
                </Pressable>
              ))}
              <Button title="Kembali" variant="secondary" onPress={() => setOrganizations(null)} disabled={loading} />
            </View>
          ) : (
            <View style={styles.form}>
              <Field label="Alamat server" hint="mis. http://10.10.20.7:8000">
                <TextInput style={styles.input} value={server} onChangeText={setServer} placeholder="http://10.10.20.7:8000" autoCapitalize="none" autoCorrect={false} keyboardType="url" />
              </Field>
              <Field label="Email">
                <TextInput style={styles.input} value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="email" textContentType="username" />
              </Field>
              <Field label="Password">
                <TextInput style={styles.input} value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" textContentType="password" onSubmitEditing={() => submit()} />
              </Field>
              <Button title="Masuk" onPress={() => submit()} loading={loading} />
            </View>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  )
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={styles.label}>{label}</Text>
      {children}
      {hint && <Text style={styles.hint}>{hint}</Text>}
    </View>
  )
}

const styles = StyleSheet.create({
  container: { flexGrow: 1, justifyContent: 'center', padding: 24, gap: 20 },
  header: { gap: 6 },
  title: { fontSize: 28, fontWeight: '800', color: colors.brand },
  subtitle: { fontSize: 15, color: colors.muted },
  form: { gap: 16 },
  label: { fontSize: 14, fontWeight: '600', color: colors.text },
  hint: { fontSize: 12, color: colors.muted },
  input: { minHeight: 48, borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 12, fontSize: 16, color: colors.text, backgroundColor: colors.surface },
  org: { padding: 16, borderRadius: 10, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  orgName: { fontSize: 16, fontWeight: '600', color: colors.text },
  orgCode: { fontSize: 13, color: colors.muted, fontFamily: Platform.select({ ios: 'Menlo', default: 'monospace' }) },
})
