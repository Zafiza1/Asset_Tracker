import { act, fireEvent, screen } from '@testing-library/react'
import { vi } from 'vitest'

/** Where the moved item started; the floating copy (DragOverlay) is measured there, as in a browser. */
let pickedUpAt = 0

/**
 * jsdom has no layout, so a SortableGrid cannot tell where its items are. This stacks the
 * labelled sections (cards, widgets) in one column in their current DOM order, 100px each, so
 * the keyboard moves of dnd-kit find the item above or below.
 */
export function stackSections() {
  return vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    const sections = Array.from(document.querySelectorAll('section[aria-label]'))
    const section = this.closest('section[aria-label]')
    const index = section ? sections.indexOf(section) : pickedUpAt
    const top = index * 120
    return { x: 0, y: top, top, left: 0, right: 300, bottom: top + 100, width: 300, height: 100, toJSON: () => ({}) } as DOMRect
  })
}

/** Moves an item the way a keyboard user does: its handle, Space, the arrow key `steps` times, Space. */
export async function moveWithKeyboard(name: string, key: 'ArrowUp' | 'ArrowDown', steps = 1) {
  const handle = screen.getByRole('button', { name: `Pindahkan ${name}` })
  pickedUpAt = Array.from(document.querySelectorAll('section[aria-label]')).indexOf(handle.closest('section[aria-label]')!)
  handle.focus()
  await act(async () => {
    fireEvent.keyDown(handle, { key: ' ', code: 'Space' })
    // dnd-kit starts listening for the arrow keys on the next tick.
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
  for (let i = 0; i < steps; i++) {
    await act(async () => {
      fireEvent.keyDown(document, { key, code: key })
    })
  }
  await act(async () => {
    fireEvent.keyDown(document, { key: ' ', code: 'Space' })
  })
}
