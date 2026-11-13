import clsx from 'clsx'
import { Loader2 } from 'lucide-react'
import type { ButtonHTMLAttributes } from 'react'
import { Link, type LinkProps } from 'react-router'

/**
 * One meaning per variant, everywhere:
 *   primary   — the main action of a page or dialog (blue); at most one per view
 *   secondary — every other action (white with a border)
 *   danger    — removes, revokes or blocks something (red); always behind a confirmation
 *   ghost     — low-emphasis actions inside rows, cards and toolbars
 */
type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'
type Size = 'sm' | 'md'

const variants: Record<Variant, string> = {
  primary: 'bg-brand-700 text-white shadow-card hover:bg-brand-800 active:bg-brand-900 disabled:bg-brand-700/50 disabled:shadow-none',
  secondary: 'bg-white text-slate-700 shadow-card ring-1 ring-inset ring-slate-300 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 disabled:text-slate-400 disabled:shadow-none',
  danger: 'bg-red-600 text-white shadow-card hover:bg-red-700 active:bg-red-800 disabled:bg-red-600/50 disabled:shadow-none',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 active:bg-slate-200 disabled:text-slate-400',
}

function buttonClass(variant: Variant, size: Size, className?: string) {
  return clsx(
    'inline-flex touch-manipulation select-none items-center justify-center gap-2 rounded-lg font-semibold transition-colors duration-150 disabled:cursor-not-allowed',
    size === 'sm' ? 'min-h-8 px-2.5 py-1 text-sm' : 'min-h-10 px-4 py-2 text-sm',
    variants[variant],
    className,
  )
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
}

export function Button({ variant = 'primary', size = 'md', loading = false, disabled, className, children, type = 'button', ...rest }: ButtonProps) {
  return (
    <button type={type} disabled={disabled || loading} aria-busy={loading || undefined} className={buttonClass(variant, size, className)} {...rest}>
      {loading && <Loader2 className="size-4 animate-spin" aria-hidden />}
      {children}
    </button>
  )
}

/**
 * A link that looks like a button, for actions that open another page ("Undang pengguna",
 * "Kembali"). It is a real link — a button inside a link is invalid HTML and is announced
 * twice by screen readers — so it also opens in a new tab like any link.
 */
export function ButtonLink({ variant = 'primary', size = 'md', className, children, ...rest }: LinkProps & { variant?: Variant; size?: Size }) {
  return (
    <Link className={buttonClass(variant, size, className)} {...rest}>
      {children}
    </Link>
  )
}
