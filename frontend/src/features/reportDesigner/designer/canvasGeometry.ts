/** Convert a client point on the (zoomed) page element into content-box millimetres. */
import { PX_PER_MM, type PageConfig } from '../design'

export function clientToContentMm(
  pageRect: { left: number; top: number },
  clientX: number,
  clientY: number,
  page: PageConfig,
  zoom: number,
): { x: number; y: number } {
  const scale = PX_PER_MM * zoom
  return {
    x: (clientX - pageRect.left) / scale - page.marginLeft,
    y: (clientY - pageRect.top) / scale - page.marginTop,
  }
}
