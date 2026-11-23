import { expect, test, type Page } from '@playwright/test'
import { E2E_PASSWORD } from './env.ts'

/**
 * F-QA: a temporary outgoing transaction, the asset coming back, and both in the outgoing
 * report (docs/factory/features/F-02-transaksi-keluar-masuk.md §10). Data: DemoSeeder, PT Maju Jaya.
 */
const ASSET = 'Laptop Dell Latitude 5440'

async function login(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email').fill('admin@majujaya.test')
  await page.getByLabel('Password').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

/**
 * Headers the SPA sends, for API calls made with the browser's session: Sanctum treats only
 * requests from a stateful origin as first-party, and checks the XSRF token on writes.
 */
async function xsrf(page: Page): Promise<Record<string, string>> {
  const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
  const origin = new URL(page.url()).origin
  return { Accept: 'application/json', Origin: origin, Referer: `${origin}/`, ...(cookie && { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) }) }
}

test('keluar sementara → masuk → laporan transaksi keluar', async ({ page }) => {
  await login(page)

  // 1. Outgoing, temporary, from the transaction history (Riwayat, D-50).
  await page.goto('/transaksi/riwayat')
  await page.getByRole('button', { name: 'Catat transaksi' }).first().click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Jenis transaksi').selectOption('outgoing')
  await dialog.getByLabel('Alasan').selectOption('external_repair')
  await dialog.getByLabel('Nama pihak luar').fill('CV Sinar Servis')
  await dialog.getByLabel('Nomor dokumen').fill('SJ/E2E/001')
  const due = new Date(Date.now() + 7 * 86_400_000).toISOString().slice(0, 10)
  await dialog.getByLabel('Rencana tanggal kembali').fill(due)
  await dialog.getByLabel(`Pilih ${ASSET}`).check()
  await dialog.getByRole('button', { name: /Catat keluar/ }).click()
  await expect(page.getByText('Transaksi dicatat')).toBeVisible()

  // 2. It is listed as not returned yet.
  await page.getByRole('tab', { name: /Belum kembali/ }).click()
  await expect(page).toHaveURL(/not_returned=1/)
  await expect(page.getByRole('tab', { name: /Belum kembali/ })).toContainText('1')
  const row = page.getByRole('row').filter({ hasText: ASSET }).filter({ hasText: /OUT-\d{4}-\d{6}/ })
  await expect(page.getByRole('row').filter({ hasText: /REG-\d{4}/ })).toHaveCount(0)
  await expect(row).toContainText('CV Sinar Servis')
  await expect(row).toContainText('Belum kembali')
  const number = (await row.getByText(/^OUT-\d{4}-\d{6}$/).textContent())!.trim()

  // 3. The asset is outside the organization; record it coming back from its detail page.
  await row.getByRole('link', { name: ASSET }).click()
  await expect(page.getByText('Di Luar Organisasi').first()).toBeVisible()
  // The number shows in the "Di luar organisasi" note and again in the asset's history.
  await expect(page.getByText(number).first()).toBeVisible()
  await page.getByRole('button', { name: 'Catat masuk' }).click()
  const back = page.getByRole('dialog')
  await expect(back.getByLabel('Jenis transaksi')).toHaveValue('incoming')
  await back.getByRole('button', { name: /Catat masuk/ }).click()
  await expect(page.getByText('Transaksi dicatat')).toBeVisible()
  await expect(page.getByText('Tersedia').first()).toBeVisible()

  // 4. Nothing is outside any more.
  await page.goto('/transaksi/riwayat?not_returned=1')
  await expect(page.getByText('Tidak ada aset yang belum kembali')).toBeVisible()

  // 5. The outgoing report sample shows the transaction as returned (API: no sample picker in the UI yet).
  const headers = await xsrf(page)
  const created = await page.request.post('/api/report-templates/from-sample', { data: { sample: 'outgoing' }, headers })
  expect(created.status()).toBe(201)
  const template = (await created.json()).data
  const preview = await page.request.post(`/api/report-templates/${template.id}/preview`, { data: {}, headers })
  expect(preview.ok()).toBeTruthy()
  const report = JSON.stringify(await preview.json())
  expect(report).toContain(number)
  expect(report).toContain('CV Sinar Servis')
  expect(report).toContain('Sudah kembali')
})
