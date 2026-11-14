import { keepPreviousData, useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowRight, Boxes, CornerDownLeft, Loader2, Search, type LucideIcon } from 'lucide-react'
import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { AssetStatusBadge } from '../features/assets/AssetStatusBadge'
import type { Asset } from '../features/assets/api'
import { api, qs } from '../lib/api/client'
import type { Paginated } from '../lib/api/types'
import type { NavSection } from './navigation'

interface Entry {
  key: string
  to: string
  title: string
  subtitle?: string
  icon: LucideIcon
  asset?: Asset
}

/** Assets shown in the palette; the last entry opens the full list for the rest. */
const ASSET_LIMIT = 6
const SEARCH_DELAY_MS = 250
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

/**
 * Search from anywhere: a field in the top bar (Ctrl/⌘+K, or "/") that finds assets by name,
 * asset number or serial number and jumps to any menu the user may open. With thousands of
 * assets, looking one up should not start with navigating to the right page.
 *
 * `sections` is the menu already filtered by permission, and assets are only searched with
 * `searchAssets` (asset.view), so the palette never offers what the user cannot open.
 */
export function GlobalSearch({ sections, searchAssets }: { sections: NavSection[]; searchAssets: boolean }) {
  const [open, setOpen] = useState(false)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = (e.target as HTMLElement | null)?.closest?.('input, textarea, select, [contenteditable="true"]')
      const shortcut = (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey)
      // Leave the shortcut alone while another dialog is open: one task at a time.
      if (!shortcut || document.querySelector('dialog[open]')) return
      e.preventDefault()
      setOpen(true)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  const label = searchAssets ? 'Cari aset atau menu' : 'Cari menu'
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={label}
        aria-keyshortcuts="Control+K"
        title={`${label} (${isMac ? '⌘' : 'Ctrl'}+K)`}
        className="grid size-10 shrink-0 place-items-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 md:flex md:w-64 md:justify-start md:gap-2 md:bg-slate-100/80 md:px-3 md:text-sm md:text-slate-500 md:ring-1 md:ring-inset md:ring-slate-200 md:hover:bg-white md:hover:ring-slate-300 xl:w-80"
      >
        <Search className="size-5 md:size-4" aria-hidden />
        <span className="hidden flex-1 truncate text-left md:block">{label}…</span>
        <kbd className="hidden rounded border border-slate-300 bg-white px-1.5 py-0.5 font-sans text-[0.6875rem] font-semibold text-slate-500 md:block">{isMac ? '⌘K' : 'Ctrl K'}</kbd>
      </button>
      {open && <SearchDialog sections={sections} searchAssets={searchAssets} label={label} onClose={() => setOpen(false)} />}
    </>
  )
}

function SearchDialog({ sections, searchAssets, label, onClose }: { sections: NavSection[]; searchAssets: boolean; label: string; onClose: () => void }) {
  const navigate = useNavigate()
  const dialog = useRef<HTMLDialogElement>(null)
  const list = useRef<HTMLUListElement>(null)
  const listId = useId()
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [active, setActive] = useState(0)

  useEffect(() => {
    const el = dialog.current
    if (el && !el.open) el.showModal?.()
  }, [])
  useEffect(() => {
    const timer = setTimeout(() => setTerm(text.trim()), SEARCH_DELAY_MS)
    return () => clearTimeout(timer)
  }, [text])

  const searching = searchAssets && term.length >= 2
  const assets = useQuery({
    queryKey: ['/assets', 'global-search', term],
    queryFn: () => api.get<Paginated<Asset>>(`/assets${qs({ search: term, per_page: ASSET_LIMIT })}`),
    enabled: searching,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  })

  const entries = useMemo<Entry[]>(() => {
    const needle = text.trim().toLowerCase()
    const pages = sections
      .flatMap((s) => s.items.map((i) => ({ key: `page:${i.to}:${i.label}`, to: i.to, title: i.label, subtitle: s.title && s.title !== i.label ? s.title : 'Menu', icon: i.icon })))
      .filter((p) => !needle || p.title.toLowerCase().includes(needle))
    if (!searching || !assets.data) return pages
    const found = assets.data.data.map<Entry>((a) => ({
      key: `asset:${a.id}`,
      to: `/aset/${a.id}`,
      title: a.name,
      subtitle: [a.serial_number, a.category?.name, a.location?.name].filter(Boolean).join(' · '),
      icon: Boxes,
      asset: a,
    }))
    const total = assets.data.meta.total
    const more: Entry[] = total > found.length ? [{ key: 'all', to: `/aset${qs({ search: term })}`, title: `Lihat semua ${total} aset yang cocok`, subtitle: 'Buka di Data Aset', icon: ArrowRight }] : []
    return [...found, ...more, ...pages]
  }, [sections, text, term, searching, assets.data])

  // Typing changes the list under the highlight: start again from the top.
  const [listKey, setListKey] = useState('')
  const nextKey = entries.map((e) => e.key).join('|')
  if (listKey !== nextKey) {
    setListKey(nextKey)
    setActive(0)
  }
  useEffect(() => {
    list.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' })
  }, [active])

  const go = (entry: Entry) => {
    onClose()
    navigate(entry.to)
  }
  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (entries.length > 0) setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : -1) + entries.length) % entries.length)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const entry = entries[active]
      if (entry) go(entry)
      // Enter before the results arrive still gets somewhere useful: the full asset list.
      else if (searchAssets && text.trim()) go({ key: 'all', to: `/aset${qs({ search: text.trim() })}`, title: '', icon: ArrowRight })
    }
  }

  const loading = searching && assets.isFetching
  const firstPage = entries.findIndex((e) => e.key.startsWith('page:'))
  const noAssets = searching && !assets.isFetching && assets.data?.data.length === 0

  return (
    <dialog
      ref={dialog}
      onClose={onClose}
      onClick={(e) => e.target === e.currentTarget && onClose()}
      aria-label={label}
      className="m-auto mt-[10dvh] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-xl p-0 shadow-overlay ring-1 ring-slate-900/5 open:animate-dialog-in backdrop:bg-slate-900/50 backdrop:backdrop-blur-[2px] open:backdrop:animate-fade-in"
    >
      <div className="flex max-h-[min(34rem,80dvh)] flex-col" onKeyDown={onKeyDown}>
        <div className="flex shrink-0 items-center gap-3 border-b border-slate-200 px-4">
          {loading ? <Loader2 className="size-5 shrink-0 animate-spin text-brand-600" aria-hidden /> : <Search className="size-5 shrink-0 text-slate-400" aria-hidden />}
          <input
            autoFocus
            type="search"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-autocomplete="list"
            aria-label={label}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={searchAssets ? 'Nama aset, no seri, atau nama menu' : 'Nama menu'}
            className="h-14 min-w-0 flex-1 border-0 bg-transparent text-base text-slate-900 outline-none placeholder:text-slate-400 focus:outline-none focus:ring-0 [&::-webkit-search-cancel-button]:appearance-none"
          />
          <kbd className="hidden rounded border border-slate-300 px-1.5 py-0.5 font-sans text-[0.6875rem] font-semibold text-slate-500 sm:block">Esc</kbd>
        </div>

        <ul ref={list} id={listId} role="listbox" aria-label="Hasil pencarian" className="flex-1 overflow-y-auto p-2">
          {assets.isError && <li className="px-3 py-2 text-sm text-red-700">Pencarian aset gagal. Coba lagi sebentar lagi.</li>}
          {noAssets && <li className="px-3 py-2 text-sm text-slate-500">Tidak ada aset yang cocok dengan “{term}”.</li>}
          {entries.map((entry, i) => (
            <li key={entry.key} role="presentation">
              {i === 0 && entry.asset && <GroupLabel>Aset</GroupLabel>}
              {i === firstPage && <GroupLabel>Menu</GroupLabel>}
              <button
                type="button"
                role="option"
                aria-selected={i === active}
                tabIndex={-1}
                onMouseMove={() => setActive(i)}
                onClick={() => go(entry)}
                className={clsx('flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-left', i === active ? 'bg-brand-50' : 'hover:bg-slate-50')}
              >
                <span className={clsx('grid size-8 shrink-0 place-items-center rounded-md', i === active ? 'bg-white text-brand-700 ring-1 ring-brand-200' : 'bg-slate-100 text-slate-500')} aria-hidden>
                  <entry.icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-900">{entry.title}</span>
                  {entry.subtitle && <span className="block truncate text-xs text-slate-500">{entry.subtitle}</span>}
                </span>
                {entry.asset && (
                  <span className="hidden shrink-0 gap-1 sm:flex">
                    <AssetStatusBadge status={entry.asset.status} archived={entry.asset.is_archived} />
                  </span>
                )}
                {i === active && <CornerDownLeft className="size-4 shrink-0 text-brand-600" aria-hidden />}
              </button>
            </li>
          ))}
          {entries.length === 0 && !noAssets && !loading && <li className="px-3 py-6 text-center text-sm text-slate-500">Tidak ada yang cocok.</li>}
        </ul>

        <p className="hidden shrink-0 items-center gap-4 border-t border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-500 sm:flex">
          <span>
            <kbd className="font-sans font-semibold">↑↓</kbd> pilih
          </span>
          <span>
            <kbd className="font-sans font-semibold">Enter</kbd> buka
          </span>
          {searchAssets && <span className="ml-auto">Ketik minimal 2 huruf untuk mencari aset</span>}
        </p>
      </div>
    </dialog>
  )
}

function GroupLabel({ children }: { children: string }) {
  return <p className="px-3 pb-1 pt-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-slate-400">{children}</p>
}
