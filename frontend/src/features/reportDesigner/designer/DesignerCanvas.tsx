import { useDroppable } from '@dnd-kit/core'
import clsx from 'clsx'
import { useEffect, useRef, type RefObject } from 'react'
import { PX_PER_MM, clampToContent, contentSizeMm, pageSizeMm, type DesignDocument } from '../design'
import { ElementView } from './ElementView'

interface DesignerCanvasProps {
  doc: DesignDocument
  selectedId: string | null
  editable: boolean
  zoom: number
  /** Attached to the unscaled page element so drops can be converted to mm. */
  pageRef: RefObject<HTMLDivElement | null>
  onSelect: (id: string | null) => void
  onMoveElement: (id: string, x: number, y: number, transient: boolean) => void
  /** Reports the zoom at which the whole page fits the visible area, whenever that area resizes. */
  onFitZoomChange?: (zoom: number) => void
}

/** Padding (px) kept around the page inside the scroll area; matches `p-6`. */
const CANVAS_PADDING = 24

/** The document page: a fixed-mm sheet scaled by zoom, with elements in its content box. */
export function DesignerCanvas({ doc, selectedId, editable, zoom, pageRef, onSelect, onMoveElement, onFitZoomChange }: DesignerCanvasProps) {
  const { setNodeRef, isOver } = useDroppable({ id: 'designer-canvas', disabled: !editable })
  const [pageW, pageH] = pageSizeMm(doc.page)
  const [contentW, contentH] = contentSizeMm(doc.page)
  const px = PX_PER_MM
  const scrollRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    const node = scrollRef.current
    if (!node || !onFitZoomChange || typeof ResizeObserver === 'undefined') return
    const report = () => {
      const width = node.clientWidth - CANVAS_PADDING * 2
      const height = node.clientHeight - CANVAS_PADDING * 2
      if (width <= 0 || height <= 0) return
      onFitZoomChange(Math.min(width / (pageW * px), height / (pageH * px)))
    }
    report()
    const observer = new ResizeObserver(report)
    observer.observe(node)
    return () => observer.disconnect()
  }, [onFitZoomChange, pageW, pageH, px])

  const move = (id: string, x: number, y: number, transient: boolean) => {
    const el = doc.elements.find((e) => e.id === id)
    if (!el) return
    const clamped = clampToContent({ ...el, x, y }, doc.page)
    onMoveElement(id, clamped.x, clamped.y, transient)
  }

  return (
    <div ref={scrollRef} className="flex min-w-0 flex-1 overflow-auto bg-slate-200/70 p-6" data-testid="designer-canvas-scroll">
      {/* m-auto centres the sheet when it is smaller than the area and keeps it scrollable when larger. */}
      <div className="relative m-auto shrink-0" style={{ width: pageW * px * zoom, height: pageH * px * zoom }}>
        <div
          ref={(node) => {
            pageRef.current = node
            setNodeRef(node)
          }}
          className={clsx('absolute left-0 top-0 overflow-hidden bg-white shadow-overlay ring-1 transition-shadow', isOver && editable && 'ring-2 ring-brand-500')}
          style={{
            width: pageW * px,
            height: pageH * px,
            transform: `scale(${zoom})`,
            transformOrigin: 'top left',
            background: doc.page.background ?? '#ffffff',
          }}
          onClick={() => onSelect(null)}
        >
          {/* Content box: where elements live (page minus margins). */}
          <div
            className="absolute"
            style={{
              left: doc.page.marginLeft * px,
              top: doc.page.marginTop * px,
              width: contentW * px,
              height: contentH * px,
            }}
          >
            {doc.elements.length === 0 && editable && (
              <p className="pointer-events-none absolute inset-0 grid place-items-center text-sm text-slate-400">
                Seret elemen dari panel kiri ke halaman, atau klik elemen untuk menambahkannya.
              </p>
            )}
            {doc.elements.map((el) => (
              <ElementView
                key={el.id}
                el={el}
                selected={el.id === selectedId}
                editable={editable}
                zoom={zoom}
                onSelect={onSelect}
                onMove={move}
              />
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
