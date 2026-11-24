import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { useState } from 'react'
import { useParams } from 'react-router'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card, PageHeader } from '../../components/ui/Card'
import { DetailSkeleton, EmptyState, ErrorState } from '../../components/ui/Feedback'
import { Select } from '../../components/ui/Field'
import { formatDateTime } from '../../lib/format'
import { flowApi, typesApi } from './api'
import { DesignerNav } from './DesignerNav'
import { FlowDocumentationView } from './FlowDocumentationView'

/**
 * "Cara kerja" of a transaction type (F08-23..26): the documentation of the active version by
 * default, any published version or the draft on request, with what changed from the version
 * before it. For everyone who may see transactions, on a phone too; prints cleanly.
 */
export function TransactionHowItWorksPage() {
  const { id = '' } = useParams()
  const type = useQuery({ queryKey: ['/transaction-types', id], queryFn: () => typesApi.get(id) })
  const flow = useQuery({ queryKey: ['/transaction-types', id, 'flow'], queryFn: () => flowApi.get(id) })
  const versions = flow.data?.data.versions ?? []
  const fallback = flow.data?.data.active?.version_number ?? flow.data?.data.draft?.version_number
  const [chosen, setChosen] = useState<number | null>(null)
  const number = chosen ?? fallback
  const doc = useQuery({ queryKey: ['/transaction-types', id, 'flow', 'describe', number ?? 'none'], queryFn: () => flowApi.describe(id, number), enabled: flow.isSuccess })

  if (type.isPending || flow.isPending) return <DetailSkeleton />
  if (type.isError || flow.isError) return <ErrorState error={type.error ?? flow.error} onRetry={() => (type.refetch(), flow.refetch())} />
  const t = type.data.data
  const shown = versions.find((v) => v.version_number === number)

  return (
    <>
      <PageHeader
        title={`Cara kerja: ${t.name}`}
        badges={shown && <Badge tone={shown.status === 'published' ? 'green' : 'amber'}>{shown.status === 'published' ? `Terbit v${shown.version_number}` : `Draft v${shown.version_number}`}</Badge>}
        description={shown?.published_at ? `Diterbitkan ${formatDateTime(shown.published_at)}${shown.published_by ? ` oleh ${shown.published_by}` : ''}.${shown.change_note ? ` Catatan: ${shown.change_note}` : ''}` : 'Dibuat otomatis dari pengaturan jenis dan alurnya.'}
        actions={
          <span className="flex flex-wrap gap-2 print:hidden">
            {versions.length > 1 && (
              <Select aria-label="Versi" value={String(number ?? '')} onChange={(e) => setChosen(Number(e.target.value))} className="w-44">
                {versions.map((v) => (
                  <option key={v.version_number} value={v.version_number}>
                    {v.status === 'published' ? `Versi ${v.version_number}` : `Draft v${v.version_number}`}
                  </option>
                ))}
              </Select>
            )}
            <Button variant="secondary" onClick={() => window.print()}>
              <Printer className="size-4" aria-hidden />
              Cetak
            </Button>
          </span>
        }
      />
      <div className="print:hidden">
        <DesignerNav typeId={id} />
      </div>
      <Card>
        <div className="p-4">
          {doc.isPending ? (
            <p className="text-sm text-slate-500">Menyusun dokumentasi…</p>
          ) : doc.isError ? (
            <ErrorState error={doc.error} onRetry={() => doc.refetch()} />
          ) : doc.data.data.records_at_once && !t.system_type && versions.length === 0 ? (
            <EmptyState title="Belum ada alur" description="Transaksi jenis ini langsung tercatat tanpa langkah persetujuan." />
          ) : (
            <FlowDocumentationView doc={doc.data.data} />
          )}
        </div>
      </Card>
    </>
  )
}
