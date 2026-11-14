import clsx from 'clsx'
import { Check, FolderTree, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { EmptyState, Skeleton } from '../../components/ui/Feedback'
import { Input } from '../../components/ui/Field'

export interface CategoryRow {
  id: string
  code: string
  name: string
  depth?: number
}

/** Each category with the names of its ancestors; rows arrive in tree order (parent before children). */
function withPaths(rows: CategoryRow[]) {
  const stack: string[] = []
  return rows.map((r) => {
    const depth = r.depth ?? 0
    stack.length = depth
    const path = stack.slice(0, depth).filter(Boolean)
    stack[depth] = r.name
    return { ...r, path }
  })
}

/**
 * Step one of registering an asset: the category decides which fields the form shows, so it is
 * chosen on its own before any data is entered.
 */
export function AssetCategoryPicker({
  rows,
  loading,
  value,
  onPick,
  changing,
}: {
  rows: CategoryRow[] | undefined
  loading: boolean
  value: string
  onPick: (id: string) => void
  /** True when returning from the form to change an already chosen category. */
  changing: boolean
}) {
  const [query, setQuery] = useState('')
  const all = useMemo(() => withPaths(rows ?? []), [rows])
  const q = query.trim().toLowerCase()
  const shown = q ? all.filter((r) => [r.name, r.code, ...r.path].some((s) => s.toLowerCase().includes(q))) : all

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-sm font-semibold text-slate-900">Langkah 1 dari 2 — Pilih kategori aset</h3>
        <p className="mt-1 text-sm text-slate-600">
          Kategori menentukan kolom data yang perlu diisi.{' '}
          {changing && 'Data yang sudah diisi tetap tersimpan; isian atribut khusus yang tidak ada di kategori baru tidak ikut disimpan.'}
        </p>
      </div>

      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden />
        <Input type="search" aria-label="Cari kategori" placeholder="Cari kategori…" value={query} onChange={(e) => setQuery(e.target.value)} className="pl-9" autoFocus />
      </div>

      {loading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Memuat kategori…">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : shown.length === 0 ? (
        <EmptyState icon={FolderTree} title={q ? 'Kategori tidak ditemukan' : 'Belum ada kategori aktif'} description={q ? 'Coba kata kunci lain.' : 'Tambahkan kategori di Master Data terlebih dahulu.'} />
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map((r) => {
            const selected = r.id === value
            return (
              <li key={r.id}>
                <button
                  type="button"
                  onClick={() => onPick(r.id)}
                  aria-pressed={selected}
                  className={clsx(
                    'flex h-full w-full items-start gap-3 rounded-xl p-4 text-left shadow-card ring-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600',
                    selected ? 'bg-brand-50 ring-brand-600' : 'bg-white ring-slate-200 hover:bg-slate-50 hover:ring-slate-300',
                  )}
                >
                  <span className={clsx('grid size-9 shrink-0 place-items-center rounded-lg', selected ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500')}>
                    {selected ? <Check className="size-4" aria-hidden /> : <FolderTree className="size-4" aria-hidden />}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-slate-900">{r.name}</span>
                    <span className="block text-xs text-slate-500">{r.code}</span>
                    {r.path.length > 0 && <span className="mt-1 block truncate text-xs text-slate-500">{r.path.join(' › ')}</span>}
                  </span>
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
