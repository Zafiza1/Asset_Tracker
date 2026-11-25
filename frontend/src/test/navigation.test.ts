import { describe, expect, it } from 'vitest'
import { currentNavTarget, tenantNavigation } from '../app/navigation'

describe('current menu entry', () => {
  it.each([
    ['/transaksi', '/transaksi'],
    ['/transaksi/d1', '/transaksi'],
    ['/tracking', '/tracking'],
    ['/master/karyawan', '/master/karyawan'],
    ['/master/customer', '/master/customer'],
    // Pages without an entry of their own fall under the closest one.
    ['/transaksi/riwayat', '/transaksi'],
    ['/transaksi/berjalan/r1', '/transaksi'],
    ['/aset/a1', '/aset'],
    ['/laporan/template/t1/desain', '/laporan/template'],
    ['/', '/'],
    // A prefix of a word is not a match.
    ['/asetku', null],
  ])('%s → %s', (path, entry) => {
    expect(currentNavTarget(tenantNavigation, path)).toBe(entry)
  })

  it('has no menu for what the PT SIG / PT CDO edition does not use', () => {
    const targets = tenantNavigation.flatMap((s) => s.items).map((i) => i.to)
    for (const removed of ['/pengaturan', '/transaksi/jenis', '/master/lokasi']) expect(targets).not.toContain(removed)
  })
})
