import { useSyncExternalStore } from 'react'

/** Tailwind `md`: editors (report designer, dashboard layout, transaction flows) need at least this width (D-34). */
export const EDITOR_MIN_WIDTH_QUERY = '(min-width: 768px)'

/**
 * Whether a CSS media query matches, updated when it changes. Without `matchMedia`
 * (tests, old browsers) the query counts as matching, so pages keep their full layout.
 */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      if (typeof window === 'undefined' || !window.matchMedia) return () => {}
      const list = window.matchMedia(query)
      list.addEventListener('change', onChange)
      return () => list.removeEventListener('change', onChange)
    },
    () => (typeof window === 'undefined' || !window.matchMedia ? true : window.matchMedia(query).matches),
    () => true,
  )
}

/** Editors are web-only on wide screens; phones show the finished result (D-34). */
export function useCanUseEditor(): boolean {
  return useMediaQuery(EDITOR_MIN_WIDTH_QUERY)
}
