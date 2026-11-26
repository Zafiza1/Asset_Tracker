import { keepPreviousData, useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Loader2, X } from 'lucide-react'
import { useEffect, useId, useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { api, qs } from '../../lib/api/client'
import type { AssetCustodian, CustodianOption } from './api'

const SEARCH_DELAY_MS = 250

/** Initials for the avatar circle ("Sari Dewi" → "SD"). */
function initials(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?'
}

export function PersonAvatar({ name, inactive, size = 'sm' }: { name: string | null | undefined; inactive?: boolean; size?: 'sm' | 'lg' }) {
  return (
    <span
      aria-hidden
      className={clsx(
        'grid shrink-0 place-items-center rounded-full font-semibold',
        size === 'lg' ? 'size-11 text-sm' : 'size-7 text-xs',
        inactive ? 'bg-slate-100 text-slate-400' : 'bg-brand-100 text-brand-700',
      )}
    >
      {initials(name)}
    </span>
  )
}

/** "Sari Dewi" with an "nonaktif" badge for a custodian who has since left. */
export function CustodianName({ custodian, detail }: { custodian: AssetCustodian; detail?: string | null }) {
  return (
    <span className="flex min-w-0 items-center gap-2">
      <PersonAvatar name={custodian.name} inactive={!custodian.is_active} />
      <span className="min-w-0">
        <span className="flex flex-wrap items-center gap-1">
          <span className="truncate">{custodian.name ?? '—'}</span>
          {!custodian.is_active && <Badge tone="amber">nonaktif</Badge>}
        </span>
        {(detail || !custodian.is_active) && (
          <span className="block truncate text-xs text-slate-500">{custodian.is_active ? detail : 'Perlu penanggung jawab baru'}</span>
        )}
      </span>
    </span>
  )
}

interface Props {
  id: string
  className: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
  /** The chosen member, or null. */
  value: AssetCustodian | null
  onChange: (value: CustodianOption | null) => void
}

/**
 * Search an active member of the organization by name or email (F-09). The list comes from
 * /assets/custodian-options, which only users who may set a custodian can read.
 */
export function CustodianPicker({ value, onChange, className, ...aria }: Props) {
  const listId = useId()
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [active, setActive] = useState(0)

  useEffect(() => {
    const timer = setTimeout(() => setTerm(text.trim()), SEARCH_DELAY_MS)
    return () => clearTimeout(timer)
  }, [text])

  const options = useQuery({
    queryKey: ['/assets/custodian-options', term],
    queryFn: async () => (await api.get<{ data: CustodianOption[] }>(`/assets/custodian-options${qs({ search: term || undefined })}`)).data,
    enabled: editing,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
  const rows = options.data ?? []

  const pick = (option: CustodianOption | null) => {
    onChange(option)
    setEditing(false)
    setText('')
  }

  if (value && !editing) {
    return (
      <div className={clsx(className, 'flex items-center gap-2 bg-white')} id={aria.id} aria-describedby={aria['aria-describedby']}>
        <span className="min-w-0 flex-1 text-sm">
          <CustodianName custodian={value} />
        </span>
        <button type="button" className="text-sm font-semibold text-brand-700 hover:underline" onClick={() => setEditing(true)}>
          Ganti
        </button>
        <button type="button" aria-label="Kosongkan penanggung jawab" className="rounded p-1 text-slate-500 hover:bg-slate-100" onClick={() => pick(null)}>
          <X className="size-4" aria-hidden />
        </button>
      </div>
    )
  }

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (rows.length > 0) setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (rows[active]) pick(rows[active])
    } else if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      setEditing(false)
      setText('')
    }
  }

  return (
    <div className="relative">
      <input
        {...aria}
        className={className}
        type="search"
        role="combobox"
        autoComplete="off"
        aria-expanded={editing}
        aria-controls={listId}
        aria-autocomplete="list"
        placeholder="Cari nama atau email anggota"
        value={text}
        onFocus={() => setEditing(true)}
        onBlur={() => setTimeout(() => setEditing(false), 150)}
        onChange={(e) => (setText(e.target.value), setActive(0), setEditing(true))}
        onKeyDown={onKeyDown}
      />
      {editing && (
        <ul id={listId} role="listbox" aria-label="Anggota business unit" className="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-lg bg-white p-1 shadow-raised ring-1 ring-slate-200">
          {options.isFetching && rows.length === 0 && (
            <li className="flex items-center gap-2 px-3 py-2 text-sm text-slate-500">
              <Loader2 className="size-4 animate-spin" aria-hidden />
              Mencari…
            </li>
          )}
          {options.isError && <li className="px-3 py-2 text-sm text-red-700">Daftar anggota gagal dimuat. Coba lagi.</li>}
          {!options.isFetching && !options.isError && rows.length === 0 && (
            <li className="px-3 py-2 text-sm text-slate-500">{term ? `Tidak ada anggota aktif yang cocok dengan “${term}”.` : 'Belum ada anggota aktif.'}</li>
          )}
          {rows.map((row, i) => (
            <li key={row.id} role="presentation">
              <button
                type="button"
                role="option"
                aria-selected={i === active}
                tabIndex={-1}
                onMouseDown={(e) => e.preventDefault()}
                onMouseMove={() => setActive(i)}
                onClick={() => pick(row)}
                className={clsx('flex w-full items-center gap-2 rounded-md px-2 py-2 text-left', i === active ? 'bg-brand-50' : 'hover:bg-slate-50')}
              >
                <PersonAvatar name={row.name} />
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium text-slate-900">{row.name}</span>
                  <span className="block truncate text-xs text-slate-500">{[row.job_title, row.home_department?.name, row.email].filter(Boolean).join(' · ')}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
