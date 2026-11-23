import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { AlertTriangle, ArrowLeft, ArrowRight, CheckCircle2, Lock, Plus, Rocket, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, DetailSkeleton, ErrorState } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { SegmentedControl } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { toOptions, useOptions } from '../master/options'
import {
  fieldTypeLabel,
  flowApi,
  isCoreNode,
  ruleOutcomeLabel,
  systemStarterGraph,
  typesApi,
  type FlowDocumentation,
  type FlowIssue,
  type FlowNode,
  type FlowOptions,
  type FlowState,
  type TransactionTypeDefinition,
  type TypeField,
} from './api'
import { DesignerNav } from './DesignerNav'
import { FlowDocumentationView } from './FlowDocumentationView'
import { ActionsEditor, FieldSelect, WhoPicker } from './FlowInputs'
import { ScenarioPanel } from './ScenarioPanel'
import { scenariosPassed, type ScenarioResult } from './scenarioLogic'
import { changesAsset, emptySimpleFlow, fromGraph, MAX_SIMPLE_APPROVALS, newStep, stages, toGraph, type SimpleFlow } from './simpleFlow'

const STEPS = ['Informasi', 'Formulir', 'Alur', 'Persetujuan', 'Aksi', 'Tinjau'] as const

/**
 * The Transaction Designer wizard (F08-06..09, simple mode F08-27/28): Informasi → Formulir →
 * Alur → Persetujuan → Aksi → Tinjau. Each "Lanjut" saves its part (the type, or the flow's draft
 * with its named rules); a flow of another shape is shown with why it needs the advanced mode
 * (the canvas) and is not changed here (F08-29). Wide screens only (D-34).
 */
export function TransactionWizardPage() {
  const { id = '' } = useParams()
  const type = useQuery({ queryKey: ['/transaction-types', id], queryFn: () => typesApi.get(id) })
  const flow = useQuery({ queryKey: ['/transaction-types', id, 'flow'], queryFn: () => flowApi.get(id) })
  if (type.isPending || flow.isPending) return <DetailSkeleton />
  if (type.isError || flow.isError) return <ErrorState error={type.error ?? flow.error} onRetry={() => (type.refetch(), flow.refetch())} />
  // Keyed by the version so the wizard starts again from what the server has after a publish.
  const version = flow.data.data.draft ?? flow.data.data.active
  return <Wizard key={`${version?.id ?? 'none'}`} type={type.data.data} state={flow.data.data} />
}

function startingFlow(type: TransactionTypeDefinition, state: FlowState): { flow: SimpleFlow | null; reasons: string[] } {
  const version = state.draft ?? state.active
  if (!version) {
    const core = type.system_type || type.core_type ? systemStarterGraph(type.name).nodes.find(isCoreNode) : undefined
    return { flow: emptySimpleFlow(core), reasons: [] }
  }
  return fromGraph(version.base_graph ?? version.graph)
}

function Wizard({ type, state }: { type: TransactionTypeDefinition; state: FlowState }) {
  const id = type.id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canUseEditor = useCanUseEditor()
  const canManage = can('transaction_flow.manage') && can('transaction_type.manage') && canUseEditor
  const [step, setStep] = useState(0)
  const [{ flow: initial, reasons }] = useState(() => startingFlow(type, state))
  const [flow, setFlow] = useState<SimpleFlow | null>(initial)
  const [dirty, setDirty] = useState(false)
  const [issues, setIssues] = useState<FlowIssue[]>(state.draft?.issues ?? [])
  const [info, setInfo] = useState({ name: type.name, code: type.code, description: type.description ?? '', multi_asset: type.multi_asset, category_ids: (type.categories ?? []).map((c) => c.id) })
  const [infoErrors, setInfoErrors] = useState<Record<string, string[]>>({})
  const version = state.draft ?? state.active
  const rules = version?.rules ?? []
  const system = !!type.system_type
  const fields = type.fields ?? []
  const options = useQuery({ queryKey: ['/transaction-flow-options'], queryFn: () => flowApi.options(), enabled: canManage })
  const doc = useQuery({ queryKey: ['/transaction-types', id, 'flow', 'describe'], queryFn: () => flowApi.describe(id), enabled: step === 5 || !flow })
  // A draft exists once the server has one, or after a step saved it here.
  const [savedDraft, setSavedDraft] = useState(!!state.draft)
  const [results, setResults] = useState<Record<string, ScenarioResult>>({})
  const [note, setNote] = useState<string | null>(null)
  // The flow the scenario results belong to: a result of another flow must be run again (F08-21).
  const flowKey = JSON.stringify([flow ? toGraph(flow) : (version?.graph ?? null), rules])
  const scenarios = useQuery({
    queryKey: ['/transaction-types', id, 'flow', 'scenarios', flowKey],
    queryFn: () => flowApi.scenarios(id),
    enabled: canManage && step === 5 && !dirty && savedDraft,
  })
  const suggestion = doc.data?.data.changes.length ? doc.data.data.changes.join('\n') : state.active ? '' : 'Versi pertama.'
  const changeNote = note ?? suggestion

  const change = (next: SimpleFlow) => (setFlow(next), setDirty(true))
  const saveInfo = useMutation({
    mutationFn: () => typesApi.update(id, { name: info.name, code: info.code, description: info.description || null, multi_asset: info.multi_asset, category_ids: info.category_ids }),
    onSuccess: () => (setInfoErrors({}), queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })),
  })
  const saveFlow = useMutation({
    mutationFn: () => flowApi.saveRules(id, toGraph(flow!), rules),
    onSuccess: (r) => {
      setIssues(r.data.issues ?? [])
      setDirty(false)
      setSavedDraft(true)
      queryClient.invalidateQueries({ queryKey: ['/transaction-types', id, 'flow', 'describe'] })
    },
  })
  const publish = useMutation({
    mutationFn: async () => {
      // The change note (F08-25) is kept with the draft that is published.
      const changeNoteText = changeNote.trim() || undefined
      if (flow) await flowApi.saveRules(id, toGraph(flow), rules, changeNoteText)
      else if (version && version.status === 'draft') {
        await (version.graph_source === 'rules' ? flowApi.saveRules(id, version.base_graph ?? version.graph, rules, changeNoteText) : flowApi.save(id, version.graph, changeNoteText))
      }
      const published = await flowApi.publish(id)
      if (type.status === 'draft') await typesApi.update(id, { status: 'active' })
      return published
    },
    onSuccess: (r) => {
      queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success(`Alur v${r.data.version_number} diterbitkan`, 'Transaksi baru jenis ini mengikuti alur ini.')
      navigate(`/transaksi/jenis/${id}`)
    },
    onError: (e) => {
      if (e instanceof ApiError && Array.isArray((e.details as { issues?: FlowIssue[] })?.issues)) setIssues((e.details as { issues: FlowIssue[] }).issues)
      toast.error('Belum dapat diterbitkan', errorMessage(e))
    },
  })

  const go = async (to: number) => {
    try {
      if (canManage && step === 0 && !system) await saveInfo.mutateAsync()
      if (canManage && step >= 2 && step <= 4 && flow && dirty) await saveFlow.mutateAsync()
      setStep(to)
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fields).length) setInfoErrors(e.fields)
      toast.error('Langkah ini belum tersimpan', errorMessage(e))
    }
  }
  const busy = saveInfo.isPending || saveFlow.isPending
  // F08-09: nothing to fix, and every scenario that can run has run on this flow and passed.
  const problems = (scenarios.data?.data ?? []).filter((x) => x.status === 'problem').length
  const passed = scenariosPassed(scenarios.data?.data, results, flowKey)
  const readyToPublish = savedDraft && issues.length === 0 && problems === 0 && passed
  const blocker = !savedDraft
    ? 'Belum ada perubahan untuk diterbitkan.'
    : issues.length > 0
      ? `Lengkapi ${issues.length} isian di atas.`
      : problems > 0
        ? 'Ada jalur yang berhenti sebelum Selesai.'
        : 'Jalankan semua skenario terlebih dahulu.'

  return (
    <>
      <PageHeader
        title={type.name}
        badges={
          <>
            {state.active && <Badge tone="green">Terbit v{state.active.version_number}</Badge>}
            {(state.draft || dirty) && <Badge tone="amber">Draft{dirty ? ' · belum disimpan' : ''}</Badge>}
          </>
        }
        description={type.source_template_key ? 'Dibuat dari template. Sesuaikan setiap langkah, lalu terbitkan.' : 'Ikuti langkahnya; setiap langkah punya jawaban bawaan yang aman.'}
        actions={
          <ButtonLink to={`/transaksi/jenis/${id}`} variant="secondary">
            Keluar, lanjutkan nanti
          </ButtonLink>
        }
      />
      <DesignerNav typeId={id} ruleCount={rules.length} />
      {!canUseEditor && (
        <div className="mb-4">
          <Alert tone="info" title="Designer hanya di layar lebar">
            Di ponsel Anda dapat melihat cara kerja jenis ini. Menyusun transaksi dilakukan di web pada layar lebar.
          </Alert>
        </div>
      )}

      <ol className="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-6" aria-label="Langkah">
        {STEPS.map((label, i) => (
          <li key={label}>
            <button
              type="button"
              aria-current={i === step ? 'step' : undefined}
              onClick={() => go(i)}
              disabled={busy}
              className={clsx('flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm ring-1', i === step ? 'bg-brand-50 font-semibold text-brand-800 ring-brand-300' : 'bg-white text-slate-600 ring-slate-200 hover:text-slate-900')}
            >
              <span className={clsx('inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums', i < step ? 'bg-green-100 text-green-700' : i === step ? 'bg-brand-700 text-white' : 'bg-slate-100')}>{i + 1}</span>
              {label}
            </button>
          </li>
        ))}
      </ol>

      <Card title={`${STEPS[step]} · Langkah ${step + 1} dari ${STEPS.length}`}>
        <div className="space-y-4 p-4">
          {step === 0 && <InfoStep type={type} info={info} errors={infoErrors} editable={canManage && !system} onChange={setInfo} />}
          {step === 1 && <FormStep fields={fields} flow={flow} typeId={id} />}
          {step >= 2 && step <= 4 && !flow && <Advanced typeId={id} reasons={reasons} />}
          {step === 2 && flow && <FlowStep flow={flow} fields={fields} options={options.data?.data} editable={canManage} onChange={change} typeId={id} />}
          {step === 3 && flow && <ApprovalStep flow={flow} fields={fields} options={options.data?.data} editable={canManage} onChange={change} rules={rules} typeId={id} />}
          {step === 4 && flow && <ActionStep flow={flow} fields={fields} options={options.data?.data} editable={canManage} onChange={change} />}
          {step === 5 && (
            <ReviewStep issues={issues} flow={flow} doc={doc.data?.data} loading={doc.isPending}>
              {canManage && (
                <>
                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold text-slate-800">Simulasikan transaksi</h3>
                    <ScenarioPanel typeId={id} flowKey={flowKey} query={scenarios} results={results} onResults={setResults} enabled={savedDraft && !dirty} />
                  </section>
                  {savedDraft && (
                    <Field label="Catatan perubahan" hint="Diisi otomatis dari perbedaan dengan versi sebelumnya; boleh diubah.">
                      {(p) => <Textarea {...p} rows={4} maxLength={500} value={changeNote} onChange={(e) => setNote(e.target.value)} />}
                    </Field>
                  )}
                </>
              )}
            </ReviewStep>
          )}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
          <Button variant="secondary" disabled={step === 0 || busy} onClick={() => go(step - 1)}>
            <ArrowLeft className="size-4" aria-hidden />
            Kembali
          </Button>
          {step < STEPS.length - 1 ? (
            <Button loading={busy} onClick={() => go(step + 1)}>
              Lanjut
              <ArrowRight className="size-4" aria-hidden />
            </Button>
          ) : (
            canManage && (
              <span className="flex flex-wrap items-center justify-end gap-2">
                {!readyToPublish && <span className="text-sm text-slate-600">{blocker}</span>}
              <Button loading={publish.isPending} disabled={!readyToPublish} onClick={() => publish.mutate()}>
                <Rocket className="size-4" aria-hidden />
                Terbitkan v{(state.draft?.version_number ?? (state.active ? state.active.version_number + 1 : 1))}
              </Button>
              </span>
            )
          )}
        </div>
      </Card>
    </>
  )
}

type Info = { name: string; code: string; description: string; multi_asset: boolean; category_ids: string[] }

function InfoStep({ type, info, errors, editable, onChange }: { type: TransactionTypeDefinition; info: Info; errors: Record<string, string[]>; editable: boolean; onChange: (i: Info) => void }) {
  const categories = toOptions(useOptions('/tenant-masters/categories').data)
  if (type.system_type) {
    return (
      <Alert tone="info" title="Jenis sistem">
        Nama, kode, dan aturan inti jenis sistem tetap. Anda dapat menambah field, persetujuan, dan aksi di sekitar aksi intinya.
      </Alert>
    )
  }
  const locked = (type.transactions_count ?? 0) > 0
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Field label="Nama transaksi" required error={errors.name?.[0]}>
        {(p) => <Input {...p} value={info.name} maxLength={100} disabled={!editable} onChange={(e) => onChange({ ...info, name: e.target.value })} />}
      </Field>
      <Field label="Kode / prefix nomor" required error={errors.code?.[0]} hint={locked ? 'Tetap karena jenis ini sudah dipakai transaksi.' : `Nomor transaksi: ${info.code || '…'}-${new Date(type.created_at).getFullYear()}-000001`}>
        {(p) => <Input {...p} value={info.code} maxLength={6} disabled={!editable || locked} onChange={(e) => onChange({ ...info, code: e.target.value.toUpperCase().replace(/[^A-Z]/g, '') })} />}
      </Field>
      <div className="md:col-span-2">
        <Field label="Deskripsi" hint="Tampil di kartu Transaction Center dan di Cara kerja.">
          {(p) => <Textarea {...p} rows={2} maxLength={1000} value={info.description} disabled={!editable} onChange={(e) => onChange({ ...info, description: e.target.value })} />}
        </Field>
      </div>
      <fieldset>
        <legend className="mb-1 text-sm font-semibold text-slate-700">Berlaku untuk kategori aset</legend>
        <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg p-2 ring-1 ring-slate-200">
          {categories.map((c) => (
            <label key={c.value} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-brand-700"
                disabled={!editable}
                checked={info.category_ids.includes(c.value)}
                onChange={(e) => onChange({ ...info, category_ids: e.target.checked ? [...info.category_ids, c.value] : info.category_ids.filter((x) => x !== c.value) })}
              />
              {c.label}
            </label>
          ))}
        </div>
        <p className="mt-1 text-xs text-slate-500">Termasuk subkategori. Kosong: semua kategori.</p>
      </fieldset>
      <div>
        <SegmentedControl
          label="Jumlah aset per transaksi"
          value={info.multi_asset ? 'many' : 'one'}
          onChange={(v) => editable && onChange({ ...info, multi_asset: v === 'many' })}
          options={[{ value: 'one', label: 'Satu aset' }, { value: 'many', label: 'Banyak aset' }]}
        />
      </div>
    </div>
  )
}

/** Step 2: the form as the recorder sees it (F08-14); fields are changed on the Formulir tab. */
function FormStep({ fields, flow, typeId }: { fields: TypeField[]; flow: SimpleFlow | null; typeId: string }) {
  const later = new Set(flow?.input?.config.fields ?? [])
  const used = new Set([...(flow?.input?.config.fields ?? []), ...(flow?.actions ?? []).flatMap((n) => (n.config.actions ?? []).flatMap((a) => [a.field, a.user_field, a.due_field, a.location_field].filter(Boolean) as string[])), ...(flow?.wait?.config.field ? [flow.wait.config.field] : [])])
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <div>
        {fields.length === 0 ? (
          <p className="text-sm text-slate-600">Belum ada field. Formulir hanya meminta aset, tanggal, dan catatan.</p>
        ) : (
          <ul className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
            {fields.map((f) => (
              <li key={f.key} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                <span>
                  <span className="font-medium text-slate-900">{f.label}</span>
                  {f.is_required && <span className="text-red-600"> *</span>}
                  <span className="block text-xs text-slate-500">{fieldTypeLabel[f.data_type]}</span>
                </span>
                {!used.has(f.key) && <Badge tone="slate">belum dipakai di alur</Badge>}
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-sm">
          <Link to={`/transaksi/jenis/${typeId}`} className="font-semibold text-brand-700 hover:underline">
            Tambah atau ubah field di tab Formulir
          </Link>
          <span className="text-slate-500"> (termasuk pengaturan lanjutan per tipe).</span>
        </p>
      </div>
      <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200" aria-label="Pratinjau formulir">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Pratinjau formulir</p>
        <p className="text-sm font-medium text-slate-800">Aset *</p>
        {fields.filter((f) => !later.has(f.key)).map((f) => (
          <p key={f.key} className="mt-2 text-sm text-slate-800">
            {f.label}
            {f.is_required ? ' *' : ''}
          </p>
        ))}
        {later.size > 0 && <p className="mt-3 text-xs text-slate-500">Diisi di langkah "{flow?.input?.label}": {fields.filter((f) => later.has(f.key)).map((f) => f.label).join(', ')}</p>}
      </div>
    </div>
  )
}

function Advanced({ typeId, reasons }: { typeId: string; reasons: string[] }) {
  return (
    <Alert tone="info" title="Alur ini memakai mode lanjutan">
      <ul className="list-disc pl-5">
        {reasons.map((r) => (
          <li key={r}>{r}</li>
        ))}
      </ul>
      <p className="mt-2">Mode sederhana tidak mengubah alur ini. Ubah langkahnya di kanvas.</p>
      <ButtonLink to={`/transaksi/jenis/${typeId}/alur`} variant="secondary" size="sm" className="mt-2">
        Buka mode lanjutan
      </ButtonLink>
    </Alert>
  )
}

interface StepProps {
  flow: SimpleFlow
  fields: TypeField[]
  options?: FlowOptions
  editable: boolean
  onChange: (f: SimpleFlow) => void
}

function StageName({ node, editable, onChange, hint }: { node: FlowNode; editable: boolean; onChange: (n: FlowNode) => void; hint?: string }) {
  return (
    <Field label="Nama tahapan" hint={hint}>
      {(p) => <Input {...p} value={node.label ?? ''} maxLength={100} disabled={!editable} onChange={(e) => onChange({ ...node, label: e.target.value })} />}
    </Field>
  )
}

/** Step 3: who records, the stages (F08-45/46), a later "Isi data" step and a "Tunggu". */
function FlowStep({ flow, fields, options, editable, onChange, typeId }: StepProps & { typeId: string }) {
  const roles = flow.start.config.roles ?? []
  const setStart = (config: FlowNode['config']) => onChange({ ...flow, start: { ...flow.start, config: { ...flow.start.config, ...config } } })
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <fieldset className="space-y-1">
          <legend className="mb-1 text-sm font-semibold text-slate-700">Siapa yang boleh mencatat?</legend>
          <SegmentedControl
            label="Siapa yang boleh mencatat"
            value={roles.length ? 'roles' : 'all'}
            onChange={(v) => editable && setStart({ roles: v === 'all' ? [] : (options?.roles ?? []).slice(0, 1).map((r) => r.id) })}
            options={[{ value: 'all', label: 'Semua yang berizin' }, { value: 'roles', label: 'Role tertentu' }]}
          />
          {roles.length > 0 && (
            <div className="flex flex-wrap gap-3 pt-1">
              {(options?.roles ?? []).map((r) => (
                <label key={r.id} className="flex items-center gap-1.5 text-sm">
                  <input type="checkbox" className="size-4 accent-brand-700" disabled={!editable} checked={roles.includes(r.id)} onChange={(e) => setStart({ roles: e.target.checked ? [...roles, r.id] : roles.filter((x) => x !== r.id) })} />
                  {r.name}
                </label>
              ))}
            </div>
          )}
        </fieldset>
        <ButtonLink to={`/transaksi/jenis/${typeId}/alur`} variant="secondary" size="sm">
          Mode lanjutan (kanvas)
        </ButtonLink>
      </div>

      <div>
        <p className="mb-1 text-sm font-semibold text-slate-700">Tahapan yang dilihat pengguna</p>
        <ol className="flex flex-wrap items-center gap-1 text-sm text-slate-700">
          {stages(flow).map((s, i) => (
            <li key={`${s}-${i}`} className="flex items-center gap-1">
              {i > 0 && <ArrowRight className="size-3.5 text-slate-400" aria-hidden />}
              <span className="rounded-md bg-slate-100 px-2 py-0.5">{s}</span>
            </li>
          ))}
        </ol>
        <div className="mt-2 grid gap-3 md:grid-cols-2">
          <StageName node={flow.start} editable={editable} hint="Tahapan pertama, saat pencatat mengirim formulir." onChange={(start) => onChange({ ...flow, start })} />
          <StageName node={flow.done} editable={editable} hint="Tahapan terakhir." onChange={(done) => onChange({ ...flow, done })} />
        </div>
      </div>

      <div className="rounded-lg p-3 ring-1 ring-slate-200">
        <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <input type="checkbox" className="size-4 accent-brand-700" disabled={!editable} checked={!!flow.input} onChange={(e) => onChange({ ...flow, input: e.target.checked ? newStep(flow, 'input') : null })} />
          Tahapan isi data (mis. serah terima: penerima mengisi foto dan No. BAST)
        </label>
        {flow.input && (
          <div className="mt-3 grid gap-3 md:grid-cols-2">
            <StageName node={flow.input} editable={editable} onChange={(input) => onChange({ ...flow, input })} />
            <WhoPicker label="Diisi oleh" value={flow.input.config.assignee ?? { type: 'starter' }} roles={options?.roles ?? []} members={options?.members ?? []} fields={fields} onChange={(assignee) => onChange({ ...flow, input: { ...flow.input!, config: { ...flow.input!.config, assignee } } })} />
            <fieldset className="md:col-span-2">
              <legend className="mb-1 text-sm font-semibold text-slate-700">Field yang diisi di tahapan ini</legend>
              <div className="flex flex-wrap gap-3">
                {fields.map((f) => {
                  const chosen = flow.input!.config.fields ?? []
                  const required = flow.input!.config.required ?? []
                  return (
                    <span key={f.key} className="flex items-center gap-3 rounded-md bg-slate-50 px-2 py-1 text-sm ring-1 ring-slate-200">
                      <label className="flex items-center gap-1.5">
                        <input type="checkbox" className="size-4 accent-brand-700" disabled={!editable} checked={chosen.includes(f.key)}
                          onChange={(e) => onChange({ ...flow, input: { ...flow.input!, config: { ...flow.input!.config, fields: e.target.checked ? [...chosen, f.key] : chosen.filter((k) => k !== f.key), required: required.filter((k) => k !== f.key || e.target.checked) } } })} />
                        {f.label}
                      </label>
                      {chosen.includes(f.key) && (
                        <label className="flex items-center gap-1 text-xs text-slate-600">
                          <input type="checkbox" className="size-3.5 accent-brand-700" disabled={!editable} checked={required.includes(f.key)}
                            onChange={(e) => onChange({ ...flow, input: { ...flow.input!, config: { ...flow.input!.config, required: e.target.checked ? [...required, f.key] : required.filter((k) => k !== f.key) } } })} />
                          wajib
                        </label>
                      )}
                    </span>
                  )
                })}
              </div>
            </fieldset>
          </div>
        )}
      </div>

      <div className="rounded-lg p-3 ring-1 ring-slate-200">
        <label className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <input type="checkbox" className="size-4 accent-brand-700" disabled={!editable} checked={!!flow.wait} onChange={(e) => onChange({ ...flow, wait: e.target.checked ? newStep(flow, 'wait') : null })} />
          Tunggu sebelum selesai (sampai tanggal, atau sampai transaksi pasangannya dicatat)
        </label>
        {flow.wait && (
          <div className="mt-3 grid gap-3 md:grid-cols-2">
            <StageName node={flow.wait} editable={editable} onChange={(wait) => onChange({ ...flow, wait })} />
            <Field label="Tunggu sampai">
              {(p) => (
                <Select {...p} value={flow.wait!.config.until ?? 'date'} disabled={!editable} onChange={(e) => onChange({ ...flow, wait: { ...flow.wait!, config: { ...flow.wait!.config, until: e.target.value as 'date' | 'paired' } } })}>
                  <option value="date">tanggal pada field</option>
                  <option value="paired">transaksi pasangannya dicatat</option>
                </Select>
              )}
            </Field>
            {(flow.wait.config.until ?? 'date') === 'date' && (
              <FieldSelect label="Field tanggal" fields={fields} types={['date']} value={flow.wait.config.field} onChange={(field) => onChange({ ...flow, wait: { ...flow.wait!, config: { ...flow.wait!.config, field } } })} />
            )}
          </div>
        )}
      </div>
    </div>
  )
}

/** Step 4: none, one or two approvals in a row; conditional approvals are rules (F08-34). */
function ApprovalStep({ flow, fields, options, editable, onChange, rules, typeId }: StepProps & { rules: { id: string; name: string; active: boolean; outcome: { kind: keyof typeof ruleOutcomeLabel } }[]; typeId: string }) {
  const count = String(flow.approvals.length) as '0' | '1' | '2'
  const setCount = (v: '0' | '1' | '2') => {
    const n = Number(v)
    let approvals = flow.approvals.slice(0, n)
    while (approvals.length < n) approvals = [...approvals, newStep({ ...flow, approvals }, 'approval')]
    onChange({ ...flow, approvals })
  }
  const setApproval = (i: number, node: FlowNode) => onChange({ ...flow, approvals: flow.approvals.map((a, j) => (j === i ? node : a)) })
  const ruled = rules.filter((r) => r.outcome.kind !== 'reject')
  return (
    <div className="space-y-4">
      <SegmentedControl
        label="Apakah transaksi ini perlu persetujuan?"
        value={count}
        onChange={(v) => editable && setCount(v)}
        options={[{ value: '0', label: 'Tidak perlu' }, { value: '1', label: 'Satu tingkat' }, ...(MAX_SIMPLE_APPROVALS >= 2 ? [{ value: '2' as const, label: 'Dua tingkat' }] : [])]}
      />
      {flow.approvals.map((a, i) => (
        <div key={a.id} className="grid gap-3 rounded-lg p-3 ring-1 ring-slate-200 md:grid-cols-2">
          <StageName node={a} editable={editable} hint={`Persetujuan ${i + 1}`} onChange={(n) => setApproval(i, n)} />
          <WhoPicker approval label="Disetujui oleh" value={a.config.approver ?? { type: 'location_supervisor' }} roles={(options?.roles ?? []).filter((r) => r.may_approve)} members={(options?.members ?? []).filter((m) => m.may_approve)} fields={fields} onChange={(approver) => setApproval(i, { ...a, config: { ...a.config, approver } })} />
          {['role', 'location_supervisor'].includes(a.config.approver?.type ?? 'location_supervisor') && (
            <Field label="Minimal penyetuju">
              {(p) => (
                <Select {...p} value={String(a.config.min_approvals ?? 1)} disabled={!editable} onChange={(e) => setApproval(i, { ...a, config: { ...a.config, min_approvals: Number(e.target.value) } })}>
                  {Array.from({ length: options?.max_approvals ?? 5 }, (_, n) => n + 1).map((n) => (
                    <option key={n} value={n}>
                      {n} orang
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}
        </div>
      ))}
      <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
        <p className="text-sm font-semibold text-slate-800">Rule persetujuan</p>
        {ruled.length === 0 ? (
          <p className="text-sm text-slate-600">Belum ada. Contoh: perlu persetujuan Super Admin bila nilai aset lebih dari Rp 10 juta.</p>
        ) : (
          <ul className="mt-1 list-disc pl-5 text-sm text-slate-700">
            {ruled.map((r) => (
              <li key={r.id} className={r.active ? '' : 'text-slate-500'}>
                {r.name} · {ruleOutcomeLabel[r.outcome.kind]}
                {!r.active && ' (nonaktif)'}
              </li>
            ))}
          </ul>
        )}
        <Link to={`/transaksi/jenis/${typeId}/rules`} className="mt-1 inline-block text-sm font-semibold text-brand-700 hover:underline">
          Kelola rules
        </Link>
      </div>
    </div>
  )
}

/** Step 5: what happens to the asset; history is always recorded (F08-07). */
function ActionStep({ flow, fields, options, editable, onChange }: StepProps) {
  const setAction = (i: number, node: FlowNode) => onChange({ ...flow, actions: flow.actions.map((a, j) => (j === i ? node : a)) })
  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-700">{changesAsset(flow) ? 'Transaksi ini mengubah aset.' : 'Tidak mengubah aset: transaksi ini hanya dicatat di riwayat aset.'}</p>
      {flow.actions.map((a, i) =>
        isCoreNode(a) ? (
          <div key={a.id} className="grid gap-3 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200 md:grid-cols-2">
            <StageName node={a} editable={editable} onChange={(n) => setAction(i, n)} />
            <p className="flex items-center gap-1.5 self-center text-sm text-slate-700">
              <Lock className="size-4" aria-hidden />
              Aksi inti jenis sistem: dicatat sesuai isian formulir bawaan dengan aturan intinya.
            </p>
          </div>
        ) : (
          <div key={a.id} className="grid gap-3 rounded-lg p-3 ring-1 ring-slate-200 md:grid-cols-2">
            <div className="space-y-2">
              <StageName node={a} editable={editable} onChange={(n) => setAction(i, n)} />
              {editable && (
                <Button variant="ghost" size="sm" onClick={() => onChange({ ...flow, actions: flow.actions.filter((_, j) => j !== i) })}>
                  <Trash2 className="size-4" aria-hidden />
                  Hapus tahapan aksi
                </Button>
              )}
            </div>
            <ActionsEditor actions={a.config.actions ?? []} fields={fields} options={options} onChange={(actions) => setAction(i, { ...a, config: { ...a.config, actions } })} />
          </div>
        ),
      )}
      {editable && (
        <Button variant="secondary" onClick={() => onChange({ ...flow, actions: [...flow.actions, newStep(flow, 'action')] })}>
          <Plus className="size-4" aria-hidden />
          Tambah tahapan aksi
        </Button>
      )}
    </div>
  )
}

/** Step 6: what still needs doing (F08-08), the scenarios and change note (children), and "Cara kerja" with the changes (F08-24/25). */
function ReviewStep({ issues, flow, doc, loading, children }: { issues: FlowIssue[]; flow: SimpleFlow | null; doc?: FlowDocumentation; loading: boolean; children?: React.ReactNode }) {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <div className="space-y-4">
        {issues.length === 0 ? (
          <Alert tone="success" title="Siap diterbitkan">
            Alur tidak memiliki masalah. Transaksi baru akan mengikuti versi yang diterbitkan; transaksi berjalan tetap memakai versinya.
          </Alert>
        ) : (
          <Alert tone="warning" title={`${issues.length} hal perlu dilengkapi sebelum terbit`}>
            <ul className="list-disc pl-5">
              {issues.map((i) => (
                <li key={`${i.code}-${i.node_id}-${i.message}`}>{i.message}</li>
              ))}
            </ul>
          </Alert>
        )}
        {flow && (
          <p className="flex flex-wrap items-center gap-1 text-sm text-slate-700">
            <CheckCircle2 className="size-4 text-green-600" aria-hidden />
            Tahapan: {stages(flow).join(' → ')}
          </p>
        )}
        {issues.length > 0 && (
          <p className="flex items-center gap-1 text-xs text-slate-500">
            <AlertTriangle className="size-3.5" aria-hidden />
            Kembali ke langkahnya untuk melengkapi, atau perbaiki di mode lanjutan.
          </p>
        )}
        {children}
      </div>
      <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Cara kerja transaksi ini</p>
        {loading || !doc ? <p className="text-sm text-slate-500">Menyusun dokumentasi…</p> : <FlowDocumentationView doc={doc} />}
      </div>
    </div>
  )
}
