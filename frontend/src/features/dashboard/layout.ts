import { useState } from 'react'

/** Widths a widget can take. The grid is 12 columns on desktop, 6 on tablet, 1 on phone. */
export type WidgetSize = 'quarter' | 'third' | 'half' | 'two_thirds' | 'full'

/** Labels are the ratio of the widget's width to the full desktop grid row. */
export const SIZE_LABEL: Record<WidgetSize, string> = {
  quarter: '1:4',
  third: '1:3',
  half: '1:2',
  two_thirds: '2:3',
  full: '1:1',
}

/** Literal class strings so Tailwind picks them up. On tablet anything up to half shares a row in pairs. */
export const SIZE_CLASS: Record<WidgetSize, string> = {
  quarter: 'md:col-span-3 xl:col-span-3',
  third: 'md:col-span-3 xl:col-span-4',
  half: 'md:col-span-3 xl:col-span-6',
  two_thirds: 'md:col-span-6 xl:col-span-8',
  full: 'md:col-span-6 xl:col-span-12',
}

const STAT_SIZES: WidgetSize[] = ['quarter', 'third', 'half']
const PANEL_SIZES: WidgetSize[] = ['third', 'half', 'two_thirds', 'full']

/** Every widget, in default page order with its default width. */
export const DASHBOARD_WIDGETS = [
  { key: 'stat_total', header: 'Total aset', kind: 'stat', size: 'quarter', sizes: STAT_SIZES },
  { key: 'stat_value', header: 'Total nilai perolehan', kind: 'stat', size: 'quarter', sizes: STAT_SIZES },
  { key: 'stat_added', header: 'Ditambahkan bulan ini', kind: 'stat', size: 'quarter', sizes: STAT_SIZES },
  { key: 'stat_transactions', header: 'Jumlah transaksi', kind: 'stat', size: 'quarter', sizes: STAT_SIZES },
  { key: 'status', header: 'Status aset', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'utilization', header: 'Pemanfaatan & peminjaman', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'data_quality', header: 'Kelengkapan data', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'trend', header: 'Penambahan & perubahan aset', kind: 'panel', size: 'two_thirds', sizes: PANEL_SIZES },
  { key: 'age', header: 'Umur aset', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'category', header: 'Per kategori', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'location', header: 'Per lokasi', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'transaction_types', header: 'Transaksi per jenis', kind: 'panel', size: 'third', sizes: PANEL_SIZES },
  { key: 'activity', header: 'Tindak lanjut, aset menganggur & transaksi terbaru', kind: 'panel', size: 'full', sizes: PANEL_SIZES },
] as const satisfies readonly { key: string; header: string; kind: 'stat' | 'panel'; size: WidgetSize; sizes: WidgetSize[] }[]

export type WidgetKey = (typeof DASHBOARD_WIDGETS)[number]['key']
export type WidgetDef = (typeof DASHBOARD_WIDGETS)[number]

export interface LayoutItem {
  key: WidgetKey
  size: WidgetSize
  hidden: boolean
}

export const widgetDef = (key: WidgetKey) => DASHBOARD_WIDGETS.find((w) => w.key === key)!

const defaultLayout = (): LayoutItem[] => DASHBOARD_WIDGETS.map((w) => ({ key: w.key, size: w.size, hidden: false }))

/**
 * Cleans a stored layout: unknown or repeated widgets are dropped, sizes a widget
 * doesn't allow fall back to its default, and widgets added since are appended visible.
 */
export function normalizeLayout(raw: unknown): LayoutItem[] {
  const items: LayoutItem[] = []
  for (const entry of Array.isArray(raw) ? raw : []) {
    const def = DASHBOARD_WIDGETS.find((w) => w.key === entry?.key)
    if (!def || items.some((i) => i.key === def.key)) continue
    const sizes: readonly WidgetSize[] = def.sizes
    items.push({ key: def.key, size: sizes.includes(entry.size) ? entry.size : def.size, hidden: entry.hidden === true })
  }
  for (const def of DASHBOARD_WIDGETS) {
    if (!items.some((i) => i.key === def.key)) items.push({ key: def.key, size: def.size, hidden: false })
  }
  return items
}

/** Before widgets could be moved, only a hidden list was kept; "summary" was the four stat tiles. */
function fromLegacyHidden(hidden: unknown): LayoutItem[] {
  const keys = new Set(Array.isArray(hidden) ? hidden.flatMap((k) => (k === 'summary' ? ['stat_total', 'stat_value', 'stat_added', 'stat_transactions'] : [k])) : [])
  return defaultLayout().map((i) => ({ ...i, hidden: keys.has(i.key) }))
}

function load(userKey: string): LayoutItem[] {
  try {
    const saved = localStorage.getItem(`dashboard:layout:${userKey}`)
    if (saved) return normalizeLayout(JSON.parse(saved))
    const legacy = localStorage.getItem(`dashboard:hidden:${userKey}`)
    if (legacy) return fromLegacyHidden(JSON.parse(legacy))
  } catch {
    // Unreadable storage or JSON — start from the default layout.
  }
  return defaultLayout()
}

/** The widget layout (order, width, visibility), remembered per user in this browser. */
export function useDashboardLayout(userId: string | undefined) {
  const userKey = userId ?? 'anon'
  const [items, setItems] = useState(() => load(userKey))
  const save = (next: LayoutItem[]) => {
    setItems(next)
    try {
      localStorage.setItem(`dashboard:layout:${userKey}`, JSON.stringify(next))
    } catch {
      // Storage unavailable (private mode, quota) — the layout just won't persist.
    }
  }
  const patch = (key: WidgetKey, change: Partial<LayoutItem>) => save(items.map((i) => (i.key === key ? { ...i, ...change } : i)))

  return {
    items,
    /** Moves `key` to where `overKey` is, shifting the widgets in between. */
    move: (key: WidgetKey, overKey: WidgetKey) => {
      const from = items.findIndex((i) => i.key === key)
      const to = items.findIndex((i) => i.key === overKey)
      if (from < 0 || to < 0 || from === to) return
      const next = [...items]
      next.splice(to, 0, ...next.splice(from, 1))
      save(next)
    },
    resize: (key: WidgetKey, size: WidgetSize) => patch(key, { size }),
    setHidden: (key: WidgetKey, hidden: boolean) => patch(key, { hidden }),
    showAll: () => save(items.map((i) => ({ ...i, hidden: false }))),
    reset: () => save(defaultLayout()),
  }
}
