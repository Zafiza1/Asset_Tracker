import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { AlertTriangle, ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, DetailSkeleton, EmptyState, ErrorState } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { SegmentedControl } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import {
  flowApi,
  ruleOutcomeLabel,
  systemStarterGraph,
  typesApi,
  type FlowGraph,
  type FlowIssue,
  type FlowOptions,
  type NamedRule,
  type RuleOutcomeKind,
  type TransactionTypeDefinition,
} from './api'
import { DesignerNav } from './DesignerNav'
import { RulesEditor, WhoPicker } from './FlowInputs'
import { byPriority, renumber, rulePreview, subjectLabel } from './rulesLogic'

/** Mulai → Selesai: the flow rules start from when an organization type has none yet. */
function emptyGraph(): FlowGraph {
  return {
    nodes: [
      { id: 'start', type: 'start', label: 'Diajukan', position: { x: 0, y: 80 }, config: {} },
      { id: 'selesai', type: 'end', label: 'Selesai', position: { x: 560, y: 80 }, config: { result: 'completed' } },
    ],
    edges: [{ id: 'e_start_selesai', source: 'start', target: 'selesai', outcome: null }],
  }
}



/**
 * Rules of a transaction type (F-08 §3.8): named, prioritized, on or off, each a JIKA … MAKA …
 * built from registered data. Saved with the flow's draft; the server turns the active ones into
 * flow steps (D-46) and they apply from the next published version (F08-37). A flow changed on
 * the canvas shows its rules for reading until the rules are applied again (F08-39).
 */
export function TransactionRulesPage() {
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canUseEditor = useCanUseEditor()
  const canManage = can('transaction_flow.manage') && canUseEditor
  const [editing, setEditing] = useState<NamedRule | 'new' | null>(null)
  const [removing, setRemoving] = useState<NamedRule | null>(null)
  const [issues, setIssues] = useState<FlowIssue[] | null>(null)

  const type = useQuery({ queryKey: ['/transaction-types', id], queryFn: () => typesApi.get(id) })
  const flow = useQuery({ queryKey: ['/transaction-types', id, 'flow'], queryFn: () => flowApi.get(id) })
  const doc = useQuery({ queryKey: ['/transaction-types', id, 'flow', 'describe'], queryFn: () => flowApi.describe(id) })
  const options = useQuery({ queryKey: ['/transaction-flow-options'], queryFn: () => flowApi.options(), enabled: canManage })

  const t = type.data?.data
  const state = flow.data?.data
  const version = state?.draft ?? state?.active ?? null
  const rules = byPriority(version?.rules ?? [])
  const base: FlowGraph | null = version ? (version.base_graph ?? version.graph) : t ? (t.system_type ? systemStarterGraph(t.name) : emptyGraph()) : null
  const canvas = version?.graph_source === 'canvas' && rules.length > 0
  const editable = canManage && !canvas
  const described = new Map((doc.data?.data.rules ?? []).map((r) => [r.id, r]))
  const shownIssues = issues ?? state?.draft?.issues ?? []
  const ruleIssues = (ruleId: string) => shownIssues.filter((i) => i.rule_id === ruleId)
  const conflicts = (doc.data?.data.rules ?? []).filter((r) => r.active && !r.applies)

  const save = useMutation({
    mutationFn: (next: NamedRule[]) => flowApi.saveRules(id, base!, renumber(next)),
    onSuccess: (r) => {
      setIssues(r.data.issues ?? [])
      queryClient.invalidateQueries({ queryKey: ['/transaction-types', id] })
      queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success('Rules disimpan di draft', 'Berlaku mulai versi alur berikutnya yang diterbitkan.')
      setEditing(null)
    },
    onError: (e) => toast.error('Rules gagal disimpan', errorMessage(e)),
  })
  const move = (index: number, by: -1 | 1) => {
    const next = [...rules]
    ;[next[index], next[index + by]] = [next[index + by], next[index]]
    save.mutate(next)
  }

  if (type.isPending || flow.isPending) return <DetailSkeleton />
  if (type.isError || flow.isError) return <ErrorState error={type.error ?? flow.error} onRetry={() => (type.refetch(), flow.refetch())} />
  if (!t) return null

  return (
    <>
      <PageHeader
        title={`Rules: ${t.name}`}
        badges={
          <>
            {state?.active && <Badge tone="green">Terbit v{state.active.version_number}</Badge>}
            {state?.draft && <Badge tone="amber">Draft v{state.draft.version_number}</Badge>}
          </>
        }
        description="Ketentuan yang berlaku pada transaksi ini. Urutan = prioritas: rule teratas dinilai lebih dulu. Rule nonaktif tidak berlaku."
        actions={
          editable && (
            <Button onClick={() => setEditing('new')}>
              <Plus className="size-4" aria-hidden />
              Rule baru
            </Button>
          )
        }
      />
      <DesignerNav typeId={id} ruleCount={rules.length} />

      {!canUseEditor && can('transaction_flow.manage') && (
        <div className="mb-4">
          <Alert tone="info" title="Rules diubah di layar lebar">
            Di ponsel rule yang berlaku tampil sebagai kalimat (hanya dilihat).
          </Alert>
        </div>
      )}
      {canvas && (
        <div className="mb-4">
          <Alert tone="warning" title="Alur diubah di mode lanjutan">
            <p>Ubah persetujuan di kanvas, atau pakai rules ini lagi: langkah dari rule dibuat ulang, langkah lain yang Anda gambar tetap.</p>
            {canManage && (
              <Button className="mt-2" size="sm" variant="secondary" loading={save.isPending} onClick={() => save.mutate(rules)}>
                Pakai rules lagi
              </Button>
            )}
          </Alert>
        </div>
      )}
      {conflicts.length > 0 && (
        <div className="mb-4">
          <Alert tone="warning" title={conflicts.length === 1 ? 'Ada rule yang tidak berlaku' : `${conflicts.length} rule tidak berlaku`}>
            <ul className="list-disc pl-5">
              {conflicts.map((c) => (
                <li key={c.id}>
                  {c.name}: {c.note}
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      )}

      <Card>
        {rules.length === 0 ? (
          <EmptyState
            title="Belum ada rule"
            description='Transaksi berjalan sesuai alurnya tanpa syarat tambahan. Tambahkan rule seperti "Perlu persetujuan Super Admin bila nilai aset lebih dari Rp 10 juta".'
            action={
              editable && (
                <Button onClick={() => setEditing('new')}>
                  <Plus className="size-4" aria-hidden />
                  Rule baru
                </Button>
              )
            }
          />
        ) : (
          <ol className="divide-y divide-slate-100">
            {rules.map((r, i) => {
              const told = described.get(r.id)
              const problems = ruleIssues(r.id)
              return (
                <li key={r.id} className={clsx('flex flex-col gap-2 p-4 sm:flex-row sm:items-start', !r.active && 'opacity-60')}>
                  <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold tabular-nums text-slate-700" aria-label={`Prioritas ${i + 1}`}>
                    {i + 1}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-1.5 font-semibold text-slate-900">
                      {r.name}
                      <Badge tone={r.outcome.kind === 'reject' ? 'red' : r.outcome.kind === 'approval' ? 'blue' : 'slate'}>{ruleOutcomeLabel[r.outcome.kind]}</Badge>
                      {!r.active && <Badge tone="slate">Nonaktif</Badge>}
                      {told && r.active && !told.applies && (
                        <Badge tone="amber">
                          <AlertTriangle className="size-3" aria-hidden />
                          Bertentangan
                        </Badge>
                      )}
                    </p>
                    {r.description && <p className="text-sm text-slate-600">{r.description}</p>}
                    <p className="mt-1 text-sm text-slate-700">{told?.sentence ?? 'Kalimat aturan tampil setelah disimpan.'}</p>
                    {problems.map((p) => (
                      <p key={p.message} className="mt-1 flex items-start gap-1 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                        {p.message}
                      </p>
                    ))}
                  </div>
                  {editable && (
                    <div className="flex shrink-0 flex-wrap items-center gap-1">
                      <label className="mr-2 flex items-center gap-1.5 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          className="size-4 accent-brand-700"
                          checked={r.active}
                          disabled={save.isPending}
                          onChange={(e) => save.mutate(rules.map((x) => (x.id === r.id ? { ...x, active: e.target.checked } : x)))}
                        />
                        Aktif
                      </label>
                      <Button variant="ghost" size="sm" aria-label={`Naikkan ${r.name}`} disabled={i === 0 || save.isPending} onClick={() => move(i, -1)}>
                        <ArrowUp className="size-4" aria-hidden />
                      </Button>
                      <Button variant="ghost" size="sm" aria-label={`Turunkan ${r.name}`} disabled={i === rules.length - 1 || save.isPending} onClick={() => move(i, 1)}>
                        <ArrowDown className="size-4" aria-hidden />
                      </Button>
                      <Button variant="ghost" size="sm" aria-label={`Ubah ${r.name}`} onClick={() => setEditing(r)}>
                        <Pencil className="size-4" aria-hidden />
                      </Button>
                      <Button variant="ghost" size="sm" aria-label={`Hapus ${r.name}`} onClick={() => setRemoving(r)}>
                        <Trash2 className="size-4" aria-hidden />
                      </Button>
                    </div>
                  )}
                </li>
              )
            })}
          </ol>
        )}
        {shownIssues.some((i) => !i.rule_id) && (
          <p className="border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
            Alur draft masih memiliki masalah lain.{' '}
            <Link to={`/transaksi/jenis/${id}/alur`} className="font-semibold text-brand-700 hover:underline">
              Lihat di Alur
            </Link>
          </p>
        )}
      </Card>

      {editing && base && (
        <RuleDialog
          rule={editing === 'new' ? null : editing}
          type={t}
          base={base}
          options={options.data?.data}
          saving={save.isPending}
          onClose={() => setEditing(null)}
          onSave={(rule) => save.mutate(editing === 'new' ? [...rules, rule] : rules.map((x) => (x.id === rule.id ? rule : x)))}
        />
      )}
      <ConfirmDialog
        open={removing !== null}
        title={`Hapus rule "${removing?.name ?? ''}"?`}
        confirmLabel="Hapus"
        tone="danger"
        onClose={() => setRemoving(null)}
        onConfirm={() => {
          save.mutate(rules.filter((x) => x.id !== removing?.id))
          setRemoving(null)
        }}
      >
        Rule dihapus dari draft dan tidak berlaku lagi mulai versi berikutnya yang diterbitkan.
      </ConfirmDialog>
    </>
  )
}

const OUTCOMES: { value: RuleOutcomeKind; label: string }[] = [
  { value: 'approval', label: 'Perlu persetujuan' },
  { value: 'reject', label: 'Tolak transaksi' },
  { value: 'skip_approval', label: 'Lewati persetujuan' },
]

/** A rule id the server accepts (a-z0-9, at most 12). */
function newRuleId(): string {
  return `r${Math.random().toString(36).slice(2, 10)}`
}

/** "JIKA … MAKA …" (F08-33): name, conditions, outcome; a default name from the outcome when left empty (F08-32). */
function RuleDialog({ rule, type, base, options, saving, onClose, onSave }: {
  rule: NamedRule | null
  type: TransactionTypeDefinition
  base: FlowGraph
  options?: FlowOptions
  saving: boolean
  onClose: () => void
  onSave: (rule: NamedRule) => void
}) {
  const [draft, setDraft] = useState<NamedRule>(
    () =>
      rule ?? {
        id: newRuleId(),
        name: '',
        description: null,
        priority: 100,
        active: true,
        match: 'all',
        assets: 'any',
        conditions: [{ subject: 'asset.acquisition_cost', op: 'gt', value: 10000000 }],
        outcome: { kind: 'approval', approver: { type: 'location_supervisor' }, min_approvals: 1 },
      },
  )
  const fields = type.fields ?? []
  const approvals = base.nodes.filter((n) => n.type === 'approval')
  const kind = draft.outcome.kind
  const setOutcome = (patch: Partial<NamedRule['outcome']>) => setDraft((d) => ({ ...d, outcome: { ...d.outcome, ...patch } }))
  const preview = rulePreview(draft, fields.map((f) => ({ key: `field.${f.key}`, label: f.label })))

  const submit = () => {
    const name = draft.name.trim() || `${ruleOutcomeLabel[kind]} bila ${subjectLabel(draft.conditions[0]?.subject ?? '', fields) || 'kondisi terpenuhi'}`.slice(0, 100)
    const outcome =
      kind === 'reject'
        ? { kind, message: draft.outcome.message ?? '' }
        : kind === 'approval'
          ? { kind, approver: draft.outcome.approver ?? { type: 'location_supervisor' as const }, min_approvals: draft.outcome.min_approvals ?? 1 }
          : { kind, step: draft.outcome.step ?? '' }
    onSave({ ...draft, name, description: draft.description?.trim() || null, outcome })
  }

  return (
    <Modal
      open
      size="xl"
      title={rule ? 'Ubah rule' : 'Rule baru'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={saving} onClick={submit}>
            Simpan rule
          </Button>
        </>
      }
    >
      <div className="grid gap-4 md:grid-cols-2">
        <div className="space-y-3">
          <Field label="Nama rule" hint="Kosongkan untuk memakai nama dari kondisi dan hasilnya.">
            {(p) => <Input {...p} value={draft.name} maxLength={100} placeholder="mis. Approval aset bernilai tinggi" onChange={(e) => setDraft({ ...draft, name: e.target.value })} />}
          </Field>
          <Field label="Deskripsi">
            {(p) => <Textarea {...p} rows={2} maxLength={500} value={draft.description ?? ''} onChange={(e) => setDraft({ ...draft, description: e.target.value })} />}
          </Field>
          <div>
            <p className="mb-1 text-xs font-bold uppercase tracking-wide text-brand-700">Jika</p>
            <RulesEditor
              label="Kondisi"
              rules={draft.conditions}
              match={draft.match}
              fields={fields}
              options={options}
              coreType={type.system_type ?? type.core_type}
              hint={kind === 'approval' ? 'Tanpa kondisi: persetujuan selalu diperlukan.' : undefined}
              onChange={(conditions, match) => setDraft({ ...draft, conditions, match })}
            />
            {type.multi_asset && (
              <Field label="Untuk banyak aset, berlaku bila">
                {(p) => (
                  <Select {...p} value={draft.assets} onChange={(e) => setDraft({ ...draft, assets: e.target.value as NamedRule['assets'] })}>
                    <option value="any">salah satu aset memenuhi</option>
                    <option value="all">setiap aset memenuhi</option>
                    <option value="total">total nilai perolehan (untuk nilai perolehan)</option>
                  </Select>
                )}
              </Field>
            )}
          </div>
        </div>
        <div className="space-y-3">
          <p className="text-xs font-bold uppercase tracking-wide text-brand-700">Maka</p>
          <SegmentedControl label="Hasil" options={OUTCOMES} value={kind} onChange={(k) => setOutcome({ kind: k })} />
          {kind === 'reject' && (
            <Field label="Pesan untuk pencatat" required>
              {(p) => <Textarea {...p} rows={3} maxLength={options?.max_message ?? 500} value={draft.outcome.message ?? ''} placeholder="mis. Aset rusak berat diperbaiki dulu." onChange={(e) => setOutcome({ message: e.target.value })} />}
            </Field>
          )}
          {kind === 'approval' && (
            <>
              <WhoPicker approval label="Disetujui oleh" value={draft.outcome.approver ?? { type: 'location_supervisor' }} roles={(options?.roles ?? []).filter((r) => r.may_approve)} members={(options?.members ?? []).filter((m) => m.may_approve)} fields={fields} onChange={(approver) => setOutcome({ approver })} />
              {['role', 'location_supervisor'].includes(draft.outcome.approver?.type ?? 'location_supervisor') && (
                <Field label="Minimal penyetuju">
                  {(p) => (
                    <Select {...p} value={String(draft.outcome.min_approvals ?? 1)} onChange={(e) => setOutcome({ min_approvals: Number(e.target.value) })}>
                      {Array.from({ length: options?.max_approvals ?? 5 }, (_, i) => i + 1).map((n) => (
                        <option key={n} value={n}>
                          {n} orang
                        </option>
                      ))}
                    </Select>
                  )}
                </Field>
              )}
            </>
          )}
          {kind === 'skip_approval' && (
            <Field label="Persetujuan yang dilewati" required hint={approvals.length === 0 ? 'Alur ini belum punya langkah persetujuan.' : undefined}>
              {(p) => (
                <Select {...p} value={draft.outcome.step ?? ''} onChange={(e) => setOutcome({ step: e.target.value })}>
                  <option value="">— Pilih langkah —</option>
                  {approvals.map((n) => (
                    <option key={n.id} value={n.id}>
                      {n.label ?? n.id}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}
          <div className="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900" aria-live="polite">
            {preview}
          </div>
          <p className="text-xs text-slate-500">Berlaku mulai versi alur berikutnya yang diterbitkan.</p>
        </div>
      </div>
    </Modal>
  )
}
