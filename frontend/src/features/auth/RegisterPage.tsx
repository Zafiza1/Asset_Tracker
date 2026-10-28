import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useNavigate } from 'react-router'
import { z } from 'zod'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { Field, Input } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { useAuth } from '../../lib/auth/context'
import { applyApiErrors, organizationCodeRule, passwordRule, suggestCode } from '../../lib/forms'
import { AuthShell } from './AuthShell'

const schema = z
  .object({
    organization_name: z.string().trim().min(1, 'Nama organisasi wajib diisi.').max(150),
    organization_code: organizationCodeRule,
    name: z.string().trim().min(1, 'Nama wajib diisi.').max(150),
    email: z.email('Masukkan email yang valid.'),
    password: z.string().refine(passwordRule.test, passwordRule.message),
    password_confirmation: z.string(),
    license_quantity: z.number().int().min(0, 'Jumlah lisensi tidak boleh negatif').max(100, 'Maksimal 100 lisensi').optional(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi password tidak sama.' })
type FormValues = z.infer<typeof schema>

/** Self-service sign-up: a new organization with the registrant as its administrator. */
export function RegisterPage() {
  const { profile, signUp } = useAuth()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [codeEdited, setCodeEdited] = useState(false)
  const [showLicenseConfirm, setShowLicenseConfirm] = useState(false)
  const [showSuccessModal, setShowSuccessModal] = useState(false)
  const [pendingData, setPendingData] = useState<FormValues | null>(null)
  const { register, handleSubmit, setError, setValue, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })

  if (profile) return <Navigate to="/" replace />

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    setPendingData(values)

    // Show confirmation dialog if user wants to purchase licenses
    if (values.license_quantity && values.license_quantity > 0) {
      setShowLicenseConfirm(true)
    } else {
      // No licenses to purchase, proceed directly
      try {
        await signUp(values)
        setShowSuccessModal(true)
      } catch (e) {
        setFormError(applyApiErrors(e, setError))
      }
    }
  })

  const handleConfirmLicensePurchase = async () => {
    if (!pendingData) return
    setShowLicenseConfirm(false)
    try {
      await signUp(pendingData)
      setShowSuccessModal(true)
    } catch (e) {
      setFormError(applyApiErrors(e, setError))
    }
  }

  const handleSuccessModalClose = () => {
    setShowSuccessModal(false)
    navigate('/', { replace: true })
  }

  const err = formState.errors

  return (
    <AuthShell
      wide
      subtitle="Daftarkan organisasi Anda. Anda akan menjadi administratornya dan dapat menambahkan pengguna lain."
      footer={
        <>
          Sudah punya akun?{' '}
          <Link to="/login" className="font-medium text-brand-700 hover:underline">
            Masuk
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {formError && <Alert tone="error">{formError}</Alert>}
        <section className="space-y-4" aria-labelledby="reg-org">
          <h2 id="reg-org" className="text-sm font-semibold text-slate-900">Organisasi</h2>
          <Field label="Nama organisasi" error={err.organization_name?.message} required>
            {(p) => (
              <Input
                {...p}
                autoFocus
                autoComplete="organization"
                placeholder="mis. PT Maju Jaya"
                {...register('organization_name', { onChange: (e) => !codeEdited && setValue('organization_code', suggestCode(e.target.value)) })}
              />
            )}
          </Field>
          <Field label="Kode organisasi" error={err.organization_code?.message} hint="Singkatan unik, dipakai sebagai identitas organisasi. Tidak dapat diubah." required>
            {(p) => <Input {...p} className={`${p.className} uppercase`} placeholder="mis. MAJU" {...register('organization_code', { onChange: () => setCodeEdited(true) })} />}
          </Field>
          <Field label="Jumlah lisensi tambahan" error={err.license_quantity?.message} hint="Masukkan jumlah lisensi yang ingin dibeli untuk organisasi Anda. Biarkan kosong jika tidak ingin membeli.">
            {(p) => <Input {...p} type="number" min="0" max="100" placeholder="0" {...register('license_quantity', { valueAsNumber: true })} />}
          </Field>
        </section>
        <section className="space-y-4 border-t border-slate-200 pt-4" aria-labelledby="reg-account">
          <h2 id="reg-account" className="text-sm font-semibold text-slate-900">Akun Anda</h2>
          <Field label="Nama lengkap" error={err.name?.message} required>
            {(p) => <Input {...p} autoComplete="name" {...register('name')} />}
          </Field>
          <Field label="Email" error={err.email?.message} required>
            {(p) => <Input {...p} type="email" autoComplete="email" {...register('email')} />}
          </Field>
          <Field label="Password" error={err.password?.message} hint={passwordRule.message} required>
            {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
          </Field>
          <Field label="Ulangi password" error={err.password_confirmation?.message} required>
            {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password_confirmation')} />}
          </Field>
        </section>
        <Button type="submit" className="w-full" loading={formState.isSubmitting}>
          Daftar
        </Button>
      </form>

      <Modal
        open={showLicenseConfirm}
        title="Konfirmasi Pembelian Lisensi"
        onClose={() => setShowLicenseConfirm(false)}
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setShowLicenseConfirm(false)}>
              Batal
            </Button>
            <Button type="button" onClick={handleConfirmLicensePurchase}>
              Ya, Beli {pendingData?.license_quantity} Lisensi
            </Button>
          </>
        }
      >
        <p className="text-slate-700">
          Apakah Anda ingin membeli <strong>{pendingData?.license_quantity}</strong> lisensi untuk organisasi "{pendingData?.organization_name}"?
        </p>
        <p className="mt-2 text-sm text-slate-600">
          Pembayaran akan dibypass dan lisensi akan langsung ditambahkan ke organisasi Anda.
        </p>
      </Modal>

      <Modal
        open={showSuccessModal}
        title="Registrasi Berhasil"
        onClose={handleSuccessModalClose}
        footer={
          <Button type="button" onClick={handleSuccessModalClose}>
            Masuk ke Dashboard
          </Button>
        }
      >
        <div className="space-y-3">
          <p className="text-slate-700">
            Organisasi "{pendingData?.organization_name}" berhasil didaftarkan{pendingData?.license_quantity && pendingData.license_quantity > 0 ? ` dan ${pendingData.license_quantity} lisensi telah ditambahkan.` : '.'}
          </p>
          {pendingData?.license_quantity && pendingData.license_quantity > 0 && (
            <div className="rounded-md bg-green-50 p-3">
              <p className="text-sm font-medium text-green-900">
                Kode lisensi Anda telah dibuat!
              </p>
              <p className="mt-1 text-sm text-green-800">
                Anda dapat melihat kode lisensi di menu <strong>Pengaturan Lisensi</strong>.
              </p>
              <p className="mt-2 text-xs text-green-700">
                Kode lisensi dapat digunakan untuk mengundang anggota baru ke organisasi Anda.
              </p>
            </div>
          )}
          <div className="rounded-md bg-brand-50 p-3">
            <p className="text-sm text-brand-900">
              Tips: Kunjungi menu <strong>Administrasi → Pengaturan Lisensi</strong> untuk melihat dan mengelola kode lisensi Anda.
            </p>
          </div>
        </div>
      </Modal>
    </AuthShell>
  )
}
