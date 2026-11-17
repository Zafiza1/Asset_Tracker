import { useEffect, useState } from 'react'
import { Button } from '../../../components/ui/Button'
import { Field, Input, Select } from '../../../components/ui/Field'
import { Modal } from '../../../components/ui/Modal'
import { PAGE_SIZE_LABEL, type PageConfig, type PageSize } from '../design'

const SIZES: PageSize[] = ['A4', 'A5', 'LETTER', 'LEGAL', 'CUSTOM']

/** Page setup dialog: size, orientation, margins and background — one undoable change. */
export function PageSettingsModal({ open, page, onClose, onApply }: { open: boolean; page: PageConfig; onClose: () => void; onApply: (page: PageConfig) => void }) {
  const [draft, setDraft] = useState<PageConfig>(page)
  // Re-seed the draft from the live page each time the dialog opens.
  useEffect(() => {
    if (open) setDraft(page)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only re-seed on open
  }, [open])

  const num = (key: 'customWidth' | 'customHeight' | 'marginTop' | 'marginRight' | 'marginBottom' | 'marginLeft') => ({
    value: draft[key] ?? '',
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => setDraft({ ...draft, [key]: e.target.value === '' ? undefined : Number(e.target.value) }),
  })

  const valid =
    (draft.size !== 'CUSTOM' || ((draft.customWidth ?? 0) > 0 && (draft.customHeight ?? 0) > 0)) &&
    [draft.marginTop, draft.marginRight, draft.marginBottom, draft.marginLeft].every((m) => m === undefined || (m >= 0 && m <= 100))

  return (
    <Modal
      open={open}
      title="Pengaturan Halaman"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Batal</Button>
          <Button
            disabled={!valid}
            onClick={() => {
              onApply({ ...draft, marginTop: draft.marginTop ?? 15, marginRight: draft.marginRight ?? 15, marginBottom: draft.marginBottom ?? 15, marginLeft: draft.marginLeft ?? 15 })
              onClose()
            }}
          >
            Terapkan
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        <Field label="Ukuran kertas">
          {(p) => (
            <Select {...p} value={draft.size} onChange={(e) => setDraft({ ...draft, size: e.target.value as PageSize })}>
              {SIZES.map((s) => (
                <option key={s} value={s}>{PAGE_SIZE_LABEL[s]}</option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Orientasi">
          {(p) => (
            <Select {...p} value={draft.orientation} onChange={(e) => setDraft({ ...draft, orientation: e.target.value as PageConfig['orientation'] })}>
              <option value="portrait">Potret</option>
              <option value="landscape">Lanskap</option>
            </Select>
          )}
        </Field>
        {draft.size === 'CUSTOM' && (
          <>
            <Field label="Lebar (mm)">
              {(p) => <Input {...p} type="number" min={10} max={2000} {...num('customWidth')} />}
            </Field>
            <Field label="Tinggi (mm)">
              {(p) => <Input {...p} type="number" min={10} max={2000} {...num('customHeight')} />}
            </Field>
          </>
        )}
      </div>
      <fieldset>
        <legend className="mb-1.5 text-sm font-semibold text-slate-700">Margin (mm)</legend>
        <div className="grid grid-cols-4 gap-2">
          {(['marginTop', 'marginRight', 'marginBottom', 'marginLeft'] as const).map((key, i) => (
            <Field key={key} label={['Atas', 'Kanan', 'Bawah', 'Kiri'][i]}>
              {(p) => <Input {...p} type="number" min={0} max={100} step={0.5} {...num(key)} />}
            </Field>
          ))}
        </div>
      </fieldset>
      <Field label="Warna latar halaman" hint="Kosongkan untuk putih.">
        {(p) => <Input {...p} type="color" value={draft.background ?? '#ffffff'} onChange={(e) => setDraft({ ...draft, background: e.target.value })} className="h-10 w-24 p-1" />}
      </Field>
    </Modal>
  )
}
