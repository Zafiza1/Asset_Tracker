import { keepPreviousData, useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { Loader2, X } from 'lucide-react'
import { useEffect, useId, useState } from 'react'
import { api, qs } from '../../lib/api/client'
import { PersonAvatar } from '../assets/CustodianPicker'

const SEARCH_DELAY_MS = 250

/** An employee who can be invited (GET /invitations/employee-options). */
export interface EmployeeOption {
  id: string
  code: string
  name: string
  division: string | null
}

interface Props {
  id: string
  className: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
  value: EmployeeOption | null
  onChange: (value: EmployeeOption | null) => void
}

/**
 * Search an employee by name or code as you type: the server returns at most 20 matches, so even
 * with hundreds of employees nobody scrolls a long dropdown. Employees who already have an account
 * or a pending invitation are not offered.
 */
export function EmployeePicker({ value, onChange, className, ...aria }: Props) {
  const listId = useId()
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [active, setActive] = useState(0)

  useEffect(() => {
    const timer = setTimeout(() => setTerm(text.trim()), SEARCH_DELAY_MS)
    return () => clearTimeout(timer)
  }, [text])

  const options = useQuery({
    queryKey: ['/invitations/employee-options', term],
    queryFn: async () => (await api.get<{ data: EmployeeOption[] }>(`/invitations/employee-options${qs({ search: term || undefined })}`)).data,
    enabled: open,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })
  const rows = options.data ?? []

  const pick = (option: EmployeeOption | null) => {
    onChange(option)
    setOpen(false)
    setText('')
  }

  if (value) {
    return (
      <div className={clsx(className, 'flex items-center gap-2 bg-white')} id={aria.id} aria-describedby={aria['aria-describedby']}>
        <PersonAvatar name={value.name} />
        <span className="min-w-0 flex-1 text-sm">
          <span className="block truncate font-medium text-slate-900">{value.name}</span>
          <span className="block truncate text-xs text-slate-500">{[value.code, value.division].filter(Boolean).join(' · ')}</span>
        </span>
        <button type="button" aria-label="Ganti karyawan" className="rounded p-1 text-slate-500 hover:bg-slate-100" onClick={() => pick(null)}>
          <X className="size-4" aria-hidden />
        </button>
      </div>
    )
  }

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      if (rows.length > 0) setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (rows[active]) pick(rows[active])
    } else if (e.key === 'Escape' && open) {
      e.preventDefault()
      e.stopPropagation()
      setOpen(false)
    }
  }

  return (
    <div className="relative">
      <input
        {...aria}
        className={className}
        type="search"
        role="combobox"
        autoComplete="off"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        placeholder="Ketik nama atau kode karyawan"
        value={text}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onChange={(e) => (setText(e.target.value), setActive(0), setOpen(true))}
        onKeyDown={onKeyDown}
      />
      {open && (
        <ul id={listId} role="listbox" aria-label="Karyawan" className="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-lg bg-white p-1 shadow-raised ring-1 ring-slate-200">
          {options.isFetching && rows.length === 0 && (
            <li className="flex items-center gap-2 px-3 py-2 text-sm text-slate-500">
              <Loader2 className="size-4 animate-spin" aria-hidden />
              Mencari…
            </li>
          )}
          {options.isError && <li className="px-3 py-2 text-sm text-red-700">Daftar karyawan gagal dimuat. Coba lagi.</li>}
          {!options.isFetching && !options.isError && rows.length === 0 && (
            <li className="px-3 py-2 text-sm text-slate-500">
              {term ? `Tidak ada karyawan yang cocok dengan “${term}”, atau karyawannya sudah punya akun.` : 'Semua karyawan aktif sudah punya akun atau undangan.'}
            </li>
          )}
          {rows.map((row, i) => (
            <li key={row.id} role="presentation">
              <button
                type="button"
                role="option"
                aria-selected={i === active}
                tabIndex={-1}
                onMouseDown={(e) => e.preventDefault()}
                onMouseMove={() => setActive(i)}
                onClick={() => pick(row)}
                className={clsx('flex w-full items-center gap-2 rounded-md px-2 py-2 text-left', i === active ? 'bg-brand-50' : 'hover:bg-slate-50')}
              >
                <PersonAvatar name={row.name} />
                <span className="min-w-0">
                  <span className="block truncate text-sm font-medium text-slate-900">{row.name}</span>
                  <span className="block truncate text-xs text-slate-500">{[row.code, row.division].filter(Boolean).join(' · ')}</span>
                </span>
              </button>
            </li>
          ))}
          {rows.length === 20 && <li className="px-3 py-1.5 text-xs text-slate-500">Menampilkan 20 hasil pertama. Ketik lebih banyak untuk mempersempit.</li>}
        </ul>
      )}
    </div>
  )
}
