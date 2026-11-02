import { useQuery } from '@tanstack/react-query'
import JsBarcode from 'jsbarcode'
import { Printer } from 'lucide-react'
import QRCode from 'qrcode'
import { Button } from '../../components/ui/Button'
import { ErrorState, Spinner } from '../../components/ui/Feedback'
import { Modal } from '../../components/ui/Modal'
import type { Asset } from './api'

type Kind = 'linear' | 'qr'

const KIND_LABEL: Record<Kind, string> = { linear: 'Barcode 1D (Code 128)', qr: 'Barcode 2D (QR Code)' }

/** Both barcodes of an asset as SVG markup; they encode the asset number, which never changes. */
async function barcodeSvgs(value: string): Promise<Record<Kind, string>> {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
  JsBarcode(svg, value, { format: 'CODE128', height: 60, margin: 8, fontSize: 14 })
  const qr = await QRCode.toString(value, { type: 'svg', margin: 1, errorCorrectionLevel: 'M' })
  return { linear: svg.outerHTML, qr }
}

const escape = (s: string) => s.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)

/** Prints labels through a hidden frame, so only the labels end up on paper. */
function printLabels(asset: Asset, svgs: Record<Kind, string>, kinds: Kind[]) {
  const frame = document.createElement('iframe')
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0'
  document.body.appendChild(frame)
  const doc = frame.contentDocument!
  const labels = kinds
    .map((k) => `<div class="label ${k}"><div class="code">${svgs[k]}</div><p class="name">${escape(asset.name)}</p>${k === 'qr' ? `<p class="number">${escape(asset.asset_number)}</p>` : ''}</div>`)
    .join('')
  doc.open()
  doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>${escape(asset.asset_number)}</title><style>
    body{margin:0;font-family:system-ui,sans-serif;display:flex;flex-wrap:wrap;gap:8mm;padding:8mm}
    .label{border:1px dashed #94a3b8;padding:3mm;text-align:center;break-inside:avoid}
    .linear svg{width:60mm;height:auto}.qr svg{width:30mm;height:30mm}
    p{margin:1mm 0 0;font-size:9pt}.number{font-family:monospace}
    @media print{.label{border-color:#cbd5e1}}
  </style></head><body>${labels}</body></html>`)
  doc.close()
  frame.contentWindow!.focus()
  frame.contentWindow!.print()
  // Removed after the print dialog closes (print() blocks in most browsers).
  setTimeout(() => frame.remove(), 1000)
}

/** Shows an asset's 1D and 2D barcode and prints them as labels. */
export function AssetBarcodeModal({ asset, onClose }: { asset: Asset; onClose: () => void }) {
  const svgs = useQuery({ queryKey: ['barcode', asset.asset_number], queryFn: () => barcodeSvgs(asset.asset_number), staleTime: Infinity, retry: false })

  return (
    <Modal
      open
      size="xl"
      title={`Barcode ${asset.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Tutup
          </Button>
          <Button disabled={!svgs.data} onClick={() => printLabels(asset, svgs.data!, ['linear', 'qr'])}>
            <Printer className="size-4" aria-hidden /> Cetak keduanya
          </Button>
        </>
      }
    >
      {svgs.isLoading ? (
        <Spinner />
      ) : svgs.isError ? (
        <ErrorState error={svgs.error} />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {(['linear', 'qr'] as const).map((k) => (
            <figure key={k} className="flex flex-col items-center gap-3 rounded-lg p-4 ring-1 ring-slate-200">
              <figcaption className="text-sm font-semibold text-slate-700">{KIND_LABEL[k]}</figcaption>
              <div
                role="img"
                aria-label={`${KIND_LABEL[k]} ${asset.asset_number}`}
                className={k === 'qr' ? 'size-40 [&>svg]:size-full' : 'w-full max-w-72 [&>svg]:h-auto [&>svg]:w-full'}
                dangerouslySetInnerHTML={{ __html: svgs.data![k] }}
              />
              <p className="font-mono text-xs text-slate-600">{asset.asset_number}</p>
              <Button size="sm" variant="secondary" onClick={() => printLabels(asset, svgs.data!, [k])}>
                <Printer className="size-4" aria-hidden /> Cetak
              </Button>
            </figure>
          ))}
        </div>
      )}
    </Modal>
  )
}
