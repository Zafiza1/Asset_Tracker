import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { Plus, ChevronDown, ChevronRight } from 'lucide-react'
import { z } from 'zod'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { Alert, ErrorState, Spinner } from '../../components/ui/Feedback'
import { Field, Input, Select } from '../../components/ui/Field'
import { api } from '../../lib/api/client'
import type { LicenseCode, OrganizationInvitation, UserOrganizationOption, Permission } from '../../lib/api/types'
import { applyApiErrors } from '../../lib/forms'
import { permissionGroupLabel } from '../../lib/format'
import { RoleQuickCreateModal } from '../roles/RoleQuickCreateModal'
import { useOrganizationOptions } from './api'

const schema = z.object({
  email: z.email('Masukkan email yang valid.'),
  employee_number: z.string().max(50).optional(),
  job_title: z.string().max(100).optional(),
  license_code_id: z.string().uuid('Format ID kode lisensi tidak valid.'),
  // One entry per organization the user is invited to, each with a single role and custom permissions.
  memberships: z
    .array(z.object({
      organization_id: z.string(),
      role_id: z.string().min(1, 'Pilih minimal satu role.'),
      permissions: z.array(z.string()).optional()
    }))
    .min(1, 'Pilih minimal satu organisasi.'),
})
type FormValues = z.infer<typeof schema>

/** Where the invitations list shows what was just sent. */
export interface InvitationSentState {
  invited: string
}

/**
 * "Tambah pengguna" sends an invitation: nobody gets access until the owner of the email
 * address accepts it (with an existing account or by signing up). The answer is the same
 * whether or not the address already has an account.
 */
export function UserCreatePage() {
  const options = useOrganizationOptions()

  if (options.isLoading) return <Spinner />
  if (options.isError) return <ErrorState error={options.error} />

  return <UserInviteForm organizations={options.data!} />
}

function UserInviteForm({ organizations }: { organizations: UserOrganizationOption[] }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string | null>(null)
  const [creatingRole, setCreatingRole] = useState(false)
  const [expandedPermissions, setExpandedPermissions] = useState<string[]>([])
  const current = organizations.find((o) => o.is_current)
  const multiple = organizations.length > 1

  // Fetch available license codes
  const licenseCodesQuery = useQuery({
    queryKey: ['license-codes', { status: 'available' }],
    queryFn: () => api.get<{ data: LicenseCode[] }>('/license-codes?status=available'),
  })

  // Fetch all permissions
  const permissionsQuery = useQuery({
    queryKey: ['permissions'],
    queryFn: () => api.get<{ data: { code: string; group: string; description: string }[] }>('/permissions'),
  })

  const { register, handleSubmit, setError, formState, control, setValue } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { memberships: current ? [{ organization_id: current.id, role_id: '', permissions: [] }] : [] },
  })
  const memberships = useWatch({ control, name: 'memberships' })
  const setMemberships = (next: FormValues['memberships']) => setValue('memberships', next, { shouldValidate: formState.isSubmitted })

  const toggleOrganization = (id: string, checked: boolean) =>
    setMemberships(
      checked
        ? // Keep the order of the organization list so error paths stay predictable.
          organizations.filter((o) => o.id === id || memberships.some((m) => m.organization_id === o.id)).map((o) => memberships.find((m) => m.organization_id === o.id) ?? { organization_id: o.id, role_id: '', permissions: [] })
        : memberships.filter((m) => m.organization_id !== id),
    )
  const setRole = (organizationId: string, roleId: string) => {
    const org = organizations.find(o => o.id === organizationId)
    const role = org?.roles.find(r => r.id === roleId)
    setMemberships(
      memberships.map((m) =>
        m.organization_id !== organizationId ? m : { ...m, role_id: roleId, permissions: role?.permissions ?? [] },
      ),
    )
  }
  const togglePermission = (organizationId: string, permission: string, checked: boolean) =>
    setMemberships(
      memberships.map((m) =>
        m.organization_id !== organizationId ? m : { ...m, permissions: checked ? [...(m.permissions ?? []), permission] : (m.permissions ?? []).filter((p) => p !== permission) },
      ),
    )

  const mutation = useMutation({
    // Invited users start with organization-wide scope; it can be narrowed once they joined.
    mutationFn: (v: FormValues) =>
      api.post<{ data: OrganizationInvitation | null; meta: { organization_ids: string[] } }>('/invitations', {
        ...v,
        memberships: v.memberships.map(m => ({
          organization_id: m.organization_id,
          role_ids: [m.role_id],
        })),
        scopes: [{ scope_type: 'organization' }],
      }),
    onSuccess: async (_res, v) => {
      await queryClient.invalidateQueries({ queryKey: ['invitations'] })
      navigate('/pengguna/undangan', { replace: true, state: { invited: v.email } satisfies InvitationSentState })
    },
    onError: (e) => setFormError(applyApiErrors(e, setError)),
  })

  const membershipErrors = formState.errors.memberships

  return (
    <>
      <PageHeader
        title="Undang Pengguna"
        description={
          multiple
            ? 'Masukkan email, pilih organisasi yang boleh diakses, lalu pilih role di setiap organisasi. Pengguna menerima undangan lewat email dan baru mendapat akses setelah menerimanya.'
            : 'Masukkan email lalu pilih role-nya; bila belum ada role yang sesuai, buat role baru beserta permission-nya langsung di sini. Pengguna baru mendapat akses setelah menerima undangan lewat email.'
        }
      />
      <Card>
        <form onSubmit={handleSubmit((v) => mutation.mutate(v))} noValidate className="grid gap-4 p-4 sm:grid-cols-2">
          {formError && (
            <div className="sm:col-span-2">
              <Alert tone="error">{formError}</Alert>
            </div>
          )}
          <div className="sm:col-span-2">
            <Alert tone="info">
              Pemilik email yang sudah punya akun masuk dengan akunnya lalu menerima undangan; yang belum punya akun mendaftar dari tautan undangan. Nama dan password diisi sendiri oleh pengguna. Undangan memiliki batas waktu dan hanya dapat dipakai sekali.
            </Alert>
          </div>
          <Field label="Email" error={formState.errors.email?.message} required>
            {(p) => <Input {...p} type="email" autoComplete="off" {...register('email')} />}
          </Field>
          <Field label="Nomor induk karyawan" error={formState.errors.employee_number?.message}>
            {(p) => <Input {...p} {...register('employee_number')} />}
          </Field>
          <Field label="Jabatan" error={formState.errors.job_title?.message}>
            {(p) => <Input {...p} {...register('job_title')} />}
          </Field>
          {current && (
            <Field label="Kode Lisensi" error={formState.errors.license_code_id?.message} hint="Pilih kode lisensi untuk ditugaskan ke pengguna ini." required>
              {(p) => (
                <Select {...p} {...register('license_code_id')}>
                  {licenseCodesQuery.data?.data.map((code) => (
                    <option key={code.id} value={code.id}>
                      {code.code} {code.notes ? `- ${code.notes}` : ''}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          )}
          <fieldset className="space-y-3 sm:col-span-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <legend className="text-sm font-medium text-slate-700">
                {multiple ? 'Akses Organisasi & Role' : 'Role & Permission'} <span className="text-red-600">*</span>
              </legend>
              {current && (
                <Button variant="secondary" size="sm" onClick={() => setCreatingRole(true)}>
                  <Plus className="size-4" aria-hidden /> Buat role baru{multiple && ` di ${current.name}`}
                </Button>
              )}
            </div>
            {membershipErrors?.message && <p className="text-sm text-red-600">{membershipErrors.message}</p>}
            {organizations.map((org) => {
              const index = memberships.findIndex((m) => m.organization_id === org.id)
              const selected = index >= 0
              const roleError = selected ? membershipErrors?.[index]?.role_id?.message ?? membershipErrors?.[index]?.organization_id?.message : undefined
              const showPermissions = expandedPermissions.includes(org.id)
              const selectedRole = org.roles.find(r => r.id === memberships[index]?.role_id)

              return (
                <div key={org.id} className={multiple ? 'rounded-md p-3 ring-1 ring-slate-200' : undefined}>
                  {multiple && (
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        className="size-4 rounded border-slate-300 text-brand-700"
                        checked={selected}
                        onChange={(e) => toggleOrganization(org.id, e.target.checked)}
                      />
                      <span className="text-sm font-medium text-slate-800">{org.name}</span>
                      <span className="text-xs text-slate-500">{org.code}</span>
                      {org.is_current && <Badge tone="blue">Organisasi aktif</Badge>}
                    </label>
                  )}
                  {selected && (
                    <div className={`space-y-3 ${multiple ? 'mt-3 pl-6' : ''}`}>
                      <Field label="Role" error={roleError} required>
                        {(p) => (
                          <Select {...p} {...register(`memberships.${index}.role_id`)} onChange={(e) => setRole(org.id, e.target.value)}>
                            <option value="">Pilih role...</option>
                            {org.roles.map((role) => (
                              <option key={role.id} value={role.id}>
                                {role.name} {role.description ? `- ${role.description}` : ''}
                              </option>
                            ))}
                          </Select>
                        )}
                      </Field>
                      {selectedRole && (
                        <div>
                          <button
                            type="button"
                            onClick={() => setExpandedPermissions((ids) => (showPermissions ? ids.filter((id) => id !== org.id) : [...ids, org.id]))}
                            className="flex items-center gap-2 text-sm font-medium text-slate-700 hover:text-slate-900"
                          >
                            {showPermissions ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                            Permission ({memberships[index].permissions?.length ?? 0})
                          </button>
                          {showPermissions && permissionsQuery.data && (
                            <div className="mt-2 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                              {(() => {
                                const groups = new Map<string, Permission[]>()
                                for (const p of permissionsQuery.data.data) {
                                  groups.set(p.group, [...(groups.get(p.group) ?? []), p])
                                }
                                return [...groups.entries()].map(([group, items]) => (
                                  <fieldset key={group} className="rounded-md p-3 ring-1 ring-slate-200">
                                    <legend className="px-1 text-sm font-semibold text-slate-700">{permissionGroupLabel[group] ?? group}</legend>
                                    <div className="space-y-1.5">
                                      {items.map((p) => (
                                        <label key={p.code} className="flex items-start gap-2 text-sm">
                                          <input
                                            type="checkbox"
                                            className="mt-0.5 size-4 rounded border-slate-300 text-brand-700"
                                            checked={memberships[index].permissions?.includes(p.code) ?? false}
                                            onChange={(e) => togglePermission(org.id, p.code, e.target.checked)}
                                          />
                                          <span>
                                            <span className="text-slate-800">{p.description}</span>
                                            <span className="block font-mono text-xs text-slate-400">{p.code}</span>
                                          </span>
                                        </label>
                                      ))}
                                    </div>
                                  </fieldset>
                                ))
                              })()}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  )}
                  {roleError && <p className={`mt-1 text-sm text-red-600 ${multiple ? 'pl-6' : ''}`}>{roleError}</p>}
                </div>
              )
            })}
          </fieldset>
          <div className="flex justify-end gap-2 sm:col-span-2">
            <Button variant="secondary" onClick={() => navigate('/pengguna')}>
              Batal
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              Kirim undangan
            </Button>
          </div>
        </form>
      </Card>
      {current && (
        <RoleQuickCreateModal
          open={creatingRole}
          onClose={() => setCreatingRole(false)}
          onCreated={async (role) => {
            await queryClient.invalidateQueries({ queryKey: ['users', 'organization-options'] })
            const base = memberships.some((m) => m.organization_id === current.id) ? memberships : [{ organization_id: current.id, role_id: '', permissions: [] }, ...memberships]
            setMemberships(base.map((m) => (m.organization_id === current.id ? { ...m, role_id: role.id, permissions: role.permissions ?? [] } : m)))
          }}
        />
      )}
    </>
  )
}
