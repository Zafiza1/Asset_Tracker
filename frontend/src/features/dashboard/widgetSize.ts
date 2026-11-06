import { createContext, useContext } from 'react'
import type { WidgetSize } from './layout'

const SIZES_ASCENDING: WidgetSize[] = ['quarter', 'third', 'half', 'two_thirds', 'full']

/** Narrowest card (px) each view still fits in. Tuned so every size holds on a 1280px desktop with the sidebar open. */
const MIN_WIDTH: Record<WidgetSize, number> = { quarter: 0, third: 0, half: 420, two_thirds: 580, full: 840 }

/**
 * The view a widget should draw: the size the user picked, stepped down while the card is
 * too narrow for it (tablets and phones stack widgets, so "Lebar penuh" can be 350px wide).
 * Never steps up, so a 1/3 widget stays the 1/3 view on a very wide monitor.
 */
export function effectiveSize(selected: WidgetSize, width: number | null): WidgetSize {
  if (!width) return selected
  let index = SIZES_ASCENDING.indexOf(selected)
  while (index > 0 && width < MIN_WIDTH[SIZES_ASCENDING[index]]) index--
  return SIZES_ASCENDING[index]
}

export const WidgetSizeContext = createContext<WidgetSize>('third')

/** The view the surrounding widget should draw. */
export const useWidgetSize = () => useContext(WidgetSizeContext)
