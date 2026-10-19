import clsx from 'clsx'
import { SlidersHorizontal } from 'lucide-react'
import { useState } from 'react'
import { NavLink } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import type { Column } from '../../components/ui/Table'
import type { Department, Location, LocationType, TenantCategory } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { CategoryAttributesModal } from './CategoryAttributes'
import { MasterListPage } from './MasterListPage'
import { toOptions, useOptions } from './options'

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
      fields={[{ name: 'name', label: 'Nama', required: true }]}
    />
  )
}

/** Locations and their types are one menu: locations on /master/lokasi, types on /master/lokasi/jenis. */
function LocationTabs() {
  const tabs = [
    { to: '/master/lokasi', label: 'Lokasi' },
    { to: '/master/lokasi/jenis', label: 'Jenis Lokasi' },
  ]
  return (
    <>
      <PageHeader title="Lokasi" />
      <nav aria-label="Lokasi" className="mb-4 flex gap-1 border-b border-slate-200">
        {tabs.map((t) => (
          <NavLink
            key={t.to}
            to={t.to}
            end
            className={({ isActive }) =>
              clsx(
                '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                isActive ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-600 hover:text-slate-900',
              )
            }
          >
            {t.label}
          </NavLink>
        ))}
      </nav>
    </>
  )
}

export function LocationsPage() {
  const locations = useOptions<Location>('/locations').data
  const types = toOptions(useOptions('/location-types').data)
  return (
    <>
      <LocationTabs />
      <MasterListPage<Location>
        title="Lokasi"
        embedded
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
          { name: 'name', label: 'Nama', required: true },
          {
            name: 'parent_id',
            label: 'Lokasi induk',
            type: 'select',
            emptyOption: '— Lokasi utama —',
            options: (locations ?? []).map((l) => ({ value: l.id, label: `${'  '.repeat(l.depth)}${l.name} (${l.code})` })),
          },
          { name: 'location_type_id', label: 'Jenis lokasi', type: 'select', required: true, options: types, hint: 'Jenis yang belum ada dapat ditambahkan di tab Jenis Lokasi.' },
          { name: 'address', label: 'Alamat', type: 'textarea' },
        ]}
        emptyHint="Tambahkan lokasi pertama, misalnya gudang atau kantor."
      />
    </>
  )
}

export function LocationTypesPage() {
  return (
    <>
      <LocationTabs />
      <MasterListPage<LocationType>
        title="Jenis Lokasi"
        embedded
        description="Jenis bawaan disediakan sistem dan tidak dapat diubah. Tambahkan jenis lokasi milik organisasi sendiri bila belum tersedia."
        endpoint="/location-types"
        {...useMasterPermissions()}
        isReadOnly={(r) => r.is_built_in}
        columns={[
          { key: 'name', header: 'Nama', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
          code<LocationType>(),
          { key: 'source', header: 'Sumber', cell: (r) => (r.is_built_in ? <Badge tone="slate">Bawaan</Badge> : <Badge tone="blue">Organisasi</Badge>) },
          { key: 'description', header: 'Deskripsi', cell: (r) => r.description ?? '—', hideOnMobile: true },
        ]}
        fields={[
          { name: 'name', label: 'Nama', required: true },
          { name: 'description', label: 'Deskripsi', type: 'textarea' },
        ]}
        emptyHint="Tambahkan jenis lokasi, misalnya gudang dingin atau ruang arsip."
      />
    </>
  )
}

export function TenantCategoriesPage() {
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  const permissions = useMasterPermissions()
  const [attributesOf, setAttributesOf] = useState<TenantCategory | null>(null)
  return (
    <>
      <MasterListPage<TenantCategory>
        title="Kategori Aset"
        description="Kelompok aset organisasi, dipakai sebagai filter utama di Pendataan Aset. Kategori dapat bertingkat; memfilter kategori induk ikut menampilkan aset di subkategorinya. Tambahkan atribut agar formulir aset kategori itu memiliki isian sendiri."
        endpoint="/tenant-masters/categories"
        {...permissions}
        columns={[
          { key: 'name', header: 'Nama', cell: treeName },
          code<TenantCategory>(),
          {
            key: 'attributes',
            header: 'Atribut',
            cell: (r) => (
              <Button size="sm" variant="ghost" onClick={() => setAttributesOf(r)} aria-label={`Atribut ${r.name} (${r.attributes_count ?? 0})`}>
                <SlidersHorizontal className="size-4" aria-hidden /> {r.attributes_count ? `${r.attributes_count} atribut` : permissions.canUpdate && r.status !== 'archived' ? 'Tambah' : '—'}
              </Button>
            ),
          },
          { key: 'description', header: 'Deskripsi', cell: (r) => r.description ?? '—', hideOnMobile: true },
        ]}
        fields={[
          { name: 'name', label: 'Nama', required: true },
          { name: 'parent_id', label: 'Kategori induk', type: 'select', options: categories, emptyOption: '— Kategori utama —' },
          { name: 'description', label: 'Deskripsi', type: 'textarea' },
        ]}
        emptyHint="Tambahkan kategori pertama, misalnya Perangkat IT, Kendaraan, atau Furnitur."
      />
      {attributesOf && <CategoryAttributesModal category={attributesOf} canUpdate={permissions.canUpdate} onClose={() => setAttributesOf(null)} />}
    </>
  )
}
