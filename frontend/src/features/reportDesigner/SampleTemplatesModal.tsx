import { useMutation, useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { ErrorState, Spinner } from '../../components/ui/Feedback'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { errorMessage } from '../../lib/forms'
import { dataSourceLabel, runApi, type ReportTemplate } from './api'

/** "Mulai dari contoh" (F-05 §3.5): copy a ready-made template into the organization as a draft. */
export function SampleTemplatesModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (template: ReportTemplate) => void }) {
  const [selected, setSelected] = useState<string | null>(null)
  const samples = useQuery({ queryKey: ['report-template-samples'], queryFn: runApi.samples, enabled: open, staleTime: 300_000 })
  const create = useMutation({
    mutationFn: (key: string) => runApi.fromSample(key),
    onSuccess: (res) => {
      toast.saved(res, 'Template dibuat dari contoh', 'Ubah formatnya sesuka Anda, lalu terbitkan.')
      setSelected(null)
      onCreated(res.data)
    },
    onError: (e) => toast.error('Gagal membuat template', errorMessage(e)),
  })

  return (
    <Modal
      open={open}
      title="Mulai dari contoh"
      size="xl"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button disabled={!selected} loading={create.isPending} onClick={() => selected && create.mutate(selected)}>
            Buat dari contoh
          </Button>
        </>
      }
    >
      <p className="mb-4 text-sm text-slate-600">Contoh disalin menjadi template draft milik organisasi. Ubah format dan datanya sesuka Anda sebelum diterbitkan.</p>
      {samples.isLoading ? (
        <Spinner label="Memuat contoh…" />
      ) : samples.isError ? (
        <ErrorState error={samples.error} onRetry={() => void samples.refetch()} />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Pilih contoh">
          {(samples.data?.data ?? []).map((s) => (
            <button
              key={s.key}
              type="button"
              role="radio"
              aria-checked={selected === s.key}
              onClick={() => setSelected(s.key)}
              className={clsx(
                'rounded-xl p-4 text-left ring-1 transition',
                selected === s.key ? 'bg-brand-50 ring-2 ring-brand-500' : 'bg-white ring-slate-200 hover:ring-brand-300',
              )}
            >
              <span className="block font-medium text-slate-900">{s.name}</span>
              <span className="mt-1 block text-sm text-slate-600">{s.description}</span>
              <span className="mt-2 block text-xs text-slate-500">Sumber data: {dataSourceLabel[s.data_source]}</span>
            </button>
          ))}
        </div>
      )}
    </Modal>
  )
}
