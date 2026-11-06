import { closestCenter, DndContext, KeyboardSensor, MouseSensor, TouchSensor, useSensor, useSensors, type Announcements, type DragEndEvent } from '@dnd-kit/core'
import { rectSortingStrategy, SortableContext, sortableKeyboardCoordinates, useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import clsx from 'clsx'
import { EyeOff, GripVertical } from 'lucide-react'
import type { ReactNode } from 'react'
import { SIZE_CLASS, SIZE_LABEL, widgetDef, type LayoutItem, type WidgetKey, type WidgetSize } from './layout'
import { WidgetSizeProvider } from './WidgetSizeProvider'

export const GRID_CLASS = 'grid grid-cols-1 gap-4 md:grid-cols-6 xl:grid-cols-12'

const title = (id: string | number) => widgetDef(id as WidgetKey).header

/** Screen reader text for keyboard dragging; dnd-kit's defaults are English. */
const announcements: Announcements = {
  onDragStart: ({ active }) => `Widget ${title(active.id)} diangkat.`,
  onDragOver: ({ active, over }) => (over ? `Widget ${title(active.id)} berada di posisi ${title(over.id)}.` : `Widget ${title(active.id)} di luar area.`),
  onDragEnd: ({ active, over }) => (over ? `Widget ${title(active.id)} dipindahkan ke posisi ${title(over.id)}.` : `Widget ${title(active.id)} dilepas.`),
  onDragCancel: ({ active }) => `Pemindahan ${title(active.id)} dibatalkan.`,
}
const screenReaderInstructions = {
  draggable: 'Tekan Spasi atau Enter untuk mengangkat widget, gunakan tombol panah untuk memindahkan, lalu Spasi atau Enter untuk meletakkan. Tekan Escape untuk membatalkan.',
}

interface WidgetGridProps {
  items: LayoutItem[]
  editing: boolean
  render: (key: WidgetKey) => ReactNode
  onMove: (key: WidgetKey, overKey: WidgetKey) => void
  onResize: (key: WidgetKey, size: WidgetSize) => void
  onHide: (key: WidgetKey) => void
}

/** Visible widgets in the user's order and widths; while editing they can be dragged, resized and hidden. */
export function WidgetGrid({ items, editing, render, onMove, onResize, onHide }: WidgetGridProps) {
  const sensors = useSensors(
    // Mouse drags start after a short move so the toolbar controls stay clickable;
    // touch waits for a long-press so the page still scrolls.
    useSensor(MouseSensor, { activationConstraint: { distance: 6 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (over && active.id !== over.id) onMove(active.id as WidgetKey, over.id as WidgetKey)
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd} accessibility={{ announcements, screenReaderInstructions }}>
      <SortableContext items={items.map((i) => i.key)} strategy={rectSortingStrategy} disabled={!editing}>
        <div className={GRID_CLASS}>
          {items.map((item) => (
            <SortableWidget key={item.key} item={item} editing={editing} onResize={onResize} onHide={onHide}>
              {render(item.key)}
            </SortableWidget>
          ))}
        </div>
      </SortableContext>
    </DndContext>
  )
}

function SortableWidget({ item, editing, onResize, onHide, children }: { item: LayoutItem; editing: boolean; onResize: WidgetGridProps['onResize']; onHide: WidgetGridProps['onHide']; children: ReactNode }) {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: item.key })
  const def = widgetDef(item.key)

  if (!editing) {
    return (
      <div ref={setNodeRef} className={clsx('min-w-0', SIZE_CLASS[item.size])}>
        <WidgetSizeProvider size={item.size} className="h-full">
          {children}
        </WidgetSizeProvider>
      </div>
    )
  }

  // The whole card drags with mouse or touch; the grip is the keyboard handle.
  return (
    <div
      ref={setNodeRef}
      {...listeners}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={clsx(
        'flex min-w-0 cursor-grab flex-col rounded-xl bg-slate-50 p-1.5 ring-2 ring-dashed transition-shadow',
        SIZE_CLASS[item.size],
        isDragging ? 'relative z-10 cursor-grabbing shadow-raised ring-brand-400' : 'ring-slate-300 hover:ring-brand-300',
      )}
    >
      <div className="flex items-center gap-1 pb-1.5">
        <button
          type="button"
          ref={setActivatorNodeRef}
          {...attributes}
          aria-label={`Pindahkan ${def.header}`}
          className="grid size-8 shrink-0 cursor-grab touch-none place-items-center rounded-md text-slate-500 hover:bg-white hover:text-slate-900"
        >
          <GripVertical className="size-4" aria-hidden />
        </button>
        <span className="min-w-0 flex-1 truncate text-xs font-medium text-slate-600">{def.header}</span>
        <select
          value={item.size}
          onChange={(e) => onResize(item.key, e.target.value as WidgetSize)}
          aria-label={`Ukuran ${def.header}`}
          className="h-8 rounded-md border-0 bg-white py-0 pl-2 pr-7 text-xs text-slate-700 ring-1 ring-slate-300"
        >
          {def.sizes.map((s) => (
            <option key={s} value={s}>
              {SIZE_LABEL[s]}
            </option>
          ))}
        </select>
        <button
          type="button"
          onClick={() => onHide(item.key)}
          aria-label={`Sembunyikan ${def.header}`}
          title="Sembunyikan"
          className="grid size-8 shrink-0 place-items-center rounded-md text-slate-500 hover:bg-white hover:text-slate-900"
        >
          <EyeOff className="size-4" aria-hidden />
        </button>
      </div>
      {/* Live preview only: links and tabs inside must not fire while arranging. */}
      <div className="pointer-events-none flex-1 select-none" inert>
        <WidgetSizeProvider size={item.size} className="h-full">
          {children}
        </WidgetSizeProvider>
      </div>
    </div>
  )
}
