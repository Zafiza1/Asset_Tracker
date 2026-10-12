import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Alert } from '../components/ui/Feedback'
import { Button } from '../components/ui/Button'

interface Props {
  /** Shown in the message, e.g. "Pendataan Aset". */
  name?: string
  /** Changing the key (e.g. the pathname) clears the error, so navigating away always recovers. */
  children: ReactNode
  /** Compact variant for a single dashboard widget. */
  compact?: boolean
}

interface State {
  error: Error | null
}

/**
 * Error isolation for one module (page) or widget. A rendering error stays inside this
 * boundary: the sidebar, header, and every other menu keep working, and the user is not
 * redirected anywhere.
 */
export class ModuleBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error(`[${this.props.name ?? 'module'}]`, error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    const title = this.props.name ? `Bagian "${this.props.name}" mengalami kesalahan` : 'Halaman ini mengalami kesalahan'
    return (
      <div className={this.props.compact ? 'p-4' : 'py-6'}>
        <Alert tone="error" title={title}>
          <p>Menu lain tetap dapat digunakan. Coba muat ulang bagian ini; bila masih gagal, hubungi administrator.</p>
          <Button className="mt-3" size="sm" variant="secondary" onClick={() => this.setState({ error: null })}>
            Coba lagi
          </Button>
        </Alert>
      </div>
    )
  }
}
