import { expect, test } from '@playwright/test'
import { E2E_PASSWORD } from './env.ts'

/**
 * F-06d: Beranda is the first page after login for every member, with the cards of their
 * permissions; the asset analytics moved to "Dashboard Aset". Data: DemoSeeder, PT Maju Jaya.
 */
test('Beranda setelah login, lalu Dashboard Aset dari menu', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill('admin@majujaya.test')
  await page.getByLabel('Password').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page).toHaveURL(/\/$/)

  await expect(page.getByRole('heading', { level: 1, name: /^Selamat (pagi|siang|sore|malam),/ })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Pengajuan saya' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Menunggu keputusan saya' })).toBeVisible()
  if (process.env.E2E_SCREENSHOT_DIR) {
    await page.screenshot({ path: `${process.env.E2E_SCREENSHOT_DIR}/beranda.png`, fullPage: true })
  }

  // Hide one card; it stays hidden after a reload (saved per user).
  await page.getByRole('button', { name: 'Atur kartu' }).click()
  await page.getByRole('button', { name: 'Sembunyikan Pengajuan saya' }).click()
  await page.getByRole('button', { name: 'Simpan susunan' }).click()
  await expect(page.getByRole('region', { name: 'Pengajuan saya' })).toBeHidden()
  await page.reload()
  await expect(page.getByRole('region', { name: 'Menunggu keputusan saya' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Pengajuan saya' })).toBeHidden()

  await page.getByRole('link', { name: 'Dashboard Aset' }).click()
  await expect(page).toHaveURL(/\/dashboard-aset$/)
})
