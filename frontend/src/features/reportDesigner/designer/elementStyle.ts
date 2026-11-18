import type { CSSProperties } from 'react'
import type { ElementStyle } from '../design'

const FONT_FAMILY: Record<string, string> = {
  sans: 'Inter, system-ui, -apple-system, sans-serif',
  serif: 'Georgia, "Times New Roman", serif',
  mono: 'ui-monospace, "Cascadia Mono", Consolas, monospace',
}

/** Text styling of an element, shared by the designer canvas and the run preview. */
export function textStyle(el: { style: ElementStyle }): CSSProperties {
  const s = el.style
  return {
    fontFamily: s.fontFamily ? FONT_FAMILY[s.fontFamily] : undefined,
    fontSize: s.fontSize ? `${s.fontSize}pt` : undefined,
    fontWeight: s.fontWeight === 'bold' ? 700 : 400,
    color: s.color,
    background: s.background,
    textAlign: s.align,
    padding: s.padding ? `${s.padding}mm` : undefined,
    border: s.borderWidth ? `${s.borderWidth}mm solid ${s.borderColor ?? '#cbd5e1'}` : undefined,
    borderRadius: s.radius ? `${s.radius}mm` : undefined,
    lineHeight: 1.3,
    overflow: 'hidden',
  }
}
