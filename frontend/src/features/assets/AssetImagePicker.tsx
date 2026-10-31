import { ImageOff, ImagePlus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from '../../components/ui/Button'

/** Mirrors the server rule in AssetController::uploadImage. */
const ACCEPT = 'image/jpeg,image/png,image/webp'
const MAX_BYTES = 5 * 1024 * 1024

/**
 * Pending change to an asset's photo: `undefined` keeps the current one, a File replaces it,
 * `null` removes it.
 */
export type ImageChange = File | null | undefined

/** Photo preview with upload/replace/remove buttons; the file is sent when the form is saved. */
export function AssetImagePicker({ currentUrl, value, onChange, error }: { currentUrl: string | null; value: ImageChange; onChange: (value: ImageChange) => void; error?: string | null }) {
  const input = useRef<HTMLInputElement>(null)
  const [localError, setLocalError] = useState<string | null>(null)
  const previewUrl = useMemo(() => (value instanceof File ? URL.createObjectURL(value) : null), [value])
  useEffect(() => () => void (previewUrl && URL.revokeObjectURL(previewUrl)), [previewUrl])

  const shown = value === null ? null : (previewUrl ?? currentUrl)

  const pick = (file: File | undefined) => {
    if (input.current) input.current.value = ''
    if (!file) return
    if (!ACCEPT.split(',').includes(file.type)) return setLocalError('Gambar harus berformat JPG, PNG, atau WEBP.')
    if (file.size > MAX_BYTES) return setLocalError('Ukuran gambar maksimal 5 MB.')
    setLocalError(null)
    onChange(file)
  }

  const message = localError ?? error

  return (
    <div className="flex flex-wrap items-start gap-4">
      <div className="flex size-32 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
        {shown ? <img src={shown} alt="Gambar aset" className="size-full object-cover" /> : <ImageOff className="size-8 text-slate-400" aria-hidden />}
      </div>
      <div className="space-y-2">
        <p className="text-sm font-medium text-slate-700">Gambar aset</p>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" onClick={() => input.current?.click()}>
            <ImagePlus className="size-4" aria-hidden /> {shown ? 'Ganti gambar' : 'Unggah gambar'}
          </Button>
          {shown && (
            <Button size="sm" variant="ghost" onClick={() => (setLocalError(null), onChange(currentUrl ? null : undefined))}>
              <Trash2 className="size-4" aria-hidden /> Hapus
            </Button>
          )}
        </div>
        <p className="text-xs text-slate-500">JPG, PNG, atau WEBP, maksimal 5 MB.</p>
        {message && (
          <p className="text-xs text-red-600" role="alert">
            {message}
          </p>
        )}
        <input ref={input} type="file" accept={ACCEPT} className="hidden" aria-label="Pilih gambar aset" onChange={(e) => pick(e.target.files?.[0])} />
      </div>
    </div>
  )
}
