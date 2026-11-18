import clsx from 'clsx'
import { Braces, Image as ImageIcon, QrCode } from 'lucide-react'
import { useEffect, useState, type PointerEvent as ReactPointerEvent } from 'react'
import QRCode from 'qrcode'
import { PX_PER_MM, type DesignElement, type ElementType } from '../design'
import { textStyle } from './elementStyle'

/** Highlight {{…}} bindings inside dynamic text so they read as data, not literal text. */
function BindingText({ text }: { text: string }) {
  const parts = text.split(/(\{\{[^}]+\}\})/g)
  return (
    <>
      {parts.map((part, i) =>
        /^\{\{[^}]+\}\}$/.test(part) ? (
          <span key={i} className="rounded bg-brand-50 px-0.5 font-medium text-brand-700">
            {part}
          </span>
        ) : (
          <span key={i}>{part}</span>
        ),
      )}
    </>
  )
}

function TablePreview({ el }: { el: DesignElement }) {
  const columns = el.props.columns ?? []
  return (
    <div className="flex h-full w-full flex-col overflow-hidden text-[10px]">
      <div className="flex border-b border-slate-300 bg-slate-100 font-semibold text-slate-700">
        {columns.map((c, i) => (
          <div key={i} className="min-w-0 flex-1 truncate px-1.5 py-1" style={c.width ? { flex: `${c.width} 0 0` } : undefined} title={c.label}>
            {c.label}
          </div>
        ))}
      </div>
      {[0, 1].map((row) => (
        <div key={row} className="flex border-b border-slate-200">
          {columns.map((c, i) => (
            <div key={i} className="min-w-0 flex-1 truncate px-1.5 py-1 text-slate-500" style={c.width ? { flex: `${c.width} 0 0` } : undefined}>
              {row === 0 ? '—' : '…'}
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}

export function QrPreview({ value }: { value: string }) {
  const [src, setSrc] = useState<string | null>(null)
  useEffect(() => {
    let alive = true
    QRCode.toDataURL(value || ' ', { margin: 1, width: 96 })
      .then((url) => alive && setSrc(url))
      .catch(() => alive && setSrc(null))
    return () => {
      alive = false
    }
  }, [value])
  if (!src) {
    return (
      <div className="grid h-full w-full place-items-center bg-slate-50 text-slate-400">
        <QrCode className="size-6" aria-hidden />
      </div>
    )
  }
  return <img src={src} alt="Pratinjau kode QR" className="h-full w-full object-contain" />
}

function ElementBody({ el }: { el: DesignElement }) {
  const style = textStyle(el)
  switch (el.type as ElementType) {
    case 'text':
      return (
        <div style={style} className="whitespace-pre-wrap break-words">
          {el.props.text}
        </div>
      )
    case 'dynamic_text':
      return (
        <div style={style} className="whitespace-pre-wrap break-words">
          <BindingText text={el.props.text ?? ''} />
        </div>
      )
    case 'image':
    case 'signature_image':
      return el.props.src ? (
        <img src={el.props.src} alt={el.props.alt ?? ''} className="h-full w-full object-contain" draggable={false} />
      ) : (
        <div className="grid h-full w-full place-items-center rounded border border-dashed border-slate-300 bg-slate-50 text-slate-400">
          <span className="flex flex-col items-center gap-1 text-[10px]">
            <ImageIcon className="size-5" aria-hidden />
            {el.type === 'signature_image' ? 'Tanda tangan' : 'Gambar'}
          </span>
        </div>
      )
    case 'table':
      return <TablePreview el={el} />
    case 'repeating_section':
      return (
        <div className="flex h-full w-full flex-col overflow-hidden rounded border border-dashed border-brand-300 bg-brand-50/40 p-1">
          <div className="mb-1 flex items-center gap-1 text-[9px] font-semibold uppercase tracking-wide text-brand-700">
            <Braces className="size-3" aria-hidden />
            Ulangi{el.props.dataPath ? `: ${el.props.dataPath}` : ' — atur sumber data'}
          </div>
          <TablePreview el={el} />
        </div>
      )
    case 'line':
      return <div className="h-full w-full" style={{ borderTop: `${Math.max(el.h, 0.4)}mm solid ${el.style.color ?? '#94a3b8'}` }} />
    case 'rectangle':
      return <div className="h-full w-full" style={{ background: el.style.background ?? '#e2e8f0', border: el.style.borderWidth ? `${el.style.borderWidth}mm solid ${el.style.borderColor ?? '#cbd5e1'}` : undefined, borderRadius: el.style.radius ? `${el.style.radius}mm` : undefined }} />
    case 'container':
      return (
        <div className="flex h-full w-full flex-col items-center justify-center rounded border border-dashed border-slate-300 bg-slate-50/60 text-[10px] text-slate-400">
          Kontainer
        </div>
      )
    case 'spacer':
      return <div className="h-full w-full bg-[repeating-linear-gradient(45deg,transparent,transparent_4px,rgba(148,163,184,0.15)_4px,rgba(148,163,184,0.15)_8px)]" />
    case 'header':
    case 'footer':
      return (
        <div style={style} className="flex h-full w-full items-center overflow-hidden border-b border-slate-200 bg-slate-50/80 text-slate-500">
          <span className="text-[9px] font-semibold uppercase tracking-wider">{el.type === 'header' ? 'Header' : 'Footer'}</span>
          {el.props.text && <span className="ml-2 truncate">{el.props.text}</span>}
        </div>
      )
    case 'page_number':
      return (
        <div style={{ ...style, display: 'flex', alignItems: 'center', justifyContent: 'center' }} className="h-full w-full">
          {el.props.format === 'number_total' ? '1 / 1' : '1'}
        </div>
      )
    case 'page_break':
      return (
        <div className="flex h-full w-full items-center justify-center gap-2">
          <div className="h-0 flex-1 border-t border-dashed border-slate-400" />
          <span className="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Ganti halaman</span>
          <div className="h-0 flex-1 border-t border-dashed border-slate-400" />
        </div>
      )
    case 'signature_area':
      return (
        <div className="flex h-full w-full flex-col justify-between overflow-hidden rounded border border-slate-300 bg-white p-1 text-slate-500">
          <div className="truncate text-[9px] font-semibold uppercase tracking-wide">{el.props.text || 'Area Tanda Tangan'}</div>
          <div className="border-b border-slate-300" />
          <div className="flex justify-between text-[8px]">
            <span>Nama</span>
            <span>Jabatan</span>
            <span>Tanggal</span>
          </div>
        </div>
      )
    case 'qr_code':
      return <QrPreview value={el.props.qrValue ?? ''} />
    default:
      return null
  }
}

interface ElementViewProps {
  el: DesignElement
  selected: boolean
  editable: boolean
  zoom: number
  onSelect: (id: string | null) => void
  /** Live position update while dragging (transient; the page commits on release). */
  onMove: (id: string, x: number, y: number, transient: boolean) => void
}

/**
 * One element on the canvas. Dragging uses pointer events (not dnd-kit) so a move is
 * tracked in screen pixels and converted back to mm through the current zoom.
 */
export function ElementView({ el, selected, editable, zoom, onSelect, onMove }: ElementViewProps) {
  const px = PX_PER_MM
  const drag = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (!editable || e.button !== 0) return
    // Let form controls inside the element keep their own pointer behaviour.
    if ((e.target as HTMLElement).closest('input,textarea,select,button,a')) return
    e.preventDefault()
    onSelect(el.id)
    const startX = e.clientX
    const startY = e.clientY
    const origin = { x: el.x, y: el.y }
    const scale = px * zoom
    const move = (ev: PointerEvent) => {
      onMove(el.id, origin.x + (ev.clientX - startX) / scale, origin.y + (ev.clientY - startY) / scale, true)
    }
    const up = (ev: PointerEvent) => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      onMove(el.id, origin.x + (ev.clientX - startX) / scale, origin.y + (ev.clientY - startY) / scale, false)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  return (
    <div
      role="button"
      tabIndex={editable ? 0 : -1}
      aria-label={`Elemen ${el.type}`}
      aria-pressed={selected}
      onPointerDown={drag}
      onClick={(e) => {
        e.stopPropagation()
        onSelect(el.id)
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onSelect(el.id)
        }
      }}
      className={clsx(
        'absolute select-none overflow-hidden',
        editable && 'cursor-move',
        selected && 'ring-2 ring-brand-600 ring-offset-1',
      )}
      style={{
        left: `${el.x * px}px`,
        top: `${el.y * px}px`,
        width: `${el.w * px}px`,
        height: `${el.h * px}px`,
        zIndex: el.z,
      }}
    >
      <ElementBody el={el} />
      {selected && editable && (
        <span className="pointer-events-none absolute -top-5 left-0 rounded bg-brand-700 px-1.5 py-0.5 text-[10px] font-semibold text-white">
          {el.type}
        </span>
      )}
    </div>
  )
}
