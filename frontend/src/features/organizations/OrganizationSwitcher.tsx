import { Check, ChevronsUpDown, Plus } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useAuth } from '../../lib/auth/context'

/** Header control: shows the active organization and switches to another one of the same account. */
export function OrganizationSwitcher() {
  const { profile, switchOrganization } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [pending, setPending] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent | KeyboardEvent) => {
      if (e instanceof KeyboardEvent ? e.key === 'Escape' : !ref.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', close)
    return () => {
      document.removeEventListener('mousedown', close)
      document.removeEventListener('keydown', close)
    }
  }, [open])

  if (!profile) return null
  const active = profile.organization

  const choose = async (id: string) => {
    setOpen(false)
    if (id === active?.id) return
    setPending(true)
    try {
      await switchOrganization(id)
      navigate('/', { replace: true })
    } finally {
      setPending(false)
    }
  }

  return (
    <div ref={ref} className="relative min-w-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={pending}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="flex max-w-full items-center gap-2 rounded-md px-2 py-1 text-left hover:bg-slate-100 disabled:opacity-60"
      >
        <span className="min-w-0">
          <span className="block truncate text-sm font-semibold text-slate-900">{active?.name ?? 'Pilih organisasi'}</span>
          <span className="block truncate text-xs text-slate-500">{active?.code}</span>
        </span>
        <ChevronsUpDown className="size-4 shrink-0 text-slate-400" aria-hidden />
      </button>
      {open && (
        <div className="absolute left-0 z-30 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-200">
          <p className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Organisasi Anda</p>
          <ul role="listbox" aria-label="Organisasi Anda" className="max-h-72 overflow-y-auto">
            {profile.organizations.map((o) => (
              <li key={o.id} role="option" aria-selected={o.id === active?.id}>
                <button type="button" onClick={() => void choose(o.id)} className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-slate-800">{o.name}</span>
                    <span className="block text-xs text-slate-500">{o.code}</span>
                  </span>
                  {o.id === active?.id && <Check className="size-4 text-brand-700" aria-hidden />}
                </button>
              </li>
            ))}
          </ul>
          <div className="mt-1 border-t border-slate-100 pt-1">
            <Link to="/organisasi/baru" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-slate-50">
              <Plus className="size-4" aria-hidden />
              Buat organisasi baru
            </Link>
          </div>
        </div>
      )}
    </div>
  )
}
