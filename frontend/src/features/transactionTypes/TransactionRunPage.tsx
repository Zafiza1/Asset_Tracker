import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, CircleX, Lock, ShieldCheck, Workflow } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, DetailSkeleton, ErrorState } from '../../components/ui/Feedback'
import { Field, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { formatDate, formatDateTime } from '../../lib/format'
import { CustomFieldInputs } from './CustomFieldInputs'
import { toPayload, type FieldValues } from './values'
import { FieldValueList } from './FieldValueList'
import { FlowDiagram } from './FlowDiagram'
import { nodeTypeLabel, outcomeLabel, runStateTone, runsApi, typesApi, type FlowGraph, type FlowNode, type TransactionRun } from './api'

const OUTCOME_TEXT: Record<string, string> = {
  started: 'Dimulai',
  submitted: 'Data diisi',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  yes: 'Ya',
  no: 'Tidak',
  applied: 'Dijalankan',
  passed: 'Lanjut',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
}

/**
 * A transaction going through its flow (F-07 §5): where it is, what happened, its data, and the
 * current step for whoever has to do it. The server checks who may act; the page only offers it.
 */
export function TransactionRunPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [working, setWorking] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [cancelNote, setCancelNote] = useState('')
  const [showDiagram, setShowDiagram] = useState(false)

  const run = useQuery({ queryKey: ['/transaction-runs', id], queryFn: () => runsApi.get(id!) })
  const invalidate = () => Promise.all(['/transaction-runs', '/transactions', '/assets', '/home'].map((k) => queryClient.invalidateQueries({ queryKey: [k] })))

  const cancel = useMutation({
    mutationFn: () => runsApi.cancel(id!, cancelNote.trim()),
    onSuccess: async () => {
      await invalidate()
      setCancelling(false)
      toast.success('Transaksi dibatalkan')
    },
  })

  if (run.isPending) return <DetailSkeleton />
  if (run.isError) return <ErrorState error={run.error} onRetry={() => run.refetch()} />
  const r = run.data.data
  const graph = r.graph ?? { nodes: [], edges: [] }
  const task = r.open_task
  const canCancel = r.state === 'running' && (r.is_mine || can('transaction.reverse'))

  return (
    <>
      <PageHeader
        title={`${r.transaction_number} · ${r.type_definition?.name ?? ''}`}
        badges={<Badge tone={runStateTone[r.state]}>{r.state === 'running' && r.current_step ? `Berjalan · ${r.current_step.label ?? nodeTypeLabel[r.current_step.type]}` : r.state_label}</Badge>}
        meta={
          <>
            <span>{r.assets?.length ?? 0} aset</span>
            <span>Dimulai {r.created_by?.name} · {formatDate(r.created_at)}</span>
            {r.ended_at && <span>Berakhir {formatDateTime(r.ended_at)}</span>}
          </>
        }
        actions={
          <>
            {canCancel && (
              <Button variant="secondary" onClick={() => setCancelling(true)}>
                Batalkan
              </Button>
            )}
            {task?.can_act && <Button onClick={() => setWorking(true)}>{task.kind === 'approval' ? 'Setujui atau tolak' : 'Kerjakan langkah ini'}</Button>}
          </>
        }
      />

      {r.state === 'running' && task && !task.can_act && (
        <div className="mb-4">
          <Alert tone="info" title={`Menunggu ${task.kind === 'approval' ? 'persetujuan' : 'pengisian data'}`}>
            Langkah ini dikerjakan oleh {task.assignee_name ?? 'penanggung jawabnya'}
            {task.kind === 'approval' && task.required_approvals > 1 ? ` (${task.approvals} dari ${task.required_approvals} persetujuan)` : ''}
            {task.due_date ? `, batas ${formatDate(task.due_date)}` : ''}.
          </Alert>
        </div>
      )}
      {r.state === 'running' && !task && r.current_step?.type === 'wait' && (
        <div className="mb-4">
          <Alert tone="info" title="Menunggu">Transaksi lanjut otomatis setelah {r.current_step.label ?? 'syarat langkah ini terpenuhi'}.</Alert>
        </div>
      )}

      <Card
        title={`Alur (versi v${r.flow_version ?? '?'})`}
        actions={
          <Button variant="ghost" size="sm" onClick={() => setShowDiagram((v) => !v)}>
            <Workflow className="size-4" aria-hidden />
            {showDiagram ? 'Sembunyikan diagram' : 'Lihat diagram lengkap'}
          </Button>
        }
        className="mb-4"
      >
        <Progress run={r} graph={graph} />
        {showDiagram && (
          <div className="h-[28rem] border-t border-slate-100">
            <FlowDiagram graph={graph} readOnly highlight={{ done: new Set((r.steps ?? []).map((s) => s.node_id)), now: r.state === 'running' ? r.current_step?.id : undefined }} />
          </div>
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Riwayat langkah" className="lg:col-span-2">
          <ol className="divide-y divide-slate-100 px-5">
            {[...(r.steps ?? [])].reverse().map((s, i) => (
              <li key={i} className="flex gap-3 py-3">
                <span className={clsx('grid size-7 shrink-0 place-items-center rounded-full', s.outcome === 'rejected' || s.outcome === 'cancelled' ? 'bg-red-50 text-red-700' : s.type === 'approval' ? 'bg-brand-50 text-brand-700' : 'bg-green-50 text-green-700')}>
                  {s.outcome === 'rejected' || s.outcome === 'cancelled' ? <CircleX className="size-4" aria-hidden /> : s.type === 'approval' ? <ShieldCheck className="size-4" aria-hidden /> : <Check className="size-4" aria-hidden />}
                </span>
                <div className="min-w-0">
                  <p className="font-medium text-slate-900">
                    {s.label ?? nodeTypeLabel[s.type]}
                    {s.outcome && <span className="font-normal text-slate-600"> · {OUTCOME_TEXT[s.outcome] ?? s.outcome}</span>}
                    {s.actor && <span className="font-normal text-slate-600"> oleh {s.actor}</span>}
                  </p>
                  <p className="text-xs text-slate-500">{nodeTypeLabel[s.type]} · {formatDateTime(s.created_at)}</p>
                  {s.note && <p className="mt-1 whitespace-pre-line text-sm text-slate-700">"{s.note}"</p>}
                </div>
              </li>
            ))}
          </ol>
        </Card>
        <Card title="Data">
          <div className="space-y-4 p-5">
            {/* A system type's built-in input (borrower, target location...), recorded at its core step. */}
            <FieldValueList values={r.system_values ?? []} variant="grid" />
            <FieldValueList values={r.field_values ?? []} variant="grid" emptyText={() => 'belum diisi'} />
            <div>
              <p className="mb-1 text-sm text-slate-500">Aset</p>
              <ul className="space-y-1 text-sm">
                {(r.assets ?? []).map((a) => (
                  <li key={a.id} className="flex flex-wrap items-center gap-2">
                    <Link to={`/aset/${a.id}`} className="font-medium text-brand-700 hover:underline">
                      {a.name}
                    </Link>
                    {a.locked && (
                      <Badge tone="slate" icon={Lock}>
                        dikunci
                      </Badge>
                    )}
                  </li>
                ))}
              </ul>
            </div>
            {r.notes && <p className="whitespace-pre-line text-sm text-slate-700">{r.notes}</p>}
          </div>
        </Card>
      </div>

      {working && task && <TaskModal run={r} graph={graph} onClose={() => setWorking(false)} onDone={invalidate} />}
      <ConfirmDialog open={cancelling} title="Batalkan transaksi?" confirmLabel="Batalkan transaksi" tone="danger" loading={cancel.isPending} error={cancel.error} onConfirm={() => cancel.mutate()} onClose={() => setCancelling(false)}>
        <p>Transaksi {r.transaction_number} berhenti dan aset dilepas. Pembatalan hanya dapat dilakukan sebelum ada perubahan pada aset.</p>
        <div className="mt-3">
          <Field label="Alasan (opsional)">{(p) => <Textarea {...p} rows={2} maxLength={1000} value={cancelNote} onChange={(e) => setCancelNote(e.target.value)} />}</Field>
        </div>
      </ConfirmDialog>
    </>
  )
}

/** The flow as a row of steps: the steps passed, the current one, and the way it will most likely go on. */
function Progress({ run, graph }: { run: TransactionRun; graph: FlowGraph }) {
  const passed = new Map<string, { outcome: string | null; actor: string | null; at: string }>()
  for (const s of run.steps ?? []) passed.set(s.node_id, { outcome: s.outcome, actor: s.actor, at: s.created_at })
  // Steps passed in order, then the rest of the straight path from the current step.
  const order: FlowNode[] = []
  const seen = new Set<string>()
  for (const s of run.steps ?? []) {
    const node = graph.nodes.find((n) => n.id === s.node_id)
    if (node && !seen.has(node.id)) {
      order.push(node)
      seen.add(node.id)
    }
  }
  let cursor = run.state === 'running' ? run.current_step?.id : undefined
  for (let i = 0; cursor && i < 30; i++) {
    const node = graph.nodes.find((n) => n.id === cursor)
    if (!node || (seen.has(node.id) && node.id !== run.current_step?.id)) break
    if (!seen.has(node.id)) {
      order.push(node)
      seen.add(node.id)
    }
    const out = graph.edges.filter((e) => e.source === cursor)
    cursor = (out.find((e) => e.outcome === 'approved' || e.outcome === 'yes' || e.outcome === null) ?? out[0])?.target
  }

  return (
    <ol className="flex overflow-x-auto px-4 py-5" aria-label="Langkah alur">
      {order.map((n, i) => {
        const done = passed.has(n.id) && !(run.state === 'running' && n.id === run.current_step?.id)
        const now = run.state === 'running' && n.id === run.current_step?.id
        const info = passed.get(n.id)
        return (
          <li key={n.id} className="relative flex min-w-28 flex-1 flex-col items-center gap-1.5 text-center text-xs">
            {i < order.length - 1 && <span className={clsx('absolute left-[calc(50%+1.125rem)] top-[0.9rem] h-0.5 w-[calc(100%-2.25rem)]', done ? 'bg-green-600' : 'bg-slate-300')} aria-hidden />}
            <span
              className={clsx(
                'grid size-8 place-items-center rounded-full border-2 font-bold',
                done ? 'border-green-600 bg-green-600 text-white' : now ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-slate-300 bg-white text-slate-400',
              )}
            >
              {done ? <Check className="size-4" aria-hidden /> : i + 1}
            </span>
            <span className="font-semibold text-slate-900">{n.label ?? nodeTypeLabel[n.type]}</span>
            <span className="text-slate-500">
              {now ? 'Sekarang' : info ? [info.outcome && (outcomeLabel[info.outcome as keyof typeof outcomeLabel] ?? OUTCOME_TEXT[info.outcome]), info.actor].filter(Boolean).join(' · ') || formatDate(info.at) : nodeTypeLabel[n.type]}
            </span>
          </li>
        )
      })}
    </ol>
  )
}

/** Doing the current step: fill in its fields, or approve / reject with a note. */
function TaskModal({ run, graph, onClose, onDone }: { run: TransactionRun; graph: FlowGraph; onClose: () => void; onDone: () => Promise<unknown> }) {
  const task = run.open_task!
  const node = graph.nodes.find((n) => n.id === task.node_id)
  const fields = (run.field_values ?? [])
  const stepKeys = node?.config.fields ?? []
  const required = node?.config.required ?? []
  const typeFields = fields
    .filter((v) => stepKeys.includes(v.key) && v.data_type)
    .map((v) => ({ key: v.key, label: v.label, data_type: v.data_type!, settings: {}, is_required: required.includes(v.key) }))
  // Settings (options, limits) come with the type; the run's values only give key, label and type.
  const [values, setValues] = useState<FieldValues>(() => Object.fromEntries(fields.filter((v) => stepKeys.includes(v.key)).map((v) => [v.key, v.value])))
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const submit = useMutation({
    mutationFn: (decision?: 'approved' | 'rejected') =>
      runsApi.complete(run.id, task.id, task.kind === 'approval' ? { decision, note: note.trim() || undefined } : { field_values: toPayload(typeFields, values), note: note.trim() || undefined }),
    onSuccess: async (res) => {
      await onDone()
      toast.success(task.kind === 'approval' ? 'Keputusan tersimpan' : 'Langkah selesai', `${run.transaction_number} · ${res.data.state_label}`)
      onClose()
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  })

  return (
    <Modal
      open
      size="xl"
      title={node?.label ?? nodeTypeLabel[task.kind]}
      onClose={onClose}
      footer={
        task.kind === 'approval' ? (
          <>
            <Button variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button variant="danger" loading={submit.isPending && submit.variables === 'rejected'} onClick={() => (setErrors({}), submit.mutate('rejected'))}>
              Tolak
            </Button>
            <Button loading={submit.isPending && submit.variables === 'approved'} onClick={() => (setErrors({}), submit.mutate('approved'))}>
              Setujui
            </Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button loading={submit.isPending} onClick={() => (setErrors({}), submit.mutate(undefined))}>
              Simpan dan lanjutkan
            </Button>
          </>
        )
      }
    >
      {submit.isError && !Object.keys(errors).length && <Alert tone="error">{errorMessage(submit.error)}</Alert>}
      {task.kind === 'approval' ? (
        <>
          <FieldValueList values={fields} variant="grid" emptyText={() => 'belum diisi'} />
          {task.required_approvals > 1 && (
            <p className="text-sm text-slate-600">
              Perlu {task.required_approvals} persetujuan dari penyetuju berbeda; sudah {task.approvals}.
            </p>
          )}
        </>
      ) : (
        <TypeFieldsLoader typeId={run.type_definition!.id} keys={stepKeys} required={required} values={values} onChange={(k, v) => setValues((s) => ({ ...s, [k]: v }))} errors={errors} />
      )}
      <Field label={task.kind === 'approval' ? 'Catatan (wajib bila menolak)' : 'Catatan'} error={errors.note?.[0]}>
        {(p) => <Textarea {...p} rows={3} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />}
      </Field>
    </Modal>
  )
}

/** The step's fields with their full settings, from the type. */
function TypeFieldsLoader({ typeId, keys, required, values, onChange, errors }: { typeId: string; keys: string[]; required: string[]; values: FieldValues; onChange: (k: string, v: unknown) => void; errors: Record<string, string[]> }) {
  const type = useQuery({ queryKey: ['/transaction-types', typeId], queryFn: () => typesApi.get(typeId) })
  if (type.isPending) return <p className="text-sm text-slate-500">Memuat isian…</p>
  if (type.isError) return <ErrorState error={type.error} />
  const fields = (type.data.data.fields ?? []).filter((f) => keys.includes(f.key))
  return <CustomFieldInputs typeId={typeId} fields={fields} values={values} onChange={onChange} errors={errors} required={required} />
}
