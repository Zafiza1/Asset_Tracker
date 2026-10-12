import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, SlidersHorizontal, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Modal'
import { api, ApiError } from '../../lib/api/client'
import type {
  AttributeDefinition,
  AttributeOverride,
  AttributeValidation,
  AttributeValue,
  GenericDefinition,
  GenericVersion,
  Resource,
  SchemaPreview,
  TenantDefinition,
  TenantVersion,
} from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { errorMessage } from '../../lib/forms'
import { dataTypeLabel, formatDateTime, versionStatusLabel } from '../../lib/format'
import { describeRules, narrowableKeys, parseValidationValue, validationLabel } from './attributeRules'
import { AttributeFormModal, AttributeTable, AttributeValueInput, Checkbox } from './attributes'

type Overrides = AttributeOverride['overrides']

export function TenantVersionPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const version = useQuery({ queryKey: ['/asset-definition-versions', id], queryFn: () => api.get<Resource<TenantVersion>>(`/asset-definition-versions/${id}`) })
  const v = version.data?.data
  const definition = useQuery({
    queryKey: ['/asset-definitions', v?.tenant_definition_id],
    queryFn: () => api.get<Resource<TenantDefinition>>(`/asset-definitions/${v!.tenant_definition_id}`),
    enabled: !!v,
  })
  const genericVersion = useQuery({
    queryKey: ['/generic-masters/versions', v?.generic_definition_version_id],
    queryFn: () => api.get<Resource<GenericVersion>>(`/generic-masters/versions/${v!.generic_definition_version_id}`),
    enabled: !!v,
  })
  const preview = useQuery({
    queryKey: ['/asset-definition-versions', id, 'schema'],
    queryFn: () => api.get<SchemaPreview>(`/asset-definition-versions/${id}/effective-schema`),
    enabled: !!v,
  })

  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ['/asset-definition-versions', id] }), queryClient.invalidateQueries({ queryKey: ['/asset-definitions'] })])

  if (version.isLoading) return <Spinner />
  if (version.isError) return <ErrorState error={version.error} />
  const ver = v!
  const draft = ver.status === 'draft'
  const editable = draft && can('master.tenant.update') && ver.definition?.status !== 'archived'

  return (
    <>
      <PageHeader
        title={`${ver.definition?.name ?? 'Definisi'} — versi ${ver.version_number}`}
        description={
          draft
            ? 'Draft: atur atribut tambahan dan override, lalu rilis. Setelah dirilis, skema efektif dibekukan dan tidak dapat diubah.'
            : `Dirilis ${formatDateTime(ver.released_at)}. Versi ini immutable; perubahan dilakukan lewat versi baru.`
        }
        actions={
          <>
            <Badge tone={statusTone[ver.status]}>{versionStatusLabel[ver.status]}</Badge>
            <Link to={`/master/definisi/${ver.tenant_definition_id}`}>
              <Button variant="secondary">Kembali ke definisi</Button>
            </Link>
            {editable && <ReleaseActions version={ver} issues={preview.data?.meta.issues ?? []} onDone={refresh} />}
            {ver.status === 'released' && can('master.tenant.update') && <DeprecateAction version={ver} onDone={refresh} />}
          </>
        }
      />
      <div className="space-y-4">
        <VersionInfoCard version={ver} definition={definition.data?.data} editable={editable} onSaved={refresh} />
        <Card title={`Atribut Generic Master${genericVersion.data ? ` (v${genericVersion.data.data.version_number})` : ''}`}>
          {genericVersion.isLoading ? (
            <Spinner />
          ) : genericVersion.isError ? (
            <ErrorState error={genericVersion.error} />
          ) : (
            <GenericAttributes version={ver} attributes={genericVersion.data!.data.attributes ?? []} editable={editable} onSaved={refresh} />
          )}
        </Card>
        <TenantAttributesCard version={ver} editable={editable} onSaved={refresh} />
        <Card title="Skema efektif" actions={preview.data && <span className="font-mono text-xs text-slate-500">hash {preview.data.meta.schema_hash.slice(0, 12)}…</span>}>
          {preview.isLoading ? <Spinner /> : preview.isError ? <ErrorState error={preview.error} /> : <SchemaPreviewView preview={preview.data!} />}
        </Card>
      </div>
    </>
  )
}

function VersionInfoCard({ version, definition, editable, onSaved }: { version: TenantVersion; definition?: TenantDefinition; editable: boolean; onSaved: () => Promise<unknown> }) {
  const [changelog, setChangelog] = useState(version.changelog ?? '')
  const [genericVersionId, setGenericVersionId] = useState(version.generic_definition_version_id)
  const generic = useQuery({
    queryKey: ['/generic-masters/definitions', definition?.generic_definition_id],
    queryFn: () => api.get<Resource<GenericDefinition>>(`/generic-masters/definitions/${definition!.generic_definition_id}`),
    enabled: !!definition && editable,
  })
  const save = useMutation({
    mutationFn: () => api.patch(`/asset-definition-versions/${version.id}`, { changelog: changelog || null, generic_definition_version_id: genericVersionId }),
    onSuccess: onSaved,
  })
  const released = generic.data?.data.versions?.filter((g) => g.status === 'released' || g.id === version.generic_definition_version_id) ?? []

  return (
    <Card title="Informasi versi">
      <div className="grid gap-4 p-4 md:grid-cols-2">
        {save.isError && (
          <div className="md:col-span-2">
            <Alert tone="error">{errorMessage(save.error)}</Alert>
          </div>
        )}
        <Field label="Catatan perubahan" hint={version.version_number > 1 ? 'Wajib untuk versi lanjutan.' : undefined}>
          {(p) => <Textarea {...p} rows={3} value={changelog} disabled={!editable} onChange={(e) => setChangelog(e.target.value)} />}
        </Field>
        <div className="space-y-3">
          <Field label="Versi Generic Master" hint="Mengganti versi generik adalah upgrade eksplisit; versi yang sudah dirilis tetap memakai versi generik lamanya.">
            {(p) => (
              <Select {...p} value={genericVersionId} disabled={!editable} onChange={(e) => setGenericVersionId(e.target.value)}>
                {released.length === 0 && <option value={version.generic_definition_version_id}>v{version.generic_version?.version_number}</option>}
                {released.map((g) => (
                  <option key={g.id} value={g.id}>
                    v{g.version_number} ({versionStatusLabel[g.status]})
                  </option>
                ))}
              </Select>
            )}
          </Field>
          {version.schema_hash && <p className="break-all font-mono text-xs text-slate-500">Hash skema: {version.schema_hash}</p>}
          {editable && (
            <div className="flex justify-end">
              <Button variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>
                Simpan informasi
              </Button>
            </div>
          )}
        </div>
      </div>
    </Card>
  )
}

function GenericAttributes({ version, attributes, editable, onSaved }: { version: TenantVersion; attributes: AttributeDefinition[]; editable: boolean; onSaved: () => Promise<unknown> }) {
  const [target, setTarget] = useState<AttributeDefinition | null>(null)
  const current = Object.fromEntries((version.overrides ?? []).map((o) => [o.generic_attribute_key, o.overrides]))

  const saveOverride = async (key: string, overrides: Overrides) => {
    const others = (version.overrides ?? []).filter((o) => o.generic_attribute_key !== key)
    const next = Object.keys(overrides).length ? [...others, { generic_attribute_key: key, overrides }] : others
    await api.put(`/asset-definition-versions/${version.id}/overrides`, { overrides: next })
    await onSaved()
  }

  return (
    <>
      <p className="border-b border-slate-200 px-4 py-2 text-xs text-slate-500">
        Atribut standar tidak dapat diubah. Perusahaan hanya dapat meng-override properti yang diizinkan Generic Master, dan hanya untuk memperketat aturan.
      </p>
      <AttributeTable
        attributes={attributes}
        actions={(a) => (
          <div className="flex items-center justify-end gap-2">
            {current[a.key] && <Badge tone="blue">Di-override: {Object.keys(current[a.key]).length}</Badge>}
            {editable && a.overridable.length > 0 && (
              <Button size="sm" variant="ghost" onClick={() => setTarget(a)} aria-label={`Atur override ${a.label}`} title="Atur override">
                <SlidersHorizontal className="size-4" aria-hidden />
              </Button>
            )}
          </div>
        )}
      />
      {target && <OverrideModal attribute={target} current={current[target.key] ?? {}} onSave={(o) => saveOverride(target.key, o)} onClose={() => setTarget(null)} />}
    </>
  )
}

/** Edits the overrides of one generic attribute; only properties the Generic Master allows are shown. */
function OverrideModal({ attribute, current, onSave, onClose }: { attribute: AttributeDefinition; current: Overrides; onSave: (o: Overrides) => Promise<void>; onClose: () => void }) {
  const allowed = new Set(attribute.overridable)
  const [label, setLabel] = useState(current.label ?? '')
  const [helpText, setHelpText] = useState(current.help_text ?? '')
  const [required, setRequired] = useState(current.required ?? false)
  const [options, setOptions] = useState<string[]>(current.options ?? attribute.options?.map((o) => o.value) ?? [])
  const [validation, setValidation] = useState<Record<string, string>>(Object.fromEntries(Object.entries(current.validation ?? {}).map(([k, v]) => [k, String(v)])))
  const [defaultValue, setDefaultValue] = useState<AttributeValue>(current.default_value ?? null)
  const [issues, setIssues] = useState<string[]>([])
  const [saving, setSaving] = useState(false)

  const effectiveOptions = attribute.options?.filter((o) => options.includes(o.value)) ?? null

  const submit = async () => {
    const o: Overrides = {}
    if (allowed.has('label') && label.trim()) o.label = label.trim()
    if (allowed.has('help_text') && helpText.trim()) o.help_text = helpText.trim()
    if (allowed.has('required') && required && !attribute.is_required) o.required = true
    if (allowed.has('options') && attribute.options && options.length !== attribute.options.length) o.options = options
    if (allowed.has('validation')) {
      const narrowed: AttributeValidation = {}
      for (const key of narrowableKeys(attribute.data_type)) {
        const parsed = parseValidationValue(attribute.data_type, key, validation[key] ?? '')
        if (parsed !== undefined) (narrowed as Record<string, unknown>)[key] = parsed
      }
      if (Object.keys(narrowed).length) o.validation = narrowed
    }
    if (allowed.has('default_value') && defaultValue !== null) o.default_value = defaultValue
    setSaving(true)
    setIssues([])
    try {
      await onSave(o)
      onClose()
    } catch (e) {
      const details = e instanceof ApiError ? (e.details.issues as string[] | undefined) : undefined
      setIssues(details ?? [errorMessage(e)])
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={`Override: ${attribute.label}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button loading={saving} onClick={submit}>
            Simpan override
          </Button>
        </>
      }
    >
      {issues.length > 0 && (
        <Alert tone="error" title="Override ditolak">
          <ul className="list-inside list-disc">
            {issues.map((i) => (
              <li key={i}>{i}</li>
            ))}
          </ul>
        </Alert>
      )}
      <p className="text-sm text-slate-600">
        Kosongkan isian untuk memakai nilai Generic Master. Tipe data ({dataTypeLabel[attribute.data_type]}) dan kunci tidak dapat diubah.
      </p>
      {allowed.has('label') && <Field label={`Label (generik: ${attribute.label})`}>{(p) => <Input {...p} value={label} onChange={(e) => setLabel(e.target.value)} />}</Field>}
      {allowed.has('help_text') && <Field label="Teks bantuan">{(p) => <Input {...p} value={helpText} placeholder={attribute.help_text ?? ''} onChange={(e) => setHelpText(e.target.value)} />}</Field>}
      {allowed.has('required') && !attribute.is_required && <Checkbox label="Jadikan wajib diisi" checked={required} onChange={setRequired} />}
      {allowed.has('options') && attribute.options && (
        <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">Opsi yang dipakai perusahaan</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {attribute.options.map((opt) => (
              <Checkbox key={opt.value} label={opt.label} checked={options.includes(opt.value)} onChange={(on) => setOptions(on ? [...options, opt.value] : options.filter((x) => x !== opt.value))} />
            ))}
          </div>
        </fieldset>
      )}
      {allowed.has('validation') && (
        <fieldset className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">Validasi (hanya dipersempit)</legend>
          <div className="grid gap-3 sm:grid-cols-3">
            {narrowableKeys(attribute.data_type).map((key) => (
              <Field key={key} label={validationLabel[key]} hint={attribute.validation[key] !== undefined ? `Generik: ${attribute.validation[key]}` : undefined}>
                {(p) => (
                  <Input
                    {...p}
                    type={attribute.data_type === 'date' ? 'date' : 'number'}
                    value={validation[key] ?? ''}
                    onChange={(e) => setValidation({ ...validation, [key]: e.target.value })}
                  />
                )}
              </Field>
            ))}
          </div>
        </fieldset>
      )}
      {allowed.has('default_value') && (
        <Field label="Nilai default">
          {(p) => <AttributeValueInput id={p.id} spec={{ data_type: attribute.data_type, options: effectiveOptions }} value={defaultValue} onChange={setDefaultValue} />}
        </Field>
      )}
    </Modal>
  )
}

function TenantAttributesCard({ version, editable, onSaved }: { version: TenantVersion; editable: boolean; onSaved: () => Promise<unknown> }) {
  const [editing, setEditing] = useState<AttributeDefinition | 'new' | null>(null)
  const [deleting, setDeleting] = useState<AttributeDefinition | null>(null)
  const remove = useMutation({
    mutationFn: (a: AttributeDefinition) => api.delete(`/asset-definition-attributes/${a.id}`),
    onSuccess: async () => {
      await onSaved()
      setDeleting(null)
    },
  })

  return (
    <Card
      title="Atribut tambahan perusahaan"
      actions={
        editable && (
          <Button size="sm" onClick={() => setEditing('new')}>
            <Plus className="size-4" aria-hidden /> Tambah atribut
          </Button>
        )
      }
    >
      <AttributeTable
        attributes={version.attributes ?? []}
        actions={
          editable
            ? (a) => (
                <div className="flex justify-end gap-1">
                  <Button size="sm" variant="ghost" onClick={() => setEditing(a)} aria-label={`Ubah ${a.label}`} title="Ubah">
                    <Pencil className="size-4" aria-hidden />
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setDeleting(a)} aria-label={`Hapus ${a.label}`} title="Hapus">
                    <Trash2 className="size-4" aria-hidden />
                  </Button>
                </div>
              )
            : undefined
        }
      />
      {editing && (
        <AttributeFormModal
          generic={false}
          unitsEndpoint="/generic-masters/units"
          attribute={editing === 'new' ? undefined : editing}
          onClose={() => setEditing(null)}
          onSubmit={async (payload) => {
            if (editing === 'new') await api.post(`/asset-definition-versions/${version.id}/attributes`, payload)
            else await api.patch(`/asset-definition-attributes/${editing.id}`, payload)
            await onSaved()
          }}
        />
      )}
      <Modal
        open={deleting !== null}
        title={`Hapus atribut ${deleting?.label ?? ''}?`}
        onClose={() => setDeleting(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDeleting(null)}>
              Batal
            </Button>
            <Button variant="danger" loading={remove.isPending} onClick={() => deleting && remove.mutate(deleting)}>
              Hapus
            </Button>
          </>
        }
      >
        {remove.isError && <Alert tone="error">{errorMessage(remove.error)}</Alert>}
        <p className="text-sm text-slate-700">Atribut hanya dihapus dari draft ini. Versi yang sudah dirilis tidak berubah.</p>
      </Modal>
    </Card>
  )
}

function SchemaPreviewView({ preview }: { preview: SchemaPreview }) {
  return (
    <div>
      {preview.meta.issues.length > 0 ? (
        <div className="p-4">
          <Alert tone="warning" title="Belum dapat dirilis">
            <ul className="list-inside list-disc">
              {preview.meta.issues.map((i) => (
                <li key={i}>{i}</li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : (
        !preview.meta.frozen && (
          <div className="p-4">
            <Alert tone="success">Skema valid dan siap dirilis.</Alert>
          </div>
        )
      )}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-slate-600">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Atribut</th>
              <th className="px-4 py-2.5 font-semibold">Tipe</th>
              <th className="px-4 py-2.5 font-semibold">Sumber</th>
              <th className="hidden px-4 py-2.5 font-semibold md:table-cell">Aturan efektif</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {preview.data.attributes.map((a) => (
              <tr key={a.key}>
                <td className="px-4 py-3">
                  <p className="font-medium text-slate-900">
                    {a.label}
                    {a.required && <span className="text-red-600"> *</span>}
                  </p>
                  <p className="font-mono text-xs text-slate-500">{a.key}</p>
                </td>
                <td className="px-4 py-3 text-slate-700">{dataTypeLabel[a.data_type]}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    <Badge tone={a.source === 'generic' ? 'slate' : 'blue'}>{a.source === 'generic' ? 'Generic' : 'Perusahaan'}</Badge>
                    {a.overridden.length > 0 && <Badge tone="amber">Override: {a.overridden.join(', ')}</Badge>}
                  </div>
                </td>
                <td className="hidden px-4 py-3 text-slate-600 md:table-cell">{describeRules(a)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ReleaseActions({ version, issues, onDone }: { version: TenantVersion; issues: string[]; onDone: () => Promise<unknown> }) {
  const navigate = useNavigate()
  const [confirm, setConfirm] = useState<'release' | 'discard' | null>(null)
  const mutation = useMutation({
    mutationFn: (action: 'release' | 'discard') =>
      action === 'release' ? api.post(`/asset-definition-versions/${version.id}/release`) : api.delete(`/asset-definition-versions/${version.id}`),
    onSuccess: async (_, action) => {
      setConfirm(null)
      if (action === 'discard') navigate(`/master/definisi/${version.tenant_definition_id}`)
      else await onDone()
    },
  })
  const releaseIssues = mutation.error instanceof ApiError ? ((mutation.error.details.issues as string[] | undefined) ?? []) : []

  return (
    <>
      <Button variant="secondary" onClick={() => (mutation.reset(), setConfirm('discard'))}>
        Buang draft
      </Button>
      <Button onClick={() => (mutation.reset(), setConfirm('release'))} disabled={issues.length > 0} title={issues.length ? 'Perbaiki masalah skema terlebih dahulu' : undefined}>
        Rilis versi
      </Button>
      <Modal
        open={confirm !== null}
        title={confirm === 'release' ? `Rilis versi ${version.version_number}?` : 'Buang draft?'}
        onClose={() => setConfirm(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setConfirm(null)}>
              Batal
            </Button>
            <Button variant={confirm === 'discard' ? 'danger' : 'primary'} loading={mutation.isPending} onClick={() => confirm && mutation.mutate(confirm)}>
              {confirm === 'release' ? 'Rilis' : 'Buang'}
            </Button>
          </>
        }
      >
        {mutation.isError && (
          <Alert tone="error" title={errorMessage(mutation.error)}>
            {releaseIssues.length > 0 && (
              <ul className="list-inside list-disc">
                {releaseIssues.map((i) => (
                  <li key={i}>{i}</li>
                ))}
              </ul>
            )}
          </Alert>
        )}
        <p className="text-sm text-slate-700">
          {confirm === 'release'
            ? 'Skema efektif akan dibekukan beserta hash-nya. Aset baru akan memakai versi ini; versi yang dirilis tidak dapat diubah lagi.'
            : 'Draft beserta atribut tambahan dan override-nya akan dihapus. Versi yang sudah dirilis tidak terpengaruh.'}
        </p>
      </Modal>
    </>
  )
}

function DeprecateAction({ version, onDone }: { version: TenantVersion; onDone: () => Promise<unknown> }) {
  const mutation = useMutation({ mutationFn: () => api.post(`/asset-definition-versions/${version.id}/deprecate`), onSuccess: onDone })
  return (
    <Button variant="secondary" loading={mutation.isPending} onClick={() => mutation.mutate()} title="Versi deprecated tidak dipakai untuk aset baru">
      Deprecate
    </Button>
  )
}
