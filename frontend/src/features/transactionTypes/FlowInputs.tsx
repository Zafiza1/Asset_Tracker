import { Lock, X } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { SegmentedControl } from '../../components/ui/Tabs'
import { toOptions, useOptions } from '../master/options'
import type { FlowAction, FlowOptions, Rule, TypeField, Who } from './api'
import { operatorsFor } from './rulesLogic'

/**
 * Inputs shared by the flow canvas (F-07e) and the Rules tab of the Designer (F-08e): the
 * condition builder over registered data only (F-07 §7, no expressions), who does or approves a
 * step, and a field picker.
 */

export function FieldSelect({ label, fields, types, value, onChange, optional }: { label: string; fields: TypeField[]; types: string[]; value?: string; onChange: (v: string | undefined) => void; optional?: boolean }) {
  const usable = fields.filter((f) => types.includes(f.data_type))
  return (
    <Field label={label} hint={usable.length === 0 ? `Tambahkan field bertipe ${types.join('/')} di pengaturan jenis.` : undefined}>
      {(p) => (
        <Select {...p} value={value ?? ''} onChange={(e) => onChange(e.target.value || undefined)}>
          <option value="">{optional ? '— Tidak ada —' : '— Pilih field —'}</option>
          {usable.map((f) => (
            <option key={f.key} value={f.key}>
              {f.label}
            </option>
          ))}
        </Select>
      )}
    </Field>
  )
}

/** Who does a step: the starter, a role, a user, the user of a field, or (approvals) the location supervisors. */
export function WhoPicker({ label, value, roles, members, fields, onChange, approval, notify }: {
  label: string
  value: Who
  roles: { id: string; name: string }[]
  members: { id: string; name: string }[]
  fields: TypeField[]
  onChange: (w: Who) => void
  approval?: boolean
  /** Email recipients may also be the assets' custodians at that step (F-08k). */
  notify?: boolean
}) {
  const kinds: { value: Who['type']; label: string }[] = approval
    ? [
        { value: 'location_supervisor', label: 'Supervisor yang membawahi aset' },
        { value: 'role', label: 'Role' },
        { value: 'user', label: 'Pengguna tertentu' },
        { value: 'asset_custodian', label: 'Penanggung jawab aset' },
        { value: 'target_department_supervisor', label: 'Supervisor departemen penerima' },
      ]
    : [
        { value: 'starter', label: 'Pembuat transaksi' },
        { value: 'role', label: 'Role' },
        { value: 'user', label: 'Pengguna tertentu' },
        { value: 'field', label: 'Pengguna dari field' },
        ...(notify ? [{ value: 'asset_custodian' as const, label: 'Penanggung jawab aset saat itu' }] : []),
      ]
  const hints: Partial<Record<Who['type'], string>> = {
    location_supervisor: 'Super Admin atau Supervisor yang cakupan datanya meliputi semua aset (lokasi atau departemennya).',
    asset_custodian: 'Setiap penanggung jawab menyetujui asetnya sendiri. Aset tanpa penanggung jawab aktif disetujui Supervisor yang membawahi aset; aset milik pencatat dianggap disetujui.',
    target_department_supervisor: 'Supervisor yang cakupannya meliputi departemen asal orang di field yang dipilih; bila tidak ada, Super Admin.',
  }
  return (
    <div className="space-y-2">
      <Field label={label} hint={approval ? hints[value.type] : undefined}>
        {(p) => (
          <Select {...p} value={value.type} onChange={(e) => onChange({ type: e.target.value as Who['type'] })}>
            {kinds.map((k) => (
              <option key={k.value} value={k.value}>
                {k.label}
              </option>
            ))}
          </Select>
        )}
      </Field>
      {value.type === 'role' && (
        <Select aria-label="Role" value={value.id ?? ''} onChange={(e) => onChange({ type: 'role', id: e.target.value || undefined })}>
          <option value="">— Pilih role —</option>
          {roles.map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
            </option>
          ))}
        </Select>
      )}
      {value.type === 'user' && (
        <Select aria-label="Pengguna" value={value.id ?? ''} onChange={(e) => onChange({ type: 'user', id: e.target.value || undefined })}>
          <option value="">— Pilih pengguna —</option>
          {members.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </Select>
      )}
      {value.type === 'field' && <FieldSelect label="Field pengguna" fields={fields} types={['user']} value={value.field} onChange={(field) => onChange({ type: 'field', field })} />}
      {value.type === 'target_department_supervisor' && (
        <FieldSelect label="Field penerima" fields={fields} types={['user']} value={value.field} onChange={(field) => onChange({ type: 'target_department_supervisor', field })} />
      )}
      {approval && value.type !== 'asset_custodian' && <p className="text-xs text-slate-500">Hanya Super Admin dan Supervisor yang dapat menyetujui.</p>}
    </div>
  )
}


/** Conditions on registered data only (F-07 §7): no expressions. */
export function RulesEditor({ label, hint, rules, match, fields, options, onChange, coreType }: {
  label: string
  hint?: string
  rules: Rule[]
  match: 'all' | 'any'
  fields: TypeField[]
  options?: FlowOptions
  onChange: (rules: Rule[], match: 'all' | 'any') => void
  /** The built-in transaction a type's core action records: a core Keluar offers its sifat. */
  coreType?: string | null
}) {
  const categories = toOptions(useOptions('/tenant-masters/categories').data)
  const locations = toOptions(useOptions('/locations').data)
  const departments = toOptions(useOptions('/departments').data)
  const userFields = fields.filter((f) => f.data_type === 'user')
  const subjects = [
    { value: 'asset.acquisition_cost', label: 'Aset · Nilai perolehan' },
    { value: 'asset.status', label: 'Aset · Status' },
    { value: 'asset.condition', label: 'Aset · Kondisi' },
    { value: 'asset.category', label: 'Aset · Kategori' },
    { value: 'asset.location', label: 'Aset · Lokasi' },
    { value: 'asset.department', label: 'Aset · Departemen' },
    { value: 'asset.custodian', label: 'Aset · Penanggung jawab' },
    { value: 'starter.is_custodian', label: 'Pembuat · Penanggung jawab aset' },
    ...(userFields.length ? [{ value: 'target.department_differs', label: 'Lintas departemen (penerima)' }] : []),
    { value: 'starter.role', label: 'Pembuat · Role' },
    ...(coreType === 'outgoing' ? [{ value: 'core.outgoing_nature', label: 'Keluar · Sifat' }] : []),
    ...fields.filter((f) => f.data_type !== 'attachment').map((f) => ({ value: `field.${f.key}`, label: `Field · ${f.label}` })),
  ]
  const choices = (subject: string): { value: string; label: string }[] | null => {
    if (subject === 'asset.department') return departments
    if (subject === 'starter.is_custodian') return [{ value: 'true', label: 'Ya' }, { value: 'false', label: 'Tidak' }]
    if (subject === 'target.department_differs') return userFields.map((f) => ({ value: f.key, label: `Field ${f.label}` }))
    if (subject === 'core.outgoing_nature') return [{ value: 'temporary', label: 'Sementara' }, { value: 'permanent', label: 'Permanen' }]
    if (subject === 'asset.status') return options?.asset_statuses ?? []
    if (subject === 'asset.condition') return options?.conditions ?? []
    if (subject === 'asset.category') return categories
    if (subject === 'asset.location') return locations
    if (subject === 'starter.role') return (options?.roles ?? []).map((r) => ({ value: r.id, label: r.name }))
    const f = fields.find((x) => `field.${x.key}` === subject)
    if (f && (f.data_type === 'select' || f.data_type === 'multiselect')) return f.settings.options ?? []
    if (f?.data_type === 'boolean') return [{ value: 'true', label: 'Ya' }, { value: 'false', label: 'Tidak' }]
    return null
  }
  const numeric = (subject: string) => subject === 'asset.acquisition_cost' || ['number', 'money'].includes(fields.find((x) => `field.${x.key}` === subject)?.data_type ?? '')
  const set = (i: number, patch: Partial<Rule>) => onChange(rules.map((r, j) => (j === i ? { ...r, ...patch } : r)), match)

  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-semibold text-slate-700">{label}</legend>
      {rules.length > 1 && (
        <SegmentedControl label="Gabungan syarat" size="sm" value={match} onChange={(m) => onChange(rules, m)} options={[{ value: 'all', label: 'Semua (dan)' }, { value: 'any', label: 'Salah satu (atau)' }]} />
      )}
      {rules.map((r, i) => {
        const list = choices(r.subject)
        const noValue = r.op === 'empty' || r.op === 'not_empty'
        const multi = r.op === 'in' || r.op === 'not_in'
        const values = Array.isArray(r.value) ? (r.value as unknown[]).map(String) : r.value === undefined || r.value === null ? [] : [String(r.value)]
        return (
          <div key={i} className="space-y-1.5 rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200">
            <div className="flex gap-1">
              <Select aria-label="Data" value={r.subject} onChange={(e) => set(i, { subject: e.target.value, op: operatorsFor(e.target.value, false)[0].value, value: undefined })}>
                {subjects.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </Select>
              <Button variant="ghost" size="sm" onClick={() => onChange(rules.filter((_, j) => j !== i), match)} aria-label="Hapus syarat">
                <X className="size-4" aria-hidden />
              </Button>
            </div>
            <Select aria-label="Operator" value={r.op} onChange={(e) => set(i, { op: e.target.value })}>
              {operatorsFor(r.subject, numeric(r.subject)).map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            {!noValue &&
              (list ? (
                multi ? (
                  <div className="max-h-32 space-y-1 overflow-y-auto rounded-md bg-white p-2 ring-1 ring-slate-200">
                    {list.map((o) => (
                      <label key={o.value} className="flex items-center gap-2 text-xs">
                        <input
                          type="checkbox"
                          className="size-3.5 accent-brand-700"
                          checked={values.includes(o.value)}
                          onChange={(e) => set(i, { value: e.target.checked ? [...values, o.value] : values.filter((v) => v !== o.value) })}
                        />
                        {o.label}
                      </label>
                    ))}
                  </div>
                ) : (
                  <Select aria-label="Nilai" value={values[0] ?? ''} onChange={(e) => set(i, { value: e.target.value === 'true' ? true : e.target.value === 'false' ? false : e.target.value })}>
                    <option value="">— Pilih —</option>
                    {list.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                )
              ) : (
                <Input
                  aria-label="Nilai"
                  type={numeric(r.subject) ? 'number' : 'text'}
                  value={multi ? values.join(', ') : (values[0] ?? '')}
                  placeholder={multi ? 'pisahkan dengan koma' : undefined}
                  onChange={(e) =>
                    set(i, { value: multi ? e.target.value.split(',').map((v) => v.trim()).filter(Boolean) : numeric(r.subject) ? (e.target.value === '' ? undefined : Number(e.target.value)) : e.target.value })
                  }
                />
              ))}
          </div>
        )
      })}
      <button type="button" className="text-sm font-semibold text-brand-700 hover:underline" onClick={() => onChange([...rules, { subject: 'asset.status', op: 'eq', value: undefined }], match)}>
        + Tambah syarat
      </button>
      {hint && <p className="text-xs text-slate-500">{hint}</p>}
    </fieldset>
  )
}

const ACTION_KINDS: { value: FlowAction['kind']; label: string }[] = [
  { value: 'set_status', label: 'Ubah status aset' },
  { value: 'move_to_field_location', label: 'Pindah ke lokasi dari field' },
  { value: 'set_condition', label: 'Ubah kondisi aset' },
  { value: 'loan_to_field_user', label: 'Pinjamkan ke pengguna dari field' },
  { value: 'return', label: 'Kembalikan (akhiri peminjaman)' },
  { value: 'notify', label: 'Kirim notifikasi email' },
  { value: 'set_custodian', label: 'Ganti penanggung jawab' },
]

export function ActionsEditor({ actions, fields, options, onChange }: { actions: FlowAction[]; fields: TypeField[]; options?: FlowOptions; onChange: (a: FlowAction[]) => void }) {
  const set = (i: number, patch: Partial<FlowAction>) => onChange(actions.map((a, j) => (j === i ? { ...a, ...patch } : a)))
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-semibold text-slate-700">Aksi (berurutan)</legend>
      {actions.map((a, i) => (
        <div key={i} className="space-y-1.5 rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200">
          <div className="flex gap-1">
            <Select aria-label="Jenis aksi" value={a.kind} onChange={(e) => set(i, { kind: e.target.value as FlowAction['kind'], status: undefined, field: undefined, value: undefined, user_field: undefined, due_field: undefined, location_field: undefined, to: undefined, message: undefined, set_department: undefined })}>
              {ACTION_KINDS.map((k) => (
                <option key={k.value} value={k.value}>
                  {k.label}
                </option>
              ))}
            </Select>
            <Button variant="ghost" size="sm" onClick={() => onChange(actions.filter((_, j) => j !== i))} aria-label="Hapus aksi">
              <X className="size-4" aria-hidden />
            </Button>
          </div>
          {a.kind === 'set_status' && (
            <Select aria-label="Status baru" value={a.status ?? ''} onChange={(e) => set(i, { status: e.target.value || undefined })}>
              <option value="">— Status —</option>
              {(options?.action_statuses ?? []).map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          )}
          {a.kind === 'move_to_field_location' && <FieldSelect label="Field lokasi tujuan" fields={fields} types={['location']} value={a.field} onChange={(field) => set(i, { field })} />}
          {a.kind === 'set_condition' && (
            <>
              <Select aria-label="Kondisi" value={a.field ? `field:${a.field}` : (a.value ?? '')} onChange={(e) => (e.target.value.startsWith('field:') ? set(i, { field: e.target.value.slice(6), value: undefined }) : set(i, { value: e.target.value || undefined, field: undefined }))}>
                <option value="">— Kondisi —</option>
                {(options?.conditions ?? []).map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
                {fields.filter((f) => f.data_type === 'select').map((f) => (
                  <option key={f.key} value={`field:${f.key}`}>
                    Dari field: {f.label}
                  </option>
                ))}
              </Select>
              <p className="text-xs text-slate-500">Mengisi field "Kondisi Aset" pada kategori aset.</p>
            </>
          )}
          {a.kind === 'loan_to_field_user' && (
            <>
              <FieldSelect label="Field pemegang" fields={fields} types={['user']} value={a.user_field} onChange={(user_field) => set(i, { user_field })} />
              <FieldSelect label="Field rencana kembali" optional fields={fields} types={['date']} value={a.due_field} onChange={(due_field) => set(i, { due_field })} />
            </>
          )}
          {a.kind === 'return' && <FieldSelect label="Field lokasi kembali" optional fields={fields} types={['location']} value={a.location_field} onChange={(location_field) => set(i, { location_field })} />}
          {a.kind === 'set_custodian' && (
            <>
              <FieldSelect label="Field penanggung jawab baru" fields={fields} types={['user']} value={a.user_field} onChange={(user_field) => set(i, { user_field })} />
              <label className="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" className="size-3.5 accent-brand-700" checked={a.set_department ?? false} onChange={(e) => set(i, { set_department: e.target.checked })} />
                Departemen aset ikut menjadi departemen asal orang itu
              </label>
            </>
          )}
          {a.kind === 'notify' && (
            <>
              <WhoPicker notify label="Penerima" value={a.to ?? { type: 'starter' }} roles={options?.roles ?? []} members={options?.members ?? []} fields={fields} onChange={(to) => set(i, { to })} />
              <Textarea aria-label="Isi pesan" rows={3} maxLength={options?.max_message ?? 500} value={a.message ?? ''} placeholder="Isi email, mis. Aset sudah dikirim, harap diterima." onChange={(e) => set(i, { message: e.target.value })} />
            </>
          )}
        </div>
      ))}
      {actions.length < 5 && (
        <button type="button" className="text-sm font-semibold text-brand-700 hover:underline" onClick={() => onChange([...actions, { kind: 'set_status' }])}>
          + Tambah aksi
        </button>
      )}
      <p className="flex items-center gap-1 text-xs text-slate-500">
        <Lock className="size-3" aria-hidden />
        Perubahan aset tetap mengikuti aturan inti.
      </p>
    </fieldset>
  )
}
