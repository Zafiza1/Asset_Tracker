import { expect, test, type Page } from '@playwright/test'
import { E2E_PASSWORD } from './env.ts'

/**
 * F-08-QA and F-08-QA2 (docs/factory/features/F-08-transaction-builder-terpandu.md §9): a type
 * from a template is adjusted in the Transaction Designer, every scenario is run and it is
 * published; another member records it in the Transaction Center, sees the rule that applies,
 * and the run goes through its stages to Selesai. Data: DemoSeeder, PT Maju Jaya (Super Admin
 * admin@, Supervisor supervisor@). The recorder never approves their own transaction.
 */
const ADMIN = 'admin@majujaya.test'
const SUPERVISOR = 'supervisor@majujaya.test'
const ADMIN_NAME = 'Admin PT Maju Jaya'

async function login(page: Page, email: string) {
  await page.context().clearCookies()
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

/** "Pakai template" from the gallery; the wizard (Panduan) opens on the new draft. */
async function useTemplate(page: Page, name: string) {
  await page.goto('/transaksi/jenis/baru')
  await page.getByRole('listitem').filter({ has: page.getByRole('heading', { name }) }).getByRole('button', { name: 'Pakai template' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Buat draft' }).click()
  await expect(page).toHaveURL(/\/transaksi\/jenis\/[^/]+\/panduan$/)
}

/** A tab of the Transaction Designer (not the app menu of the same name). */
function designerTab(page: Page, name: string | RegExp) {
  return page.getByRole('navigation', { name: 'Bagian jenis transaksi' }).getByRole('link', { name })
}

/** Tinjau: run every scenario, then publish once the button allows it (F08-09). */
async function runScenariosAndPublish(page: Page) {
  await designerTab(page, 'Panduan').click()
  await page.getByRole('button', { name: /Tinjau/ }).click()
  await page.getByRole('button', { name: /Jalankan semua skenario/ }).click()
  const publish = page.getByRole('button', { name: /Terbitkan v1/ })
  await expect(publish).toBeEnabled({ timeout: 30_000 })
  await expect(page.getByText('Belum dijalankan')).toHaveCount(0)
  await expect(page.getByText('Gagal', { exact: true })).toHaveCount(0)
  await publish.click()
  await expect(page.getByText(/Alur v1 diterbitkan/)).toBeVisible()
}

/** Opens the first run waiting for the user, in Persetujuan ("Perlu keputusan saya"). */
async function openMyTask(page: Page, prefix: string) {
  await page.goto('/notifikasi')
  await page.getByRole('link', { name: new RegExp(`${prefix}-\\d{4}-`) }).first().click()
  await expect(page).toHaveURL(/\/transaksi\/berjalan\//)
}

async function approve(page: Page) {
  await page.getByRole('button', { name: 'Setujui atau tolak' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Setujui', exact: true }).click()
  await expect(page.getByRole('dialog')).toBeHidden()
}

test('F-08-QA: Mutasi antar lokasi dari template, batas nilai diubah, skenario, terbit, dicatat sampai selesai', async ({ page }) => {
  await login(page, ADMIN)
  await useTemplate(page, 'Mutasi antar lokasi')

  // The threshold of "Approval aset bernilai tinggi" from Rp 10 jt to Rp 3 jt.
  await designerTab(page, /^Rules/).click()
  await page.getByRole('button', { name: 'Ubah Approval aset bernilai tinggi' }).click()
  const rule = page.getByRole('dialog')
  await rule.getByLabel('Nilai', { exact: true }).fill('3000000')
  await rule.getByRole('button', { name: 'Simpan rule' }).click()
  await expect(page.getByText(/lebih dari Rp 3\.000\.000/)).toBeVisible()

  await runScenariosAndPublish(page)
  await designerTab(page, 'Cara kerja').click()
  await expect(page.getByText(/Rp 3\.000\.000/).first()).toBeVisible()

  // The Supervisor records it in the Transaction Center; the rule shows before sending.
  await login(page, SUPERVISOR)
  await page.goto('/transaksi')
  await page.getByRole('button', { name: /Mutasi antar lokasi/ }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Pilih Kursi Ergonomis').check()
  await form.getByLabel('Lokasi tujuan').selectOption({ label: 'Gudang Jakarta (GDG-JKT)' })
  await form.getByLabel(/^Alasan/).fill('Dipakai tim gudang.')
  await form.getByLabel(/^Penerima/).selectOption({ label: ADMIN_NAME })
  await expect(form.getByText('Approval aset bernilai tinggi')).toBeVisible()
  await form.getByRole('button', { name: /Mulai transaksi/ }).click()
  await expect(form).toBeHidden()

  // The Super Admin approves as Supervisor over the asset, then for the rule, then receives it.
  await login(page, ADMIN)
  await openMyTask(page, 'MUT')
  await approve(page)
  await approve(page)
  await page.getByRole('button', { name: 'Kerjakan langkah ini' }).click()
  const receive = page.getByRole('dialog')
  await receive.getByLabel('No. BAST').fill('BAST/E2E/001')
  await receive.getByRole('button', { name: 'Simpan dan lanjutkan' }).click()
  await expect(page.getByText('Selesai').first()).toBeVisible()
})

test('F-08-QA2: Peminjaman dengan rule Approval aset bernilai tinggi, dicatat di Transaction Center sampai selesai', async ({ page }) => {
  await login(page, ADMIN)
  await useTemplate(page, 'Peminjaman ke karyawan')

  // A new rule: above Rp 10 jt the Super Admin approves.
  await designerTab(page, /^Rules/).click()
  await page.getByRole('button', { name: 'Rule baru' }).first().click()
  const rule = page.getByRole('dialog')
  await rule.getByLabel('Nama rule').fill('Approval aset bernilai tinggi')
  await rule.getByLabel('Disetujui oleh').selectOption('role')
  await rule.getByLabel('Role', { exact: true }).selectOption({ label: 'Super Admin' })
  await rule.getByRole('button', { name: 'Simpan rule' }).click()
  await expect(page.getByText(/lebih dari Rp 10\.000\.000, perlu disetujui pemegang role Super Admin/)).toBeVisible()

  // The scenario of a costly asset (simulated with the sample asset) passes with the others.
  await designerTab(page, 'Panduan').click()
  await page.getByRole('button', { name: /Tinjau/ }).click()
  await expect(page.getByText(/^Nilai perolehan .*lebih dari Rp 10\.000\.000, disetujui pemegang role Super Admin → Selesai$/).first()).toBeVisible()
  await runScenariosAndPublish(page)

  // The Supervisor lends a costly asset from the Transaction Center.
  await login(page, SUPERVISOR)
  await page.goto('/transaksi')
  await page.getByRole('button', { name: /Peminjaman ke karyawan/ }).click()
  const form = page.getByRole('dialog')
  await form.getByLabel('Pilih Mobil Operasional Avanza').check()
  await expect(form.getByText('Approval aset bernilai tinggi')).toBeVisible()
  await form.getByLabel(/^Pemegang/).selectOption({ label: ADMIN_NAME })
  await form.getByLabel('Rencana kembali').fill(new Date(Date.now() + 7 * 86_400_000).toISOString().slice(0, 10))
  await form.getByRole('button', { name: /Mulai transaksi/ }).click()
  await expect(form).toBeHidden()

  await page.goto('/transaksi/riwayat?proses=1')
  await expect(page.getByText('Tahapan sekarang: Approval aset bernilai tinggi')).toBeVisible()

  // The Super Admin approves; the asset is lent and the run is Selesai.
  await login(page, ADMIN)
  await openMyTask(page, 'PJK')
  await approve(page)
  await expect(page.getByText('Selesai').first()).toBeVisible()
})
