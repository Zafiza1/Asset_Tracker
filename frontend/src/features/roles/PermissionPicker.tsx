import { useMemo } from 'react'
import type { Permission } from '../../lib/api/types'
import { useAuth } from '../../lib/auth/context'
import { permissionGroupLabel } from '../../lib/format'

/**
 * Permission checklist grouped by module. Only permissions the current user holds can be granted;
 * `allowed` further caps a default role to its template's permissions (others stay locked).
 * The approval group is reserved for the Super Admin and Supervisor roles (`approvalRole`).
 */
export function PermissionPicker({
  catalog,
  selected,
  onChange,
  editable,
  allowed,
  approvalRole = false,
  className = 'grid gap-4 md:grid-cols-2 xl:grid-cols-3',
}: {
  catalog: Permission[]
  selected: Set<string>
  onChange: (next: Set<string>) => void
  editable: boolean
  allowed?: Set<string>
  approvalRole?: boolean
  className?: string
}) {
  const { profile } = useAuth()
  const own = useMemo(() => new Set(profile?.permissions ?? []), [profile])
  const groups = useMemo(() => {
    const map = new Map<string, Permission[]>()
    for (const p of catalog) map.set(p.group, [...(map.get(p.group) ?? []), p])
    return [...map.entries()]
  }, [catalog])

  const toggle = (code: string, on: boolean) => {
    const next = new Set(selected)
    if (on) next.add(code)
    else next.delete(code)
    onChange(next)
  }

  return (
    <div className={className}>
      {groups.map(([group, items]) => (
        <fieldset key={group} className="rounded-md p-3 ring-1 ring-slate-200">
          <legend className="px-1 text-sm font-semibold text-slate-700">{permissionGroupLabel[group] ?? group}</legend>
          {group === 'approval' && <p className="mb-2 text-xs text-slate-500">Hanya untuk role Super Admin dan Supervisor.</p>}
          <div className="space-y-1.5">
            {items.map((p) => {
              // Mirrors the server: only permissions you hold can be granted, and a default role
              // only within its template.
              const checked = selected.has(p.code)
              const grantable = editable && own.has(p.code) && (!allowed || allowed.has(p.code)) && (p.group !== 'approval' || approvalRole)
              return (
                <label key={p.code} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="mt-0.5 size-4 rounded border-slate-300 text-brand-700 disabled:opacity-50"
                    checked={checked}
                    disabled={!grantable}
                    onChange={(e) => toggle(p.code, e.target.checked)}
                  />
                  <span>
                    <span className="text-slate-800">{p.description}</span>
                    <span className="block font-mono text-xs text-slate-400">{p.code}</span>
                  </span>
                </label>
              )
            })}
          </div>
        </fieldset>
      ))}
    </div>
  )
}
