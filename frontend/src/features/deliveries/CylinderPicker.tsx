import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { useState } from 'react'
import { Alert, Spinner } from '../../components/ui/Feedback'
import { Input } from '../../components/ui/Field'
import { errorMessage } from '../../lib/forms'
import { useDebounced } from '../../lib/useDebounced'
import { cylinderSummary, deliveriesApi, DELIVERIES_KEY, type Cylinder, type Direction } from './api'

/**
 * Search and check the cylinders of one customer. `taken` are those already picked for another
 * customer of the same transaction. At most 50 are listed at a time; searching narrows them down.
 */
export function CylinderPicker({ direction, customerId, deliveryId, value, taken, onChange }: { direction: Direction; customerId: string; deliveryId?: string; value: Cylinder[]; taken: ReadonlySet<string>; onChange: (next: Cylinder[]) => void }) {
  const [search, setSearch] = useState('')
  const term = useDebounced(search.trim(), 300)
  const params = { direction, search: term, delivery_id: deliveryId, customer_id: direction === 'incoming' ? customerId : undefined }
  const query = useQuery({ queryKey: [...DELIVERIES_KEY, 'cylinders', params], queryFn: () => deliveriesApi.cylinders(params), placeholderData: keepPreviousData })
  const rows = (query.data?.data ?? []).filter((c) => !taken.has(c.id))
  const picked = new Set(value.map((c) => c.id))
  const toggle = (c: Cylinder) => onChange(picked.has(c.id) ? value.filter((x) => x.id !== c.id) : [...value, c])
  const unpickedRows = rows.filter((c) => !picked.has(c.id))

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari nama produk atau No Manufaktur" aria-label="Cari tabung" className="min-w-0 flex-1" onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()} />
        {unpickedRows.length > 0 && (
          <button type="button" className="text-sm font-semibold text-brand-700 hover:underline" onClick={() => onChange([...value, ...unpickedRows])}>
            Pilih {unpickedRows.length} yang tampil
          </button>
        )}
      </div>
      {value.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" aria-label="Tabung dipilih">
          {value.map((c) => (
            <li key={c.id} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-brand-800 ring-1 ring-inset ring-brand-200">
              <span title={c.name}>{c.serial_number ?? c.name}</span>
              <button type="button" onClick={() => toggle(c)} className="rounded-full p-0.5 hover:bg-brand-100" aria-label={`Hapus ${c.serial_number ?? c.name}`}>
                <X className="size-3" aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      )}
      {query.isPending ? (
        <Spinner label="Memuat tabung…" />
      ) : query.isError ? (
        <Alert tone="error">{errorMessage(query.error)}</Alert>
      ) : rows.length === 0 ? (
        <p className="rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
          {term ? 'Tidak ada tabung yang cocok.' : direction === 'outgoing' ? 'Tidak ada tabung tersedia di gudang.' : 'Tidak ada tabung tercatat di customer ini. Tabung tetap bisa di-scan saat dijemput.'}
        </p>
      ) : (
        <ul className="max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg ring-1 ring-slate-200" aria-label="Tabung tersedia">
          {rows.map((c) => (
            <li key={c.id}>
              <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50">
                <input type="checkbox" className="size-4 accent-brand-700" checked={picked.has(c.id)} onChange={() => toggle(c)} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-900">
                    {c.name} <span className="font-mono text-xs font-normal text-slate-600">{c.serial_number}</span>
                  </span>
                  <span className="block truncate text-xs text-slate-500">{cylinderSummary(c.details)}</span>
                </span>
              </label>
            </li>
          ))}
        </ul>
      )}
      {query.data && query.data.meta.total > query.data.data.length && <p className="text-xs text-slate-500">Menampilkan {query.data.data.length} dari {query.data.meta.total} tabung. Persempit dengan pencarian.</p>}
    </div>
  )
}
