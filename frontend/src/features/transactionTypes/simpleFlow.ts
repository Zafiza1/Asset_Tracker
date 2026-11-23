import { isCoreNode, type FlowEdge, type FlowGraph, type FlowNode, type NodeType } from './api'

/**
 * The simple mode of the Transaction Designer (F08-27..29, D-42): a straight flow the wizard can
 * ask about step by step.
 *
 *   Diajukan → up to 2 approvals in a row → at most one "Isi data" → action steps → at most
 *   one "Tunggu" → Selesai; a refused approval ends at one "Ditolak".
 *
 * Approvals that depend on conditions are named rules (F-08g), compiled by the server, so they
 * are not part of this shape. Every step keeps its node (id, label = tahapan name, config), so a
 * flow read from the canvas and written back is the same flow. A flow of another shape is shown
 * with the reasons it needs the advanced mode, and never rewritten silently (F08-29).
 */
export interface SimpleFlow {
  start: FlowNode
  approvals: FlowNode[]
  input: FlowNode | null
  actions: FlowNode[]
  wait: FlowNode | null
  done: FlowNode
  /** The end refused approvals lead to; made when there are approvals. */
  rejected: FlowNode | null
}

export const MAX_SIMPLE_APPROVALS = 2

const RANK: Partial<Record<NodeType, number>> = { approval: 0, input: 1, action: 2, wait: 3 }
const ORDER_LABEL = ['persetujuan', 'isi data', 'aksi', 'tunggu']

/** The flow as a simple flow, or why it is not one (to show in the wizard). */
export function fromGraph(graph: FlowGraph): { flow: SimpleFlow; reasons: [] } | { flow: null; reasons: string[] } {
  const nodes = new Map(graph.nodes.map((n) => [n.id, n]))
  const next = (id: string, outcome: FlowEdge['outcome']) => graph.edges.find((e) => e.source === id && e.outcome === outcome)?.target
  const name = (n: FlowNode) => `"${n.label || n.id}"`
  const reasons: string[] = []
  const starts = graph.nodes.filter((n) => n.type === 'start')
  if (starts.length !== 1) return { flow: null, reasons: ['Alur harus punya tepat satu langkah Mulai.'] }
  const start = starts[0]

  const path: FlowNode[] = []
  const seen = new Set<string>([start.id])
  let done: FlowNode | null = null
  let cursor = next(start.id, null)
  while (cursor) {
    const node = nodes.get(cursor)
    if (!node || seen.has(cursor)) {
      reasons.push(node ? `Alur berulang kembali ke langkah ${name(node)}.` : 'Ada garis ke langkah yang tidak ada.')
      break
    }
    seen.add(cursor)
    if (node.type === 'end') {
      if ((node.config.result ?? 'completed') === 'completed') done = node
      else reasons.push(`Jalur utama berakhir di ${name(node)}, bukan Selesai.`)
      break
    }
    if (node.type === 'condition') {
      reasons.push(`Langkah Kondisi ${name(node)} membuat cabang. Persetujuan bersyarat dapat dibuat sebagai rule.`)
      break
    }
    path.push(node)
    cursor = next(node.id, node.type === 'approval' ? 'approved' : null)
  }
  if (!done && reasons.length === 0) reasons.push('Jalur utama belum sampai di langkah Selesai.')

  const approvals = path.filter((n) => n.type === 'approval')
  const inputs = path.filter((n) => n.type === 'input')
  const waits = path.filter((n) => n.type === 'wait')
  if (approvals.length > MAX_SIMPLE_APPROVALS) reasons.push(`Ada ${approvals.length} persetujuan; mode sederhana memuat paling banyak ${MAX_SIMPLE_APPROVALS}.`)
  if (inputs.length > 1) reasons.push(`Ada ${inputs.length} langkah Isi data; mode sederhana memuat satu.`)
  if (waits.length > 1) reasons.push(`Ada ${waits.length} langkah Tunggu; mode sederhana memuat satu.`)
  for (let i = 1; i < path.length; i++) {
    const before = RANK[path[i - 1].type] ?? 0
    const now = RANK[path[i].type] ?? 0
    if (now < before) {
      reasons.push(`Langkah ${name(path[i])} (${ORDER_LABEL[now]}) ada setelah ${ORDER_LABEL[before]}; mode sederhana berurutan: persetujuan, isi data, aksi, tunggu.`)
      break
    }
  }

  const refusals = new Set(approvals.map((a) => next(a.id, 'rejected')))
  const rejectedId = refusals.size === 1 ? [...refusals][0] : undefined
  const rejected = rejectedId ? (nodes.get(rejectedId) ?? null) : null
  if (approvals.length > 0 && (!rejected || rejected.type !== 'end' || rejected.config.result !== 'rejected')) {
    reasons.push('Setiap penolakan harus berakhir di satu langkah Ditolak yang sama.')
  }

  const used = new Set([start.id, ...path.map((n) => n.id), ...(done ? [done.id] : []), ...(rejected ? [rejected.id] : [])])
  const outside = graph.nodes.filter((n) => !used.has(n.id))
  if (outside.length > 0) reasons.push(`Ada langkah di luar jalur utama: ${outside.map(name).join(', ')}.`)

  if (reasons.length > 0 || !done) return { flow: null, reasons }
  return {
    flow: { start, approvals, input: inputs[0] ?? null, actions: path.filter((n) => n.type === 'action'), wait: waits[0] ?? null, done, rejected: approvals.length ? rejected : null },
    reasons: [],
  }
}

/** The flow drawn from a simple flow, laid out left to right. */
export function toGraph(flow: SimpleFlow): FlowGraph {
  const main = [flow.start, ...flow.approvals, ...(flow.input ? [flow.input] : []), ...flow.actions, ...(flow.wait ? [flow.wait] : []), flow.done]
  const rejected = flow.approvals.length ? (flow.rejected ?? endNode(uniqueId('ditolak', main), 'Ditolak', 'rejected')) : null
  const placed = main.map((n, i) => ({ ...n, position: { x: i * 260, y: 80 } }))
  const nodes = rejected ? [...placed, { ...rejected, position: { x: Math.max(1, flow.approvals.length) * 260, y: 280 } }] : placed
  const edges: FlowEdge[] = []
  for (let i = 0; i < main.length - 1; i++) {
    const from = main[i]
    const approval = from.type === 'approval'
    edges.push({ id: `e_${from.id}_${main[i + 1].id}`, source: from.id, target: main[i + 1].id, outcome: approval ? 'approved' : null })
    if (approval && rejected) edges.push({ id: `e_${from.id}_${rejected.id}`, source: from.id, target: rejected.id, outcome: 'rejected' })
  }
  return { nodes, edges }
}

/** Mulai → Selesai, the simple flow of a type without a flow yet (a system type keeps its core step). */
export function emptySimpleFlow(core?: FlowNode): SimpleFlow {
  return {
    start: { id: 'start', type: 'start', label: 'Diajukan', position: { x: 0, y: 0 }, config: {} },
    approvals: [],
    input: null,
    actions: core ? [core] : [],
    wait: null,
    done: endNode('selesai', 'Selesai', 'completed'),
    rejected: null,
  }
}

/** A step to add, with an id unique in the flow. */
export function newStep(flow: SimpleFlow, type: 'approval' | 'input' | 'action' | 'wait'): FlowNode {
  const ids = allNodes(flow)
  const node = (id: string, label: string, config: FlowNode['config']): FlowNode => ({ id: uniqueId(id, ids), type, label, position: { x: 0, y: 0 }, config })
  switch (type) {
    case 'approval':
      return node('setuju', 'Menunggu persetujuan', { approver: { type: 'location_supervisor' }, min_approvals: 1 })
    case 'input':
      return node('isi', 'Isi data', { assignee: { type: 'starter' }, fields: [], required: [] })
    case 'action':
      return node('aksi', 'Dilaksanakan', { actions: [{ kind: 'set_status' }] })
    case 'wait':
      return node('tunggu', 'Menunggu', { until: 'date' })
  }
}

/** Whether the steps change the asset at all (step 5 "Tidak mengubah aset", F08-07). */
export function changesAsset(flow: SimpleFlow): boolean {
  return flow.actions.some((n) => isCoreNode(n) || (n.config.actions ?? []).some((a) => a.kind !== 'notify'))
}

/** The stage names users follow (F08-45, D-48): the labels of the steps from start to end. */
export function stages(flow: SimpleFlow): string[] {
  return [flow.start, ...flow.approvals, ...(flow.input ? [flow.input] : []), ...flow.actions, ...(flow.wait ? [flow.wait] : []), flow.done].map((n) => n.label || n.id)
}

function endNode(id: string, label: string, result: 'completed' | 'rejected'): FlowNode {
  return { id, type: 'end', label, position: { x: 0, y: 0 }, config: { result } }
}

function allNodes(flow: SimpleFlow): FlowNode[] {
  return [flow.start, ...flow.approvals, ...(flow.input ? [flow.input] : []), ...flow.actions, ...(flow.wait ? [flow.wait] : []), flow.done, ...(flow.rejected ? [flow.rejected] : [])]
}

function uniqueId(base: string, nodes: FlowNode[]): string {
  const taken = new Set(nodes.map((n) => n.id))
  if (!taken.has(base)) return base
  for (let i = 2; ; i++) if (!taken.has(`${base}_${i}`)) return `${base}_${i}`
}
