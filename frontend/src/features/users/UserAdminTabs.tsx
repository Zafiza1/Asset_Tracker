import clsx from 'clsx'
import { NavLink } from 'react-router'
import { useAuth } from '../../lib/auth/context'

/** Users, their invitations and their roles are one menu: /pengguna, /pengguna/undangan, /pengguna/role. */
export function UserAdminTabs() {
  const { can } = useAuth()
  const tabs = [
    { to: '/pengguna', label: 'Pengguna', show: can('user.view') },
    { to: '/pengguna/undangan', label: 'Undangan', show: can('user.view') },
    { to: '/pengguna/role', label: 'Role & Permission', show: can('role.view') },
  ].filter((t) => t.show)
  if (tabs.length < 2) return null

  return (
    <nav aria-label="Pengguna" className="mb-4 flex gap-1 border-b border-slate-200">
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
  )
}
