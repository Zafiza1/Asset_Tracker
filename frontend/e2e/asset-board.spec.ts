import { expect, test } from '@playwright/test'
import { E2E_PASSWORD } from './env.ts'

/**
 * F-06e: Dashboard Aset from the role template, every widget loads its own data, a category
 * tab, and adding a widget from the catalog; then each built-in role. Data: DemoSeeder, PT Maju Jaya.
 */
test('Dashboard Aset: widget template role, tab kategori, tambah widget', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill('admin@majujaya.test')
  await page.getByLabel('Password').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page).not.toHaveURL(/\/login/)

  await page.getByRole('link', { name: 'Dashboard Aset' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard Aset' })).toBeVisible()
  await expect(page.getByText(/^Template: .+ · salinan pribadi Anda$/)).toBeVisible()

  // Every widget of the template answers with data the page can draw.
  const widgets = page.locator('main section[aria-label]')
  await expect(widgets.first()).toBeVisible()
  await expect(page.getByRole('status', { name: /^Memuat / })).toHaveCount(0, { timeout: 20_000 })
  await expect(page.getByText('Widget gagal dimuat')).toHaveCount(0)
  expect(await widgets.count()).toBeGreaterThan(3)
  if (process.env.E2E_SCREENSHOT_DIR) {
    await page.screenshot({ path: `${process.env.E2E_SCREENSHOT_DIR}/dashboard-aset.png`, fullPage: true })
  }

  // A category tab opens that category's dashboard.
  const categoryTab = page.getByRole('tab').nth(1)
  const categoryName = await categoryTab.textContent()
  await categoryTab.click()
  await expect(page.getByText(new RegExp(`^Dashboard kategori ${categoryName}`))).toBeVisible()
  await expect(page.getByRole('status', { name: /^Memuat / })).toHaveCount(0, { timeout: 20_000 })
  await expect(page.getByText('Widget gagal dimuat')).toHaveCount(0)
  if (process.env.E2E_SCREENSHOT_DIR) {
    await page.screenshot({ path: `${process.env.E2E_SCREENSHOT_DIR}/dashboard-kategori.png`, fullPage: true })
  }

  // Back on Ringkasan: add a note widget.
  await page.getByRole('tab', { name: 'Ringkasan' }).click()
  await page.getByRole('button', { name: 'Tambah widget' }).click()
  const dialog = page.getByRole('dialog', { name: 'Tambah widget' })
  await dialog.getByLabel('Cari widget').fill('catatan')
  await dialog.getByRole('button', { name: /Catatan/ }).click()
  await dialog.getByLabel('Isi catatan').fill('Stock opname 1 Desember')
  await dialog.getByRole('button', { name: 'Tambahkan' }).click()
  await expect(page.getByRole('region', { name: 'Catatan' }).getByText('Stock opname 1 Desember')).toBeVisible()
})

/**
 * F-06f: each built-in role opens its own template (D-21) and every widget of it answers.
 * pemilik@grup.test is the demo's only Admin and belongs to two organizations.
 */
const ROLES = [
  { role: 'Super Admin', email: 'admin@majujaya.test', template: 'Ringkasan Eksekutif' },
  { role: 'Supervisor', email: 'supervisor@majujaya.test', template: 'Pengawasan Operasional' },
  { role: 'Admin', email: 'pemilik@grup.test', template: 'Operasional Harian' },
]

for (const { role, email, template } of ROLES) {
  test(`Dashboard Aset ${role}: template role, semua widget termuat`, async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password').fill(E2E_PASSWORD)
    await page.getByRole('button', { name: 'Masuk' }).click()
    await page.waitForURL((url) => !url.pathname.startsWith('/login'))
    if (new URL(page.url()).pathname === '/pilih-organisasi') {
      await page.getByRole('button', { name: /PT Maju Jaya/ }).click()
      await page.waitForURL((url) => url.pathname !== '/pilih-organisasi')
    }

    await page.getByRole('link', { name: 'Dashboard Aset' }).click()
    await expect(page.getByText(`Template: ${template} · salinan pribadi Anda`)).toBeVisible()
    const widgets = page.locator('main section[aria-label]')
    await expect(widgets.first()).toBeVisible()
    await expect(page.getByRole('status', { name: /^Memuat / })).toHaveCount(0, { timeout: 20_000 })
    await expect(page.getByText('Widget gagal dimuat')).toHaveCount(0)
    await expect(page.getByText(/mengalami kesalahan/)).toHaveCount(0)
    expect(await widgets.count()).toBeGreaterThan(2)
  })
}
