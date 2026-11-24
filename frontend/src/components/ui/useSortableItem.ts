import { useSortable } from '@dnd-kit/sortable'
import type { PointerEventHandler } from 'react'

/**
 * One item of a SortableGrid. With `enabled` false (not arranging) the item stays where it is.
 * Put `setNodeRef` and `itemProps` on the item, so it can be dragged from anywhere on it, and
 * give `handle` to a DragHandle (the keyboard way, and a visible hint that it can be dragged).
 */
export function useSortableItem(id: string, enabled: boolean) {
  const { setNodeRef, setActivatorNodeRef, attributes, listeners, isDragging } = useSortable({ id, disabled: !enabled })
  return {
    setNodeRef,
    dragging: isDragging,
    itemProps: enabled ? { onPointerDown: listeners?.onPointerDown as PointerEventHandler<HTMLElement> | undefined } : {},
    handle: enabled ? { setActivatorNodeRef, attributes, listeners } : null,
  }
}

export type SortableHandle = NonNullable<ReturnType<typeof useSortableItem>['handle']>
