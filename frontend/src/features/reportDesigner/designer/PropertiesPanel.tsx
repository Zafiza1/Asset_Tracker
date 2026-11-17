import clsx from 'clsx'
import { ArrowDownToLine, ArrowUpToLine, Copy, Trash2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import type { ReportField } from '../api'
import {
  PAGE_SIZE_LABEL,
  TEXTUAL_TYPES,
  contentSizeMm,
  pageSizeMm,
  type ElementProps,
  type ElementStyle,
  type TableColumn,
} from '../design'
import type { DesignerState } from './useDesigner'

const inputCls =
  'block min-h-8 w-full rounded-md border-0 bg-white px-2 py-1 text-xs text-slate-900 ring-1 ring-inset ring-slate-300 transition-shadow placeholder:text-slate-400 hover:ring-slate-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-600 disabled:cursor-not-allowed disabled:bg-slate-100'

function Row({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="block">
      <span className="mb-0.5 block text-[11px] font-semibold text-slate-500">{label}</span>
      {children}
      {hint && <span className="mt-0.5 block text-[10px] leading-snug text-slate-400">{hint}</span>}
    </label>
  )
}

/** Number input committed on blur/Enter so typing doesn't flood the undo history. */
function NumInput({ value, onCommit, min, max, step = 0.5, disabled }: { value: number | undefined; onCommit: (v: number | undefined) => void; min?: number; max?: number; step?: number; disabled?: boolean }) {
  const [draft, setDraft] = useState<string | null>(null)
  return (
    <input
      type="number"
      className={inputCls}
      disabled={disabled}
      min={min}
      max={max}
      step={step}
      defaultValue={value ?? ''}
      key={value}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={() => {
        if (draft === null) return
        const n = draft === '' ? undefined : Number(draft)
        setDraft(null)
        if (n !== undefined && Number.isFinite(n)) onCommit(Math.min(max ?? Infinity, Math.max(min ?? -Infinity, n)))
        else if (n === undefined) onCommit(undefined)
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
      }}
    />
  )
}

/** Text input committed on blur so each finished edit is one undo step. */
function TextInput({ value, onCommit, placeholder, disabled, mono }: { value: string | undefined; onCommit: (v: string) => void; placeholder?: string; disabled?: boolean; mono?: boolean }) {
  return (
    <input
      type="text"
      className={clsx(inputCls, mono && 'font-mono')}
      disabled={disabled}
      placeholder={placeholder}
      defaultValue={value ?? ''}
      key={value}
      onBlur={(e) => {
        if (e.target.value !== (value ?? '')) onCommit(e.target.value)
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter') (e.target as HTMLInputElement).blur()
      }}
    />
  )
}

function AreaInput({ value, onCommit, placeholder, disabled, rows = 3 }: { value: string | undefined; onCommit: (v: string) => void; placeholder?: string; disabled?: boolean; rows?: number }) {
  return (
    <textarea
      className={clsx(inputCls, 'min-h-16 resize-y')}
      disabled={disabled}
      placeholder={placeholder}
      rows={rows}
      defaultValue={value ?? ''}
      key={value}
      onBlur={(e) => {
        if (e.target.value !== (value ?? '')) onCommit(e.target.value)
      }}
    />
  )
}

function SelectInput({ value, onCommit, options, disabled }: { value: string | undefined; onCommit: (v: string) => void; options: { value: string; label: string }[]; disabled?: boolean }) {
  return (
    <select className={inputCls} disabled={disabled} value={value ?? options[0]?.value ?? ''} onChange={(e) => onCommit(e.target.value)}>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-2 border-b border-slate-100 px-3 py-3">
      <h4 className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      {children}
    </section>
  )
}

interface PropertiesPanelProps {
  designer: DesignerState
  /** Registry fields of the template's own data source (for column pickers). */
  sourceFields: ReportField[]
  /** All active registry fields, for visibility/binding hints. */
  allFields: ReportField[]
  editable: boolean
  onOpenPageSettings: () => void
}

/** Right sidebar: properties of the selected element, or of the page when nothing is selected. */
export function PropertiesPanel({ designer, sourceFields, allFields, editable, onOpenPageSettings }: PropertiesPanelProps) {
  const el = designer.selected
  const page = designer.doc.page
  const patch = (p: Parameters<DesignerState['patchElement']>[1]) => el && designer.patchElement(el.id, p)
  const patchStyle = (style: ElementStyle) => patch({ style })
  const patchProps = (props: ElementProps) => patch({ props })

  const fieldOptions = sourceFields.map((f) => ({ value: f.field_key, label: f.label }))

  const columnsEditor = (columns: TableColumn[] | undefined, onChange: (cols: TableColumn[]) => void) => (
    <div className="space-y-1.5">
      {(columns ?? []).map((col, i) => (
        <div key={i} className="flex items-center gap-1">
          <input
            className={clsx(inputCls, 'w-24 shrink-0')}
            disabled={!editable}
            defaultValue={col.label}
            key={`${i}-${col.label}`}
            placeholder="Label"
            onBlur={(e) => {
              if (e.target.value !== col.label) onChange(columns!.map((c, j) => (j === i ? { ...c, label: e.target.value } : c)))
            }}
          />
          <select
            className={clsx(inputCls, 'min-w-0 flex-1')}
            disabled={!editable}
            value={col.key}
            onChange={(e) => onChange(columns!.map((c, j) => (j === i ? { ...c, key: e.target.value } : c)))}
            aria-label={`Field kolom ${i + 1}`}
          >
            {fieldOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
          <NumInput value={col.width} min={0} disabled={!editable} onCommit={(v) => onChange(columns!.map((c, j) => (j === i ? { ...c, width: v } : c)))} />
          <button
            type="button"
            disabled={!editable}
            onClick={() => onChange(columns!.filter((_, j) => j !== i))}
            className="grid size-7 shrink-0 place-items-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
            aria-label={`Hapus kolom ${i + 1}`}
          >
            <Trash2 className="size-3.5" aria-hidden />
          </button>
        </div>
      ))}
      <button
        type="button"
        disabled={!editable}
        onClick={() => onChange([...(columns ?? []), { key: fieldOptions[0]?.value ?? '', label: 'Kolom' }])}
        className="text-xs font-semibold text-brand-700 hover:underline disabled:opacity-40"
      >
        + Tambah kolom
      </button>
    </div>
  )

  if (!el) {
    const [pw, ph] = pageSizeMm(page)
    const [cw, ch] = contentSizeMm(page)
    return (
      <aside className="flex w-64 shrink-0 flex-col overflow-y-auto border-l border-slate-200 bg-white" aria-label="Properti halaman">
        <div className="flex-1 space-y-3 p-3">
          <section className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
            <p className="font-semibold text-slate-800">Tidak ada elemen terpilih</p>
            <p className="mt-1">Klik elemen di kanvas untuk mengubah propertinya, atau atur halaman di bawah.</p>
          </section>
          <dl className="space-y-1.5 text-xs">
            <div className="flex justify-between"><dt className="text-slate-500">Ukuran</dt><dd className="font-medium">{PAGE_SIZE_LABEL[page.size]} · {page.orientation === 'portrait' ? 'Potret' : 'Lanskap'}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Halaman</dt><dd className="font-medium tabular-nums">{pw} × {ph} mm</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Area konten</dt><dd className="font-medium tabular-nums">{cw} × {ch} mm</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Margin</dt><dd className="font-medium tabular-nums">{page.marginTop}/{page.marginRight}/{page.marginBottom}/{page.marginLeft} mm</dd></div>
          </dl>
        </div>
        <div className="border-t border-slate-100 p-3">
          <button type="button" onClick={onOpenPageSettings} className="w-full rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 hover:bg-brand-100">
            Pengaturan Halaman
          </button>
        </div>
      </aside>
    )
  }

  const isTextual = TEXTUAL_TYPES.has(el.type)
  const hasTypography = ['text', 'dynamic_text', 'header', 'footer', 'page_number', 'signature_area'].includes(el.type)
  const showBox = !['line', 'page_break', 'page_number', 'spacer'].includes(el.type)

  return (
    <aside className="flex w-64 shrink-0 flex-col overflow-y-auto border-l border-slate-200 bg-white" aria-label="Properti elemen">
      <div className="border-b border-slate-100 px-3 py-2.5">
        <p className="truncate text-sm font-semibold text-slate-800">{el.type.replace(/_/g, ' ')}</p>
        <p className="text-[11px] text-slate-400">{el.id.slice(0, 8)}</p>
      </div>
      <div className="flex-1">
        {(el.type === 'text' || el.type === 'dynamic_text' || el.type === 'header' || el.type === 'footer' || el.type === 'signature_area') && (
          <Section title="Konten">
            {el.type === 'dynamic_text' ? (
              <AreaInput value={el.props.text} disabled={!editable} onCommit={(v) => patchProps({ text: v })} placeholder="Teks dengan {{field.key}}" rows={4} />
            ) : (
              <AreaInput value={el.props.text} disabled={!editable} onCommit={(v) => patchProps({ text: v })} placeholder="Teks" rows={2} />
            )}
            <p className="text-[10px] leading-snug text-slate-400">
              Binding yang tersedia:{' '}
              <span className="font-mono">{allFields.slice(0, 6).map((f) => `{{${f.field_key}}}`).join(', ')}</span>
              {allFields.length > 6 ? '…' : ''}
            </p>
          </Section>
        )}
        {(el.type === 'image' || el.type === 'signature_image') && (
          <Section title="Konten">
            <Row label={el.type === 'signature_image' ? 'URL gambar tanda tangan' : 'URL gambar'}>
              <TextInput value={el.props.src} disabled={!editable} onCommit={(v) => patchProps({ src: v })} placeholder="https://… atau /storage/…" />
            </Row>
            <Row label="Teks alternatif">
              <TextInput value={el.props.alt} disabled={!editable} onCommit={(v) => patchProps({ alt: v })} />
            </Row>
          </Section>
        )}
        {el.type === 'qr_code' && (
          <Section title="Konten">
            <Row label="Isi kode QR" hint="Teks atau {{field.key}}">
              <TextInput value={el.props.qrValue} disabled={!editable} onCommit={(v) => patchProps({ qrValue: v })} mono />
            </Row>
          </Section>
        )}
        {el.type === 'page_number' && (
          <Section title="Konten">
            <Row label="Format">
              <SelectInput
                value={el.props.format ?? 'number'}
                disabled={!editable}
                onCommit={(v) => patchProps({ format: v as 'number' | 'number_total' })}
                options={[
                  { value: 'number', label: '1' },
                  { value: 'number_total', label: '1 / 5' },
                ]}
              />
            </Row>
          </Section>
        )}
        {(el.type === 'table' || el.type === 'repeating_section') && (
          <Section title={el.type === 'table' ? 'Kolom tabel' : 'Kolom & sumber data'}>
            {el.type === 'repeating_section' && (
              <Row label="Sumber berulang" hint="Field array dari registry (mis. atribut)">
                <TextInput value={el.props.dataPath} disabled={!editable} onCommit={(v) => patchProps({ dataPath: v })} mono placeholder="{{field.array}}" />
              </Row>
            )}
            {columnsEditor(el.props.columns, (cols) => patchProps({ columns: cols }))}
          </Section>
        )}
        {hasTypography && (
          <Section title="Tipografi">
            <div className="grid grid-cols-2 gap-2">
              <Row label="Font">
                <SelectInput
                  value={el.style.fontFamily ?? 'sans'}
                  disabled={!editable}
                  onCommit={(v) => patchStyle({ fontFamily: v as ElementStyle['fontFamily'] })}
                  options={[
                    { value: 'sans', label: 'Sans' },
                    { value: 'serif', label: 'Serif' },
                    { value: 'mono', label: 'Mono' },
                  ]}
                />
              </Row>
              <Row label="Ukuran (pt)">
                <NumInput value={el.style.fontSize} min={6} max={72} disabled={!editable} onCommit={(v) => patchStyle({ fontSize: v })} />
              </Row>
              <Row label="Tebal">
                <SelectInput
                  value={el.style.fontWeight ?? 'normal'}
                  disabled={!editable}
                  onCommit={(v) => patchStyle({ fontWeight: v as ElementStyle['fontWeight'] })}
                  options={[
                    { value: 'normal', label: 'Normal' },
                    { value: 'bold', label: 'Bold' },
                  ]}
                />
              </Row>
              <Row label="Rata">
                <SelectInput
                  value={el.style.align ?? 'left'}
                  disabled={!editable}
                  onCommit={(v) => patchStyle({ align: v as ElementStyle['align'] })}
                  options={[
                    { value: 'left', label: 'Kiri' },
                    { value: 'center', label: 'Tengah' },
                    { value: 'right', label: 'Kanan' },
                  ]}
                />
              </Row>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <Row label="Warna teks">
                <input type="color" className="h-8 w-full cursor-pointer rounded-md ring-1 ring-slate-300" disabled={!editable} value={el.style.color ?? '#0f172a'} onChange={(e) => patchStyle({ color: e.target.value })} aria-label="Warna teks" />
              </Row>
              <Row label="Warna latar">
                <input type="color" className="h-8 w-full cursor-pointer rounded-md ring-1 ring-slate-300" disabled={!editable} value={el.style.background ?? '#ffffff'} onChange={(e) => patchStyle({ background: e.target.value })} aria-label="Warna latar" />
              </Row>
            </div>
          </Section>
        )}
        {showBox && (
          <Section title="Tampilan">
            <div className="grid grid-cols-2 gap-2">
              <Row label="Padding (mm)">
                <NumInput value={el.style.padding} min={0} max={30} disabled={!editable} onCommit={(v) => patchStyle({ padding: v })} />
              </Row>
              <Row label="Radius (mm)">
                <NumInput value={el.style.radius} min={0} max={20} disabled={!editable} onCommit={(v) => patchStyle({ radius: v })} />
              </Row>
              <Row label="Border (mm)">
                <NumInput value={el.style.borderWidth} min={0} max={5} step={0.1} disabled={!editable} onCommit={(v) => patchStyle({ borderWidth: v })} />
              </Row>
              <Row label="Warna border">
                <input type="color" className="h-8 w-full cursor-pointer rounded-md ring-1 ring-slate-300" disabled={!editable} value={el.style.borderColor ?? '#cbd5e1'} onChange={(e) => patchStyle({ borderColor: e.target.value })} aria-label="Warna border" />
              </Row>
            </div>
          </Section>
        )}
        <Section title="Tata Letak (mm)">
          <div className="grid grid-cols-4 gap-1.5">
            <Row label="X"><NumInput value={el.x} min={0} disabled={!editable} onCommit={(v) => v !== undefined && patch({ x: v })} /></Row>
            <Row label="Y"><NumInput value={el.y} min={0} disabled={!editable} onCommit={(v) => v !== undefined && patch({ y: v })} /></Row>
            <Row label="L"><NumInput value={el.w} min={1} disabled={!editable} onCommit={(v) => v !== undefined && patch({ w: v })} /></Row>
            <Row label="T"><NumInput value={el.h} min={1} disabled={!editable} onCommit={(v) => v !== undefined && patch({ h: v })} /></Row>
          </div>
        </Section>
        {isTextual && (
          <Section title="Visibilitas">
            <Row label="Tampil jika" hint="Kosong = selalu tampil. Binding atau ekspresi.">
              <TextInput value={el.props.visibleWhen} disabled={!editable} onCommit={(v) => patchProps({ visibleWhen: v })} mono placeholder="{{asset.status}}" />
            </Row>
          </Section>
        )}
      </div>
      {editable && (
        <div className="space-y-1.5 border-t border-slate-100 p-3">
          <div className="flex gap-1.5">
            <button type="button" onClick={() => designer.duplicateElement(el.id)} className="flex flex-1 items-center justify-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50" title="Duplikat elemen">
              <Copy className="size-3.5" aria-hidden /> Duplikat
            </button>
            <button type="button" onClick={() => designer.reorder('front')} className="grid size-8 place-items-center rounded-md text-slate-500 ring-1 ring-inset ring-slate-200 hover:bg-slate-50" title="Bawa ke depan" aria-label="Bawa ke depan">
              <ArrowUpToLine className="size-3.5" aria-hidden />
            </button>
            <button type="button" onClick={() => designer.reorder('back')} className="grid size-8 place-items-center rounded-md text-slate-500 ring-1 ring-inset ring-slate-200 hover:bg-slate-50" title="Kirim ke belakang" aria-label="Kirim ke belakang">
              <ArrowDownToLine className="size-3.5" aria-hidden />
            </button>
          </div>
          <button type="button" onClick={() => designer.removeElement(el.id)} className="flex w-full items-center justify-center gap-1.5 rounded-md bg-red-50 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
            <Trash2 className="size-3.5" aria-hidden /> Hapus elemen
          </button>
        </div>
      )}
    </aside>
  )
}
