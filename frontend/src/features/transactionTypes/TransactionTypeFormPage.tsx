import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Lock, Plus, Save, Trash2, Workflow, X } from 'lucide-react'
import { useState } from 'react'
import { keyFromLabel } from './values'
import { useNavigate, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button, ButtonLink } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { Alert, ErrorState, FormSkeleton } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { SegmentedControl } from '../../components/ui/Tabs'
import { toast } from '../../components/ui/toast'
import { ApiError } from '../../lib/api/client'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { useCanUseEditor } from '../../lib/useMediaQuery'
import { CategoryFilter } from '../assets/CategoryFilter'
import { toOptions, useOptions } from '../master/options'
import {
  attachmentKindLabel,
  fieldTypeLabel,
  typeStatusLabel,
  typeStatusTone,
  typesApi,
  type AttachmentKind,
  type FieldSettings,
  type FieldType,
  type TransactionTypeDefinition,
  type TypeStatus,
} from './api'

interface DraftField {
  /** Local row id; existing fields keep their key, which cannot change. */
  uid: string
  key: string
  label: string
  data_type: FieldType
  is_required: boolean
  settings: FieldSettings
  saved: boolean
}

interface Draft {
  code: string
  name: string
  description: string
  status: TypeStatus
  multi_asset: boolean
  locks_assets: boolean
  closes_type_id: string
  category_ids: string[]
  fields: DraftField[]
}

const MAX_FIELDS = 40
let uidCounter = 0
const uid = () => `f${++uidCounter}`

function toDraft(t: TransactionTypeDefinition | undefined): Draft {
  return {
    code: t?.code ?? '',
    name: t?.name ?? '',
    description: t?.description ?? '',
    status: t?.status ?? 'active',
    multi_asset: t?.multi_asset ?? false,
    locks_assets: t?.locks_assets ?? true,
    closes_type_id: t?.closes_type?.id ?? '',
    category_ids: t?.categories?.map((c) => c.id) ?? [],
    fields: (t?.fields ?? []).map((f) => ({ uid: uid(), key: f.key, label: f.label, data_type: f.data_type, is_required: f.is_required, settings: { ...f.settings }, saved: true })),
  }
}

/**
 * A transaction type and its form fields (F-07 §3.1–3.2). Steps and rules are drawn in the flow
 * editor. Read-only without transaction_type.manage or on a narrow screen (D-27, D-34).
 */
export function TransactionTypeFormPage() {
  const { id } = useParams()
  const isNew = !id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const canUseEditor = useCanUseEditor()
  const [draft, setDraft] = useState<Draft>(() => toDraft(undefined))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [deleting, setDeleting] = useState(false)

  const type = useQuery({ queryKey: ['/transaction-types', id], queryFn: () => typesApi.get(id!), enabled: !isNew })
  // A system type (F-07f-1) follows the built-in core rules and is read-only for everyone.
  const system = !!type.data?.data.system_type
  const editable = can('transaction_type.manage') && canUseEditor && !system
  const others = useQuery({ queryKey: ['/transaction-types', 'all'], queryFn: () => typesApi.list() })
  const categories = toOptions(useOptions('/tenant-masters/categories').data)

  // The form starts from the saved type once it arrives (and again after saving).
  const loaded = type.data?.data
  const [shown, setShown] = useState<typeof loaded>(undefined)
  if (loaded && loaded !== shown) {
    setShown(loaded)
    setDraft(toDraft(loaded))
  }

  const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setDraft((d) => ({ ...d, [k]: v }))
  const setField = (uid: string, patch: Partial<DraftField>) =>
    setDraft((d) => ({ ...d, fields: d.fields.map((f) => (f.uid === uid ? { ...f, ...patch } : f)) }))
  const move = (index: number, by: -1 | 1) =>
    setDraft((d) => {
      const fields = [...d.fields]
      const [row] = fields.splice(index, 1)
      fields.splice(index + by, 0, row)
      return { ...d, fields }
    })
  const err = (k: string) => errors[k]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const input = {
        ...(isNew ? { code: draft.code.trim() } : {}),
        name: draft.name.trim(),
        description: draft.description.trim() || null,
        status: draft.status,
        multi_asset: draft.multi_asset,
        locks_assets: draft.locks_assets,
        closes_type_id: draft.closes_type_id || null,
        category_ids: draft.category_ids,
        fields: draft.fields.map((f) => ({ key: f.key, label: f.label.trim(), data_type: f.data_type, is_required: f.is_required, settings: cleanSettings(f) })),
      }
      return isNew ? typesApi.create(input) : typesApi.update(id!, input)
    },
    onSuccess: async (res) => {
      await queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success(isNew ? 'Jenis transaksi dibuat' : 'Jenis transaksi disimpan', res.data.name)
      setErrors({})
      if (isNew) navigate(`/transaksi/jenis/${res.data.id}`, { replace: true })
    },
    onError: (e) => setErrors(e instanceof ApiError ? e.fields : {}),
  })

  const remove = useMutation({
    mutationFn: () => typesApi.remove(id!),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['/transaction-types'] })
      toast.success('Jenis transaksi dihapus')
      navigate('/transaksi/jenis', { replace: true })
    },
  })

  if (!isNew && type.isPending) return <FormSkeleton fields={6} />
  if (!isNew && type.isError) return <ErrorState error={type.error} onRetry={() => type.refetch()} />
  if (isNew && !editable) return <Alert tone="info" title="Tidak dapat membuat jenis di sini">Membuat jenis transaksi dilakukan oleh Super Admin atau Supervisor di web pada layar lebar.</Alert>

  const t = type.data?.data
  const used = (t?.transactions_count ?? 0) > 0
  const closable = (others.data?.data ?? []).filter((o) => o.id !== id)

  return (
    <>
      <PageHeader
        title={isNew ? 'Jenis transaksi baru' : (t?.name ?? '')}
        badges={
          t && (
            <>
              {system && (
                <Badge tone="slate">
                  <Lock className="size-3" aria-hidden />
                  Sistem
                </Badge>
              )}
              <Badge tone={typeStatusTone[t.status]}>{typeStatusLabel[t.status]}</Badge>
            </>
          )
        }
        description={
          <>
            Pengaturan jenis dan field formulir. Langkah dan aturan diatur di editor alur{isNew ? ' setelah jenis disimpan' : ''}.
          </>
        }
        actions={
          <>
            {!isNew && !system && (
              <ButtonLink to={`/transaksi/jenis/${id}/alur`} variant="secondary">
                <Workflow className="size-4" aria-hidden />
                {editable ? 'Editor alur' : 'Lihat alur'}
              </ButtonLink>
            )}
            {editable && (
              <Button type="submit" form="type-form" loading={save.isPending}>
                <Save className="size-4" aria-hidden />
                Simpan
              </Button>
            )}
          </>
        }
      />

      {system ? (
        <div className="mb-4">
          <Alert tone="info" title="Jenis sistem">
            Jenis ini bawaan aplikasi dan memakai aturan inti (mis. aset yang dipinjam tidak dapat dipindah). Pencatatannya lewat menu Transaksi, langsung tercatat tanpa alur. Jenis sistem belum dapat diubah.
          </Alert>
        </div>
      ) : !editable && (
        <div className="mb-4">
          <Alert tone="info" title={canUseEditor ? 'Hanya dapat dilihat' : 'Hanya dapat dilihat di ponsel'}>
            {canUseEditor ? 'Mengubah jenis dan field dilakukan oleh Super Admin atau Supervisor.' : 'Ubah jenis dan field di web pada layar lebar.'}
          </Alert>
        </div>
      )}

      <form
        id="type-form"
        noValidate
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          setErrors({})
          save.mutate()
        }}
      >
        {save.isError && !Object.keys(errors).length && <Alert tone="error">{errorMessage(save.error)}</Alert>}
        {Object.keys(errors).length > 0 && <Alert tone="error">Periksa isian yang ditandai merah.</Alert>}

        <fieldset disabled={!editable} className="space-y-4">
          <Card title="Jenis">
            <div className="grid gap-4 p-5 sm:grid-cols-2">
              <Field label="Nama" required error={err('name')}>
                {(p) => <Input {...p} value={draft.name} maxLength={100} onChange={(e) => set('name', e.target.value)} placeholder="mis. Mutasi Cabang" />}
              </Field>
              <Field label="Kode / prefix nomor" required error={err('code')} hint={isNew ? '2–6 huruf; dipakai di nomor transaksi, mis. MUT-2026-000001.' : used ? 'Tidak dapat diubah: jenis sudah dipakai.' : 'Kode tidak dapat diubah setelah dibuat.'}>
                {(p) => <Input {...p} value={draft.code} maxLength={6} disabled={!isNew} className={`${p.className} uppercase`} onChange={(e) => set('code', e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} />}
              </Field>
              <div className="sm:col-span-2">
                <Field label="Keterangan" error={err('description')}>
                  {(p) => <Textarea {...p} rows={2} maxLength={1000} value={draft.description} onChange={(e) => set('description', e.target.value)} />}
                </Field>
              </div>
              <div>
                <p className="mb-1.5 text-sm font-semibold text-slate-700">Berlaku untuk kategori</p>
                <CategoryFilter options={categories} selected={draft.category_ids} onChange={(ids) => set('category_ids', ids)} />
                <p className="mt-1.5 text-xs text-slate-500">{draft.category_ids.length ? 'Termasuk subkategorinya.' : 'Kosong: semua kategori.'}</p>
                {err('category_ids') && <p className="mt-1.5 text-sm text-red-700">{err('category_ids')}</p>}
              </div>
              <div>
                <p className="mb-1.5 text-sm font-semibold text-slate-700">Jumlah aset per transaksi</p>
                <SegmentedControl
                  label="Jumlah aset per transaksi"
                  value={draft.multi_asset ? 'many' : 'one'}
                  onChange={(v) => set('multi_asset', v === 'many')}
                  options={[{ value: 'one', label: 'Satu aset' }, { value: 'many', label: 'Banyak aset' }]}
                />
              </div>
              <Field label="Menutup jenis lain" error={err('closes_type_id')} hint={'Mis. "Kembali dari kalibrasi" menutup "Kalibrasi"; alur jenis yang ditutup dapat menunggu transaksi ini.'}>
                {(p) => (
                  <Select {...p} value={draft.closes_type_id} onChange={(e) => set('closes_type_id', e.target.value)}>
                    <option value="">Tidak berpasangan</option>
                    {closable.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name} ({o.code})
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Status" error={err('status')} hint="Hanya jenis Aktif yang dapat dipakai mencatat transaksi.">
                {(p) => (
                  <Select {...p} value={draft.status} onChange={(e) => set('status', e.target.value as TypeStatus)}>
                    {(Object.keys(typeStatusLabel) as TypeStatus[]).map((s) => (
                      <option key={s} value={s}>
                        {typeStatusLabel[s]}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <label className="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2">
                <input type="checkbox" className="mt-0.5 size-4 rounded border-slate-300 accent-brand-700" checked={draft.locks_assets} onChange={(e) => set('locks_assets', e.target.checked)} />
                <span>
                  <span className="font-semibold">Kunci aset selama transaksi berjalan</span>
                  <span className="block text-xs text-slate-500">Aset tidak dapat ikut transaksi lain sampai transaksi ini selesai, ditolak, atau dibatalkan.</span>
                </span>
              </label>
            </div>
          </Card>

          <Card
            title="Field formulir"
            actions={
              editable && (
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={draft.fields.length >= MAX_FIELDS}
                  onClick={() =>
                    setDraft((d) => ({ ...d, fields: [...d.fields, { uid: uid(), key: '', label: '', data_type: 'text', is_required: false, settings: {}, saved: false }] }))
                  }
                >
                  <Plus className="size-4" aria-hidden />
                  Tambah field
                </Button>
              )
            }
          >
            {err('fields') && <div className="px-5 pt-4"><Alert tone="error">{err('fields')}</Alert></div>}
            {draft.fields.length === 0 ? (
              <p className="px-5 py-6 text-sm text-slate-500">Belum ada field. Tambahkan isian yang diminta formulir, mis. lokasi tujuan, nomor PO, atau foto berita acara.</p>
            ) : (
              <ol className="divide-y divide-slate-100">
                {draft.fields.map((f, i) => (
                  <FieldRow
                    key={f.uid}
                    field={f}
                    index={i}
                    count={draft.fields.length}
                    editable={editable}
                    errors={errors}
                    onChange={(patch) => {
                      // A new field's key follows its label until it is saved.
                      if (!f.saved && patch.label !== undefined) {
                        const taken = draft.fields.filter((o) => o.uid !== f.uid).map((o) => o.key)
                        patch = { ...patch, key: keyFromLabel(patch.label, taken) }
                      }
                      setField(f.uid, patch)
                    }}
                    onMove={(by) => move(i, by)}
                    onRemove={() => setDraft((d) => ({ ...d, fields: d.fields.filter((o) => o.uid !== f.uid) }))}
                  />
                ))}
              </ol>
            )}
            <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
              Field yang diisi di langkah tertentu (mis. No. BAST di langkah Serah terima) diatur di editor alur. Menghapus field tidak menghapus nilai yang sudah tercatat.
            </p>
          </Card>
        </fieldset>
      </form>

      {editable && !isNew && (
        <div className="mt-6 flex justify-end">
          {used ? (
            <p className="flex items-center gap-1.5 text-xs text-slate-500">
              <Lock className="size-3.5" aria-hidden />
              Jenis yang sudah dipakai tidak dapat dihapus; ubah statusnya menjadi Nonaktif.
            </p>
          ) : (
            <Button variant="danger" onClick={() => setDeleting(true)}>
              <Trash2 className="size-4" aria-hidden />
              Hapus jenis
            </Button>
          )}
        </div>
      )}
      <ConfirmDialog open={deleting} title="Hapus jenis transaksi?" confirmLabel="Hapus" tone="danger" loading={remove.isPending} error={remove.error} onConfirm={() => remove.mutate()} onClose={() => setDeleting(false)}>
        <p>Jenis {t?.name} beserta field dan alurnya dihapus. Tindakan ini tidak dapat dibatalkan.</p>
      </ConfirmDialog>
    </>
  )
}

/** Only the settings the field type uses. */
function cleanSettings(f: DraftField): FieldSettings {
  const s = f.settings
  const num = (v: unknown) => (v === '' || v === undefined || v === null || Number.isNaN(Number(v)) ? undefined : Number(v))
  switch (f.data_type) {
    case 'text':
    case 'textarea':
      return num(s.max_length) ? { max_length: num(s.max_length) } : {}
    case 'number':
      return { min: num(s.min), max: num(s.max), decimals: num(s.decimals) ?? 0 }
    case 'money':
      return { min: num(s.min), max: num(s.max) }
    case 'date':
      return { not_before_today: !!s.not_before_today, not_after_today: !!s.not_after_today }
    case 'select':
    case 'multiselect':
      return { options: (s.options ?? []).filter((o) => o.label.trim()).map((o) => ({ value: o.value || keyFromLabel(o.label, []).toUpperCase(), label: o.label.trim() })) }
    case 'boolean':
      return s.default === undefined ? {} : { default: s.default }
    case 'attachment':
      return { ...(s.accept?.length ? { accept: s.accept } : {}), ...(num(s.max_mb) ? { max_mb: num(s.max_mb) } : {}) }
    default:
      return {}
  }
}

function FieldRow({ field: f, index, count, editable, errors, onChange, onMove, onRemove }: {
  field: DraftField
  index: number
  count: number
  editable: boolean
  errors: Record<string, string[]>
  onChange: (patch: Partial<DraftField>) => void
  onMove: (by: -1 | 1) => void
  onRemove: () => void
}) {
  const err = (k: string) => errors[`fields.${index}.${k}`]?.[0]
  const s = f.settings
  const setS = (patch: Partial<FieldSettings>) => onChange({ settings: { ...s, ...patch } })
  const settingErrors = Object.entries(errors).filter(([k]) => k.startsWith(`fields.${index}.settings`)).map(([, m]) => m[0])

  return (
    <li className="space-y-3 px-5 py-4">
      <div className="grid items-start gap-3 sm:grid-cols-[1fr_12rem_auto]">
        <Field label={`Field ${index + 1}`} required error={err('label') ?? err('key')} hint={f.key ? `Kunci: ${f.key}${f.saved ? ' (tetap)' : ''}` : undefined}>
          {(p) => <Input {...p} value={f.label} maxLength={100} onChange={(e) => onChange({ label: e.target.value })} placeholder="mis. Lokasi tujuan" />}
        </Field>
        <Field label="Tipe" error={err('data_type')} hint={f.saved ? 'Tipe tidak dapat diubah.' : undefined}>
          {(p) => (
            <Select {...p} value={f.data_type} disabled={f.saved} onChange={(e) => onChange({ data_type: e.target.value as FieldType, settings: {} })}>
              {(Object.keys(fieldTypeLabel) as FieldType[]).map((t) => (
                <option key={t} value={t}>
                  {fieldTypeLabel[t]}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <div className="flex items-center gap-1 sm:pt-7">
          <label className="mr-2 inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="size-4 rounded border-slate-300 accent-brand-700" checked={f.is_required} onChange={(e) => onChange({ is_required: e.target.checked })} />
            Wajib
          </label>
          {editable && (
            <>
              <Button variant="ghost" size="sm" disabled={index === 0} onClick={() => onMove(-1)} aria-label={`Naikkan ${f.label || 'field'}`}>
                <ArrowUp className="size-4" aria-hidden />
              </Button>
              <Button variant="ghost" size="sm" disabled={index === count - 1} onClick={() => onMove(1)} aria-label={`Turunkan ${f.label || 'field'}`}>
                <ArrowDown className="size-4" aria-hidden />
              </Button>
              <Button variant="ghost" size="sm" onClick={onRemove} aria-label={`Hapus ${f.label || 'field'}`}>
                <X className="size-4" aria-hidden />
              </Button>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-4 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200/70 empty:hidden">
        {(f.data_type === 'text' || f.data_type === 'textarea') && (
          <SmallNumber label="Panjang maksimal" value={s.max_length} onChange={(v) => setS({ max_length: v })} />
        )}
        {(f.data_type === 'number' || f.data_type === 'money') && (
          <>
            <SmallNumber label="Minimal" value={s.min} onChange={(v) => setS({ min: v })} />
            <SmallNumber label="Maksimal" value={s.max} onChange={(v) => setS({ max: v })} />
            {f.data_type === 'number' && <SmallNumber label="Desimal" value={s.decimals} onChange={(v) => setS({ decimals: v })} />}
          </>
        )}
        {f.data_type === 'date' && (
          <>
            <Check label="Tidak sebelum hari ini" checked={!!s.not_before_today} onChange={(v) => setS({ not_before_today: v, ...(v ? { not_after_today: false } : {}) })} />
            <Check label="Tidak setelah hari ini" checked={!!s.not_after_today} onChange={(v) => setS({ not_after_today: v, ...(v ? { not_before_today: false } : {}) })} />
          </>
        )}
        {(f.data_type === 'select' || f.data_type === 'multiselect') && <OptionsEditor options={s.options ?? []} onChange={(options) => setS({ options })} />}
        {f.data_type === 'boolean' && (
          <label className="inline-flex items-center gap-2">
            Nilai bawaan
            <Select className="w-32" value={s.default === undefined ? '' : s.default ? '1' : '0'} onChange={(e) => setS({ default: e.target.value === '' ? undefined : e.target.value === '1' })}>
              <option value="">Kosong</option>
              <option value="1">Ya</option>
              <option value="0">Tidak</option>
            </Select>
          </label>
        )}
        {f.data_type === 'attachment' && (
          <>
            <span className="font-semibold text-slate-700">Jenis file:</span>
            {(Object.keys(attachmentKindLabel) as AttachmentKind[]).map((k) => (
              <Check
                key={k}
                label={attachmentKindLabel[k]}
                checked={!!s.accept?.includes(k)}
                onChange={(v) => setS({ accept: v ? [...(s.accept ?? []), k] : (s.accept ?? []).filter((x) => x !== k) })}
              />
            ))}
            <SmallNumber label="Maks. MB" value={s.max_mb} onChange={(v) => setS({ max_mb: v })} />
            <span className="text-xs text-slate-500">Tanpa centang: semua jenis. Ukuran tidak melebihi batas unggah organisasi.</span>
          </>
        )}
        {(f.data_type === 'user' || f.data_type === 'location' || f.data_type === 'counterparty' || f.data_type === 'datetime') && (
          <span className="text-xs text-slate-500">
            {f.data_type === 'user' ? 'Memilih anggota aktif organisasi.' : f.data_type === 'location' ? 'Memilih lokasi dalam cakupan data pengisi.' : f.data_type === 'counterparty' ? 'Nama dan kontak; kontak hanya tampil bagi yang berizin.' : 'Tanggal dan jam.'}
          </span>
        )}
      </div>
      {settingErrors.length > 0 && <p className="text-sm text-red-700">{settingErrors[0]}</p>}
    </li>
  )
}

function SmallNumber({ label, value, onChange }: { label: string; value: number | undefined; onChange: (v: number | undefined) => void }) {
  return (
    <label className="inline-flex items-center gap-2">
      {label}
      <Input type="number" className="w-28" value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? undefined : Number(e.target.value))} />
    </label>
  )
}

function Check({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="inline-flex items-center gap-2">
      <input type="checkbox" className="size-4 rounded border-slate-300 accent-brand-700" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  )
}

function OptionsEditor({ options, onChange }: { options: { value: string; label: string }[]; onChange: (o: { value: string; label: string }[]) => void }) {
  return (
    <div className="w-full space-y-2">
      <p className="font-semibold text-slate-700">Pilihan</p>
      {options.map((o, i) => (
        <div key={i} className="flex items-center gap-2">
          <Input aria-label={`Pilihan ${i + 1}`} value={o.label} maxLength={100} onChange={(e) => onChange(options.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
          <Button variant="ghost" size="sm" onClick={() => onChange(options.filter((_, j) => j !== i))} aria-label={`Hapus pilihan ${o.label || i + 1}`}>
            <X className="size-4" aria-hidden />
          </Button>
        </div>
      ))}
      <Button variant="secondary" size="sm" onClick={() => onChange([...options, { value: '', label: '' }])}>
        <Plus className="size-4" aria-hidden />
        Tambah pilihan
      </Button>
    </div>
  )
}
