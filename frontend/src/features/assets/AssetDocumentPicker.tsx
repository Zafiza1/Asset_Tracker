import { FilePlus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { Button } from '../../components/ui/Button'

/** Mirrors the server rule in AssetController::uploadDocument. */
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png'
const MAX_BYTES = 10 * 1024 * 1024

/** Pending change to an asset's document: `undefined` keeps it, a File replaces it, `null` removes it. */
export type DocumentChange = File | null | undefined

/** Document name with upload/replace/remove buttons; the file is sent when the form is saved. */
export function AssetDocumentPicker({ currentName, value, onChange, error }: { currentName: string | null; value: DocumentChange; onChange: (value: DocumentChange) => void; error?: string | null }) {
  const input = useRef<HTMLInputElement>(null)
  const [localError, setLocalError] = useState<string | null>(null)
  const shown = value === null ? null : (value?.name ?? currentName)

  const pick = (file: File | undefined) => {
    if (input.current) input.current.value = ''
    if (!file) return
    if (file.size > MAX_BYTES) return setLocalError('Ukuran dokumen maksimal 10 MB.')
    setLocalError(null)
    onChange(file)
  }

  const message = localError ?? error

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium text-slate-700">Dokumen aset</p>
      {shown && <p className="break-all text-sm text-slate-600">{shown}</p>}
      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="secondary" onClick={() => input.current?.click()}>
          <FilePlus className="size-4" aria-hidden /> {shown ? 'Ganti dokumen' : 'Unggah dokumen'}
        </Button>
        {shown && (
          <Button size="sm" variant="ghost" onClick={() => (setLocalError(null), onChange(currentName ? null : undefined))}>
            <Trash2 className="size-4" aria-hidden /> Hapus
          </Button>
        )}
      </div>
      <p className="text-xs text-slate-500">PDF, Word, Excel, JPG, atau PNG. Maksimal 10 MB.</p>
      {message && <p className="text-sm text-red-600">{message}</p>}
      <input ref={input} type="file" accept={ACCEPT} className="hidden" aria-label="Pilih dokumen aset" onChange={(e) => pick(e.target.files?.[0])} />
    </div>
  )
}
