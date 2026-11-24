import { flowApi, outcomeLabel, type PathStep, type Scenario } from './api'

/** The outcome of running one scenario, for the flow it was run on (`key`). */
export interface ScenarioResult {
  passed: boolean
  key: string
  /** The path the dry run took, as text, when it was not the scenario's. */
  detail: string | null
}

/** Whether every scenario that must run did, on this flow, and passed (F08-09, F08-21). */
export function scenariosPassed(scenarios: Scenario[] | undefined, results: Record<string, ScenarioResult>, key: string): boolean {
  if (!scenarios) return false
  return scenarios.every((s) => s.status === 'no_sample' || (s.status === 'ready' && results[s.id]?.key === key && results[s.id].passed))
}

/** Runs a scenario through flow/simulate (nothing saved) and checks it took its path. */
export async function runScenario(typeId: string, scenario: Scenario, key: string): Promise<ScenarioResult> {
  const { data } = await flowApi.simulate(typeId, { asset_ids: scenario.inputs.asset_ids, field_values: scenario.inputs.field_values, decisions: scenario.inputs.decisions })
  const taken = data.path.map((p) => `${p.node_id}:${p.outcome ?? ''}`).join('>')
  const expected = scenario.steps.map((s) => `${s.node_id}:${s.outcome ?? ''}`).join('>')
  const sameRule = scenario.kind !== 'blocked' || (data.path[0]?.rule_id ?? null) === scenario.rule_id
  const passed = taken === expected && sameRule
  return { passed, key, detail: passed ? null : pathText(data.path) }
}

function pathText(path: PathStep[]): string {
  return path.map((p) => `${p.label ?? p.node_id}${p.outcome && p.outcome in outcomeLabel ? ` (${outcomeLabel[p.outcome as keyof typeof outcomeLabel]})` : p.outcome === 'blocked' ? ' (ditolak saat dicatat)' : ''}`).join(' → ')
}
