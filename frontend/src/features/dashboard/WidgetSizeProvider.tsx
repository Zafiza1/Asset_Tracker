import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import type { WidgetSize } from './layout'
import { effectiveSize, WidgetSizeContext } from './widgetSize'

/** Measures the widget's card and tells its content which view to draw. */
export function WidgetSizeProvider({ size, className, children }: { size: WidgetSize; className?: string; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState<number | null>(null)

  // Before paint, so a phone never flashes the wide view first.
  useLayoutEffect(() => {
    const element = ref.current
    if (!element) return
    setWidth(element.getBoundingClientRect().width || null)
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width || null))
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return (
    <div ref={ref} className={className}>
      <WidgetSizeContext.Provider value={effectiveSize(size, width)}>{children}</WidgetSizeContext.Provider>
    </div>
  )
}
