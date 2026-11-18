import type { BadgeTone } from '../../components/ui/badgeTones'
import { api, downloadFile, qs } from '../../lib/api/client'
import type { Paginated, Resource } from '../../lib/api/types'
import type { DesignDocument } from './design'

/** Custom Report Designer: API shapes mirroring the backend resources. */

export type TemplateStatus = 'draft' | 'published' | 'archived'
export type TemplateDataSource = 'asset' | 'asset_transaction'

export interface VersionRef {
  id: string
  version_number: number
  status: TemplateStatus
  published_at: string | null
}

export interface ReportTemplate {
  id: string
  code: string
  name: string
  description: string | null
  category: string | null
  data_source: TemplateDataSource
  status: TemplateStatus
  active_version: number | null
  created_by?: string | null
  versions_count?: number
  draft_version?: VersionRef | null
  published_version?: VersionRef | null
  created_at: string
  updated_at: string
}

export interface TemplateVersion {
  id: string
  template_id: string
  version_number: number
  status: TemplateStatus
  design: DesignDocument | null
  excel_config: Record<string, unknown>
  change_note: string | null
  created_by?: string | null
  published_at: string | null
  published_by?: string | null
  created_at: string
  updated_at: string
}

export type FieldDataType = 'string' | 'number' | 'currency' | 'date' | 'boolean' | 'image' | 'array' | 'relation'

export interface ReportField {
  id: string
  data_source: string
  field_key: string
  label: string
  data_type: FieldDataType
  bind_path: string
  relation: string | null
  is_repeatable: boolean
  formats: Record<string, unknown>
  required_permission: string | null
}

export interface TemplateInput {
  code?: string
  name?: string
  description?: string | null
  category?: string | null
  data_source?: TemplateDataSource
  design?: DesignDocument
  excel_config?: Record<string, unknown>
  change_note?: string | null
}

/** Design validation issue from POST /report-templates/{id}/validate. */
export interface DesignIssue {
  severity: 'error' | 'warning'
  element_id: string | null
  path: string
  code: string
  message: string
}

export interface DesignValidation {
  valid: boolean
  issues: DesignIssue[]
  bindings: string[]
}

export interface PreviewColumn {
  key: string
  label: string
  width: number | null
  format: string | null
}

export interface PreviewElement {
  id: string | null
  type: string | null
  x: number
  y: number
  w: number
  h: number
  z: number
  style: Record<string, unknown>
  visible: boolean
  text?: string
  src?: string
  alt?: string | null
  qrValue?: string
  format?: string
  columns?: PreviewColumn[]
  rows?: Record<string, string>[]
}

/** Resolved preview payload from POST /report-templates/{id}/preview (data binding). */
export interface ReportPreview {
  template: { id: string; code: string; name: string; data_source: TemplateDataSource }
  version: { id: string; version_number: number; status: TemplateStatus }
  valid: boolean
  issues: DesignIssue[]
  context: Record<string, string>
  rows: Record<string, string>[]
  total: number
  restricted_fields: string[]
  elements: PreviewElement[]
}

export const templatesApi = {
  list: (params: Record<string, string | number | undefined | null>) =>
    api.get<Paginated<ReportTemplate>>(`/report-templates${qs(params)}`),
  get: (id: string) => api.get<Resource<ReportTemplate>>(`/report-templates/${id}`),
  create: (input: TemplateInput) => api.post<Resource<ReportTemplate>>('/report-templates', input),
  update: (id: string, input: TemplateInput) => api.put<Resource<ReportTemplate>>(`/report-templates/${id}`, input),
  remove: (id: string) => api.delete(`/report-templates/${id}`),
  duplicate: (id: string) => api.post<Resource<ReportTemplate>>(`/report-templates/${id}/duplicate`),
  publish: (id: string, versionNumber?: number) =>
    api.post<Resource<ReportTemplate>>(`/report-templates/${id}/publish`, versionNumber ? { version_number: versionNumber } : {}),
  archive: (id: string) => api.post<Resource<ReportTemplate>>(`/report-templates/${id}/archive`),
  versions: (id: string) => api.get<Paginated<TemplateVersion>>(`/report-templates/${id}/versions${qs({ per_page: 50 })}`),
  /** Registry check of a design document — pass the live canvas doc or omit for the stored version. */
  validate: (id: string, design?: DesignDocument) =>
    api.post<Resource<DesignValidation>>(`/report-templates/${id}/validate`, design ? { design } : {}),
  /** Live-data preview of a version (draft by default) with optional source filters. */
  preview: (id: string, params?: { version_number?: number; filters?: Record<string, string | number | undefined | null> }) =>
    api.post<Resource<ReportPreview>>(`/report-templates/${id}/preview`, params ?? {}),
  /** All registry fields grouped by data source, for the designer's data panel. */
  fields: () => api.get<{ data: Record<string, ReportField[]> }>('/report-fields'),
}

/** Ready-made template from GET /report-templates/samples (F-04). */
export interface SampleTemplate {
  key: string
  code: string
  name: string
  description: string
  category: string | null
  data_source: TemplateDataSource
}

export type RunFilterValue = string | boolean
export type RunFilters = Record<string, RunFilterValue | undefined>

/** One input on the run page, from GET /report-templates/{id}/filters. */
export interface RunFilterField {
  key: string
  label: string
  type: 'date' | 'select' | 'boolean' | 'text' | 'lookup'
  /** Two date fields sharing a range name form one "from – to" pair. */
  range?: string
  options?: { value: string; label: string; group?: string | null }[]
  /** Master list the page loads itself. */
  lookup?: 'categories' | 'locations'
  placeholder?: string
}

export interface RunFilterDefinition {
  data_source: TemplateDataSource
  defaults: RunFilters
  fields: RunFilterField[]
  max_rows: number
}

/** POST /report-templates/{id}/run-preview: the active version filled with live data. */
export interface RunPreview extends ReportPreview {
  filters: RunFilters
  filter_description: string[]
  max_rows: number
}

export type ExportFormat = 'pdf' | 'xlsx'
export type ExportStatus = 'pending' | 'processing' | 'completed' | 'failed' | 'expired'

/** A row of the generate history (`report_exports`). */
export interface ReportExportRow {
  id: string
  export_number: string
  format: ExportFormat
  status: ExportStatus
  downloadable: boolean
  filters: RunFilters
  filter_description: string[]
  row_count: number | null
  file_size: number | null
  template?: { id: string; code: string; name: string }
  version_number?: number
  created_by?: string | null
  created_at: string
  expires_at: string | null
}

export const formatLabel: Record<ExportFormat, string> = { pdf: 'PDF', xlsx: 'Excel' }

/** Only filled values go to the API, so an emptied filter falls back to "all". */
export function compactFilters(filters: RunFilters): Record<string, RunFilterValue> {
  const out: Record<string, RunFilterValue> = {}
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === '' || value === false) continue
    out[key] = value
  }
  return out
}

/** Running a published template (F-05): filters, preview, PDF/Excel, history and download. */
export const runApi = {
  samples: () => api.get<{ data: SampleTemplate[] }>('/report-templates/samples'),
  fromSample: (sample: string) => api.post<Resource<ReportTemplate>>('/report-templates/from-sample', { sample }),
  filters: (id: string) => api.get<Resource<RunFilterDefinition>>(`/report-templates/${id}/filters`),
  preview: (id: string, filters: RunFilters) =>
    api.post<Resource<RunPreview>>(`/report-templates/${id}/run-preview`, { filters: compactFilters(filters) }),
  generate: (id: string, format: ExportFormat, filters: RunFilters) =>
    api.post<Resource<ReportExportRow>>(`/report-templates/${id}/exports`, { format, filters: compactFilters(filters) }),
  history: (params: { template_id?: string; page?: number }) => api.get<Paginated<ReportExportRow>>(`/report-exports${qs(params)}`),
  download: (row: ReportExportRow) => downloadFile(`/report-exports/${row.id}/download`, `${row.export_number}.${row.format}`),
}

export const templateStatusLabel: Record<TemplateStatus, string> = {
  draft: 'Draft',
  published: 'Diterbitkan',
  archived: 'Diarsipkan',
}

export const templateStatusTone: Record<TemplateStatus, BadgeTone> = {
  draft: 'amber',
  published: 'green',
  archived: 'slate',
}

export const dataSourceLabel: Record<TemplateDataSource, string> = {
  asset: 'Data Aset',
  asset_transaction: 'Riwayat Transaksi',
}
