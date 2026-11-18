import { useQuery } from '@tanstack/react-query'
import { runsApi } from './api'

/** How many running transactions wait for the user's step (menu badge, F-07 §5). */
export function useTaskCount(enabled = true) {
  return useQuery({
    queryKey: ['/transaction-runs', 'task-count'],
    queryFn: async () => (await runsApi.list({ assigned_to_me: '1', state: 'running', per_page: '1' })).meta.total,
    refetchInterval: 60_000,
    enabled,
  })
}
