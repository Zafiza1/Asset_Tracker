import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CopyPlus } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import type { Column } from '../../components/ui/Table'
import { api } from '../../lib/api/client'
import type { Branch, Department, GenericCategory, Location, ReferenceItem, TenantCategory } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { MasterListPage } from './MasterListPage'
import { toOptions, useOptions } from './options'

const codeField = { name: 'code', label: 'Kode', required: true, createOnly: true, hint: 'Huruf besar, angka, titik, garis bawah, atau strip.' }

/** Name cell indented by tree depth. */
function treeName(row: { name: string; depth: number }) {
  return (
    <span style={{ paddingLeft: `${row.depth * 1.25}rem` }} className="font-medium text-slate-900">
      {row.depth > 0 && <span className="mr-1 text-slate-400">└</span>}
      {row.name}
    </span>
  )
}

const code = <T extends { code: string }>(): Column<T> => ({ key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.code}</span> })

function useMasterPermissions() {
  const { can } = useAuth()
  return { canCreate: can('master.tenant.create'), canUpdate: can('master.tenant.update'), canArchive: can('master.tenant.archive') }
}

export function BranchesPage() {
  return (
    <MasterListPage<Branch>
      title="Cabang"
      description="Cabang atau unit wilayah organisasi. Lokasi dan departemen dapat dikaitkan ke cabang."
      endpoint="/branches"
      {...useMasterPermissions()}
      columns={[
        code<Branch>(),
        { key: 'name', header: 'Nama', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'phone', header: 'Telepon', cell: (r) => r.phone ?? '—', hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'phone', label: 'Telepon' },
        { name: 'address', label: 'Alamat', type: 'textarea' },
      ]}
    />
  )
}

export function DepartmentsPage() {
  const branches = toOptions(useOptions('/branches').data)
  return (
    <MasterListPage<Department>
      title="Departemen"
      description="Unit kerja penanggung jawab aset."
      endpoint="/departments"
      {...useMasterPermissions()}
      filters={[{ name: 'branch_id', label: 'Cabang', options: branches }]}
      columns={[
        code<Department>(),
        { key: 'name', header: 'Nama', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'branch', header: 'Cabang', cell: (r) => r.branch?.name ?? '—', hideOnMobile: true },
      ]}
      fields={[codeField, { name: 'name', label: 'Nama', required: true }, { name: 'branch_id', label: 'Cabang', type: 'select', options: branches, emptyOption: '— Tanpa cabang —' }]}
    />
  )
}

export function LocationsPage() {
  const branches = toOptions(useOptions('/branches').data)
  const locations = useOptions<Location>('/locations').data
  const types = toOptions(useOptions('/generic-masters/location-types').data)
  return (
    <MasterListPage<Location>
      title="Lokasi"
      description="Lokasi fisik aset, dapat bertingkat (gedung → lantai → ruangan). Sub-lokasi harus berada di cabang yang sama dengan induknya."
      endpoint="/locations"
      {...useMasterPermissions()}
      filters={[
        { name: 'branch_id', label: 'Cabang', options: branches },
        { name: 'location_type_id', label: 'Jenis', options: types },
      ]}
      columns={[
        { key: 'name', header: 'Nama', cell: treeName },
        code<Location>(),
        { key: 'type', header: 'Jenis', cell: (r) => r.location_type?.name ?? '—', hideOnMobile: true },
        { key: 'branch', header: 'Cabang', cell: (r) => r.branch?.name ?? '—', hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'branch_id', label: 'Cabang', type: 'select', required: true, options: branches },
        {
          name: 'parent_id',
          label: 'Lokasi induk',
          type: 'select',
          emptyOption: '— Lokasi utama —',
          options: (locations ?? []).map((l) => ({ value: l.id, label: `${'  '.repeat(l.depth)}${l.name} (${l.branch?.code ?? ''})` })),
        },
        { name: 'location_type_id', label: 'Jenis lokasi', type: 'select', required: true, options: types },
        { name: 'address', label: 'Alamat', type: 'textarea' },
      ]}
      emptyHint="Buat cabang terlebih dahulu, lalu tambahkan lokasi."
    />
  )
}

export function TenantCategoriesPage() {
  const generic = toOptions(useOptions<GenericCategory>('/generic-masters/categories').data)
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  return (
    <MasterListPage<TenantCategory>
      title="Kategori Aset"
      description="Kategori khusus perusahaan. Setiap kategori utama mengkhususkan satu kategori Generic Master; subkategori mewarisi induknya."
      endpoint="/tenant-masters/categories"
      {...useMasterPermissions()}
      filters={[{ name: 'generic_category_id', label: 'Kategori generik', options: generic }]}
      columns={[
        { key: 'name', header: 'Nama', cell: treeName },
        code<TenantCategory>(),
        { key: 'generic', header: 'Kategori generik', cell: (r) => r.generic_category?.name ?? '—', hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'parent_id', label: 'Kategori induk', type: 'select', options: categories, emptyOption: '— Kategori utama —' },
        {
          name: 'generic_category_id',
          label: 'Kategori Generic Master',
          type: 'select',
          createOnly: true,
          options: generic,
          hint: 'Wajib untuk kategori utama; subkategori otomatis mengikuti induknya.',
        },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ]}
    />
  )
}

export function ConditionsPage() {
  const permissions = useMasterPermissions()
  return (
    <MasterListPage<ReferenceItem>
      title="Kondisi Aset"
      description="Kondisi fisik aset. Kondisi standar disalin dari Generic Master dan dapat disesuaikan tanpa mengubah standar platform."
      endpoint="/tenant-masters/conditions"
      {...permissions}
      headerActions={permissions.canCreate && <ImportGenericConditions />}
      columns={[
        code<ReferenceItem>(),
        { key: 'name', header: 'Nama', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'severity', header: 'Tingkat', cell: (r) => r.severity ?? '—' },
        { key: 'source', header: 'Sumber', cell: (r) => (r.generic_condition_id ? 'Standar' : 'Kustom'), hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'severity', label: 'Tingkat keparahan (1 = terbaik)', type: 'number', required: true },
        { name: 'sort_order', label: 'Urutan', type: 'number' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ]}
    />
  )
}

function ImportGenericConditions() {
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: () => api.post<{ meta: { created: number } }>('/tenant-masters/conditions/import-generic'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['/tenant-masters/conditions'] }),
  })
  return (
    <div className="flex items-center gap-2">
      {mutation.isSuccess && <span className="text-sm text-slate-600">{mutation.data.meta.created} kondisi disalin.</span>}
      {mutation.isError && <Alert tone="error">{errorMessage(mutation.error)}</Alert>}
      <Button variant="secondary" loading={mutation.isPending} onClick={() => mutation.mutate()}>
        <CopyPlus className="size-4" aria-hidden /> Salin kondisi standar
      </Button>
    </div>
  )
}
