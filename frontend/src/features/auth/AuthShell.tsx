import { Boxes } from 'lucide-react'
import type { ReactNode } from 'react'

/** Centered card with the product mark, shared by the login and sign-up pages. */
export function AuthShell({ subtitle, children, footer, wide }: { subtitle: string; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-gradient-to-br from-brand-50 to-white px-4 py-8">
      <div className={wide ? 'w-full max-w-md' : 'w-full max-w-sm'}>
        <div className="mb-6 flex flex-col items-center gap-2 text-center">
          <span className="rounded-xl bg-brand-700 p-2.5 text-white">
            <Boxes className="size-7" aria-hidden />
          </span>
          <h1 className="text-xl font-semibold text-slate-900">Asset Tracker Enterprise</h1>
          <p className="text-sm text-slate-600">{subtitle}</p>
        </div>
        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">{children}</div>
        {footer && <p className="mt-4 text-center text-sm text-slate-600">{footer}</p>}
      </div>
    </div>
  )
}
