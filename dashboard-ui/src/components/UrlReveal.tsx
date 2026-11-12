import { useEffect, useState, type ReactNode } from 'react'
import type { Toast } from '../pages/commands/shared'
import { copyText } from './clipboard'

// Shared overlay URL box (the Alerts "OBS browser source" box). The URL holds the channel key,
// so it is only fetched from the server on Reveal or Copy and kept in memory, never stored.
export function UrlReveal({ mask, fetchUrl, toast, disabled, placeholder, label = 'Overlay URL', name, copied = 'Overlay URL copied.', fail, resetKey, compact, children }: {
  mask: string; fetchUrl: () => Promise<string>; toast: (t: Toast) => void; disabled?: boolean; placeholder?: string
  label?: string; name?: string; copied?: string; fail?: string; resetKey?: string
  // Icon-only buttons (with a tooltip), for tight table cells.
  compact?: boolean; children?: ReactNode
}) {
  const [url, setUrl] = useState(''), [shown, setShown] = useState(false), [busy, setBusy] = useState(false)
  useEffect(() => { setUrl(''); setShown(false) }, [resetKey])
  const what = name ? `${name} URL` : label
  async function reveal(copy: boolean) {
    setBusy(true)
    try {
      if (copy) {
        // No await before the copy starts: copyText keeps the write tied to the click.
        const p = url ? Promise.resolve(url) : fetchUrl()
        const ok = await copyText(p)
        const u = await p; setUrl(u)
        if (ok) toast({ kind: 'ok', text: copied })
        else { setShown(true); toast({ kind: 'err', text: `Your browser blocked the copy. The ${what} is shown, select it and copy it by hand.` }) }
      } else { const u = url || await fetchUrl(); setUrl(u); setShown((s) => !s) }
    } catch (e) { toast({ kind: 'err', text: fail || (e as Error).message || 'Could not get the overlay URL.' }) }
    setBusy(false)
  }
  return (
    <div className="al-src">
      <code className="al-src-url" aria-label={label} style={shown && url && !placeholder ? { userSelect: 'all' } : undefined}>{placeholder ?? (shown && url ? url : mask)}</code>
      <button type="button" className="btn btn-ghost" aria-label={`${shown ? 'Hide' : 'Reveal'} ${what}`} title={compact ? (shown ? 'Hide' : 'Reveal') : undefined} disabled={disabled || busy} onClick={() => reveal(false)}><i className={shown ? 'fas fa-eye-slash' : 'fas fa-eye'} />{compact ? null : <> {shown ? 'Hide' : 'Reveal'}</>}</button>
      <button type="button" className="btn btn-ghost" aria-label={`Copy ${what}`} title={compact ? 'Copy' : undefined} disabled={disabled || busy} onClick={() => reveal(true)}><i className="fas fa-copy" />{compact ? null : ' Copy'}</button>
      {children}
    </div>
  )
}
