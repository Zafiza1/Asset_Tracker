export interface FilterChip {
  key: string
  label: string
  clear: () => void
}

/** Chip for the applied search keyword, if any. */
export function searchChip(term: string | null, clear: () => void): FilterChip[] {
  return term ? [{ key: 'search', label: `Kata kunci: "${term}"`, clear }] : []
}
