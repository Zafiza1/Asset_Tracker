import { api } from '../../lib/api/client'

/** Dashboard Aset (docs/factory/features/F-06 §4): API shapes of the dashboard builder. */

export type ComponentType = 'statistic' | 'chart' | 'table' | 'activity' | 'status' | 'reminder' | 'location' | 'note' | 'shortcuts'

/** Widths in the 12-column grid and heights in rows (SystemWidgets::LAYOUT_WIDTHS / LAYOUT_HEIGHTS). */
export const WIDTHS = [3, 4, 6, 8, 12] as const
export type Width = (typeof WIDTHS)[number]
export const WIDTH_LABEL: Record<Width, string> = { 3: '1/4', 4: '1/3', 6: '1/2', 8: '2/3', 12: 'Penuh' }

export interface WidgetLayout {
  w: Width
  h: 1 | 2 | 3
}

export interface DashboardWidget {
  id: string
  widget_key: string
  component_type: ComponentType
  title: string
  custom_title: string | null
  configuration: Record<string, unknown>
  layout: Partial<WidgetLayout>
  sort_order: number
  is_visible: boolean
  is_available: boolean
}

export interface Dashboard {
  id: string
  name: string
  description: string | null
  dashboard_type: 'overview' | 'category' | 'custom' | 'asset_board'
  visibility: 'personal' | 'organization'
  category?: { id: string; code: string; name: string; parent_id: string | null } | null
  template?: { id: string; name: string; scope: string; version: number } | null
  template_update_available?: boolean
  can: { edit: boolean; manage: boolean; delete: boolean }
  widgets: DashboardWidget[]
}

export type ConfigField =
  | { type: 'category_ids' | 'location_ids' | 'transaction_types'; label: string; default?: string[] }
  | { type: 'enum'; label: string; options: string[]; default?: string }
  | { type: 'enum_list'; label: string; options: string[]; default?: string[] }
  | { type: 'integer'; label: string; min?: number; max?: number; default?: number }
  | { type: 'boolean'; label: string; default?: boolean }
  | { type: 'text'; label: string; max?: number; default?: string }
  | { type: 'attribute'; label: string; data_type?: string; data_types?: string[]; default?: string }
  | { type: 'report_template'; label: string; default?: string }

export interface CatalogEntry {
  id: string
  widget_key: string
  name: string
  description: string | null
  component_type: ComponentType
  required_permission: string | null
  configuration_schema: { fields?: Record<string, ConfigField> }
  default_layout: Partial<WidgetLayout>
}

export interface NavigationCategory {
  category_id: string
  parent_id: string | null
  code: string
  name: string
  dashboard_id: string | null
}

export interface Navigation {
  categories: NavigationCategory[]
}

export interface WidgetInput {
  title?: string | null
  configuration?: Record<string, unknown>
  layout?: WidgetLayout
  is_visible?: boolean
}

/**
 * What a widget's data endpoint answers. The shape follows the kind of widget, not its key,
 * so new widgets of a known kind render without frontend changes.
 */
export type WidgetPayload =
  | { value: number | string | null; unit: 'count' | 'money' | 'percent'; currency?: string }
  | { chart: 'donut' | 'bar' | 'line' | 'list'; categories: string[]; series: { key: string; label: string; data: number[] }[] }
  | { columns: { key: string; label: string }[]; rows: Record<string, unknown>[]; count: number }
  | { items: { status: string; label: string; count: number }[] }
  | { items: { id: string | null; name: string; count: number }[] }
  | { days: number; count: number; items: { asset: { id: string; name: string | null }; label: string; due_date: string }[] }
  | { count: number; items: ActivityItem[] }
  | { text: string }
  | { items: { key: string; label: string; target: string; permission: string | null }[] }

export interface ActivityItem {
  id: string
  /** Recent transactions */
  transaction_number?: string
  type?: string
  transaction_date?: string
  created_by?: string | null
  /** Asset change history */
  action?: string
  actor?: string | null
  at?: string | null
  asset: { id: string; name: string | null } | null
}

export const boardApi = {
  assetBoard: () => api.get<{ data: Dashboard }>('/dashboards/asset-board'),
  category: (categoryId: string) => api.get<{ data: Dashboard }>(`/dashboards/categories/${categoryId}`),
  navigation: () => api.get<{ data: Navigation }>('/dashboards/navigation'),
  catalog: () => api.get<{ data: CatalogEntry[] }>('/widget-catalog'),
  widgetData: (dashboardId: string, widgetId: string) => api.get<{ data: WidgetPayload }>(`/dashboards/${dashboardId}/widgets/${widgetId}/data`),
  addWidget: (dashboardId: string, input: WidgetInput & { widget_catalog_id: string }) => api.post<{ data: DashboardWidget }>(`/dashboards/${dashboardId}/widgets`, input),
  updateWidget: (dashboardId: string, widgetId: string, input: WidgetInput) => api.patch<{ data: DashboardWidget }>(`/dashboards/${dashboardId}/widgets/${widgetId}`, input),
  removeWidget: (dashboardId: string, widgetId: string) => api.delete<void>(`/dashboards/${dashboardId}/widgets/${widgetId}`),
  reorder: (dashboardId: string, order: string[]) => api.put<void>(`/dashboards/${dashboardId}/widgets-order`, { order }),
  applyTemplate: (dashboardId: string) => api.post<{ data: Dashboard }>(`/dashboards/${dashboardId}/apply-template`, { template_id: null }),
}

/** A widget's width, falling back to the catalog default and then a third of the row. */
export function widthOf(widget: Pick<DashboardWidget, 'layout'>, fallback?: Partial<WidgetLayout>): Width {
  const w = widget.layout.w ?? fallback?.w
  return (WIDTHS as readonly number[]).includes(w ?? 0) ? (w as Width) : 4
}

export function heightOf(widget: Pick<DashboardWidget, 'layout'>, fallback?: Partial<WidgetLayout>): 1 | 2 | 3 {
  const h = widget.layout.h ?? fallback?.h
  return h === 1 || h === 2 || h === 3 ? h : 2
}

/** Catalog groups shown in "Tambah widget", by what a widget shows. */
export const CATALOG_GROUPS: { key: string; label: string; types: ComponentType[] }[] = [
  { key: 'kpi', label: 'Angka (KPI)', types: ['statistic'] },
  { key: 'chart', label: 'Grafik', types: ['chart', 'status', 'location'] },
  { key: 'list', label: 'Daftar', types: ['table', 'activity', 'reminder'] },
  { key: 'other', label: 'Lain-lain', types: ['note', 'shortcuts'] },
]

/** Labels of enum options used by the catalog (periods, visualizations); unknown values show as is. */
export const OPTION_LABEL: Record<string, string> = {
  '7d': '7 hari terakhir',
  '30d': '30 hari terakhir',
  '90d': '90 hari terakhir',
  '365d': '1 tahun terakhir',
  all: 'Semua waktu',
  donut: 'Donut',
  bar: 'Batang',
  line: 'Garis',
  list: 'Daftar',
}
