/**
 * Custom Report Designer — the design document stored in
 * `report_template_versions.design` (schema 1).
 *
 * Geometry is absolute, in millimetres, relative to the page's content box
 * (inside the margins). The PDF/Excel renderers (later phases) read this same
 * document, so the canvas here is the source of truth — never invent fields
 * the backend Field Registry does not know.
 */

export const PX_PER_MM = 4

export type ElementType =
  | 'text'
  | 'dynamic_text'
  | 'image'
  | 'table'
  | 'repeating_section'
  | 'line'
  | 'rectangle'
  | 'container'
  | 'spacer'
  | 'header'
  | 'footer'
  | 'page_number'
  | 'page_break'
  | 'signature_image'
  | 'signature_area'
  | 'qr_code'

export interface ElementStyle {
  fontFamily?: 'sans' | 'serif' | 'mono'
  /** Points, like print layouts. */
  fontSize?: number
  fontWeight?: 'normal' | 'bold'
  color?: string
  background?: string
  align?: 'left' | 'center' | 'right'
  /** Inner padding in mm. */
  padding?: number
  borderColor?: string
  /** Border width in mm (0.3 ≈ 1px on screen). */
  borderWidth?: number
  /** Corner radius in mm. */
  radius?: number
}

export interface TableColumn {
  /** Field key from the registry (e.g. 'asset.name'), or a literal label. */
  key: string
  label: string
  /** Relative width hint; equal columns when omitted. */
  width?: number
  /** Registry format hint: 'date', 'currency', 'number', 'labels'. */
  format?: string
}

export interface ElementProps {
  /** Static text, dynamic binding ({{…}}), or caption — depends on the element type. */
  text?: string
  /** Image URL/path for image and signature_image. */
  src?: string
  alt?: string
  /** Table / repeating_section columns. */
  columns?: TableColumn[]
  /** Repeating_section: bind path of the row collection (registry repeatable field). */
  dataPath?: string
  /** QR code payload — may contain {{…}} bindings. */
  qrValue?: string
  /** page_number: 'number' shows the page, 'number_total' adds the total. */
  format?: 'number' | 'number_total'
  /** Conditional visibility: a binding/expression; the element hides when it resolves falsy. */
  visibleWhen?: string
}

export interface DesignElement {
  id: string
  type: ElementType
  /** Position/size in mm inside the content box. */
  x: number
  y: number
  w: number
  h: number
  /** Paint order; later elements draw on top. */
  z: number
  style: ElementStyle
  props: ElementProps
}

export type PageSize = 'A4' | 'A5' | 'LETTER' | 'LEGAL' | 'CUSTOM'
export type Orientation = 'portrait' | 'landscape'

export interface PageConfig {
  size: PageSize
  orientation: Orientation
  /** mm — only used when size is CUSTOM. */
  customWidth?: number
  customHeight?: number
  marginTop: number
  marginRight: number
  marginBottom: number
  marginLeft: number
  background?: string
}

export interface DesignDocument {
  schema: 1
  page: PageConfig
  elements: DesignElement[]
}

const MM: Record<Exclude<PageSize, 'CUSTOM'>, [number, number]> = {
  A4: [210, 297],
  A5: [148, 210],
  LETTER: [215.9, 279.4],
  LEGAL: [215.9, 355.6],
}

export const PAGE_SIZE_LABEL: Record<PageSize, string> = {
  A4: 'A4',
  A5: 'A5',
  LETTER: 'Letter',
  LEGAL: 'Legal',
  CUSTOM: 'Kustom',
}

export const DEFAULT_PAGE: PageConfig = {
  size: 'A4',
  orientation: 'portrait',
  marginTop: 15,
  marginRight: 15,
  marginBottom: 15,
  marginLeft: 15,
}

/** Page size in mm after orientation is applied. */
export function pageSizeMm(page: PageConfig): [number, number] {
  const [w, h] = page.size === 'CUSTOM' ? [page.customWidth ?? 210, page.customHeight ?? 297] : MM[page.size]
  return page.orientation === 'landscape' ? [h, w] : [w, h]
}

/** Content box in mm (page minus margins). */
export function contentSizeMm(page: PageConfig): [number, number] {
  const [w, h] = pageSizeMm(page)
  return [Math.max(10, w - page.marginLeft - page.marginRight), Math.max(10, h - page.marginTop - page.marginBottom)]
}

export function defaultDesign(): DesignDocument {
  return { schema: 1, page: { ...DEFAULT_PAGE }, elements: [] }
}

export function uid(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `el-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/** A {{…}} binding token for a registry field key. */
export function binding(fieldKey: string): string {
  return `{{${fieldKey}}}`
}

interface ElementDef {
  type: ElementType
  label: string
  description: string
  w: number
  h: number
}

/** Palette order and default sizes (mm). */
export const ELEMENT_DEFS: ElementDef[] = [
  { type: 'text', label: 'Teks', description: 'Teks statis', w: 80, h: 10 },
  { type: 'dynamic_text', label: 'Teks Dinamis', description: 'Nilai dari field data', w: 80, h: 10 },
  { type: 'image', label: 'Gambar', description: 'Logo atau ilustrasi', w: 40, h: 30 },
  { type: 'table', label: 'Tabel', description: 'Kolom dari field registry', w: 170, h: 50 },
  { type: 'repeating_section', label: 'Bagian Berulang', description: 'Diulang per baris data', w: 170, h: 40 },
  { type: 'header', label: 'Header', description: 'Bagian atas halaman', w: 170, h: 14 },
  { type: 'footer', label: 'Footer', description: 'Bagian bawah halaman', w: 170, h: 14 },
  { type: 'page_number', label: 'Nomor Halaman', description: 'Penomoran halaman', w: 30, h: 8 },
  { type: 'line', label: 'Garis', description: 'Pemisah horizontal', w: 80, h: 1 },
  { type: 'rectangle', label: 'Persegi', description: 'Bentuk dengan warna', w: 40, h: 25 },
  { type: 'container', label: 'Kontainer', description: 'Kotak pengelompokan', w: 80, h: 35 },
  { type: 'spacer', label: 'Spasi', description: 'Jarak kosong', w: 20, h: 10 },
  { type: 'page_break', label: 'Ganti Halaman', description: 'Pemisah halaman', w: 170, h: 4 },
  { type: 'signature_image', label: 'Tanda Tangan', description: 'Gambar tanda tangan', w: 45, h: 25 },
  { type: 'signature_area', label: 'Area Tanda Tangan', description: 'Kotak nama, jabatan, tanggal', w: 65, h: 32 },
  { type: 'qr_code', label: 'Kode QR', description: 'QR dari teks atau field', w: 25, h: 25 },
]

const defOf = (type: ElementType): ElementDef => ELEMENT_DEFS.find((d) => d.type === type) ?? ELEMENT_DEFS[0]

/** Sensible default columns for the two V1 sources. */
export function defaultColumns(dataSource: string): TableColumn[] {
  if (dataSource === 'asset_transaction') {
    return [
      { key: 'transaction.number', label: 'No. Transaksi' },
      { key: 'transaction.date', label: 'Tanggal' },
      { key: 'transaction.type', label: 'Jenis' },
      { key: 'transaction.asset_name', label: 'Aset' },
    ]
  }
  return [
    { key: 'asset.asset_number', label: 'No. Aset' },
    { key: 'asset.name', label: 'Nama Aset' },
    { key: 'asset.status', label: 'Status' },
    { key: 'asset.location', label: 'Lokasi' },
  ]
}

/** Default element for a palette type, placed at (x, y) in mm. */
export function createElement(type: ElementType, dataSource: string, x = 15, y = 15): DesignElement {
  const def = defOf(type)
  const nextZ = 1
  const base: DesignElement = {
    id: uid(),
    type,
    x,
    y,
    w: def.w,
    h: def.h,
    z: nextZ,
    style: { fontSize: 10, color: '#0f172a' },
    props: {},
  }
  switch (type) {
    case 'text':
      return { ...base, props: { text: 'Teks' } }
    case 'dynamic_text':
      return { ...base, props: { text: binding('report.title') } }
    case 'image':
      return { ...base, props: { src: '', alt: 'Gambar' } }
    case 'table':
      return { ...base, props: { columns: defaultColumns(dataSource) } }
    case 'repeating_section':
      return { ...base, props: { dataPath: '', columns: defaultColumns(dataSource) } }
    case 'header':
      return { ...base, props: { text: 'Header' } }
    case 'footer':
      return { ...base, props: { text: 'Footer' } }
    case 'page_number':
      return { ...base, props: { format: 'number' } }
    case 'page_break':
      return { ...base, style: { color: '#64748b' }, props: {} }
    case 'signature_image':
      return { ...base, props: { src: '', alt: 'Tanda tangan' } }
    case 'signature_area':
      return { ...base, props: { text: 'Area Tanda Tangan' } }
    case 'qr_code':
      return { ...base, props: { qrValue: binding('organization.name') } }
    default:
      return base
  }
}

/** Elements that carry editable text/binding content. */
export const TEXTUAL_TYPES: ReadonlySet<ElementType> = new Set(['text', 'dynamic_text', 'header', 'footer', 'signature_area'])

/** Clamp a position so the element stays inside the content box horizontally. */
export function clampToContent(el: Pick<DesignElement, 'x' | 'y' | 'w' | 'h'>, page: PageConfig): { x: number; y: number } {
  const [cw] = contentSizeMm(page)
  const x = Math.min(Math.max(0, el.x), Math.max(0, cw - el.w))
  return { x: round1(x), y: Math.max(0, round1(el.y)) }
}

export function round1(n: number): number {
  return Math.round(n * 10) / 10
}
