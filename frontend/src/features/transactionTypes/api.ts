import { api, qs } from '../../lib/api/client'
import type { Paginated } from '../../lib/api/types'

/**
 * Organization transaction types, their fields, flows and running transactions (F-07).
 * Shapes mirror TransactionTypeResource, TransactionFlowController and TransactionRunResource.
 */

export type FieldType =
  | 'text' | 'textarea' | 'number' | 'money' | 'date' | 'datetime' | 'select' | 'multiselect'
  | 'boolean' | 'user' | 'location' | 'counterparty' | 'attachment'

export const fieldTypeLabel: Record<FieldType, string> = {
  text: 'Teks pendek',
  textarea: 'Teks panjang',
  number: 'Angka',
  money: 'Uang',
  date: 'Tanggal',
  datetime: 'Tanggal & waktu',
  select: 'Pilihan tunggal',
  multiselect: 'Pilihan ganda',
  boolean: 'Ya/Tidak',
  user: 'Pengguna',
  location: 'Lokasi',
  counterparty: 'Pihak luar',
  attachment: 'Lampiran',
}

export type AttachmentKind = 'pdf' | 'image' | 'office'
export const attachmentKindLabel: Record<AttachmentKind, string> = { pdf: 'PDF', image: 'Gambar', office: 'Word/Excel' }
/** File input `accept` per kind (the API checks the content again). */
export const attachmentAccept: Record<AttachmentKind, string> = {
  pdf: '.pdf,application/pdf',
  image: '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp',
  office: '.doc,.docx,.xls,.xlsx',
}

export interface FieldSettings {
  max_length?: number
  min?: number
  max?: number
  decimals?: number
  options?: { value: string; label: string }[]
  not_before_today?: boolean
  not_after_today?: boolean
  default?: boolean
  accept?: AttachmentKind[]
  max_mb?: number
}

export interface TypeField {
  key: string
  label: string
  data_type: FieldType
  data_type_label?: string
  settings: FieldSettings
  is_required: boolean
}

export type TypeStatus = 'draft' | 'active' | 'inactive'
export const typeStatusLabel: Record<TypeStatus, string> = { draft: 'Draft', active: 'Aktif', inactive: 'Nonaktif' }
export const typeStatusTone: Record<TypeStatus, 'slate' | 'green' | 'amber'> = { draft: 'slate', active: 'green', inactive: 'amber' }

export interface TransactionTypeDefinition {
  id: string
  code: string
  /**
   * One of the seven built-in types (F-07f); null for the organization's own. Listed only with
   * `include_system=1`, read-only, and recorded through the Transaksi menu as before.
   */
  system_type?: string | null
  name: string
  description: string | null
  icon: string | null
  status: TypeStatus
  multi_asset: boolean
  locks_assets: boolean
  closes_type?: { id: string; code: string; name: string } | null
  /** Empty: every category. */
  categories?: { id: string; name: string }[]
  fields?: TypeField[]
  transactions_count?: number
  flow?: { active_version?: number | null; draft_version?: number | null }
  created_at: string
  updated_at: string
}

export interface TypeInput {
  code?: string
  name?: string
  description?: string | null
  multi_asset?: boolean
  locks_assets?: boolean
  closes_type_id?: string | null
  status?: TypeStatus
  category_ids?: string[]
  fields?: { key: string; label: string; data_type: FieldType; settings?: FieldSettings; is_required?: boolean }[]
}

// ---- Flow ----

export type NodeType = 'start' | 'input' | 'approval' | 'condition' | 'action' | 'wait' | 'end'
export type EdgeOutcome = 'approved' | 'rejected' | 'yes' | 'no' | null

export const nodeTypeLabel: Record<NodeType, string> = {
  start: 'Mulai',
  input: 'Isi data',
  approval: 'Persetujuan',
  condition: 'Kondisi',
  action: 'Aksi sistem',
  wait: 'Tunggu',
  end: 'Selesai',
}

export const outcomeLabel: Record<Exclude<EdgeOutcome, null>, string> = { approved: 'Disetujui', rejected: 'Ditolak', yes: 'Ya', no: 'Tidak' }

export interface Rule {
  subject: string
  op: string
  value?: unknown
}

export interface Who {
  type: 'starter' | 'role' | 'user' | 'field' | 'location_supervisor'
  id?: string
  field?: string
}

export interface FlowAction {
  kind: 'set_status' | 'move_to_field_location' | 'set_condition' | 'loan_to_field_user' | 'return' | 'notify'
  status?: string
  field?: string
  value?: string
  user_field?: string
  due_field?: string
  location_field?: string
  to?: Who
  message?: string
}

export interface NodeConfig {
  roles?: string[]
  preconditions?: { rules: Rule[]; match?: 'all' | 'any'; assets?: 'all' | 'any' }
  message?: string
  assignee?: Who
  approver?: Who
  fields?: string[]
  required?: string[]
  due_days?: number | null
  min_approvals?: number
  allow_self?: boolean
  rules?: Rule[]
  match?: 'all' | 'any'
  assets?: 'any' | 'all' | 'total'
  actions?: FlowAction[]
  until?: 'date' | 'paired'
  /** Wait: the date field waited for. */
  field?: string
  result?: 'completed' | 'rejected' | 'cancelled'
}

export interface FlowNode {
  id: string
  type: NodeType
  label: string | null
  position: { x: number; y: number }
  config: NodeConfig
}

export interface FlowEdge {
  id: string
  source: string
  target: string
  outcome: EdgeOutcome
}

export interface FlowGraph {
  nodes: FlowNode[]
  edges: FlowEdge[]
}

export interface FlowIssue {
  code: string
  message: string
  node_id: string | null
}

export interface FlowVersion {
  id: string
  version_number: number
  status: 'draft' | 'published'
  graph: FlowGraph
  change_note: string | null
  published_at: string | null
  updated_at: string | null
  issues?: FlowIssue[]
}

export interface FlowState {
  active: FlowVersion | null
  draft: FlowVersion | null
  versions: { version_number: number; status: 'draft' | 'published'; change_note: string | null; published_at: string | null; published_by: string | null }[]
}

export interface FlowOptions {
  roles: { id: string; name: string; may_approve: boolean }[]
  members: { id: string; name: string; may_approve: boolean }[]
  action_statuses: { value: string; label: string }[]
  asset_statuses: { value: string; label: string }[]
  conditions: { value: string; label: string }[]
  max_approvals: number
  max_message: number
}

export interface PathStep {
  node_id: string
  type: NodeType
  label: string | null
  outcome: string | null
}

// ---- Runs ----

export type RunState = 'running' | 'completed' | 'rejected' | 'cancelled'
export const runStateTone: Record<RunState, 'amber' | 'green' | 'red' | 'slate'> = { running: 'amber', completed: 'green', rejected: 'red', cancelled: 'slate' }

export interface AttachmentSummary {
  id: string
  name: string
  size: number
  mime_type: string
}

export interface RunFieldValue {
  key: string
  label: string
  data_type: FieldType | null
  value: unknown
  /** The value as text: option labels, member and location names, file names. */
  display: string | null
}

export interface OpenTask {
  id: string
  node_id: string
  kind: 'input' | 'approval'
  assignee_type: 'user' | 'role' | 'location_supervisor'
  assignee_id: string | null
  assignee_name: string | null
  due_date: string | null
  required_approvals: number
  approvals: number
  can_act: boolean
}

export interface TransactionRun {
  id: string
  transaction_number: string
  state: RunState
  state_label: string
  type_definition?: { id: string; code: string; name: string }
  flow_version?: number
  current_step: { id: string; type: NodeType; label: string | null } | null
  assets?: { id: string; name: string; locked: boolean }[]
  transaction_date: string
  notes: string | null
  created_by?: { id: string; name: string }
  is_mine: boolean
  created_at: string
  ended_at: string | null
  field_values?: RunFieldValue[]
  graph?: FlowGraph
  steps?: { node_id: string; type: NodeType; label: string | null; outcome: string | null; actor: string | null; note: string | null; created_at: string }[]
  open_task?: OpenTask | null
}

export const typesApi = {
  list: (params: Record<string, string | undefined> = {}) =>
    api.get<{ data: TransactionTypeDefinition[] }>(`/transaction-types${qs(params)}`),
  get: (id: string) => api.get<{ data: TransactionTypeDefinition }>(`/transaction-types/${id}`),
  create: (input: TypeInput) => api.post<{ data: TransactionTypeDefinition }>('/transaction-types', input),
  update: (id: string, input: TypeInput) => api.put<{ data: TransactionTypeDefinition }>(`/transaction-types/${id}`, input),
  remove: (id: string) => api.delete<void>(`/transaction-types/${id}`),
  members: (search: string, ids: string[] = []) =>
    api.get<{ data: { id: string; name: string }[] }>(`/transaction-members${qs({ search, ids: ids.join(',') })}`),
  upload: (typeId: string, fieldKey: string, file: File) => {
    const form = new FormData()
    form.append('field_key', fieldKey)
    form.append('file', file)
    return api.upload<{ data: AttachmentSummary }>(`/transaction-types/${typeId}/attachments`, form)
  },
}

export const flowApi = {
  get: (typeId: string) => api.get<{ data: FlowState }>(`/transaction-types/${typeId}/flow`),
  save: (typeId: string, graph: FlowGraph, change_note?: string) =>
    api.put<{ data: FlowVersion }>(`/transaction-types/${typeId}/flow`, { graph, change_note: change_note || null }),
  validate: (typeId: string, graph?: FlowGraph) =>
    api.post<{ data: { valid: boolean; issues: FlowIssue[] } }>(`/transaction-types/${typeId}/flow/validate`, graph ? { graph } : {}),
  publish: (typeId: string) => api.post<{ data: FlowVersion }>(`/transaction-types/${typeId}/flow/publish`),
  simulate: (typeId: string, body: { graph?: FlowGraph; asset_ids: string[]; field_values?: Record<string, unknown>; decisions?: Record<string, string> }) =>
    api.post<{ data: { path: PathStep[] } }>(`/transaction-types/${typeId}/flow/simulate`, body),
  options: () => api.get<{ data: FlowOptions }>('/transaction-flow-options'),
}

export const runsApi = {
  list: (params: Record<string, string | undefined> = {}) => api.get<Paginated<TransactionRun>>(`/transaction-runs${qs(params)}`),
  get: (id: string) => api.get<{ data: TransactionRun }>(`/transaction-runs/${id}`),
  complete: (id: string, taskId: string, body: { decision?: 'approved' | 'rejected'; field_values?: Record<string, unknown>; note?: string }) =>
    api.post<{ data: TransactionRun }>(`/transaction-runs/${id}/tasks/${taskId}/complete`, body),
  cancel: (id: string, note?: string) => api.post<{ data: TransactionRun }>(`/transaction-runs/${id}/cancel`, { note: note || null }),
}

/** Fields filled in at a later "Isi data" step of the flow: not asked when starting. */
export function laterFields(graph: FlowGraph | null | undefined): Set<string> {
  return new Set((graph?.nodes ?? []).filter((n) => n.type === 'input').flatMap((n) => n.config.fields ?? []))
}

/** Readable file size, e.g. "1,2 MB". */
export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toLocaleString('id-ID', { maximumFractionDigits: 0 })} KB`
  return `${(bytes / 1024 / 1024).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`
}
