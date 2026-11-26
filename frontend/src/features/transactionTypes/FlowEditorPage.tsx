import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Connection, EdgeChange, NodeChange } from '@xyflow/react'
import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Lock, Play, Plus, Redo2, Save, ShieldCheck, Trash2, Undo2, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, DetailSkeleton, ErrorState } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { SegmentedControl } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDateTime } from '../../lib/format'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import type { Asset } from '../assets/api'
import { CustomFieldInputs } from './CustomFieldInputs'
import { toPayload, type FieldValues } from './values'
import { DesignerNav } from './DesignerNav'
import { FlowDiagram } from './FlowDiagram'
import { ActionsEditor, FieldSelect, RulesEditor, WhoPicker } from './FlowInputs'
import {
  flowApi,
  isCoreNode,
  nodeTypeLabel,
  systemStarterGraph,
  outcomeLabel,
  typesApi,
  type EdgeOutcome,
  type FlowEdge,
  type FlowGraph,
  type FlowIssue,
  type FlowNode,
  type FlowOptions,
  type NodeConfig,
  type NodeType,
  type PathStep,
  type TypeField,
} from './api'

const PALETTE: NodeType[] = ['start', 'input', 'approval', 'condition', 'action', 'wait', 'end']
const OUTCOMES: Partial<Record<NodeType, Exclude<EdgeOutcome, null>[]>> = { approval: ['approved', 'rejected'], condition: ['yes', 'no'] }
const HISTORY = 50

/** A new flow: Mulai → Selesai, ready to grow. */
function starterGraph(): FlowGraph {
  return {
    nodes: [
      { id: 'start', type: 'start', label: 'Mulai', position: { x: 0, y: 80 }, config: {} },
      { id: 'selesai', type: 'end', label: 'Selesai', position: { x: 320, y: 80 }, config: { result: 'completed' } },
    ],
    edges: [{ id: 'e_start_selesai', source: 'start', target: 'selesai', outcome: null }],
  }
}

function defaultConfig(type: NodeType): NodeConfig {
  switch (type) {
    case 'input':
      return { assignee: { type: 'starter' }, fields: [], required: [] }
    case 'approval':
      return { approver: { type: 'location_supervisor' }, min_approvals: 1 }
    case 'condition':
      return { rules: [{ subject: 'asset.acquisition_cost', op: 'gt', value: 10000000 }], match: 'all', assets: 'any' }
    case 'action':
      return { actions: [{ kind: 'set_status', status: 'under_maintenance' }] }
    case 'wait':
      return { until: 'date' }
    case 'end':
      return { result: 'completed' }
    default:
      return {}
  }
}

/**
 * Editor alur (F-07 §4): draw the steps of a transaction type, check, test and publish them.
 * Web-only on wide screens and for transaction_flow.manage (D-25, D-27, D-34); everyone else
 * sees the published flow.
 */
export function FlowEditorPage() {
  const { id } = useParams()
  const typeId = id!
  const { can } = useAuth()
  const canUseEditor = useCanUseEditor()
  const queryClient = useQueryClient()

  const type = useQuery({ queryKey: ['/transaction-types', typeId], queryFn: () => typesApi.get(typeId) })
  // A system type's flow (F-07f-3) is edited too, around its core step, which stays.
  const editable = can('transaction_flow.manage') && canUseEditor
  const flow = useQuery({ queryKey: ['/transaction-types', typeId, 'flow'], queryFn: () => flowApi.get(typeId) })
  const options = useQuery({ queryKey: ['/transaction-flow-options'], queryFn: () => flowApi.options(), enabled: editable, staleTime: 60_000 })

  const [graph, setGraph] = useState<FlowGraph | null>(null)
  const [past, setPast] = useState<FlowGraph[]>([])
  const [future, setFuture] = useState<FlowGraph[]>([])
  const [selected, setSelected] = useState<string | null>(null)
  const [issues, setIssues] = useState<FlowIssue[] | null>(null)
  const [dirty, setDirty] = useState(false)
  const [testing, setTesting] = useState(false)
  const [path, setPath] = useState<PathStep[] | null>(null)
  const [publishing, setPublishing] = useState(false)
  const [note, setNote] = useState('')

  // Start from the draft, else the published version, else Mulai → Selesai (Mulai → Aksi inti → Selesai for a system type).
  const state = flow.data?.data
  const loadedType = type.data?.data
  if (state && loadedType && !graph) {
    const starter = loadedType.system_type ? systemStarterGraph(loadedType.name) : starterGraph()
    setGraph(structuredClone((editable ? state.draft?.graph : null) ?? state.active?.graph ?? (editable ? starter : { nodes: [], edges: [] })))
    setIssues(editable ? (state.draft?.issues ?? null) : null)
  }

  // Leaving with unsaved changes asks first.
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  /** Every change goes through here so it can be undone. Moves while dragging are merged. */
  const change = useCallback((next: FlowGraph, record = true) => {
    setGraph((prev) => {
      if (prev && record) {
        setPast((p) => [...p.slice(-HISTORY + 1), prev])
        setFuture([])
      }
      return next
    })
    setDirty(true)
    setPath(null)
  }, [])

  const fields = type.data?.data.fields ?? []

  const save = useMutation({
    mutationFn: () => flowApi.save(typeId, graph!, note),
    onSuccess: async (res) => {
      setIssues(res.data.issues ?? [])
      setDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success('Draft alur disimpan', `v${res.data.version_number}${res.data.issues?.length ? ` · ${res.data.issues.length} masalah` : ' · valid'}`)
    },
    onError: (e) => toast.error('Gagal menyimpan', errorMessage(e)),
  })
  const check = useMutation({
    mutationFn: () => flowApi.validate(typeId, graph!),
    onSuccess: (res) => {
      setIssues(res.data.issues)
      if (res.data.valid) toast.success('Alur valid', 'Siap diterbitkan.')
    },
    onError: (e) => toast.error('Gagal memeriksa', errorMessage(e)),
  })
  const publish = useMutation({
    mutationFn: async () => {
      if (dirty) await flowApi.save(typeId, graph!, note)
      return flowApi.publish(typeId)
    },
    onSuccess: async (res) => {
      setPublishing(false)
      setDirty(false)
      setIssues([])
      await queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success(`Alur v${res.data.version_number} diterbitkan`, 'Transaksi baru jenis ini mengikuti alur ini; yang sedang berjalan tetap memakai versinya.')
    },
    onError: (e) => {
      const found = (e as { details?: { issues?: FlowIssue[] } }).details?.issues
      if (found) setIssues(found)
    },
  })

  const onNodesChange = useCallback(
    (changes: NodeChange[]) => {
      if (!graph || !editable) return
      let next = graph
      let record = false
      for (const c of changes) {
        if (c.type === 'position' && c.position) {
          next = { ...next, nodes: next.nodes.map((n) => (n.id === c.id ? { ...n, position: { x: Math.round(c.position!.x), y: Math.round(c.position!.y) } } : n)) }
          record = record || !c.dragging
        } else if (c.type === 'remove' && !next.nodes.some((n) => n.id === c.id && isCoreNode(n))) {
          next = { nodes: next.nodes.filter((n) => n.id !== c.id), edges: next.edges.filter((e) => e.source !== c.id && e.target !== c.id) }
          record = true
          if (selected === c.id) setSelected(null)
        }
      }
      if (next !== graph) change(next, record)
    },
    [graph, editable, change, selected],
  )

  const onEdgesChange = useCallback(
    (changes: EdgeChange[]) => {
      if (!graph || !editable) return
      const removed = changes.filter((c) => c.type === 'remove').map((c) => c.id)
      if (removed.length) change({ ...graph, edges: graph.edges.filter((e) => !removed.includes(e.id)) })
    },
    [graph, editable, change],
  )

  /** A line drawn: branching steps take the next free outcome (Disetujui, then Ditolak; Ya, then Tidak). */
  const onConnect = useCallback(
    (c: Connection) => {
      if (!graph || !c.source || !c.target || c.source === c.target) return
      const source = graph.nodes.find((n) => n.id === c.source)
      if (!source || source.type === 'end') return
      const outs = graph.edges.filter((e) => e.source === c.source)
      const branches = OUTCOMES[source.type]
      let edges = graph.edges
      let outcome: EdgeOutcome = null
      if (branches) {
        outcome = branches.find((o) => !outs.some((e) => e.outcome === o)) ?? branches[branches.length - 1]
        edges = edges.filter((e) => !(e.source === c.source && e.outcome === outcome))
      } else {
        // One way out: a new line replaces the old one.
        edges = edges.filter((e) => e.source !== c.source)
      }
      change({ ...graph, edges: [...edges, { id: `e_${c.source}_${c.target}_${outcome ?? 'x'}`, source: c.source, target: c.target, outcome }] })
    },
    [graph, change],
  )

  const addNode = (t: NodeType) => {
    if (!graph) return
    let n = graph.nodes.length + 1
    while (graph.nodes.some((x) => x.id === `${t}_${n}`)) n++
    const maxX = Math.max(0, ...graph.nodes.map((x) => x.position.x))
    const node: FlowNode = { id: `${t}_${n}`, type: t, label: nodeTypeLabel[t], position: { x: maxX + 240, y: 80 + (n % 3) * 110 }, config: defaultConfig(t) }
    change({ ...graph, nodes: [...graph.nodes, node] })
    setSelected(node.id)
  }

  const updateNode = (nodeId: string, patch: Partial<FlowNode>) => graph && change({ ...graph, nodes: graph.nodes.map((n) => (n.id === nodeId ? { ...n, ...patch } : n)) })
  const updateConfig = (nodeId: string, patch: Partial<NodeConfig>) =>
    graph && change({ ...graph, nodes: graph.nodes.map((n) => (n.id === nodeId ? { ...n, config: { ...n.config, ...patch } } : n)) })

  const undo = () => {
    const prev = past[past.length - 1]
    if (!prev || !graph) return
    setPast((p) => p.slice(0, -1))
    setFuture((f) => [graph, ...f])
    setGraph(prev)
    setDirty(true)
  }
  const redo = () => {
    const next = future[0]
    if (!next || !graph) return
    setFuture((f) => f.slice(1))
    setPast((p) => [...p, graph])
    setGraph(next)
    setDirty(true)
  }

  const highlight = useMemo(() => {
    const errors = new Set((issues ?? []).map((i) => i.node_id).filter((x): x is string => !!x))
    if (!path || !graph) return { errors }
    const nodes = new Set(path.map((p) => p.node_id))
    const edges = new Set<string>()
    path.forEach((p, i) => {
      const next = path[i + 1]
      if (!next) return
      const e = graph.edges.find((x) => x.source === p.node_id && x.target === next.node_id)
      if (e) edges.add(e.id)
    })
    return { errors, path: nodes, edges }
  }, [issues, path, graph])

  if (type.isError || flow.isError) return <ErrorState error={type.error ?? flow.error} />
  if (!type.data || !flow.data || !graph) return <DetailSkeleton />
  const t = type.data.data
  const active = state?.active
  const draftNumber = state?.draft?.version_number ?? (state?.versions[0]?.version_number ?? 0) + (state?.draft ? 0 : 1)
  const node = graph.nodes.find((n) => n.id === selected) ?? null
  const valid = issues !== null && issues.length === 0

  return (
    <>
      <PageHeader
        title={`Alur: ${t.name}`}
        badges={
          <>
            {active && <Badge tone="green">Terbit v{active.version_number}</Badge>}
            {editable && (state?.draft || dirty) && <Badge tone="amber">Draft v{draftNumber}{dirty ? ' · belum disimpan' : ''}</Badge>}
          </>
        }
        description={
          <>
            <Link to={`/transaksi/jenis/${typeId}`} className="font-semibold text-brand-700 hover:underline">
              ← {t.name}
            </Link>
            {active ? ` · Transaksi yang sedang berjalan tetap memakai versinya.` : ' · Belum ada alur terbit: transaksi jenis ini langsung tercatat.'}
          </>
        }
        actions={
          editable && (
            <>
              <Button variant="secondary" onClick={() => (setTesting((v) => !v), setPath(null))}>
                <Play className="size-4" aria-hidden />
                Uji alur
              </Button>
              <Button variant="secondary" loading={check.isPending} onClick={() => check.mutate()}>
                <ShieldCheck className="size-4" aria-hidden />
                Validasi
              </Button>
              <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>
                <Save className="size-4" aria-hidden />
                Simpan draft
              </Button>
              <Button onClick={() => setPublishing(true)} disabled={issues !== null && issues.length > 0 && !dirty}>
                Terbitkan v{draftNumber}
              </Button>
            </>
          )
        }
      />
      <DesignerNav typeId={typeId} ruleCount={(state?.draft ?? state?.active)?.rules?.length} />

      {!editable && (
        <div className="mb-4">
          <Alert tone="info" title={canUseEditor ? 'Hanya dapat dilihat' : 'Alur hanya dapat dilihat di ponsel'}>
            {canUseEditor ? 'Menggambar dan menerbitkan alur dilakukan oleh Super Admin atau Supervisor.' : 'Menggambar, menguji, dan menerbitkan alur dilakukan di web pada layar lebar.'}
          </Alert>
        </div>
      )}

      <div className="overflow-hidden rounded-xl bg-white shadow-card ring-1 ring-slate-200/80">
        {editable && (
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2">
            <div className="flex items-center gap-1">
              <Button variant="ghost" size="sm" onClick={undo} disabled={!past.length} aria-label="Urungkan" title="Urungkan">
                <Undo2 className="size-4" aria-hidden />
              </Button>
              <Button variant="ghost" size="sm" onClick={redo} disabled={!future.length} aria-label="Ulangi" title="Ulangi">
                <Redo2 className="size-4" aria-hidden />
              </Button>
              <span className="ml-2 hidden text-xs text-slate-500 lg:inline">Tarik garis dari titik di sisi kanan langkah ke langkah berikutnya. Pilih garis atau langkah lalu tekan Delete untuk menghapus.</span>
            </div>
            {issues === null ? (
              <span className="text-xs text-slate-500">Belum diperiksa</span>
            ) : valid ? (
              <Badge tone="green" icon={CheckCircle2}>
                Alur valid
              </Badge>
            ) : (
              <Badge tone="red" icon={AlertTriangle}>
                {issues.length} masalah
              </Badge>
            )}
          </div>
        )}
        {editable && testing && <TestBar typeId={typeId} graph={graph} fields={fields} onPath={setPath} path={path} />}

        <div className={clsx('grid', editable ? 'min-h-[36rem] lg:grid-cols-[9.5rem_1fr_19rem]' : 'h-[32rem]')}>
          {editable && (
            <div className="flex gap-2 overflow-x-auto border-b border-slate-200 p-3 lg:flex-col lg:border-b-0 lg:border-r">
              <p className="hidden text-xs font-bold text-slate-700 lg:block">Langkah</p>
              {PALETTE.map((p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => addNode(p)}
                  className="flex shrink-0 items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50"
                >
                  <Plus className="size-3.5 text-slate-400" aria-hidden />
                  {nodeTypeLabel[p]}
                </button>
              ))}
            </div>
          )}
          <div className="relative min-h-[28rem] bg-slate-50">
            <FlowDiagram
              graph={graph}
              readOnly={!editable}
              highlight={highlight}
              selectedId={selected}
              onSelect={editable ? setSelected : undefined}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              onConnect={onConnect}
            />
          </div>
          {editable && (
            <aside className="max-h-[48rem] space-y-4 overflow-y-auto border-t border-slate-200 p-4 lg:border-l lg:border-t-0">
              {issues && issues.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-bold text-slate-700">Masalah alur</p>
                  <ul className="space-y-2">
                    {issues.map((i, k) => (
                      <li key={k}>
                        <button type="button" className="w-full text-left" onClick={() => i.node_id && setSelected(i.node_id)}>
                          <Alert tone="error">{i.message}</Alert>
                        </button>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-slate-500">Perbaiki dulu sebelum diterbitkan. Draft tetap dapat disimpan.</p>
                </div>
              )}
              {node ? (
                <NodePanel
                  key={node.id}
                  node={node}
                  graph={graph}
                  fields={fields}
                  options={options.data?.data}
                  typeName={t.name}
                  onLabel={(label) => updateNode(node.id, { label })}
                  onConfig={(patch) => updateConfig(node.id, patch)}
                  onRemove={() => {
                    change({ nodes: graph.nodes.filter((n) => n.id !== node.id), edges: graph.edges.filter((e) => e.source !== node.id && e.target !== node.id) })
                    setSelected(null)
                  }}
                  onRemoveEdge={(edgeId) => change({ ...graph, edges: graph.edges.filter((e) => e.id !== edgeId) })}
                />
              ) : (
                <div className="space-y-3 text-sm text-slate-600">
                  <p>Pilih langkah di kanvas untuk mengatur siapa yang mengerjakan, field, syarat, atau aksinya.</p>
                  <Field label="Catatan perubahan (opsional)">{(p) => <Textarea {...p} rows={2} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />}</Field>
                  <Alert tone="info" title="Aturan inti tetap berlaku">
                    Aset yang dipinjam, di luar business unit, atau nonaktif tidak dapat ditransaksikan sembarangan, apa pun alurnya.
                  </Alert>
                  {state && state.versions.length > 0 && (
                    <div>
                      <p className="mb-1 font-semibold text-slate-700">Versi</p>
                      <ul className="space-y-1 text-xs">
                        {state.versions.map((v) => (
                          <li key={v.version_number}>
                            v{v.version_number} · {v.status === 'published' ? `terbit ${formatDateTime(v.published_at)}${v.published_by ? ` oleh ${v.published_by}` : ''}` : 'draft'}
                            {v.change_note ? ` — ${v.change_note}` : ''}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}
            </aside>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={publishing}
        title={`Terbitkan alur v${draftNumber}?`}
        confirmLabel="Terbitkan"
        loading={publish.isPending}
        error={publish.error}
        onConfirm={() => publish.mutate()}
        onClose={() => setPublishing(false)}
      >
        <p>Transaksi baru {t.name} mengikuti alur ini. Transaksi yang sedang berjalan tetap memakai versi saat dibuat. Versi terbit tidak dapat diubah; perubahan berikutnya menjadi draft baru.</p>
      </ConfirmDialog>
    </>
  )
}

/** Settings of the selected step (F-07 §4.1). */
function NodePanel({ node, graph, fields, options, typeName, onLabel, onConfig, onRemove, onRemoveEdge }: {
  node: FlowNode
  graph: FlowGraph
  fields: TypeField[]
  options?: FlowOptions
  typeName: string
  onLabel: (label: string) => void
  onConfig: (patch: Partial<NodeConfig>) => void
  onRemove: () => void
  onRemoveEdge: (id: string) => void
}) {
  const c = node.config ?? {}
  const outs = graph.edges.filter((e) => e.source === node.id)
  const label = (id: string) => graph.nodes.find((n) => n.id === id)?.label ?? id
  const roles = options?.roles ?? []
  const members = options?.members ?? []
  const core = isCoreNode(node)

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-sm font-bold text-slate-700">
          {core && <Lock className="size-3.5" aria-hidden />}
          {core ? 'Aksi inti' : nodeTypeLabel[node.type]}
        </p>
        {!core && (
          <Button variant="ghost" size="sm" onClick={onRemove} aria-label="Hapus langkah">
            <Trash2 className="size-4" aria-hidden />
          </Button>
        )}
      </div>
      <Field label="Nama langkah">{(p) => <Input {...p} maxLength={100} value={node.label ?? ''} onChange={(e) => onLabel(e.target.value)} />}</Field>

      {node.type === 'start' && (
        <>
          <CheckList
            label="Yang boleh memulai"
            hint="Tanpa centang: semua yang berizin mencatat transaksi."
            items={roles.map((r) => ({ value: r.id, label: r.name }))}
            value={c.roles ?? []}
            onChange={(v) => onConfig({ roles: v })}
          />
          <RulesEditor
            label="Prasyarat"
            hint="Transaksi ditolak saat dibuat bila syarat tidak terpenuhi."
            rules={c.preconditions?.rules ?? []}
            match={c.preconditions?.match ?? 'all'}
            fields={fields}
            options={options}
            onChange={(rules, match) => onConfig({ preconditions: { ...c.preconditions, rules, match, assets: c.preconditions?.assets ?? 'all' } })}
          />
          {(c.preconditions?.rules?.length ?? 0) > 0 && (
            <Field label="Pesan bila ditolak">
              {(p) => <Textarea {...p} rows={2} maxLength={300} value={c.message ?? ''} placeholder="mis. Aset yang dipinjam harus dikembalikan dulu." onChange={(e) => onConfig({ message: e.target.value || undefined })} />}
            </Field>
          )}
        </>
      )}

      {node.type === 'input' && (
        <>
          <WhoPicker label="Penanggung jawab" value={c.assignee ?? { type: 'starter' }} roles={roles} members={members} fields={fields} onChange={(assignee) => onConfig({ assignee })} />
          <fieldset>
            <legend className="mb-1.5 text-sm font-semibold text-slate-700">Field diisi di langkah ini</legend>
            {fields.length === 0 && <p className="text-xs text-slate-500">Jenis ini belum punya field. Tambahkan di pengaturan jenis.</p>}
            <ul className="space-y-1.5">
              {fields.map((f) => {
                const on = c.fields?.includes(f.key) ?? false
                const req = c.required?.includes(f.key) ?? false
                return (
                  <li key={f.key} className="flex items-center justify-between gap-2 text-sm">
                    <label className="inline-flex min-w-0 items-center gap-2">
                      <input
                        type="checkbox"
                        className="size-4 accent-brand-700"
                        checked={on}
                        onChange={(e) =>
                          onConfig({
                            fields: e.target.checked ? [...(c.fields ?? []), f.key] : (c.fields ?? []).filter((k) => k !== f.key),
                            required: e.target.checked ? c.required : (c.required ?? []).filter((k) => k !== f.key),
                          })
                        }
                      />
                      <span className="truncate">{f.label}</span>
                    </label>
                    {on && (
                      <label className="inline-flex shrink-0 items-center gap-1 text-xs text-slate-600">
                        <input type="checkbox" className="size-3.5 accent-brand-700" checked={req} onChange={(e) => onConfig({ required: e.target.checked ? [...(c.required ?? []), f.key] : (c.required ?? []).filter((k) => k !== f.key) })} />
                        wajib
                      </label>
                    )}
                  </li>
                )
              })}
            </ul>
          </fieldset>
          <DueDays value={c.due_days} onChange={(due_days) => onConfig({ due_days })} />
        </>
      )}

      {node.type === 'approval' && (
        <>
          <WhoPicker label="Penyetuju" approval value={c.approver ?? { type: 'location_supervisor' }} roles={roles.filter((r) => r.may_approve)} members={members.filter((m) => m.may_approve)} fields={fields} onChange={(approver) => onConfig({ approver })} />
          <Field label="Jumlah minimal penyetuju" hint="Penyetuju berbeda; satu penolakan langsung mengakhiri.">
            {(p) => (
              <Input {...p} type="number" min={1} max={options?.max_approvals ?? 5} value={c.min_approvals ?? 1} onChange={(e) => onConfig({ min_approvals: Math.max(1, Number(e.target.value) || 1) })} />
            )}
          </Field>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="size-4 accent-brand-700" checked={!!c.allow_self} onChange={(e) => onConfig({ allow_self: e.target.checked })} />
            Boleh menyetujui transaksi yang dimulai sendiri
          </label>
          <DueDays value={c.due_days} onChange={(due_days) => onConfig({ due_days })} />
        </>
      )}

      {node.type === 'condition' && (
        <>
          <RulesEditor label='Syarat (cabang "Ya")' rules={c.rules ?? []} match={c.match ?? 'all'} fields={fields} options={options} onChange={(rules, match) => onConfig({ rules, match })} />
          <Field label="Untuk transaksi banyak aset">
            {(p) => (
              <Select {...p} value={c.assets ?? 'any'} onChange={(e) => onConfig({ assets: e.target.value as NodeConfig['assets'] })}>
                <option value="any">Ya bila salah satu aset memenuhi</option>
                <option value="all">Ya bila semua aset memenuhi</option>
                <option value="total">Ya bila total nilai perolehan memenuhi</option>
              </Select>
            )}
          </Field>
        </>
      )}

      {core ? (
        <Alert tone="info" title="Langkah ini tetap ada">
          Mencatat {typeName} dengan isian formulirnya dan aturan inti (mis. aset yang dipinjam tidak dapat dipindah). Langkah ini tidak dapat dihapus atau diganti; tambahkan persetujuan, isi data, atau aksi lain sebelum atau sesudahnya.
        </Alert>
      ) : (
        node.type === 'action' && <ActionsEditor actions={c.actions ?? []} fields={fields} options={options} onChange={(actions) => onConfig({ actions })} />
      )}

      {node.type === 'wait' && (
        <>
          <Field label="Tunggu sampai">
            {(p) => (
              <Select {...p} value={c.until ?? 'date'} onChange={(e) => onConfig({ until: e.target.value as 'date' | 'paired', field: undefined })}>
                <option value="date">Tanggal pada field</option>
                <option value="paired">Transaksi pasangan dicatat (jenis yang menutup jenis ini)</option>
              </Select>
            )}
          </Field>
          {(c.until ?? 'date') === 'date' && (
            <FieldSelect label="Field tanggal" fields={fields} types={['date']} value={c.field} onChange={(field) => onConfig({ field })} />
          )}
          <p className="text-xs text-slate-500">Diperiksa otomatis setiap hari dan saat transaksi pasangan dicatat.</p>
        </>
      )}

      {node.type === 'end' && (
        <Field label="Hasil">
          {(p) => (
            <Select {...p} value={c.result ?? 'completed'} onChange={(e) => onConfig({ result: e.target.value as NodeConfig['result'] })}>
              <option value="completed">Selesai</option>
              <option value="rejected">Ditolak</option>
              <option value="cancelled">Dibatalkan</option>
            </Select>
          )}
        </Field>
      )}

      {node.type !== 'end' && (
        <div>
          <p className="mb-1.5 text-sm font-semibold text-slate-700">Garis keluar</p>
          {outs.length === 0 && <p className="text-xs text-slate-500">Belum ada. Tarik garis dari titik kanan langkah ini.</p>}
          <ul className="space-y-1">
            {outs.map((e: FlowEdge) => (
              <li key={e.id} className="flex items-center justify-between gap-2 text-xs">
                <span>
                  {e.outcome && <Badge tone={e.outcome === 'rejected' || e.outcome === 'no' ? 'slate' : 'green'}>{outcomeLabel[e.outcome]}</Badge>} → {label(e.target)}
                </span>
                <button type="button" className="rounded p-1 text-slate-500 hover:bg-slate-100" onClick={() => onRemoveEdge(e.id)} aria-label={`Hapus garis ke ${label(e.target)}`}>
                  <X className="size-3.5" aria-hidden />
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function DueDays({ value, onChange }: { value?: number | null; onChange: (v: number | null) => void }) {
  return (
    <Field label="Batas waktu (hari)" hint="Kosong: tanpa batas. Tampil di Beranda penanggung jawab.">
      {(p) => <Input {...p} type="number" min={1} max={365} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Math.max(1, Math.round(Number(e.target.value))))} />}
    </Field>
  )
}

function CheckList({ label, hint, items, value, onChange }: { label: string; hint?: string; items: { value: string; label: string }[]; value: string[]; onChange: (v: string[]) => void }) {
  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-semibold text-slate-700">{label}</legend>
      <div className="space-y-1">
        {items.map((i) => (
          <label key={i.value} className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="size-4 accent-brand-700" checked={value.includes(i.value)} onChange={(e) => onChange(e.target.checked ? [...value, i.value] : value.filter((v) => v !== i.value))} />
            {i.label}
          </label>
        ))}
      </div>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </fieldset>
  )
}

/** "Uji alur": a sample asset and values, approval decisions; shows the path, saves nothing. */
function TestBar({ typeId, graph, fields, path, onPath }: { typeId: string; graph: FlowGraph; fields: TypeField[]; path: PathStep[] | null; onPath: (p: PathStep[] | null) => void }) {
  const [search, setSearch] = useState('')
  const [assetId, setAssetId] = useState('')
  const [values, setValues] = useState<FieldValues>({})
  const [decisions, setDecisions] = useState<Record<string, string>>({})
  const assets = useQuery({ queryKey: ['/assets', 'flow-test', search], queryFn: () => api.get<Paginated<Asset>>(`/assets${qs({ search, per_page: 20 })}`) })
  const approvals = graph.nodes.filter((n) => n.type === 'approval')
  const run = useMutation({
    mutationFn: () => flowApi.simulate(typeId, { graph, asset_ids: assetId ? [assetId] : [], field_values: toPayload(fields, values), decisions }),
    onSuccess: (res) => onPath(res.data.path),
    onError: (e) => toast.error('Uji alur gagal', errorMessage(e)),
  })
  const last = path?.[path.length - 1]

  return (
    <div className="space-y-3 border-b border-brand-100 bg-brand-50/60 p-3">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-56">
          <Field label="Cari aset contoh">{(p) => <Input {...p} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="nama atau nomor seri" />}</Field>
        </div>
        <div className="w-72">
          <Field label="Aset contoh">
            {(p) => (
              <Select {...p} value={assetId} onChange={(e) => setAssetId(e.target.value)}>
                <option value="">— Tanpa aset —</option>
                {(assets.data?.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        {approvals.map((n) => (
          <div key={n.id}>
            <p className="mb-1.5 text-sm font-semibold text-slate-700">{n.label ?? 'Persetujuan'}</p>
            <SegmentedControl label={n.label ?? 'Persetujuan'} size="sm" value={decisions[n.id] ?? 'approved'} onChange={(v) => setDecisions((d) => ({ ...d, [n.id]: v }))} options={[{ value: 'approved', label: 'Disetujui' }, { value: 'rejected', label: 'Ditolak' }]} />
          </div>
        ))}
        <Button loading={run.isPending} onClick={() => run.mutate()}>
          <Play className="size-4" aria-hidden />
          Jalankan uji
        </Button>
      </div>
      {fields.length > 0 && (
        <details className="rounded-lg bg-white p-3 ring-1 ring-slate-200">
          <summary className="cursor-pointer text-sm font-semibold text-slate-700">Nilai field contoh</summary>
          <div className="mt-3">
            <CustomFieldInputs typeId={typeId} fields={fields.filter((f) => f.data_type !== 'attachment')} values={values} onChange={(k, v) => setValues((s) => ({ ...s, [k]: v }))} required={[]} />
          </div>
        </details>
      )}
      {path && (
        <Alert tone={last?.outcome === 'blocked' ? 'warning' : 'info'} title={`Jalur: ${path.length} langkah`}>
          {path.map((p) => `${p.label ?? nodeTypeLabel[p.type]}${p.outcome && p.outcome !== 'blocked' ? ` (${outcomeLabel[p.outcome as keyof typeof outcomeLabel] ?? p.outcome})` : ''}`).join(' → ')}
          {last?.outcome === 'blocked' ? '. Prasyarat tidak terpenuhi: transaksi akan ditolak saat dibuat.' : '. Tidak ada transaksi yang disimpan.'}
        </Alert>
      )}
    </div>
  )
}
