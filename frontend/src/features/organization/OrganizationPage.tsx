import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Building2, CheckCircle2, Globe2, Lock, Pencil, Phone, ShieldCheck, X, type LucideIcon } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select, Textarea } from '../../components/ui/Field'
import { Badge } from '../../components/ui/Badge'
import { statusTone } from '../../components/ui/badgeTones'
import { api } from '../../lib/api/client'
import type { Organization, Resource } from '../../lib/api/types'
import { useAuth, ME_KEY } from '../../lib/auth/context'
import { applyApiErrors } from '../../lib/forms'
import { formatDate, formatDateTime, orgStatusLabel } from '../../lib/format'
import {
  COMMON_CURRENCIES,
  EMAIL_PATTERN,
  INDONESIAN_TIMEZONES,
  NPWP_PATTERN,
  PHONE_PATTERN,
  PROFILE_SECTIONS,
  allTimezones,
  currencyName,
  describeCurrency,
  describeTimezone,
  formatNpwp,
  isFilled,
  npwpDigits,
  phoneDigits,
  profileCompleteness,
  type ProfileField,
} from './profileFormat'

type FormValues = Pick<Organization, ProfileField>

const SUCCESS_TIMEOUT_MS = 6000

const SECTION_META: Record<(typeof PROFILE_SECTIONS)[number]['key'], { title: string; description: string; icon: LucideIcon }> = {
  identity: { title: 'Identitas', description: 'Nama yang tampil di aplikasi dan dokumen resmi.', icon: Building2 },
  contact: { title: 'Kontak', description: 'Cara menghubungi organisasi.', icon: Phone },
  regional: { title: 'Regional', description: 'Dipakai untuk tanggal, waktu, dan nilai aset.', icon: Globe2 },
}

export function OrganizationPage() {
  const { can } = useAuth()
  const query = useQuery({ queryKey: ['organization'], queryFn: () => api.get<Resource<Organization>>('/organization') })
  const [editing, setEditing] = useState(false)
  const [saved, setSaved] = useState(false)
  const savedRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!saved) return
    savedRef.current?.focus()
    const timer = setTimeout(() => setSaved(false), SUCCESS_TIMEOUT_MS)
    return () => clearTimeout(timer)
  }, [saved])

  if (query.isLoading) return <Spinner />
  if (query.isError) return <ErrorState error={query.error} />
  const org = query.data!.data
  const canManage = can('organization.manage')

  const startEditing = () => {
    setSaved(false)
    setEditing(true)
  }

  return (
    <div className="w-full max-w-6xl">
      <PageHeader
        title="Profil Organisasi"
        description="Identitas, kontak, dan pengaturan regional organisasi Anda."
        badges={<Badge tone={statusTone[org.status]}>{orgStatusLabel[org.status]}</Badge>}
        meta={
          <>
            <span>
              Kode: <span className="font-mono font-medium text-slate-700">{org.code}</span>
            </span>
            <span title={formatDateTime(org.updated_at)}>Diperbarui {formatDate(org.updated_at)}</span>
          </>
        }
        actions={
          canManage &&
          !editing && (
            <Button onClick={startEditing} className="w-full sm:w-auto">
              <Pencil className="size-4" aria-hidden /> Edit profil
            </Button>
          )
        }
      />

      {saved && (
        <div ref={savedRef} tabIndex={-1} className="relative mb-4 outline-none">
          <Alert tone="success" title="Profil organisasi berhasil diperbarui">
            <p>Perubahan sudah disimpan dan langsung berlaku.</p>
          </Alert>
          <button
            type="button"
            onClick={() => setSaved(false)}
            aria-label="Tutup notifikasi"
            className="absolute right-2 top-2 grid size-8 place-items-center rounded-md text-green-700 hover:bg-green-100"
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>
      )}

      {editing ? (
        <OrganizationForm
          org={org}
          onCancel={() => setEditing(false)}
          onSaved={() => {
            setEditing(false)
            setSaved(true)
          }}
        />
      ) : (
        <div className="space-y-4">
          <CompletenessCard org={org} canManage={canManage} onComplete={startEditing} />
          <Card>
            {PROFILE_SECTIONS.map((section) => {
              const meta = SECTION_META[section.key]
              const missing = section.fields.filter((f) => !isFilled(org[f.key])).length
              return (
                <Section key={section.key} {...meta} status={<SectionStatus missing={missing} />}>
                  {section.fields.map((f) => (
                    <Item key={f.key} label={f.label} value={displayValue(org, f.key)} wide={f.key === 'address'} />
                  ))}
                </Section>
              )
            })}
          </Card>
          <AccessNote canManage={canManage} />
        </div>
      )}
    </div>
  )
}

function displayValue(org: Organization, key: ProfileField): ReactNode {
  const value = org[key]
  if (!isFilled(value)) return null
  switch (key) {
    case 'tax_id':
      return <span className="font-mono tabular-nums">{formatNpwp(value)}</span>
    case 'email':
      return (
        <a href={`mailto:${value}`} className="text-brand-700 hover:underline">
          {value}
        </a>
      )
    case 'phone':
      return (
        <a href={`tel:${value}`} className="tabular-nums text-brand-700 hover:underline">
          {value}
        </a>
      )
    case 'timezone':
      return describeTimezone(value!)
    case 'currency':
      return describeCurrency(value!)
    default:
      return value
  }
}

function CompletenessCard({ org, canManage, onComplete }: { org: Organization; canManage: boolean; onComplete: () => void }) {
  const { total, filled, missing, percent } = profileCompleteness(org)
  const complete = missing.length === 0
  return (
    <Card>
      <div className="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:gap-6 sm:px-6">
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-3">
            <h2 className="text-sm font-semibold text-slate-900">Kelengkapan Profil</h2>
            <span className={clsx('text-2xl font-semibold tabular-nums', complete ? 'text-green-700' : 'text-slate-900')}>{percent}%</span>
          </div>
          <div
            role="progressbar"
            aria-label="Kelengkapan profil"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
          >
            <div className={clsx('h-full rounded-full transition-[width] duration-500', complete ? 'bg-green-600' : 'bg-brand-600')} style={{ width: `${percent}%` }} />
          </div>
          <p className="mt-2 text-sm text-slate-600">
            {filled} dari {total} informasi telah dilengkapi.
            {!complete && (
              <>
                {' '}
                <span className="font-medium text-slate-700">{missing.length} informasi belum diisi</span>: {missing.map((f) => f.label).join(', ')}.
              </>
            )}
          </p>
          {!complete && !canManage && <p className="mt-1 text-sm text-slate-500">Hubungi Super Admin organisasi untuk melengkapinya.</p>}
        </div>
        {complete ? (
          <p className="flex items-center gap-1.5 text-sm font-medium text-green-700">
            <CheckCircle2 className="size-4" aria-hidden /> Profil lengkap
          </p>
        ) : (
          canManage && (
            <Button variant="secondary" onClick={onComplete} className="w-full shrink-0 sm:w-auto">
              Lengkapi sekarang
            </Button>
          )
        )}
      </div>
    </Card>
  )
}

function SectionStatus({ missing }: { missing: number }) {
  return missing > 0 ? (
    <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200">{missing} belum diisi</span>
  ) : (
    <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700">
      <CheckCircle2 className="size-3.5" aria-hidden /> Lengkap
    </span>
  )
}

/** A labelled group of fields: heading on the left on wide screens, stacked above on mobile. */
function Section({
  title,
  description,
  icon: Icon,
  status,
  children,
  as: Tag = 'dl',
}: {
  title: string
  description: string
  icon: LucideIcon
  status?: ReactNode
  children: ReactNode
  as?: 'dl' | 'div'
}) {
  return (
    <div className="grid gap-4 border-b border-slate-100 px-4 py-6 last:border-b-0 sm:px-6 md:grid-cols-[14rem_1fr] md:gap-10">
      <div className="flex gap-3">
        <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700">
          <Icon className="size-4" aria-hidden />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            {status}
          </div>
          <p className="mt-0.5 text-sm text-slate-500">{description}</p>
        </div>
      </div>
      <Tag className="grid gap-x-8 gap-y-5 sm:grid-cols-2">{children}</Tag>
    </div>
  )
}

function Item({ label, value, wide }: { label: string; value: ReactNode; wide?: boolean }) {
  const empty = value === null || value === undefined
  return (
    <div className={clsx('min-w-0', wide && 'sm:col-span-2')}>
      <dt className="text-sm font-medium text-slate-600">{label}</dt>
      <dd className="mt-1 whitespace-pre-line break-words text-[0.9375rem] text-slate-900">
        {empty ? <span className="inline-block rounded-md bg-slate-100 px-2 py-0.5 text-sm text-slate-500">Belum diisi</span> : value}
      </dd>
    </div>
  )
}

function AccessNote({ canManage }: { canManage: boolean }) {
  const Icon = canManage ? ShieldCheck : Lock
  return (
    <p className="flex items-start gap-2 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
      <Icon className="mt-0.5 size-4 shrink-0 text-slate-500" aria-hidden />
      {canManage ? (
        <span>
          <span className="font-medium text-slate-800">Super Admin</span> — Anda dapat mengedit profil organisasi. Peran lain hanya dapat melihatnya.
        </span>
      ) : (
        <span>Anda dapat melihat profil ini. Hanya Super Admin yang dapat mengubahnya.</span>
      )}
    </p>
  )
}

const collapseSpaces = (v: string | null) => (v ?? '').replace(/\s+/g, ' ').trim()

function OrganizationForm({ org, onCancel, onSaved }: { org: Organization; onCancel: () => void; onSaved: () => void }) {
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<FormValues>({
    defaultValues: { ...org, tax_id: formatNpwp(org.tax_id) },
  })
  const mutation = useMutation({
    mutationFn: (values: FormValues) => api.put<Resource<Organization>>('/organization', values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['organization'] })
      await queryClient.invalidateQueries({ queryKey: ME_KEY })
      onSaved()
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })
  const { errors } = formState

  const zones = allTimezones()
  const indonesian = new Set(INDONESIAN_TIMEZONES.map((z) => z.zone))
  const otherZones = zones.filter((z) => !indonesian.has(z))
  if (!indonesian.has(org.timezone) && !otherZones.includes(org.timezone)) otherZones.unshift(org.timezone)
  const currencies = COMMON_CURRENCIES.includes(org.currency) ? COMMON_CURRENCIES : [org.currency, ...COMMON_CURRENCIES]

  return (
    <Card>
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate>
        {formError && (
          <div className="px-4 pt-5 sm:px-6">
            <Alert tone="error">{formError}</Alert>
          </div>
        )}
        <Section as="div" {...SECTION_META.identity}>
          <Field label="Nama" error={errors.name?.message} hint="Nama singkat yang tampil di seluruh aplikasi, mis. PT SIG." required>
            {(p) => (
              <Input
                {...p}
                placeholder="PT SIG"
                {...register('name', {
                  setValueAs: collapseSpaces,
                  validate: (v) => (v.length >= 2 ? true : v ? 'Nama minimal 2 karakter.' : 'Nama wajib diisi.'),
                })}
              />
            )}
          </Field>
          <Field label="Nama legal" error={errors.legal_name?.message} hint="Persis seperti di akta pendirian/NIB, termasuk bentuk badan usaha (PT, CV, Tbk).">
            {(p) => <Input {...p} placeholder="PT Sinar Indah Gemilang Tbk" {...register('legal_name', { setValueAs: (v: string | null) => collapseSpaces(v) || null })} />}
          </Field>
          <Field label="NPWP" error={errors.tax_id?.message} hint="15 digit (99.999.999.9-999.999) atau 16 digit.">
            {(p) => (
              <Input
                {...p}
                inputMode="numeric"
                placeholder="01.234.567.8-901.234"
                {...register('tax_id', {
                  setValueAs: (v: string | null) => npwpDigits(v ?? '') || null,
                  validate: (v) => !v || NPWP_PATTERN.test(v) || 'NPWP harus 15 digit (99.999.999.9-999.999) atau 16 digit.',
                })}
              />
            )}
          </Field>
        </Section>
        <Section as="div" {...SECTION_META.contact}>
          <Field label="Email" error={errors.email?.message}>
            {(p) => (
              <Input
                {...p}
                type="email"
                autoComplete="email"
                placeholder="admin@perusahaan.co.id"
                {...register('email', {
                  setValueAs: (v: string | null) => (v ?? '').trim().toLowerCase() || null,
                  validate: (v) => !v || EMAIL_PATTERN.test(v) || 'Format email tidak valid, contoh: admin@perusahaan.co.id.',
                })}
              />
            )}
          </Field>
          <Field label="Telepon" error={errors.phone?.message} hint="Diawali 0 atau kode negara, mis. +62.">
            {(p) => (
              <Input
                {...p}
                type="tel"
                autoComplete="tel"
                placeholder="021-5551234 atau +62 812-3456-7890"
                {...register('phone', {
                  setValueAs: (v: string | null) => phoneDigits((v ?? '').trim()) || null,
                  validate: (v) => !v || PHONE_PATTERN.test(v) || 'Nomor telepon diawali 0 atau +62 dan berisi 9–15 digit.',
                })}
              />
            )}
          </Field>
          <div className="sm:col-span-2">
            <Field label="Alamat" error={errors.address?.message} hint="Cantumkan jalan dan nomor, kelurahan/kecamatan, kota, provinsi, dan kode pos.">
              {(p) => (
                <Textarea
                  {...p}
                  rows={4}
                  placeholder={'Jl. Jend. Sudirman No. 1\nKel. Karet, Kec. Setiabudi\nJakarta Selatan, DKI Jakarta 12920'}
                  {...register('address', { setValueAs: (v: string | null) => (v ?? '').trim() || null })}
                />
              )}
            </Field>
          </div>
        </Section>
        <Section as="div" {...SECTION_META.regional}>
          <Field label="Zona waktu" error={errors.timezone?.message} required>
            {(p) => (
              <Select {...p} {...register('timezone', { required: 'Zona waktu wajib diisi.' })}>
                <optgroup label="Indonesia">
                  {INDONESIAN_TIMEZONES.map((z) => (
                    <option key={z.zone} value={z.zone}>
                      {describeTimezone(z.zone)}
                    </option>
                  ))}
                </optgroup>
                <optgroup label="Lainnya">
                  {otherZones.map((z) => (
                    <option key={z} value={z}>
                      {z}
                    </option>
                  ))}
                </optgroup>
              </Select>
            )}
          </Field>
          <Field label="Mata uang" error={errors.currency?.message} required>
            {(p) => (
              <Select {...p} {...register('currency', { required: 'Mata uang wajib diisi.' })}>
                {currencies.map((c) => (
                  <option key={c} value={c}>
                    {c} — {currencyName(c) ?? c}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </Section>
        <div className="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50/60 px-4 py-4 sm:flex-row sm:justify-end sm:px-6">
          <Button variant="secondary" onClick={onCancel} disabled={mutation.isPending} className="w-full sm:w-auto">
            Batal
          </Button>
          <Button type="submit" loading={mutation.isPending} className="w-full sm:w-auto">
            Simpan perubahan
          </Button>
        </div>
      </form>
    </Card>
  )
}
