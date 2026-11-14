import clsx from 'clsx'

export interface PillTab<T extends string> {
  value: T
  label: string
  /** Number shown after the label, e.g. how many rows the tab holds. */
  count?: number
  /** Marks a count that needs attention (e.g. requests waiting for a decision). */
  attention?: boolean
}

/**
 * Tabs that filter one list by a status (Menunggu / Disetujui / …), with optional counts.
 * The selected tab is marked by `aria-selected`, a ring and weight — not by color alone.
 * On narrow screens the row scrolls sideways instead of wrapping into a block of buttons.
 */
export function PillTabs<T extends string>({ label, tabs, value, onChange }: { label: string; tabs: PillTab<T>[]; value: T; onChange: (value: T) => void }) {
  return (
    <div role="tablist" aria-label={label} className="-mx-1 flex gap-1 overflow-x-auto overscroll-x-contain px-1 py-0.5">
      {tabs.map((t) => {
        const active = value === t.value
        return (
          <button
            key={t.value || 'all'}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.value)}
            className={clsx(
              'inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors',
              active ? 'bg-brand-50 font-semibold text-brand-800 ring-1 ring-inset ring-brand-200' : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900',
            )}
          >
            {t.label}
            {t.count !== undefined && (
              <span
                className={clsx(
                  'min-w-5 rounded-full px-1.5 text-center text-xs font-semibold tabular-nums leading-5',
                  t.attention && t.count > 0 ? 'bg-amber-100 text-amber-800' : active ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-600',
                )}
              >
                {t.count > 999 ? '999+' : t.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

/**
 * Two or three views of the same data (Timeline / Tabel, Jumlah / Nilai): a compact switch
 * where exactly one option is pressed.
 */
export function SegmentedControl<T extends string>({ label, options, value, onChange, size = 'md' }: { label: string; options: { value: T; label: string }[]; value: T; onChange: (value: T) => void; size?: 'sm' | 'md' }) {
  return (
    <div role="group" aria-label={label} className="inline-flex shrink-0 rounded-lg bg-slate-100 p-0.5">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          aria-pressed={value === o.value}
          onClick={() => onChange(o.value)}
          className={clsx(
            'rounded-md text-xs transition-colors',
            size === 'sm' ? 'px-2 py-1' : 'px-3 py-1.5',
            value === o.value ? 'bg-white font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200' : 'font-medium text-slate-600 hover:text-slate-900',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  )
}
