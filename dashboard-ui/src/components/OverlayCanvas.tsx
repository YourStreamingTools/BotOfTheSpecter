import { useEffect, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

// Shared drag-canvas pieces for the Alerts and Makers & Crafting pages: the preview
// background swatches, snap guide lines, snap maths and the Expand layer (portal,
// scrim, Escape to close, page scroll locked). Styles are the .al-* canvas rules.
export const CANVAS_BGS: [string, string][] = [['transparent', 'Transparent'], ['dark', 'Dark'], ['light', 'Light'], ['red', 'Red']]
export const SNAP_LINES = [0, 100 / 3, 50, 200 / 3, 100]
export type Guide = { x: number | null; y: number | null }

export function BgSwatches({ bg, onChange }: { bg: string; onChange: (k: string) => void }) {
  return (
    <div className="seg al-swatches" role="group" aria-label="Preview background">
      {CANVAS_BGS.map(([k, l]) => <button key={k} type="button" aria-pressed={bg === k} className={bg === k ? 'on' : ''} title={`${l} background`} aria-label={`${l} background`} onClick={() => onChange(k)}><span className={`al-sw al-bg-${k}`} /></button>)}
    </div>
  )
}

export function SnapGuides({ guide }: { guide: Guide }) {
  return <>
    {guide.x !== null && <div className="al-guide al-guide-v" style={{ left: `${guide.x}%` }} />}
    {guide.y !== null && <div className="al-guide al-guide-h" style={{ top: `${guide.y}%` }} />}
  </>
}

// Nudge pos (%) so its nearest anchor (start, middle, end) lands on a line within th (%).
export function snapAxis(pos: number, size: number, lines: number[], th: number) {
  let best: { pos: number; line: number; d: number } | null = null
  for (const line of lines) for (const an of [pos, pos + size / 2, pos + size]) { const d = Math.abs(an - line); if (d <= th && (!best || d < best.d)) best = { pos: pos + (line - an), line, d } }
  return best
}

export function useExpandLayer(expanded: boolean, close: () => void) {
  useEffect(() => {
    if (!expanded) return
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); close() } }
    window.addEventListener('keydown', k, true)
    const ov = document.body.style.overflow; document.body.style.overflow = 'hidden'
    setTimeout(() => (document.querySelector('.al-xv .al-exp') as HTMLElement | null)?.focus(), 0)
    return () => { window.removeEventListener('keydown', k, true); document.body.style.overflow = ov }
  }, [expanded]) // eslint-disable-line react-hooks/exhaustive-deps
}

export function ExpandLayer({ label, bar, onClose, children }: { label: string; bar: ReactNode; onClose: () => void; children: ReactNode }) {
  return createPortal(
    <div className="al-xv" role="dialog" aria-modal="true" aria-label={label}>
      <div className="al-xv-scrim" onClick={onClose} />
      <div className="al-xv-panel">
        <div className="al-xv-bar">{bar}</div>
        {children}
      </div>
    </div>, document.body)
}

export function ExpandButton({ expanded, onToggle }: { expanded: boolean; onToggle: () => void }) {
  return <button type="button" className="btn btn-ghost al-exp" data-autofocus={expanded || undefined} onClick={onToggle}><i className={expanded ? 'fas fa-compress' : 'fas fa-expand'} /> {expanded ? 'Collapse' : 'Expand'}</button>
}
