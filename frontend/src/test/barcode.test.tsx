// jsdom has no showModal(), so the dialog's content counts as hidden for role queries.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { Asset } from '../features/assets/api'
import { AssetBarcodeModal } from '../features/assets/AssetBarcodeModal'

const asset = { id: 'a1', asset_number: 'AST-2026-000001', name: 'Laptop Dell', barcode_enabled: true } as Asset

describe('AssetBarcodeModal', () => {
  it('shows a 1D and a 2D barcode of the asset number and prints them', async () => {
    // JsBarcode measures its caption on a canvas, which jsdom lacks.
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ measureText: () => ({ width: 80 }) } as unknown as CanvasRenderingContext2D)
    render(
      <QueryClientProvider client={new QueryClient()}>
        <AssetBarcodeModal asset={asset} onClose={() => {}} />
      </QueryClientProvider>,
    )
    const linear = await screen.findByRole('img', { name: 'Barcode 1D (Code 128) AST-2026-000001', hidden: true })
    const qr = screen.getByRole('img', { name: 'Barcode 2D (QR Code) AST-2026-000001', hidden: true })
    expect(linear.querySelector('svg')).not.toBeNull()
    expect(qr.querySelector('svg')).not.toBeNull()

    const print = vi.fn()
    const origin = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'contentWindow')!
    Object.defineProperty(HTMLIFrameElement.prototype, 'contentWindow', {
      configurable: true,
      get() {
        const win = origin.get!.call(this) as Window
        return Object.assign(win, { print, focus: () => {} })
      },
    })
    fireEvent.click(screen.getByRole('button', { name: /Cetak keduanya/, hidden: true }))
    expect(print).toHaveBeenCalledOnce()
    const label = document.querySelector('iframe')!.contentDocument!.body
    expect(label.querySelectorAll('.label')).toHaveLength(2)
    expect(label).toHaveTextContent('Laptop Dell')
    Object.defineProperty(HTMLIFrameElement.prototype, 'contentWindow', origin)
    vi.restoreAllMocks()
  })
})
