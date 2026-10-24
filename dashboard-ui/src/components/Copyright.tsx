import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

// Launch time from config/project-time.php.
const LAUNCH = Date.UTC(2023, 9, 17, 0, 54, 58) // 17 Oct 2023 11:54:58 AEDT
export function sinceLaunch(): string {
  const start = new Date(LAUNCH), now = new Date()
  let y = now.getUTCFullYear() - start.getUTCFullYear()
  let m = now.getUTCMonth() - start.getUTCMonth()
  let d = now.getUTCDate() - start.getUTCDate()
  if (d < 0) { m--; d += new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 0)).getUTCDate() }
  if (m < 0) { y--; m += 12 }
  const p = (n: number, w: string) => (n ? `${n} ${w}${n !== 1 ? 's' : ''}` : '')
  return [p(y, 'year'), p(m, 'month'), p(d, 'day')].filter(Boolean).join(', ')
}

// Wording from the live dashboard footer (dashboard/layout.php, lang/en.php layout_footer_*).
export default function Copyright() {
  const [open, setOpen] = useState(false)
  const year = new Date().getFullYear()
  const btn = useRef<HTMLButtonElement>(null)
  const box = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const prev = document.activeElement as HTMLElement | null
    box.current?.querySelector<HTMLElement>('button')?.focus({ preventScroll: true })
    const key = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.preventDefault(); setOpen(false) }
      if (e.key === 'Tab' && box.current) {
        const f = [...box.current.querySelectorAll<HTMLElement>('button, a[href]')]
        if (!f.length) return
        const first = f[0], last = f[f.length - 1]
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
      }
    }
    document.addEventListener('keydown', key)
    return () => { document.removeEventListener('keydown', key); (prev ?? btn.current)?.focus({ preventScroll: true }) }
  }, [open])
  return (
    <>
      <button ref={btn} type="button" className="version-badge copy-pill" aria-haspopup="dialog" onClick={() => setOpen(true)}>© 2023-{year} BotOfTheSpecter</button>
      {open && createPortal(
        <div className="modal-scrim" onMouseDown={(e) => { if (e.target === e.currentTarget) setOpen(false) }}>
          <div ref={box} className="modal card" role="dialog" aria-modal="true" aria-labelledby="copy-title">
            <div className="modal-head"><h3 id="copy-title"><i className="fa-regular fa-copyright" /> Copyright and legal</h3>
              <button type="button" className="combo-x" aria-label="Close" onClick={() => setOpen(false)}><i className="fa-solid fa-xmark" /></button></div>
            <p><b>© 2023-{year} BotOfTheSpecter. All rights reserved.</b></p>
            <p>BotOfTheSpecter is a project operated under the business name “YourStreamingTools” by LochStudios, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).</p>
            <p>Project has been running since 17th October 2023, 11:54:58 AEDT. As of now, it's been {sinceLaunch()} since launch.</p>
            <p>This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.</p>
            <p className="muted">All trademarks, logos, and brand names are the property of their respective owners and are used for identification purposes only.</p>
            <button type="button" className="btn modal-ok" onClick={() => setOpen(false)}>Close</button>
          </div>
        </div>, document.body
      )}
    </>
  )
}
