import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, Copy, Lock, Plus, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, TableSkeleton } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { PillTabs } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { errorMessage } from '../../lib/forms'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { typesApi, type Template, type TemplateCard, type TemplateCategory, type TransactionTypeDefinition } from './api'
import { FlowDocumentationView } from './FlowDocumentationView'
import { codeFromName } from './rulesLogic'

type Group = '' | TemplateCategory | 'system'


/**
 * "Jenis baru" (F08-01, F08-02): start from a ready-made template (the default), copy an existing
 * type, or start empty. Template cards show their purpose, field and approval counts and "Lihat
 * cara kerja" (the documentation, nothing is made). System types (F-07f) are edited in place or
 * copied; a copy keeps their core action (D-47).
 */
export function TransactionTypeStartPage() {
  const navigate = useNavigate()
  const canUseEditor = useCanUseEditor()
  const [group, setGroup] = useState<Group>('')
  const [reading, setReading] = useState<TemplateCard | null>(null)
  const [using, setUsing] = useState<Template | null>(null)
  const [copying, setCopying] = useState<TransactionTypeDefinition | 'pick' | null>(null)

  const templates = useQuery({ queryKey: ['/transaction-type-templates'], queryFn: () => typesApi.templates() })
  const types = useQuery({ queryKey: ['/transaction-types', 'with-system'], queryFn: () => typesApi.list({ include_system: '1' }) })
  const all = templates.data?.data ?? []
  const categories = templates.data?.meta.categories
  const system = (types.data?.data ?? []).filter((t) => t.system_type)
  const shown = group === 'system' ? [] : all.filter((t) => !group || t.category === group)

  const tabs = [
    { value: '' as Group, label: 'Semua', count: all.length + system.length },
    ...(Object.entries(categories ?? {}) as [TemplateCategory, string][]).map(([value, label]) => ({ value: value as Group, label, count: all.filter((t) => t.category === value).length })),
    { value: 'system' as Group, label: 'Jenis sistem', count: system.length },
  ]

  return (
    <>
      <PageHeader
        title="Buat jenis transaksi baru"
        description="Pilih template yang paling mirip kebutuhan Anda. Semua isian dapat diubah sebelum diterbitkan."
        actions={
          <Button variant="secondary" onClick={() => setCopying('pick')} disabled={!canUseEditor}>
            <Copy className="size-4" aria-hidden />
            Salin jenis yang ada
          </Button>
        }
      />
      {!canUseEditor && (
        <div className="mb-4">
          <Alert tone="info" title="Designer hanya di layar lebar">
            Di ponsel Anda dapat membaca cara kerja template. Menyusun jenis transaksi dilakukan di web pada layar lebar.
          </Alert>
        </div>
      )}
      <div className="mb-4">
        <PillTabs label="Kelompok template" tabs={tabs} value={group} onChange={setGroup} />
      </div>

      {templates.isPending ? (
        <TableSkeleton rows={4} columns={3} label="Memuat template…" />
      ) : templates.isError ? (
        <ErrorState error={templates.error} onRetry={() => templates.refetch()} />
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map((t) => (
            <li key={t.key} className="flex flex-col rounded-xl bg-white p-4 shadow-card ring-1 ring-slate-200">
              <div className="mb-1 flex items-start justify-between gap-2">
                <h2 className="font-semibold text-slate-900">{t.name}</h2>
                <Badge tone="blue">{categories?.[t.category] ?? t.category}</Badge>
              </div>
              <p className="flex-1 text-sm text-slate-600">{t.purpose}</p>
              <p className="mt-2 text-xs text-slate-500">
                {t.field_count} field · {t.approval_count ? `${t.approval_count} langkah persetujuan` : 'tanpa persetujuan'}
                {t.rules.length ? ` · ${t.rules.length} rule` : ''}
                {t.pair ? ` · dibuat bersama "${t.pair.name}"` : ''}
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                <Button variant="secondary" size="sm" onClick={() => setReading(t)}>
                  <BookOpen className="size-4" aria-hidden />
                  Lihat cara kerja
                </Button>
                <Button size="sm" onClick={() => setUsing(t)} disabled={!canUseEditor}>
                  <Sparkles className="size-4" aria-hidden />
                  Pakai template
                </Button>
              </div>
            </li>
          ))}
          {(group === '' || group === 'system') &&
            system.map((t) => (
              <li key={t.id} className="flex flex-col rounded-xl bg-white p-4 shadow-card ring-1 ring-slate-200">
                <div className="mb-1 flex items-start justify-between gap-2">
                  <h2 className="font-semibold text-slate-900">{t.name}</h2>
                  <Badge tone="slate">
                    <Lock className="size-3" aria-hidden />
                    Jenis sistem
                  </Badge>
                </div>
                <p className="flex-1 text-sm text-slate-600">{t.description}</p>
                <p className="mt-2 text-xs text-slate-500">Ubah langsung untuk menambah field atau persetujuan, atau salin sebagai jenis baru. Aksi intinya selalu ikut.</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  <ButtonLink to={`/transaksi/jenis/${t.id}`} variant="secondary" size="sm">
                    Ubah jenis sistem
                  </ButtonLink>
                  <Button size="sm" onClick={() => setCopying(t)} disabled={!canUseEditor}>
                    <Copy className="size-4" aria-hidden />
                    Salin sebagai jenis baru
                  </Button>
                </div>
              </li>
            ))}
          {group === '' && (
            <li className="flex flex-col rounded-xl border-2 border-dashed border-slate-300 p-4">
              <h2 className="mb-1 font-semibold text-slate-900">Transaksi custom</h2>
              <p className="flex-1 text-sm text-slate-600">Buat transaksi sesuai proses bisnis Anda, mulai dari formulir kosong.</p>
              <div className="mt-3">
                <ButtonLink to="/transaksi/jenis/baru/kosong" variant="secondary" size="sm">
                  <Plus className="size-4" aria-hidden />
                  Mulai dari kosong
                </ButtonLink>
              </div>
            </li>
          )}
        </ul>
      )}

      <Modal open={reading !== null} title={reading ? `Cara kerja: ${reading.name}` : ''} onClose={() => setReading(null)} size="xl"
        footer={<Button variant="secondary" onClick={() => setReading(null)}>Tutup</Button>}>
        {reading && <FlowDocumentationView doc={reading.documentation} />}
      </Modal>
      {using && <UseTemplateDialog template={using} onClose={() => setUsing(null)} onMade={(id) => navigate(`/transaksi/jenis/${id}/panduan`)} />}
      {copying && (
        <CopyDialog
          source={copying === 'pick' ? null : copying}
          types={(types.data?.data ?? []).filter((t) => !t.system_type)}
          onClose={() => setCopying(null)}
          onMade={(id) => navigate(`/transaksi/jenis/${id}/panduan`)}
        />
      )}
    </>
  )
}

/** "Pakai template" (F08-03): a draft type with the template's fields and flow; name and code may change. */
function UseTemplateDialog({ template, onClose, onMade }: { template: Template; onClose: () => void; onMade: (id: string) => void }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(template.name)
  const [code, setCode] = useState(template.code)
  const [pairName, setPairName] = useState(template.pair?.name ?? '')
  const [pairCode, setPairCode] = useState(template.pair?.code ?? '')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [year] = useState(() => new Date().getFullYear())
  const make = useMutation({
    mutationFn: () => typesApi.fromTemplate({ template: template.key, name, code, ...(template.pair ? { pair: { name: pairName, code: pairCode } } : {}) }),
    onSuccess: (r) => {
      queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success(`Draft "${r.data.name}" dibuat`, r.meta.pair ? `Bersama pasangannya "${r.meta.pair.name}".` : 'Sesuaikan isiannya, lalu terbitkan alurnya.')
      onMade(r.data.id)
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? e.fields : {})
      if (!(e instanceof ApiError) || Object.keys(e.fields).length === 0) toast.error('Template gagal dipakai', errorMessage(e))
    },
  })
  return (
    <Modal
      open
      title={`Pakai template ${template.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={make.isPending} onClick={() => make.mutate()}>
            Buat draft
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Nama transaksi" required error={errors.name?.[0]}>
          {(p) => <Input {...p} value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />}
        </Field>
        <Field label="Kode / prefix nomor" required error={errors.code?.[0]} hint={`Nomor transaksi: ${code.toUpperCase() || '…'}-${year}-000001`}>
          {(p) => <Input {...p} value={code} maxLength={6} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} />}
        </Field>
        {template.pair && (
          <div className="space-y-3 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
            <p className="text-sm text-slate-700">Dibuat bersama pasangannya, yang menutup transaksi ini (Berpasangan).</p>
            <Field label="Nama pasangan" required error={errors['pair.name']?.[0]}>
              {(p) => <Input {...p} value={pairName} maxLength={100} onChange={(e) => setPairName(e.target.value)} />}
            </Field>
            <Field label="Kode pasangan" required error={errors['pair.code']?.[0]}>
              {(p) => <Input {...p} value={pairCode} maxLength={6} onChange={(e) => setPairCode(e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} />}
            </Field>
          </div>
        )}
        {errors.template?.[0] && <Alert tone="error">{errors.template[0]}</Alert>}
        <p className="text-xs text-slate-500">Template tidak ikut berubah dan tidak terhubung dengan jenis yang Anda buat.</p>
      </div>
    </Modal>
  )
}

/** "Salin jenis yang ada" (F08-05, D-47): fields and the published flow (else the draft) of a type of this organization. */
function CopyDialog({ source, types, onClose, onMade }: { source: TransactionTypeDefinition | null; types: TransactionTypeDefinition[]; onClose: () => void; onMade: (id: string) => void }) {
  const queryClient = useQueryClient()
  const [sourceId, setSourceId] = useState(source?.id ?? '')
  const picked = source ?? types.find((t) => t.id === sourceId) ?? null
  const [name, setName] = useState(source ? `${source.name} (salinan)` : '')
  const [code, setCode] = useState(source ? codeFromName(source.name) : '')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const copy = useMutation({
    mutationFn: () => typesApi.duplicate(picked!.id, { name, code }),
    onSuccess: (r) => {
      queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success(`Draft "${r.data.name}" dibuat`, r.data.core_type ? 'Aksi inti jenis sistem ikut tersalin.' : undefined)
      onMade(r.data.id)
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? e.fields : {})
      if (!(e instanceof ApiError) || Object.keys(e.fields).length === 0) toast.error('Jenis gagal disalin', errorMessage(e))
    },
  })
  return (
    <Modal
      open
      title={source ? `Salin ${source.name}` : 'Salin jenis yang ada'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={copy.isPending} disabled={!picked} onClick={() => copy.mutate()}>
            Salin
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {!source && (
          <Field label="Jenis yang disalin" required>
            {(p) => (
              <Select
                {...p}
                value={sourceId}
                onChange={(e) => {
                  const t = types.find((x) => x.id === e.target.value)
                  setSourceId(e.target.value)
                  if (t) {
                    setName(`${t.name} (salinan)`)
                    setCode(codeFromName(t.name))
                  }
                }}
              >
                <option value="">— Pilih jenis —</option>
                {types.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} ({t.code})
                  </option>
                ))}
              </Select>
            )}
          </Field>
        )}
        <Field label="Nama transaksi" required error={errors.name?.[0]}>
          {(p) => <Input {...p} value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />}
        </Field>
        <Field label="Kode / prefix nomor" required error={errors.code?.[0]} hint="Diusulkan dari nama; 2–6 huruf.">
          {(p) => <Input {...p} value={code} maxLength={6} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} />}
        </Field>
        <p className="text-xs text-slate-500">Field, kategori, dan alur ikut tersalin sebagai draft. Jenis asalnya tidak berubah.</p>
      </div>
    </Modal>
  )
}
