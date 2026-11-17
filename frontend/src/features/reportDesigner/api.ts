import type { BadgeTone } from '../../components/ui/badgeTones'
import { api, qs } from '../../lib/api/client'
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
