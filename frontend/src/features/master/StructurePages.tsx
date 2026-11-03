import { FileSpreadsheet, Plus, SlidersHorizontal, Upload } from 'lucide-react'
import { useState } from 'react'
import { Button } from '../../components/ui/Button'
import { PageHeader } from '../../components/ui/Card'
import { ImportModal } from '../../components/ui/ImportModal'
import type { Column } from '../../components/ui/Table'
import type { Department, Location, TenantCategory } from '../../lib/api/types'
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

export function LocationsPage() {
  const locations = useOptions<Location>('/locations').data
  const permissions = useMasterPermissions()
  const [editing, setEditing] = useState<Location | 'new' | null>(null)
  const [importing, setImporting] = useState(false)
  const [bulkEditing, setBulkEditing] = useState(false)
  return (
    <>
      <PageHeader
        title="Lokasi"
        actions={
          (permissions.canCreate || permissions.canUpdate) && (
            <div className="flex gap-2">
              {permissions.canUpdate && (
                <Button variant="secondary" onClick={() => setBulkEditing(true)}>
                  <FileSpreadsheet className="size-4" aria-hidden /> Multiple Edit
                </Button>
              )}
              {permissions.canCreate && (
                <>
                  <Button variant="secondary" onClick={() => setImporting(true)}>
                    <Upload className="size-4" aria-hidden /> Import
                  </Button>
                  <Button onClick={() => setEditing('new')}>
                    <Plus className="size-4" aria-hidden /> Tambah lokasi
                  </Button>
                </>
              )}
            </div>
          )
        }
      />
      <MasterListPage<Location>
        title="Lokasi"
        embedded
        endpoint="/locations"
        canCreate={false}
        canUpdate={permissions.canUpdate}
        canArchive={permissions.canArchive}
        editing={editing}
        onEditChange={setEditing}
        filters={[
        ]}
        columns={[
          { key: 'name', header: 'Nama', cell: treeName },
          code<Location>(),
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
          { name: 'address', label: 'Alamat', type: 'textarea' },
        ]}
        emptyHint="Tambahkan lokasi pertama, misalnya gudang atau kantor."
        showColumnToggle
        defaultColumns={['name', 'code']}
      />
      {importing && (
        <ImportModal
          open={importing}
          onClose={() => setImporting(false)}
          title="Import Lokasi"
          endpoint="/locations/import"
          templateEndpoint="/locations/template"
          templateFilename="template_lokasi.xlsx"
        />
      )}
      {bulkEditing && (
        <ImportModal
          open
          mode="edit"
          onClose={() => setBulkEditing(false)}
          title="Multiple Edit Lokasi (Excel)"
          endpoint="/locations/edit-sheet"
          templateEndpoint="/locations/edit-sheet"
          templateFilename="edit_lokasi.xlsx"
          scopeNote="semua lokasi yang belum diarsipkan"
        />
      )}
    </>
  )
}

export function TenantCategoriesPage() {
  const categories = toOptions(useOptions<TenantCategory>('/tenant-masters/categories').data)
  const permissions = useMasterPermissions()
  const [attributesOf, setAttributesOf] = useState<TenantCategory | null>(null)
  const [importing, setImporting] = useState(false)
  const [bulkEditing, setBulkEditing] = useState(false)
  return (
    <>
      <MasterListPage<TenantCategory>
        title="Kategori Aset"
        endpoint="/tenant-masters/categories"
        {...permissions}
        canDelete={permissions.canArchive}
        headerActions={
          <>
            {permissions.canUpdate && (
              <Button variant="secondary" onClick={() => setBulkEditing(true)}>
                <FileSpreadsheet className="size-4" aria-hidden /> Multiple Edit
              </Button>
            )}
            {permissions.canCreate && (
              <Button variant="secondary" onClick={() => setImporting(true)}>
                <Upload className="size-4" aria-hidden /> Import
              </Button>
            )}
          </>
        }
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
        showColumnToggle
        defaultColumns={['name', 'code', 'attributes']}
      />
      {attributesOf && <CategoryAttributesModal category={attributesOf} canUpdate={permissions.canUpdate} onClose={() => setAttributesOf(null)} />}
      {importing && (
        <ImportModal
          open={importing}
          onClose={() => setImporting(false)}
          title="Import Kategori Aset"
          endpoint="/tenant-masters/categories/import"
          templateEndpoint="/tenant-masters/categories/template"
          templateFilename="template_kategori_aset.xlsx"
        />
      )}
      {bulkEditing && (
        <ImportModal
          open
          mode="edit"
          onClose={() => setBulkEditing(false)}
          title="Multiple Edit Kategori Aset (Excel)"
          endpoint="/tenant-masters/categories/edit-sheet"
          templateEndpoint="/tenant-masters/categories/edit-sheet"
          templateFilename="edit_kategori_aset.xlsx"
          scopeNote="semua kategori yang belum diarsipkan"
        />
      )}
    </>
  )
}
