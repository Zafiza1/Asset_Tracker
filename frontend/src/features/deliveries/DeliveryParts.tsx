import clsx from 'clsx'
import { ArrowDownToLine, ArrowUpFromLine, Check } from 'lucide-react'
import { lazy, Suspense, type ComponentProps } from 'react'
import { Badge } from '../../components/ui/Badge'
import { Spinner } from '../../components/ui/Feedback'
import { deliveryStatusTone, directionTone, progressLabels, progressStep, type Delivery } from './api'

// The map library is loaded only by pages that show a map.
const LazyMap = lazy(() => import('./DeliveryMap'))

export function DeliveryMapView(props: ComponentProps<typeof LazyMap>) {
  return (
    <Suspense fallback={<Spinner label="Memuat peta…" />}>
      <LazyMap {...props} />
    </Suspense>
  )
}

export function DirectionBadge({ delivery }: { delivery: Delivery }) {
  return (
    <Badge tone={directionTone[delivery.direction]} icon={delivery.direction === 'outgoing' ? ArrowUpFromLine : ArrowDownToLine}>
      {delivery.direction === 'outgoing' ? 'Keluar' : 'Masuk'}
    </Badge>
  )
}

export function DeliveryStatusBadge({ delivery }: { delivery: Delivery }) {
  return <Badge tone={deliveryStatusTone[delivery.status]}>{delivery.status_label}</Badge>
}

/** Where the trip is between loading and unloading. */
export function DeliveryProgress({ delivery }: { delivery: Delivery }) {
  if (delivery.status === 'cancelled') return null
  const current = progressStep(delivery)
  return (
    <ol className="grid grid-cols-5 gap-1" aria-label="Tahap perjalanan">
      {progressLabels[delivery.direction].map((label, i) => {
        const done = i < current || delivery.status === 'completed'
        return (
          <li key={label} aria-current={i === current ? 'step' : undefined} className="flex min-w-0 flex-col items-center gap-1.5 text-center">
            <div className="flex w-full items-center">
              <span className={clsx('h-0.5 flex-1', i === 0 ? 'bg-transparent' : i <= current ? 'bg-brand-600' : 'bg-slate-200')} aria-hidden />
              <span
                className={clsx(
                  'grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold',
                  done ? 'bg-brand-600 text-white' : i === current ? 'bg-white text-brand-700 ring-2 ring-brand-600' : 'bg-slate-100 text-slate-500',
                )}
                aria-hidden
              >
                {done ? <Check className="size-4" /> : i + 1}
              </span>
              <span className={clsx('h-0.5 flex-1', i === 4 ? 'bg-transparent' : i < current ? 'bg-brand-600' : 'bg-slate-200')} aria-hidden />
            </div>
            <span className={clsx('text-xs', i === current ? 'font-semibold text-slate-900' : 'text-slate-500')}>{label}</span>
          </li>
        )
      })}
    </ol>
  )
}
