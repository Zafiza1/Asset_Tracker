import { Boxes, History, MapPin, ShieldCheck } from 'lucide-react'
import type { ReactNode } from 'react'

const highlights = [
  { icon: MapPin, text: 'Lokasi & pemegang setiap aset selalu tercatat' },
  { icon: History, text: 'Riwayat transaksi dan audit trail lengkap' },
  { icon: ShieldCheck, text: 'Akses berbasis role per organisasi' },
]

/** Product mark + form card, shared by the login and sign-up pages. A brand panel joins on wide screens. */
export function AuthShell({ subtitle, children, footer, wide }: { subtitle: string; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  return (
    <div className="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <aside className="relative hidden overflow-hidden bg-gradient-to-br from-brand-800 via-brand-900 to-brand-950 p-12 text-brand-100 lg:flex lg:flex-col lg:justify-between">
        {/* Decorative grid; carries no information. */}
        <div className="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:linear-gradient(white_1px,transparent_1px),linear-gradient(90deg,white_1px,transparent_1px)] [background-size:32px_32px]" aria-hidden />
        <div className="relative flex items-center gap-2.5 font-display font-semibold tracking-tight text-white">
          <span className="grid size-9 place-items-center rounded-lg bg-white/10 ring-1 ring-white/20">
            <Boxes className="size-5" aria-hidden />
          </span>
          Asset Tracker Enterprise
        </div>
        <div className="relative max-w-md">
          <p className="font-display text-3xl font-semibold leading-tight tracking-tight text-white">Kelola seluruh aset organisasi dalam satu tempat.</p>
          <ul className="mt-8 space-y-4">
            {highlights.map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-center gap-3 text-[0.9375rem]">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-white/10 text-brand-200">
                  <Icon className="size-4" aria-hidden />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-brand-300">Sistem manajemen aset untuk organisasi.</p>
      </aside>

      <main className="flex items-center justify-center bg-gradient-to-br from-brand-50 via-white to-white px-4 py-10 lg:bg-none lg:bg-slate-50">
        <div className={wide ? 'w-full max-w-md' : 'w-full max-w-sm'}>
          <div className="mb-6 flex flex-col items-center gap-2 text-center">
            <span className="rounded-xl bg-brand-700 p-2.5 text-white shadow-raised lg:hidden">
              <Boxes className="size-7" aria-hidden />
            </span>
            <h1 className="text-xl font-semibold text-slate-900 sm:text-2xl">Asset Tracker Enterprise</h1>
            <p className="text-sm text-slate-600">{subtitle}</p>
          </div>
          <div className="rounded-xl bg-white p-6 shadow-raised ring-1 ring-slate-200/80 sm:p-7">{children}</div>
          {footer && <p className="mt-5 text-center text-sm text-slate-600">{footer}</p>}
        </div>
      </main>
    </div>
  )
}
