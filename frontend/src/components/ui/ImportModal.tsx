import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Download, Upload, X } from 'lucide-react'
import { Button } from './Button'
import { Alert } from './Feedback'
import { Modal } from './Modal'
import { api, ApiError, downloadFile } from '../../lib/api/client'

interface ImportModalProps {
  open: boolean
  onClose: () => void
  title: string
  /** POST endpoint that receives the file as `file`. */
  endpoint: string
  /** GET endpoint returning the .xlsx template. */
  templateEndpoint: string
  templateFilename: string
  onSuccess?: () => void
}

const MAX_SIZE = 5 * 1024 * 1024

export function ImportModal({ open, onClose, title, endpoint, templateEndpoint, templateFilename, onSuccess }: ImportModalProps) {
  const queryClient = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [importErrors, setImportErrors] = useState<string[]>([])
  const [downloading, setDownloading] = useState(false)

  const resetMessages = () => {
    setError(null)
    setImportErrors([])
  }

  const importMutation = useMutation({
    mutationFn: (form: FormData) => api.upload<{ data: { imported: number } }>(endpoint, form),
    onSuccess: async () => {
      await queryClient.invalidateQueries()
      onSuccess?.()
      onClose()
    },
    onError: (e) => {
      if (!(e instanceof ApiError)) {
        setError('Terjadi kesalahan saat mengimpor data.')
        return
      }
      const rowErrors = e.details.errors
      if (Array.isArray(rowErrors) && rowErrors.length > 0) {
        setError(e.message)
        setImportErrors(rowErrors.map(String))
      } else {
        setError(e.fields.file?.[0] ?? e.message)
      }
    },
  })

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0]
    // Allow choosing the same file again after fixing it.
    e.target.value = ''
    if (!selected) return
    resetMessages()
    if (!selected.name.toLowerCase().endsWith('.xlsx')) {
      setError('Hanya file Excel (.xlsx) yang diperbolehkan.')
      setFile(null)
      return
    }
    if (selected.size > MAX_SIZE) {
      setError('Ukuran file maksimal 5MB.')
      setFile(null)
      return
    }
    setFile(selected)
  }

  const handleDownloadTemplate = async () => {
    setDownloading(true)
    try {
      await downloadFile(templateEndpoint, templateFilename)
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Gagal mengunduh template.')
    } finally {
      setDownloading(false)
    }
  }

  const handleImport = () => {
    if (!file) {
      setError('Pilih file Excel terlebih dahulu.')
      return
    }
    const form = new FormData()
    form.append('file', file)
    resetMessages()
    importMutation.mutate(form)
  }

  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={handleDownloadTemplate} variant="secondary" loading={downloading}>
            <Download className="size-4" aria-hidden /> Download Template
          </Button>
          <Button onClick={handleImport} loading={importMutation.isPending} disabled={!file}>
            <Upload className="size-4" aria-hidden /> Import
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <Alert tone="error">
            <div className={importErrors.length > 0 ? 'mb-2 font-medium' : undefined}>{error}</div>
            {importErrors.length > 0 && (
              <ul className="max-h-48 list-inside list-disc space-y-1 overflow-y-auto text-sm">
                {importErrors.map((err, idx) => (
                  <li key={idx}>{err}</li>
                ))}
              </ul>
            )}
          </Alert>
        )}

        <div className="rounded-lg border-2 border-dashed border-slate-300 p-6 text-center">
          <input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={handleFileChange}
            className="hidden"
            id="file-upload"
            disabled={importMutation.isPending}
          />
          <label htmlFor="file-upload" className="cursor-pointer">
            <div className="flex flex-col items-center gap-2">
              <Upload className="size-8 text-slate-400" />
              <p className="text-sm text-slate-600">
                {file ? (
                  <span className="font-medium text-slate-900">{file.name}</span>
                ) : (
                  <>
                    Klik untuk memilih file <span className="font-medium">Excel (.xlsx)</span>
                  </>
                )}
              </p>
              <p className="text-xs text-slate-500">Maksimal ukuran file: 5MB</p>
            </div>
          </label>
          {file && (
            <button
              type="button"
              onClick={() => {
                setFile(null)
                resetMessages()
              }}
              className="mt-2 text-sm text-red-600 hover:text-red-700"
              disabled={importMutation.isPending}
            >
              <X className="inline size-3" /> Hapus file
            </button>
          )}
        </div>

        <div className="rounded-md bg-slate-50 p-3 text-sm text-slate-600">
          <p className="mb-1 font-medium text-slate-900">Instruksi:</p>
          <ol className="list-inside list-decimal space-y-1">
            <li>Download template Excel terlebih dahulu</li>
            <li>Isi data di sheet "Data". Kolom bertanda * wajib diisi; kolom berlatar biru muda punya dropdown</li>
            <li>Upload file Excel yang sudah diisi</li>
            <li>Jika ada baris yang salah, tidak ada data yang disimpan — perbaiki lalu upload ulang</li>
          </ol>
        </div>
      </div>
    </Modal>
  )
}
