import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  pointerWithin,
  useSensor,
  useSensors,
  type Announcements,
  type CollisionDetection,
  type DragOverEvent,
  type DragStartEvent,
  type UniqueIdentifier,
} from '@dnd-kit/core'
import { SortableContext, arrayMove, sortableKeyboardCoordinates, type SortingStrategy } from '@dnd-kit/sortable'
import clsx from 'clsx'
import { GripVertical } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import type { SortableHandle } from './useSortableItem'

/**
 * Items of a grid that the user puts in order by dragging them (Beranda cards, dashboard widgets).
 * The order changes while dragging, so the grid reflows with the items' real sizes (widgets have
 * different widths); a floating copy follows the pointer. Escape puts everything back.
 * Keyboard: focus an item's handle, Space or Enter to pick it up, arrow keys to move, Space or
 * Enter to drop.
 */
export function SortableGrid({
  ids,
  onReorder,
  nameOf,
  overlay,
  className,
  children,
}: {
  ids: string[]
  onReorder: (ids: string[]) => void
  /** Name of an item, for screen reader announcements. */
  nameOf: (id: string) => string
  /** The floating copy of the item being dragged. */
  overlay: (id: string) => ReactNode
  className?: string
  children: ReactNode
}) {
  const sensors = useSensors(
    // A small move first, so a click on the item's own buttons stays a click.
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const [active, setActive] = useState<string | null>(null)
  const before = useRef<string[]>([])

  const start = ({ active }: DragStartEvent) => {
    before.current = ids
    setActive(String(active.id))
  }
  const over = ({ active, over }: DragOverEvent) => {
    if (!over || active.id === over.id) return
    const from = ids.indexOf(String(active.id))
    const to = ids.indexOf(String(over.id))
    if (from >= 0 && to >= 0) onReorder(arrayMove(ids, from, to))
  }
  const name = (id: UniqueIdentifier) => nameOf(String(id))
  const position = (id: UniqueIdentifier) => `posisi ${ids.indexOf(String(id)) + 1} dari ${ids.length}`
  const announcements: Announcements = {
    onDragStart: ({ active }) => `${name(active.id)} diangkat, ${position(active.id)}.`,
    onDragOver: ({ active }) => `${name(active.id)} dipindah ke ${position(active.id)}.`,
    onDragEnd: ({ active }) => `${name(active.id)} diletakkan di ${position(active.id)}.`,
    onDragCancel: ({ active }) => `Pemindahan ${name(active.id)} dibatalkan.`,
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={collision}
      onDragStart={start}
      onDragOver={over}
      onDragEnd={() => setActive(null)}
      onDragCancel={() => {
        onReorder(before.current)
        setActive(null)
      }}
      accessibility={{
        announcements,
        screenReaderInstructions: {
          draggable: 'Tekan Spasi atau Enter untuk mengangkat. Pindahkan dengan tombol panah, lalu tekan Spasi atau Enter untuk meletakkan, atau Escape untuk batal.',
        },
      }}
    >
      <SortableContext items={ids} strategy={reflow}>
        <div className={className}>{children}</div>
      </SortableContext>
      <DragOverlay>{active ? overlay(active) : null}</DragOverlay>
    </DndContext>
  )
}

/** The grid reflows by itself (the order changes while dragging), so nothing is shifted by transforms. */
const reflow: SortingStrategy = () => null

/** The item under the pointer; with the keyboard (no pointer) the nearest one. */
const collision: CollisionDetection = (args) => {
  const hits = pointerWithin(args)
  return hits.length > 0 ? hits : closestCenter(args)
}

/** The grip of an item: drag it, or focus it and use Space and the arrow keys. */
export function DragHandle({ name, handle }: { name: string; handle: SortableHandle }) {
  const { setActivatorNodeRef, attributes, listeners } = handle
  return (
    <button
      type="button"
      ref={setActivatorNodeRef}
      {...attributes}
      {...listeners}
      aria-label={`Pindahkan ${name}`}
      title="Seret untuk memindahkan"
      className="-ml-1 inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 active:cursor-grabbing"
    >
      <GripVertical className="size-4" aria-hidden />
    </button>
  )
}

/** The floating copy of an item while it is dragged: its title on a raised card. */
export function DragPreview({ title, className }: { title: string; className?: string }) {
  return (
    <div className={clsx('flex h-full cursor-grabbing items-start gap-2 rounded-xl bg-white px-3 py-3 shadow-xl ring-2 ring-brand-400', className)}>
      <GripVertical className="mt-0.5 size-4 shrink-0 text-brand-500" aria-hidden />
      <span className="text-[0.9375rem] font-semibold text-slate-900">{title}</span>
    </div>
  )
}
