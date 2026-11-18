import '@xyflow/react/dist/style.css'
import {
  Background,
  Controls,
  Handle,
  MarkerType,
  Position,
  ReactFlow,
  type Connection,
  type Edge,
  type EdgeChange,
  type Node,
  type NodeChange,
  type NodeProps,
} from '@xyflow/react'
import clsx from 'clsx'
import { useMemo } from 'react'
import { nodeTypeLabel, outcomeLabel, type FlowAction, type FlowGraph, type FlowNode, type NodeType } from './api'

/** Colors per step type (F-07 mockup): start green, approval blue, action purple, condition/wait amber, end slate/red. */
const NODE_STYLE: Record<NodeType, string> = {
  start: 'rounded-full border-green-600 text-center',
  input: 'rounded-lg border-slate-400',
  approval: 'rounded-lg border-brand-600',
  condition: 'rounded-lg border-amber-500 border-dashed',
  action: 'rounded-lg border-violet-600',
  wait: 'rounded-lg border-amber-500 border-dotted',
  end: 'rounded-full border-slate-500 text-center',
}
const KIND_COLOR: Record<NodeType, string> = {
  start: 'text-green-700',
  input: 'text-slate-600',
  approval: 'text-brand-700',
  condition: 'text-amber-800',
  action: 'text-violet-700',
  wait: 'text-amber-800',
  end: 'text-slate-600',
}

const ACTION_TEXT: Record<FlowAction['kind'], string> = {
  set_status: 'ubah status',
  move_to_field_location: 'pindah lokasi',
  set_condition: 'ubah kondisi',
  loan_to_field_user: 'pinjamkan',
  return: 'kembalikan',
  notify: 'notifikasi',
}

/** One-line summary of a step's settings shown under its name. */
function nodeSummary(n: FlowNode): string {
  const c = n.config ?? {}
  switch (n.type) {
    case 'start':
      return c.preconditions?.rules?.length ? `prasyarat: ${c.preconditions.rules.length} aturan` : 'siapa saja yang berizin'
    case 'input':
      return c.fields?.length ? `${c.fields.length} field${c.due_days ? ` · batas ${c.due_days} hari` : ''}` : 'belum ada field'
    case 'approval': {
      const who = c.approver?.type === 'location_supervisor' ? 'atasan lokasi' : c.approver?.type === 'role' ? 'role' : c.approver?.type === 'user' ? 'pengguna' : 'pilih penyetuju'
      return `${who} · min. ${c.min_approvals ?? 1}${c.allow_self ? '' : ' · bukan pengaju'}`
    }
    case 'condition':
      return `${c.rules?.length ?? 0} syarat`
    case 'action':
      return (c.actions ?? []).map((a) => ACTION_TEXT[a.kind]).join(', ') || 'belum ada aksi'
    case 'wait':
      return c.until === 'paired' ? 'transaksi pasangan' : 'sampai tanggal'
    case 'end':
      return c.result === 'rejected' ? 'hasil: ditolak' : c.result === 'cancelled' ? 'hasil: dibatalkan' : 'hasil: selesai'
  }
}

interface StepData extends Record<string, unknown> {
  node: FlowNode
  state?: 'done' | 'now' | 'path' | 'error'
}

function StepNode({ data, selected }: NodeProps<Node<StepData>>) {
  const n = data.node
  const rejected = n.type === 'end' && n.config?.result !== 'completed' && n.config?.result !== undefined
  return (
    <div
      className={clsx(
        'w-44 border-2 bg-white px-3 py-2 text-xs shadow-card',
        NODE_STYLE[n.type],
        rejected && 'border-red-600',
        data.state === 'done' && 'bg-green-50',
        data.state === 'now' && 'ring-4 ring-amber-200',
        data.state === 'path' && 'ring-4 ring-brand-100',
        data.state === 'error' && 'border-red-600 bg-red-50',
        selected && 'outline outline-2 outline-offset-2 outline-brand-600',
      )}
    >
      {n.type !== 'start' && <Handle type="target" position={Position.Left} className="!size-2.5 !border-2 !border-slate-400 !bg-white" />}
      <div className={clsx('text-[10px] font-bold uppercase tracking-wide', rejected ? 'text-red-700' : KIND_COLOR[n.type])}>{nodeTypeLabel[n.type]}</div>
      <div className="truncate text-[13px] font-semibold text-slate-900">{n.label || nodeTypeLabel[n.type]}</div>
      <div className="truncate text-slate-500">{nodeSummary(n)}</div>
      {n.type !== 'end' && <Handle type="source" position={Position.Right} className="!size-2.5 !border-2 !border-slate-400 !bg-white" />}
    </div>
  )
}

const nodeTypes = { step: StepNode }

function toFlowNodes(graph: FlowGraph, state: (n: FlowNode) => StepData['state'] = () => undefined): Node<StepData>[] {
  return graph.nodes.map((n) => ({ id: n.id, type: 'step', position: n.position ?? { x: 0, y: 0 }, data: { node: n, state: state(n) } }))
}

function toFlowEdges(graph: FlowGraph, onPath?: Set<string>): Edge[] {
  return graph.edges.map((e) => ({
    id: e.id,
    source: e.source,
    target: e.target,
    label: e.outcome ? outcomeLabel[e.outcome] : undefined,
    labelBgPadding: [6, 3] as [number, number],
    labelBgBorderRadius: 4,
    labelStyle: { fontWeight: 600, fontSize: 11 },
    markerEnd: { type: MarkerType.ArrowClosed, color: onPath?.has(e.id) ? '#2563eb' : '#94a3b8' },
    style: { stroke: onPath?.has(e.id) ? '#2563eb' : e.outcome === 'rejected' || e.outcome === 'no' ? '#94a3b8' : '#64748b', strokeWidth: onPath?.has(e.id) ? 3 : 2 },
  }))
}

interface Props {
  graph: FlowGraph
  readOnly?: boolean
  /** Run detail: steps passed and the current one. */
  highlight?: { done?: Set<string>; now?: string; path?: Set<string>; errors?: Set<string>; edges?: Set<string> }
  selectedId?: string | null
  onSelect?: (id: string | null) => void
  onNodesChange?: (changes: NodeChange[]) => void
  onEdgesChange?: (changes: EdgeChange[]) => void
  onConnect?: (c: Connection) => void
  onDropType?: (type: NodeType, position: { x: number; y: number }) => void
}

/**
 * The flow diagram on a React Flow canvas (D-25): steps as boxes/ovals, edges with their
 * outcome. Read-only on the run page and on phones; the editor passes the change handlers.
 */
export function FlowDiagram({ graph, readOnly, highlight, selectedId, onSelect, onNodesChange, onEdgesChange, onConnect }: Props) {
  const nodes = useMemo(
    () =>
      toFlowNodes(graph, (n) =>
        highlight?.errors?.has(n.id) ? 'error' : highlight?.now === n.id ? 'now' : highlight?.done?.has(n.id) ? 'done' : highlight?.path?.has(n.id) ? 'path' : undefined,
      ).map((n) => ({ ...n, selected: n.id === selectedId })),
    [graph, highlight, selectedId],
  )
  const edges = useMemo(() => toFlowEdges(graph, highlight?.edges), [graph, highlight])

  return (
    <ReactFlow
      nodes={nodes}
      edges={edges}
      nodeTypes={nodeTypes}
      fitView
      fitViewOptions={{ padding: 0.2 }}
      minZoom={0.2}
      maxZoom={1.5}
      nodesDraggable={!readOnly}
      nodesConnectable={!readOnly}
      elementsSelectable={!readOnly || !!onSelect}
      deleteKeyCode={readOnly ? null : ['Delete', 'Backspace']}
      onNodesChange={onNodesChange ? (changes) => onNodesChange(changes.toArray() as NodeChange[]) : undefined}
      onEdgesChange={onEdgesChange ? (changes) => onEdgesChange(changes.toArray()) : undefined}
      onConnect={onConnect}
      onNodeClick={(_, n) => onSelect?.(n.id)}
      onPaneClick={() => onSelect?.(null)}
      proOptions={{ hideAttribution: true }}
    >
      <Background gap={16} />
      <Controls showInteractive={false} />
    </ReactFlow>
  )
}
