import { Plus, SlidersHorizontal } from 'lucide-react'
import { useState } from 'react'
import { AddMenu } from '../../components/ui/AddMenu'
import { ImportModal } from '../../components/ui/ImportModal'
import type { Column } from '../../components/ui/Table'
import type { Customer, Department, Employee, TenantCategory, Vehicle } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { formatDate } from '../../lib/format'
import { CategoryAttributesModal } from './CategoryAttributes'
import { MasterListPage, type MasterImpact } from './MasterListPage'
import { toOptions, useOptions } from './options'

/** A code as a compact monospace chip; long generated codes end in "…" with the full code as tooltip. */
function CodeChip({ code }: { code: string }) {
  return (
    <span title={code} className="inline-block max-w-full truncate rounded bg-slate-100 px-1.5 py-0.5 align-middle font-mono text-xs font-medium tracking-tight text-slate-700 ring-1 ring-inset ring-slate-200">
      {code}
    </span>
  )
}

const code = <T extends { code: string }>(): Column<T> => ({ key: 'code', header: 'Kode', className: 'w-44', hideOnMobile: true, sortKey: 'code', cell: (r) => <CodeChip code={r.code} /> })

function useMasterPermissions() {
  const { can } = useAuth()
  return { canCreate: can('master.tenant.create'), canUpdate: can('master.tenant.update'), canArchive: can('master.tenant.archive') }
}

const DELIVERY_ROLE_OPTIONS = [
  { value: 'driver', label: 'Supir' },
  { value: 'helper', label: 'Kernet' },
]

/** Divisi (stored as departments). An employee's division is also their position (jabatan). */
export function DepartmentsPage() {
  return (
    <MasterListPage<Department>
      title="Divisi"
      description="Divisi sekaligus jabatan karyawan, misalnya Supir, Kernet, atau Petugas Gudang. Divisi yang ditandai Supir atau Kernet bisa dipilih sebagai kru di pengiriman."
      endpoint="/departments"
      emptyHint="Tambahkan divisi, misalnya Supir, Kernet, dan Petugas Gudang, lalu pilih divisinya di data karyawan."
      {...useMasterPermissions()}
      filters={[{ name: 'delivery_role', label: 'Peran di pengiriman', options: DELIVERY_ROLE_OPTIONS }]}
      columns={[
        code<Department>(),
        { key: 'name', header: 'Nama', sortKey: 'name', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'delivery_role', header: 'Peran di pengiriman', sortKey: 'delivery_role', className: 'w-44', cell: (r) => r.delivery_role_label ?? '—' },
        { key: 'employees', header: 'Karyawan', className: 'w-28', hideOnMobile: true, cell: (r) => r.employees_count ?? 0 },
      ]}
      fields={[
        { name: 'name', label: 'Nama', required: true, placeholder: 'mis. Supir' },
        { name: 'code', label: 'Kode', createOnly: true, hint: 'Boleh dikosongkan: dibuat otomatis dari nama. Huruf besar, angka, - _ . (maks. 30).' },
        {
          name: 'delivery_role',
          label: 'Peran di pengiriman',
          type: 'select',
          options: DELIVERY_ROLE_OPTIONS,
          emptyOption: '— Tidak ada —',
          hint: 'Supir: karyawannya bisa dipilih sebagai supir. Kernet: bisa dipilih sebagai kernet (supir juga bisa menjadi kernet).',
        },
      ]}
    />
  )
}

/**
 * Name cell of a tree master. In the full list rows are indented as a tree; once the list is narrowed by
 * search or filters the parent rows may be missing, so the parent is named instead. On small screens,
 * where the code column is hidden, the code is shown under the name, with an optional extra line.
 */
function treeNameCell(row: { name: string; code: string; depth: number; parent?: { name: string } | null }, filtered: boolean, extra?: string | null) {
  return (
    <div className="min-w-0" style={filtered ? undefined : { paddingLeft: `${row.depth * 1.25}rem` }}>
      <div className="truncate font-medium text-slate-900" title={row.name}>
        {!filtered && row.depth > 0 && <span className="mr-1 text-slate-400">└</span>}
        {row.name}
      </div>
      {filtered && row.parent && <div className="truncate text-xs text-slate-500">di bawah {row.parent.name}</div>}
      <div className="mt-0.5 md:hidden">
        <CodeChip code={row.code} />
        {extra && <div className="mt-0.5 truncate text-xs text-slate-500">{extra}</div>}
      </div>
    </div>
  )
}

const today = () => new Date().toISOString().slice(0, 10)

export function EmployeesPage() {
  const divisions = toOptions(useOptions<Department>('/departments').data)
  return (
    <MasterListPage<Employee>
      title="Karyawan"
      description="Supir, kernet, dan karyawan lain. Jabatan karyawan adalah divisinya; karyawan divisi Supir dan Kernet dipilih di mobile saat tabung dikirim atau dijemput."
      endpoint="/employees"
      searchPlaceholder="Cari NIK, nama, atau No HP"
      emptyHint="Tambahkan supir dan kernet agar bisa dipilih di aplikasi mobile."
      {...useMasterPermissions()}
      filters={[{ name: 'department_id', label: 'Divisi', options: divisions }]}
      columns={[
        { ...code<Employee>(), header: 'NIK' },
        { key: 'name', header: 'Nama', sortKey: 'name', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'department', header: 'Divisi', sortKey: 'department', className: 'w-40', cell: (r) => r.department?.name ?? '—' },
        { key: 'phone', header: 'No HP', className: 'w-40', hideOnMobile: true, cell: (r) => r.phone ?? '—' },
        {
          key: 'license',
          header: 'SIM',
          className: 'w-48',
          hideOnMobile: true,
          cell: (r) =>
            r.license_number ? (
              <div>
                <div>{r.license_number}</div>
                {r.license_expires_on && <div className={r.license_expires_on < today() ? 'text-xs font-semibold text-red-700' : 'text-xs text-slate-500'}>{r.license_expires_on < today() ? 'Habis' : 'Berlaku s.d.'} {formatDate(r.license_expires_on)}</div>}
              </div>
            ) : (
              '—'
            ),
        },
      ]}
      fields={[
        { name: 'name', label: 'Nama', required: true },
        { name: 'code', label: 'NIK', createOnly: true, hint: 'Boleh dikosongkan: dibuat otomatis dari nama. Huruf besar, angka, - _ . (maks. 30).' },
        { name: 'department_id', label: 'Divisi', type: 'select', required: true, options: divisions, emptyOption: '— Pilih divisi —', hint: 'Divisi sekaligus jabatan karyawan. Atur daftarnya di Master Data › Divisi.' },
        { name: 'phone', label: 'No HP' },
        { name: 'license_number', label: 'No SIM', hint: 'Untuk supir.' },
        { name: 'license_expires_on', label: 'SIM berlaku sampai', type: 'date' },
      ]}
    />
  )
}

export function CustomersPage() {
  return (
    <MasterListPage<Customer>
      title="Customer"
      description="Tujuan tabung dikirim dan tempat tabung dijemput."
      endpoint="/customers"
      searchPlaceholder="Cari kode, nama, alamat, atau kontak"
      emptyHint="Tambahkan customer agar bisa dipilih di transaksi masuk dan keluar."
      {...useMasterPermissions()}
      columns={[
        code<Customer>(),
        { key: 'name', header: 'Nama', sortKey: 'name', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'address', header: 'Alamat', sortKey: 'address', hideOnMobile: true, className: 'max-w-xs truncate', cell: (r) => r.address ?? '—', title: (r) => r.address },
        { key: 'contact', header: 'Kontak', hideOnMobile: true, cell: (r) => [r.contact_name, r.phone].filter(Boolean).join(' · ') || '—' },
      ]}
      fields={[
        { name: 'name', label: 'Nama', required: true },
        { name: 'code', label: 'Kode', createOnly: true, hint: 'Boleh dikosongkan: dibuat otomatis dari nama. Huruf besar, angka, - _ . (maks. 30).' },
        { name: 'address', label: 'Alamat', type: 'textarea' },
        { name: 'contact_name', label: 'Nama kontak' },
        { name: 'phone', label: 'No HP' },
      ]}
    />
  )
}

const VEHICLE_TYPE_OPTIONS = [
  { value: 'truk', label: 'Truk' },
  { value: 'pickup', label: 'Pick Up' },
  { value: 'mobil', label: 'Mobil' },
  { value: 'motor', label: 'Motor' },
  { value: 'bus', label: 'Bus' },
  { value: 'forklift', label: 'Forklift' },
  { value: 'alat_berat', label: 'Alat Berat' },
  { value: 'lainnya', label: 'Lainnya' },
]

const FUEL_OPTIONS = [
  { value: 'bensin', label: 'Bensin' },
  { value: 'solar', label: 'Solar' },
  { value: 'listrik', label: 'Listrik' },
  { value: 'hybrid', label: 'Hybrid' },
  { value: 'gas', label: 'Gas' },
  { value: 'lainnya', label: 'Lainnya' },
]

/** A document's end date, red once it has passed. */
function ValidUntil({ date }: { date: string | null }) {
  if (!date) return <>—</>
  const expired = date < today()
  return <span className={expired ? 'font-semibold text-red-700' : undefined}>{expired ? 'Habis ' : ''}{formatDate(date)}</span>
}

/** Master Data › Kendaraan: what cylinders are delivered with. Not assets: Data Aset only holds cylinders. */
export function VehiclesPage() {
  return (
    <MasterListPage<Vehicle>
      title="Kendaraan"
      description="Kendaraan untuk mengirim dan menjemput tabung. Kendaraan aktif bisa dipilih di transaksi dan di mobile."
      endpoint="/vehicles"
      searchPlaceholder="Cari kode, nama, nomor polisi, rangka, atau mesin"
      emptyHint="Tambahkan kendaraan agar bisa dipilih saat tabung dikirim atau dijemput."
      {...useMasterPermissions()}
      filters={[{ name: 'type', label: 'Jenis', options: VEHICLE_TYPE_OPTIONS }]}
      columns={[
        code<Vehicle>(),
        {
          key: 'plate_number',
          header: 'Nomor Polisi',
          sortKey: 'plate_number',
          className: 'w-36',
          cell: (r) => <span className="font-mono text-sm font-medium text-slate-900">{r.plate_number}</span>,
        },
        { key: 'name', header: 'Nama / Merk', sortKey: 'name', cell: (r) => <span className="font-medium text-slate-900">{r.name}</span> },
        { key: 'type', header: 'Jenis', sortKey: 'type', className: 'w-28', cell: (r) => r.type_label },
        { key: 'year', header: 'Tahun', sortKey: 'year', className: 'w-20', hideOnMobile: true, cell: (r) => r.year ?? '—' },
        { key: 'stnk', header: 'STNK s.d.', sortKey: 'stnk_expires_on', className: 'w-36', hideOnMobile: true, cell: (r) => <ValidUntil date={r.stnk_expires_on} /> },
        { key: 'kir', header: 'KIR s.d.', sortKey: 'kir_expires_on', className: 'w-36', hideOnMobile: true, cell: (r) => <ValidUntil date={r.kir_expires_on} /> },
      ]}
      fields={[
        { name: 'name', label: 'Nama / Merk', required: true, placeholder: 'mis. Truk Hino Dutro' },
        { name: 'plate_number', label: 'Nomor Polisi', required: true, placeholder: 'mis. L 9123 UA' },
        { name: 'code', label: 'Kode', createOnly: true, hint: 'Boleh dikosongkan: dibuat otomatis dari nama. Huruf besar, angka, - _ . (maks. 30).' },
        { name: 'type', label: 'Jenis Kendaraan', type: 'select', required: true, options: VEHICLE_TYPE_OPTIONS, emptyOption: '— Pilih jenis —' },
        { name: 'year', label: 'Tahun Pembuatan', type: 'number' },
        { name: 'chassis_number', label: 'Nomor Rangka' },
        { name: 'engine_number', label: 'Nomor Mesin' },
        { name: 'fuel', label: 'Bahan Bakar', type: 'select', options: FUEL_OPTIONS, emptyOption: '— Pilih —' },
        { name: 'capacity', label: 'Kapasitas Muatan (kg)', type: 'number' },
        { name: 'stnk_expires_on', label: 'STNK berlaku sampai', type: 'date' },
        { name: 'kir_expires_on', label: 'KIR berlaku sampai', type: 'date' },
      ]}
    />
  )
}

/** Field count of a category as a button that opens its fields: own fields plus those inherited from parents. */
function AttributeCount({ row, canEdit, onOpen }: { row: TenantCategory; canEdit: boolean; onOpen: () => void }) {
  const own = row.attributes_count ?? 0
  const inherited = row.inherited_attributes_count ?? 0
  const total = own + inherited
  const label = total > 0 ? `${total} atribut` : canEdit ? 'Atur atribut' : 'Lihat'
  const detail = inherited > 0 ? `${own} milik kategori ini, ${inherited} dari kategori induk` : undefined
  const action = canEdit ? 'melihat atau mengubah' : 'melihat'
  return (
    <button
      type="button"
      onClick={onOpen}
      title={detail ? `${detail}. Klik untuk ${action}.` : `Klik untuk ${action} atribut.`}
      aria-label={`Atribut ${row.name}: ${total} atribut${detail ? ` (${detail})` : ''}`}
      className="inline-flex max-w-full items-center gap-1.5 rounded-md bg-brand-50 px-2 py-1 text-sm font-medium text-brand-800 ring-1 ring-inset ring-brand-200 transition-colors hover:bg-brand-100 hover:text-brand-900"
    >
      {total > 0 ? <SlidersHorizontal className="size-3.5 shrink-0" aria-hidden /> : <Plus className="size-3.5 shrink-0" aria-hidden />}
      <span className="truncate">{label}</span>
      {inherited > 0 && <span className="shrink-0 text-xs font-normal text-brand-700/80">({inherited} induk)</span>}
    </button>
  )
}

/** What depends on a category, for the deactivate and archive confirmations. */
function categoryImpact(row: TenantCategory): MasterImpact {
  const assets = row.assets_count ?? 0
  const children = row.children_count ?? 0
  return {
    deactivate: [
      assets > 0 ? `${assets} aset yang sudah memakai kategori ini tidak berubah.` : 'Belum ada aset aktif yang memakai kategori ini.',
      ...(children > 0 ? [`${children} subkategori di bawahnya tetap aktif; nonaktifkan terpisah bila perlu.`] : []),
    ],
    archiveBlocked:
      children > 0
        ? `Kategori ini masih memiliki ${children} subkategori yang belum diarsipkan. Arsipkan atau pindahkan subkategorinya terlebih dahulu.`
        : assets > 0
          ? `Kategori ini masih dipakai oleh ${assets} aset aktif. Pindahkan asetnya ke kategori lain terlebih dahulu.`
          : null,
  }
}

const LEVEL_OPTIONS = [
  { value: 'root', label: 'Kategori utama' },
  { value: 'sub', label: 'Subkategori' },
]

export function TenantCategoriesPage() {
  const categoryRows = useOptions<TenantCategory>('/tenant-masters/categories').data
  const categories = toOptions(categoryRows)
  // A category cannot sit under itself or under one of its own subcategories.
  const parentOptions = (record: { id: string } | null) => {
    if (!record) return categories
    const excluded = new Set([record.id])
    for (let grew = true; grew; ) {
      grew = false
      for (const c of categoryRows ?? []) {
        if (c.parent_id && excluded.has(c.parent_id) && !excluded.has(c.id)) {
          excluded.add(c.id)
          grew = true
        }
      }
    }
    return categories.filter((o) => !excluded.has(o.value))
  }
  const permissions = useMasterPermissions()
  const [attributesOf, setAttributesOf] = useState<TenantCategory | null>(null)
  const [editing, setEditing] = useState<TenantCategory | 'new' | null>(null)
  const [importing, setImporting] = useState(false)
  return (
    <>
      <MasterListPage<TenantCategory>
        title="Kategori Aset"
        description="Kelompok aset beserta atribut isiannya. Subkategori mewarisi atribut kategori induknya."
        endpoint="/tenant-masters/categories"
        {...permissions}
        // Adding goes through the AddMenu in headerActions (form or Excel import).
        canCreate={false}
        editing={editing}
        onEditChange={setEditing}
        // No archiving here: a category is deactivated, not archived.
        canArchive={false}
        searchPlaceholder="Cari kode, nama, atau deskripsi"
        bulkEdit={{ title: 'Multiple Edit Kategori Aset', endpoint: '/tenant-masters/categories/edit-sheet', filename: 'edit_kategori_aset.xlsx', noun: 'kategori', selectable: true }}
        impact={categoryImpact}
        headerActions={permissions.canCreate && <AddMenu label="Tambah kategori" noun="kategori" onForm={() => setEditing('new')} onImport={() => setImporting(true)} />}
        filters={[
          { name: 'parent_id', label: 'Di bawah kategori', options: categories },
          { name: 'level', label: 'Tingkat', options: LEVEL_OPTIONS },
        ]}
        columns={({ filtered }) => [
          code<TenantCategory>(),
          { key: 'name', header: 'Nama', sortKey: 'name', cell: (r) => treeNameCell(r, filtered) },
          {
            key: 'attributes',
            header: 'Atribut',
            sortKey: 'attributes',
            className: 'w-48',
            cell: (r) => <AttributeCount row={r} canEdit={permissions.canUpdate && r.status !== 'archived'} onOpen={() => setAttributesOf(r)} />,
          },
          { key: 'description', header: 'Deskripsi', sortKey: 'description', cell: (r) => r.description ?? '—', title: (r) => r.description, hideOnMobile: true },
        ]}
        fields={[
          { name: 'name', label: 'Nama', required: true, placeholder: 'mis. Tabung Oksigen' },
          { name: 'code', label: 'Kode', createOnly: true, placeholder: 'mis. TABUNG-O2', hint: 'Boleh dikosongkan: kode dibuat otomatis dari nama. Huruf besar, angka, - _ . (maks. 30).' },
          { name: 'parent_id', label: 'Kategori induk', type: 'select', optionsFor: parentOptions, emptyOption: '— Kategori utama —', hint: 'Subkategori mewarisi atribut kategori induknya.' },
          { name: 'description', label: 'Deskripsi', type: 'textarea' },
        ]}
        emptyHint="Tambahkan kategori pertama, misalnya Tabung."
        showColumnToggle
        defaultColumns={['code', 'name', 'attributes']}
      />
      {attributesOf && <CategoryAttributesModal category={attributesOf} canUpdate={permissions.canUpdate} onClose={() => setAttributesOf(null)} />}
      {importing && (
        <ImportModal
          open={importing}
          onClose={() => setImporting(false)}
          title="Tambah Kategori Aset dari Excel (Import)"
          endpoint="/tenant-masters/categories/import"
          templateEndpoint="/tenant-masters/categories/template"
          templateFilename="template_kategori_aset.xlsx"
        />
      )}
    </>
  )
}
