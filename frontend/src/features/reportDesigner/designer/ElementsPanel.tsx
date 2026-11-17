import { useDraggable } from '@dnd-kit/core'
import clsx from 'clsx'
import {
  ArrowDownUp,
  Baseline,
  Box,
  Braces,
  Hash,
  Image as ImageIcon,
  Minus,
  PanelBottom,
  PanelTop,
  PenLine,
  QrCode,
  SeparatorHorizontal,
  Signature,
  Square,
  Table,
  Type,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReportField } from '../api'
import { ELEMENT_DEFS, type ElementType } from '../design'

const ICONS: Record<ElementType, LucideIcon> = {
  text: Type,
  dynamic_text: Braces,
  image: ImageIcon,
  table: Table,
  repeating_section: Baseline,
  line: Minus,
  rectangle: Square,
  container: Box,
  spacer: ArrowDownUp,
  header: PanelTop,
  footer: PanelBottom,
  page_number: Hash,
  page_break: SeparatorHorizontal,
  signature_image: PenLine,
  signature_area: Signature,
  qr_code: QrCode,
}

function PaletteItem({ type, label, description, onAdd }: { type: ElementType; label: string; description: string; onAdd: () => void }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: `palette-${type}`, data: { kind: 'palette', type } })
  const Icon = ICONS[type]
  return (
    <button
      ref={setNodeRef}
      type="button"
      {...listeners}
      {...attributes}
      onClick={onAdd}
      title={`${label} — ${description}`}
      className={clsx(
        'flex w-full items-center gap-2.5 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-left text-sm text-slate-700 shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50/50',
        isDragging && 'opacity-40',
      )}
    >
      <Icon className="size-4 shrink-0 text-slate-500" aria-hidden />
      <span className="min-w-0">
        <span className="block truncate font-medium">{label}</span>
        <span className="block truncate text-[11px] text-slate-500">{description}</span>
      </span>
    </button>
  )
}

function FieldItem({ field, onPick }: { field: ReportField; onPick: () => void }) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: `field-${field.id}`, data: { kind: 'field', fieldKey: field.field_key } })
  return (
    <button
      ref={setNodeRef}
      type="button"
      {...listeners}
      {...attributes}
      onClick={onPick}
      title={`Sisipkan ${field.field_key}`}
      className={clsx(
        'flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-xs text-slate-600 transition-colors hover:bg-brand-50 hover:text-brand-800',
        isDragging && 'opacity-40',
      )}
    >
      <span className="min-w-0">
        <span className="block truncate font-medium text-slate-800">{field.label}</span>
        <span className="block truncate font-mono text-[10px] text-slate-400">{`{{${field.field_key}}}`}</span>
      </span>
      {field.is_repeatable && <span className="shrink-0 rounded bg-slate-100 px-1 text-[9px] font-semibold text-slate-500">ULANG</span>}
    </button>
  )
}

interface ElementsPanelProps {
  /** Data sources to offer fields for: the template's source plus 'context'. */
  fieldGroups: { key: string; title: string; fields: ReportField[] }[]
  onAddElement: (type: ElementType) => void
  onInsertBinding: (fieldKey: string) => void
}

/** Left sidebar: draggable element palette and the Field Registry data panel. */
export function ElementsPanel({ fieldGroups, onAddElement, onInsertBinding }: ElementsPanelProps) {
  return (
    <aside className="flex w-60 shrink-0 flex-col gap-4 overflow-y-auto border-r border-slate-200 bg-white p-3" aria-label="Panel elemen dan data">
      <section>
        <h3 className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Elemen</h3>
        <div className="grid grid-cols-1 gap-1.5">
          {ELEMENT_DEFS.map((def) => (
            <PaletteItem key={def.type} type={def.type} label={def.label} description={def.description} onAdd={() => onAddElement(def.type)} />
          ))}
        </div>
      </section>
      <section>
        <h3 className="mb-1 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Field Data</h3>
        <p className="mb-2 px-1 text-[11px] leading-snug text-slate-400">
          Klik untuk menyisipkan ke elemen teks terpilih, atau seret ke kanvas. Hanya field dari registry yang tersedia.
        </p>
        <div className="space-y-3">
          {fieldGroups.map((group) => (
            <div key={group.key}>
              <p className="mb-1 px-1 text-[11px] font-semibold text-slate-500">{group.title}</p>
              <div className="space-y-0.5">
                {group.fields.map((f) => (
                  <FieldItem key={f.id} field={f} onPick={() => onInsertBinding(f.field_key)} />
                ))}
              </div>
            </div>
          ))}
        </div>
      </section>
      <p className="mt-auto px-1 text-[10px] leading-snug text-slate-400">
        Petunjuk: <span className="font-mono">{`{{field.key}}`}</span> adalah binding data. Teks dinamis, tabel, dan kode QR menerimanya.
      </p>
    </aside>
  )
}

