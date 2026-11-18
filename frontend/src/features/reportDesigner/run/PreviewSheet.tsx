import { useEffect, useRef, useState } from 'react'
import type { PreviewElement } from '../api'
import { PX_PER_MM, pageSizeMm, type ElementStyle, type PageConfig } from '../design'
import { textStyle as designTextStyle } from '../designer/elementStyle'
import { QrPreview } from '../designer/ElementView'

const textStyle = (el: PreviewElement) => designTextStyle({ style: el.style as ElementStyle })

function PreviewTable({ el }: { el: PreviewElement }) {
  const columns = el.columns ?? []
  const rows = el.rows ?? []
  const flex = (width: number | null) => (width ? { flex: `${width} 0 0` } : undefined)
  return (
    <div className="flex h-full w-full flex-col overflow-hidden text-[10px]" style={textStyle(el)}>
      <div className="flex border-b border-slate-400 bg-slate-100 font-semibold text-slate-800">
        {columns.map((c) => (
          <div key={c.key} className="min-w-0 flex-1 truncate px-1.5 py-1" style={flex(c.width)} title={c.label}>
            {c.label}
          </div>
        ))}
      </div>
      {rows.length === 0 ? (
        <div className="px-1.5 py-2 text-slate-400">Tidak ada data.</div>
      ) : (
        rows.map((row, i) => (
          <div key={i} className="flex border-b border-slate-200">
            {columns.map((c) => (
              <div key={c.key} className="min-w-0 flex-1 truncate px-1.5 py-1 text-slate-800" style={flex(c.width)} title={row[c.key]}>
                {row[c.key] ?? ''}
              </div>
            ))}
          </div>
        ))
      )}
    </div>
  )
}

function PreviewBody({ el }: { el: PreviewElement }) {
  const style = textStyle(el)
  switch (el.type) {
    case 'text':
    case 'dynamic_text':
    case 'header':
    case 'footer':
      return (
        <div style={style} className="h-full w-full whitespace-pre-wrap break-words">
          {el.text}
        </div>
      )
    case 'signature_area':
      return (
        <div style={style} className="flex h-full w-full flex-col justify-between whitespace-pre-wrap break-words">
          <span>{el.text}</span>
          <span className="border-b border-slate-400" />
        </div>
      )
    case 'image':
    case 'signature_image':
      return el.src ? <img src={el.src} alt={el.alt ?? ''} className="h-full w-full object-contain" /> : null
    case 'table':
    case 'repeating_section':
      return <PreviewTable el={el} />
    case 'line':
      return <div className="h-full w-full" style={{ borderTop: `${Math.max(el.h, 0.4)}mm solid ${String(el.style.color ?? '#94a3b8')}` }} />
    case 'rectangle':
      return <div className="h-full w-full" style={{ ...style, background: String(el.style.background ?? '#e2e8f0') }} />
    case 'page_number':
      return (
        <div style={{ ...style, display: 'flex', alignItems: 'center', justifyContent: 'center' }} className="h-full w-full">
          {el.format === 'number_total' ? '1 / 1' : '1'}
        </div>
      )
    case 'qr_code':
      return <QrPreview value={el.qrValue ?? ''} />
    default:
      return null
  }
}

/**
 * The first page of a run preview: the template's sheet at its real size in mm, scaled down
 * to the available width. Tables show the sample rows the server sent; the files hold every row.
 */
export function PreviewSheet({ page, elements }: { page: PageConfig; elements: PreviewElement[] }) {
  const [pageW, pageH] = pageSizeMm(page)
  const boxRef = useRef<HTMLDivElement | null>(null)
  const [scale, setScale] = useState(1)

  useEffect(() => {
    const node = boxRef.current
    if (!node || typeof ResizeObserver === 'undefined') return
    const fit = () => node.clientWidth > 0 && setScale(Math.min(1, node.clientWidth / (pageW * PX_PER_MM)))
    fit()
    const observer = new ResizeObserver(fit)
    observer.observe(node)
    return () => observer.disconnect()
  }, [pageW])

  const px = PX_PER_MM
  return (
    <div ref={boxRef} className="w-full" data-testid="run-preview-sheet">
      <div className="relative mx-auto" style={{ width: pageW * px * scale, height: pageH * px * scale }}>
        <div
          className="absolute left-0 top-0 overflow-hidden bg-white shadow-overlay ring-1 ring-slate-200"
          style={{ width: pageW * px, height: pageH * px, transform: `scale(${scale})`, transformOrigin: 'top left', background: page.background ?? '#ffffff' }}
        >
          <div className="absolute" style={{ left: page.marginLeft * px, top: page.marginTop * px }}>
            {elements
              .filter((el) => el.visible)
              .map((el, i) => (
                <div
                  key={el.id ?? i}
                  className="absolute overflow-hidden"
                  style={{ left: el.x * px, top: el.y * px, width: el.w * px, height: el.h * px, zIndex: el.z }}
                >
                  <PreviewBody el={el} />
                </div>
              ))}
          </div>
        </div>
      </div>
    </div>
  )
}
