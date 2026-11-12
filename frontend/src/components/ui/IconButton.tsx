import { useState, type FocusEvent, type MouseEvent } from 'react'
import { createPortal } from 'react-dom'
import { Button, type ButtonProps } from './Button'

/**
 * Icon-only button with a visible tooltip on hover and keyboard focus. The tooltip is
 * rendered in a portal with fixed positioning, so table cells with `overflow-hidden`
 * cannot clip it. `label` is both the accessible name and the tooltip text.
 */
export function IconButton({ label, tooltip, onMouseEnter, onMouseLeave, onFocus, onBlur, onClick, size = 'sm', variant = 'ghost', ...rest }: Omit<ButtonProps, 'title' | 'aria-label'> & {
  /** Accessible name, e.g. "Ubah Laptop". */
  label: string
  /** Short visible text, e.g. "Ubah"; defaults to the label. */
  tooltip?: string
}) {
  const [tip, setTip] = useState<{ x: number; y: number } | null>(null)
  const show = (e: MouseEvent<HTMLButtonElement> | FocusEvent<HTMLButtonElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    setTip({ x: rect.left + rect.width / 2, y: rect.top })
  }

  return (
    <>
      <Button
        {...rest}
        size={size}
        variant={variant}
        // The tooltip repeats (part of) the name, so it is not also announced as a description.
        aria-label={label}
        onMouseEnter={(e) => (show(e), onMouseEnter?.(e))}
        onMouseLeave={(e) => (setTip(null), onMouseLeave?.(e))}
        onFocus={(e) => (e.currentTarget.matches(':focus-visible') && show(e), onFocus?.(e))}
        onBlur={(e) => (setTip(null), onBlur?.(e))}
        onKeyDown={(e) => e.key === 'Escape' && setTip(null)}
        onClick={(e) => (setTip(null), onClick?.(e))}
      />
      {tip &&
        createPortal(
          <span
            aria-hidden
            style={{ left: tip.x, top: tip.y }}
            className="pointer-events-none fixed z-[60] -translate-x-1/2 -translate-y-[calc(100%+6px)] whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-lg"
          >
            {tooltip ?? label}
          </span>,
          document.body,
        )}
    </>
  )
}
