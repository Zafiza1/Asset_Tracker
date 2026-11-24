import type { UseQueryResult } from '@tanstack/react-query'
import { CheckCircle2, CircleAlert, CircleDashed, Play, RotateCcw, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Alert } from '../../components/ui/Feedback'
import { errorMessage } from '../../lib/forms'
import type { Scenario } from './api'
import { runScenario, type ScenarioResult } from './scenarioLogic'

/**
 * "Simulasikan transaksi" in Tinjau (F08-18..22): a scenario per path of the saved draft, each
 * run with its sample asset and values. A result belongs to the flow it was run on: after the
 * flow changes it shows "perlu dijalankan ulang". A path that stops before Selesai is an error;
 * a path without a sample asset is told, not required.
 */
export function ScenarioPanel({ typeId, flowKey, query, results, onResults, enabled }: {
  typeId: string
  /** Identifies the flow the scenarios belong to (graph and rules). */
  flowKey: string
  query: UseQueryResult<{ data: Scenario[]; meta: { total: number; truncated: boolean; limit: number } }>
  results: Record<string, ScenarioResult>
  onResults: (update: (r: Record<string, ScenarioResult>) => Record<string, ScenarioResult>) => void
  enabled: boolean
}) {
  const [running, setRunning] = useState<string | 'all' | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const scenarios = query.data?.data ?? []
  const runnable = scenarios.filter((s) => s.status === 'ready')

  const run = async (list: Scenario[], which: string | 'all') => {
    setRunning(which)
    setFailure(null)
    try {
      for (const s of list) {
        const result = await runScenario(typeId, s, flowKey)
        onResults((r) => ({ ...r, [s.id]: result }))
      }
    } catch (e) {
      setFailure(errorMessage(e))
    } finally {
      setRunning(null)
    }
  }

  if (!enabled) return <p className="text-sm text-slate-500">Simpan alur terlebih dahulu untuk membuat skenario.</p>
  if (query.isPending) return <p className="text-sm text-slate-500">Menyusun skenario…</p>
  if (query.isError) return <Alert tone="error">{errorMessage(query.error)}</Alert>

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-700">
          {scenarios.length} skenario dari alur draft{query.data?.meta.truncated ? ` (dari ${query.data.meta.total} jalur; sisanya dapat diuji di Skenario sendiri pada kanvas)` : ''}.
        </p>
        <Button size="sm" variant="secondary" disabled={runnable.length === 0} loading={running === 'all'} onClick={() => run(runnable, 'all')}>
          <Play className="size-4" aria-hidden />
          Jalankan semua skenario ({runnable.length})
        </Button>
      </div>
      {failure && <Alert tone="error">{failure}</Alert>}
      <ul className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
        {scenarios.map((s) => {
          const result = results[s.id]
          const stale = !!result && result.key !== flowKey
          const state = s.status !== 'ready' ? s.status : !result ? 'not_run' : stale ? 'stale' : result.passed ? 'passed' : 'failed'
          return (
            <li key={s.id} className="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0 text-sm">
                <p className="flex items-start gap-1.5 text-slate-800">
                  <StateIcon state={state} />
                  <span>{s.name}</span>
                </p>
                {s.note && <p className="pl-5 text-xs text-slate-500">{s.note}</p>}
                {state === 'failed' && result?.detail && <p className="pl-5 text-xs text-red-700">Simulasi menempuh: {result.detail}</p>}
              </div>
              <div className="flex shrink-0 items-center gap-2 pl-5 sm:pl-0">
                <StateBadge state={state} />
                {s.status === 'ready' && (
                  <Button size="sm" variant="ghost" aria-label={`Jalankan ${s.name}`} loading={running === s.id} disabled={running !== null} onClick={() => run([s], s.id)}>
                    {stale ? <RotateCcw className="size-4" aria-hidden /> : <Play className="size-4" aria-hidden />}
                  </Button>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

type State = 'passed' | 'failed' | 'not_run' | 'stale' | 'no_sample' | 'problem'

function StateBadge({ state }: { state: State }) {
  const map: Record<State, [string, 'green' | 'red' | 'slate' | 'amber']> = {
    passed: ['Lulus', 'green'],
    failed: ['Gagal', 'red'],
    not_run: ['Belum dijalankan', 'slate'],
    stale: ['Perlu dijalankan ulang', 'amber'],
    no_sample: ['Tanpa aset contoh', 'slate'],
    problem: ['Bermasalah', 'red'],
  }
  return <Badge tone={map[state][1]}>{map[state][0]}</Badge>
}

function StateIcon({ state }: { state: State }) {
  const cls = 'mt-0.5 size-4 shrink-0'
  if (state === 'passed') return <CheckCircle2 className={`${cls} text-green-600`} aria-hidden />
  if (state === 'failed' || state === 'problem') return <XCircle className={`${cls} text-red-600`} aria-hidden />
  if (state === 'stale') return <CircleAlert className={`${cls} text-amber-600`} aria-hidden />
  return <CircleDashed className={`${cls} text-slate-400`} aria-hidden />
}
