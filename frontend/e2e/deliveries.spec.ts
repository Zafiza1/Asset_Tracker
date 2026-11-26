import { expect, test, type APIRequestContext, type Page } from '@playwright/test'
import { E2E_PASSWORD } from './env.ts'

/**
 * PT SIG / PT CDO: cylinders out to a customer and back. The web plans and follows the trip; the
 * phone's part (crew, scans, reports on the way) is made with the API calls of the mobile app and
 * its own token. Data: DemoSeeder, PT Maju Jaya, plus what the test registers itself.
 */
const EMAIL = 'admin@majujaya.test'

async function login(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(EMAIL)
  await page.getByLabel('Password').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Masuk' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

/** API calls with the browser's session (Sanctum: stateful origin, XSRF token on writes). */
function web(page: Page) {
  const headers = async () => {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')
    const origin = new URL(page.url()).origin
    return { Accept: 'application/json', Origin: origin, Referer: `${origin}/`, ...(cookie && { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) }) }
  }
  return {
    get: async (path: string) => (await page.request.get(`/api${path}`, { headers: await headers() })).json(),
    post: async (path: string, data: object) => {
      const res = await page.request.post(`/api${path}`, { data, headers: await headers() })
      expect(res.status(), `${path}: ${await res.text()}`).toBe(201)
      return (await res.json()).data
    },
  }
}

/** The mobile app: its own login, bearer token and write calls. */
async function phone(request: APIRequestContext) {
  const res = await request.post('/api/auth/mobile/login', { data: { email: EMAIL, password: E2E_PASSWORD, device_name: 'E2E' }, headers: { Accept: 'application/json' } })
  expect(res.ok(), await res.text()).toBeTruthy()
  const { token, abilities } = (await res.json()).data
  expect(abilities).toContain('delivery:write')
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` }
  const send = async (method: 'post' | 'put', path: string, data: object) => {
    const r = await request[method](`/api/deliveries/${path}`, { data: { recorded_at: new Date().toISOString(), ...data }, headers })
    return { status: r.status(), body: await r.json() }
  }
  return {
    crew: (id: string, data: object) => send('put', `${id}/crew`, data),
    scan: (id: string, phase: string, code: string, stop_id?: string) => send('post', `${id}/scan`, { phase, code, stop_id }),
    report: (id: string, type: string, data: object = {}) => send('post', `${id}/events`, { type, ...data }),
  }
}

interface Field {
  key: string
  label: string
  data_type: string
  is_required?: boolean
  options: { value: string }[] | null
}

async function register(page: Page) {
  const api = web(page)
  const categories: { id: string; code: string }[] = (await api.get('/tenant-masters/categories?all=1&status=active')).data
  const asset = async (code: string, body: object, values: Record<string, unknown> = {}) => {
    const category = categories.find((c) => c.code === code)!
    const fields: Field[] = (await api.get(`/tenant-masters/categories/${category.id}/attributes`)).data
    const sample = (f: Field) => f.options?.[0]?.value ?? (['integer', 'decimal'].includes(f.data_type) ? 6 : f.data_type === 'date' ? '2026-01-31' : 'Contoh')
    const attributes = Object.fromEntries(fields.filter((f) => f.is_required || f.label in values).map((f) => [f.key, values[f.label] ?? sample(f)]))
    return api.post('/assets', { category_id: category.id, status: 'available', attributes, ...body })
  }
  const cylinders = []
  for (const n of [1, 2, 3]) cylinders.push(await asset('AST_TABUNG', { name: 'Oksigen Medis 6 m3', serial_number: `E2E-TBG-${n}` }))
  return {
    cylinders,
    vehicle: await asset('AST_KENDARAAN', { name: 'Truk E2E', serial_number: 'E2E-KDR-1', status: 'in_use' }, { 'Nomor Polisi': 'L 1234 E2E' }),
    driver: await api.post('/employees', { name: 'Supir E2E', position: 'driver' }),
    helper: await api.post('/employees', { name: 'Kernet E2E', position: 'helper' }),
    customer: await api.post('/customers', { name: 'RS Uji E2E', address: 'Jl. Uji No. 1' }),
  }
}

const shot = async (page: Page, name: string) => {
  if (process.env.E2E_SCREENSHOT_DIR) await page.screenshot({ path: `${process.env.E2E_SCREENSHOT_DIR}/${name}.png`, fullPage: true })
}

test('transaksi keluar dan masuk tabung: rencana di web, jalan di mobile, pantau di Tracking', async ({ page, playwright, baseURL }) => {
  await login(page)
  const data = await register(page)
  const request = await playwright.request.newContext({ baseURL })
  const hp = await phone(request)

  // 1. The edition's menu.
  const menu = page.getByRole('navigation').first()
  for (const label of ['Transaksi', 'Tracking', 'Karyawan', 'Customer']) await expect(menu.getByRole('link', { name: label, exact: true })).toBeVisible()
  for (const label of ['Lisensi', 'Lokasi', 'Jenis Transaksi']) await expect(menu.getByRole('link', { name: label, exact: true })).toHaveCount(0)

  // 2. Plan an outgoing transaction of two cylinders; the crew is left to the phone.
  await page.goto('/transaksi')
  await page.getByRole('button', { name: 'Transaksi', exact: true }).click()
  await page.getByRole('menuitem', { name: /Transaksi keluar/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Customer').selectOption({ label: 'RS Uji E2E' })
  await dialog.getByLabel(/E2E-TBG-1/).check()
  await dialog.getByLabel(/E2E-TBG-2/).check()
  await shot(page, 'transaksi-form')
  await dialog.getByRole('button', { name: 'Simpan' }).click()
  await expect(page.getByText('Transaksi dibuat').first()).toBeVisible()
  const planned = (await web(page).get('/deliveries?status=draft')).data[0]
  expect(planned.number).toMatch(/^OUT-\d{4}-\d{6}$/)
  const id: string = planned.id
  const stop: string = planned.stops[0].id

  // 3. On the phone: not away without crew, then load by label and by typed number, and leave.
  expect((await hp.scan(id, 'load', data.cylinders[0].id)).body.data.status).toBe('loading')
  expect((await hp.scan(id, 'load', 'e2e-tbg-2')).status).toBe(200)
  expect((await hp.report(id, 'depart')).body.error.code).toBe('CREW_REQUIRED')
  expect((await hp.crew(id, { vehicle_asset_id: data.vehicle.id, driver_id: data.driver.id, helper_id: data.helper.id })).status).toBe(200)
  const client_id = crypto.randomUUID()
  expect((await hp.report(id, 'depart', { client_id, latitude: -7.2575, longitude: 112.7521 })).status).toBe(200)
  // Sent again because the answer got lost: recorded once.
  expect((await hp.report(id, 'depart', { client_id })).status).toBe(200)
  // A problem stays visible while periodic positions come in.
  await hp.report(id, 'issue', { note: 'Ban bocor' })
  await hp.report(id, 'position', { latitude: -7.265, longitude: 112.76 })

  // 4. Tracking shows the vehicle, the crew entered on the phone, and the problem.
  await page.goto('/tracking')
  const trip = page.getByRole('listitem').filter({ hasText: planned.number }).first()
  await expect(trip).toContainText('L 1234 E2E')
  await expect(trip).toContainText('Supir E2E')
  await expect(trip).toContainText('Kernet E2E')
  await expect(trip).toContainText('Ban bocor')
  await expect(page.getByLabel('Peta posisi kendaraan')).toBeVisible()
  await shot(page, 'tracking')

  // 5. Unload at the customer and finish.
  expect((await hp.report(id, 'arrive', { stop_id: stop, latitude: -7.28, longitude: 112.77 })).status).toBe(200)
  for (const code of ['E2E-TBG-1', 'E2E-TBG-2']) expect((await hp.scan(id, 'unload', code, stop)).status).toBe(200)
  expect((await hp.report(id, 'stop_done', { stop_id: stop, receiver_name: 'Ibu Sri' })).status).toBe(200)
  expect((await hp.report(id, 'completed')).body.data.status).toBe('completed')
  await page.goto(`/transaksi/${id}`)
  await expect(page.getByRole('heading', { level: 1, name: planned.number })).toBeVisible()
  await expect(page.getByText('Penerima Ibu Sri')).toBeVisible()
  await expect(page.getByText('Supir E2E').first()).toBeVisible()
  await shot(page, 'transaksi-detail')

  // 6. The cylinder is at the customer now.
  await page.goto(`/aset/${data.cylinders[0].id}`)
  await expect(page.getByText('Di RS Uji E2E').first()).toBeVisible()

  // 7. Incoming: fetched at the customer, unloaded at the warehouse, one is left behind.
  await page.goto('/transaksi')
  await page.getByRole('button', { name: 'Transaksi', exact: true }).click()
  await page.getByRole('menuitem', { name: /Transaksi masuk/ }).click()
  await page.getByRole('dialog').getByLabel('Customer').selectOption({ label: 'RS Uji E2E' })
  await page.getByRole('dialog').getByLabel(/E2E-TBG-1/).check()
  await page.getByRole('dialog').getByRole('button', { name: 'Simpan' }).click()
  await expect(page.getByText('Transaksi dibuat').first()).toBeVisible()
  const back = (await web(page).get('/deliveries?status=draft&direction=incoming')).data[0]
  expect(back.number).toMatch(/^IN-/)
  await hp.crew(back.id, { vehicle_asset_id: data.vehicle.id, driver_id: data.driver.id })
  expect((await hp.report(back.id, 'depart')).status).toBe(200)
  expect((await hp.scan(back.id, 'load', 'E2E-TBG-1')).status).toBe(200)
  expect((await hp.report(back.id, 'stop_done')).status).toBe(200)
  expect((await hp.report(back.id, 'return')).status).toBe(200)
  expect((await hp.scan(back.id, 'unload', 'E2E-TBG-1')).status).toBe(200)
  expect((await hp.report(back.id, 'completed')).body.data.status).toBe('completed')
  await page.goto(`/aset/${data.cylinders[0].id}`)
  await expect(page.getByText('Di gudang').first()).toBeVisible()
  await page.goto(`/aset/${data.cylinders[1].id}`)
  await expect(page.getByText('Di RS Uji E2E').first()).toBeVisible()

  // 8. Both are listed.
  await page.goto('/transaksi')
  await expect(page.getByRole('row').filter({ hasText: planned.number })).toContainText('Selesai')
  await expect(page.getByRole('row').filter({ hasText: back.number })).toContainText('Selesai')
  await shot(page, 'transaksi-daftar')
  await request.dispose()
})
