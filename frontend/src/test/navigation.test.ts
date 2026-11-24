import { describe, expect, it } from 'vitest'
import { currentNavTarget, tenantNavigation } from '../app/navigation'

describe('current menu entry', () => {
  it.each([
    // An entry under another entry's address: only the closer one is current.
    ['/transaksi/jenis', '/transaksi/jenis'],
    ['/transaksi/jenis/abc/alur', '/transaksi/jenis'],
    ['/transaksi', '/transaksi'],
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
})
