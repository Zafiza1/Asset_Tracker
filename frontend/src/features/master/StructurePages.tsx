import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CopyPlus } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import type { Column } from '../../components/ui/Table'
import { api } from '../../lib/api/client'
import type { Department, Location, ReferenceItem, TenantCategory } from '../../lib/api/types'
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

export function DepartmentsPage() {
  return (
    <MasterListPage<Department>
      title="Departemen"
      description="Unit kerja penanggung jawab aset."
      endpoint="/departments"
      {...useMasterPermissions()}
      columns={[
        code<Department>(),
        { key: 'name', header: 'Nama', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
      ]}
      fields={[codeField, { name: 'name', label: 'Nama', required: true }]}
    />
  )
}

export function LocationsPage() {
  const locations = useOptions<Location>('/locations').data
  const types = toOptions(useOptions('/generic-masters/location-types').data)
  return (
    <MasterListPage<Location>
      title="Lokasi"
      description="Lokasi fisik aset, dapat bertingkat (gedung → lantai → ruangan). Memfilter lokasi induk ikut menampilkan aset di sub-lokasinya."
      endpoint="/locations"
      {...useMasterPermissions()}
      filters={[
        { name: 'location_type_id', label: 'Jenis', options: types },
      ]}
      columns={[
        { key: 'name', header: 'Nama', cell: treeName },
        code<Location>(),
        { key: 'type', header: 'Jenis', cell: (r) => r.location_type?.name ?? '—', hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        {
          name: 'parent_id',
          label: 'Lokasi induk',
          type: 'select',
          emptyOption: '— Lokasi utama —',
          options: (locations ?? []).map((l) => ({ value: l.id, label: `${'  '.repeat(l.depth)}${l.name} (${l.code})` })),
        },
        { name: 'location_type_id', label: 'Jenis lokasi', type: 'select', required: true, options: types },
        { name: 'address', label: 'Alamat', type: 'textarea' },
      ]}
      emptyHint="Tambahkan lokasi pertama, misalnya gudang atau kantor."
    />
  )
}

export function TenantCategoriesPage() {
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  return (
    <MasterListPage<TenantCategory>
      title="Kategori Aset"
      description="Kelompok aset organisasi, dipakai sebagai filter utama di Pendataan Aset. Kategori dapat bertingkat; memfilter kategori induk ikut menampilkan aset di subkategorinya."
      endpoint="/tenant-masters/categories"
      {...useMasterPermissions()}
      columns={[
        { key: 'name', header: 'Nama', cell: treeName },
        code<TenantCategory>(),
        { key: 'description', header: 'Deskripsi', cell: (r) => r.description ?? '—', hideOnMobile: true },
      ]}
      fields={[
        codeField,
        { name: 'name', label: 'Nama', required: true },
        { name: 'parent_id', label: 'Kategori induk', type: 'select', options: categories, emptyOption: '— Kategori utama —' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ]}
      emptyHint="Tambahkan kategori pertama, misalnya Perangkat IT, Kendaraan, atau Furnitur."
    />
  )
}

export function ConditionsPage() {
  const permissions = useMasterPermissions()
  return (
    <MasterListPage<ReferenceItem>
      title="Kondisi Aset"
      description="Kondisi fisik aset. Tingkat keparahan 1 = terbaik; aset dengan tingkat 3 atau lebih ditandai perlu perhatian di dashboard."
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
        <CopyPlus className="size-4" aria-hidden /> Tambahkan kondisi standar
      </Button>
    </div>
  )
}
