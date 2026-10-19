import clsx from 'clsx'
import { Loader2 } from 'lucide-react'
import type { ButtonHTMLAttributes } from 'react'

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'

const variants: Record<Variant, string> = {
  primary: 'bg-brand-700 text-white shadow-card hover:bg-brand-800 active:bg-brand-900 disabled:bg-brand-700/50 disabled:shadow-none',
  secondary: 'bg-white text-slate-700 shadow-card ring-1 ring-inset ring-slate-300 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 disabled:text-slate-400 disabled:shadow-none',
  danger: 'bg-red-600 text-white shadow-card hover:bg-red-700 active:bg-red-800 disabled:bg-red-600/50 disabled:shadow-none',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 active:bg-slate-200 disabled:text-slate-400',
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: 'sm' | 'md'
  loading?: boolean
}

export function Button({ variant = 'primary', size = 'md', loading = false, disabled, className, children, type = 'button', ...rest }: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={clsx(
        'inline-flex touch-manipulation select-none items-center justify-center gap-2 rounded-lg font-semibold transition-colors duration-150 disabled:cursor-not-allowed',
        size === 'sm' ? 'min-h-8 px-2.5 py-1 text-sm' : 'min-h-10 px-4 py-2 text-sm',
        variants[variant],
        className,
      )}
      {...rest}
    >
      {loading && <Loader2 className="size-4 animate-spin" aria-hidden />}
      {children}
    </button>
  )
}
