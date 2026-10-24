import { useEffect, useState, type ReactNode } from 'react'
import type { Toast } from '../pages/commands/shared'

// Shared overlay URL box (the Alerts "OBS browser source" box). The URL holds the channel key,
// so it is only fetched from the server on Reveal or Copy and kept in memory, never stored.
export function UrlReveal({ mask, fetchUrl, toast, disabled, placeholder, label = 'Overlay URL', name, copied = 'Overlay URL copied.', fail, resetKey, children }: {
  mask: string; fetchUrl: () => Promise<string>; toast: (t: Toast) => void; disabled?: boolean; placeholder?: string
  label?: string; name?: string; copied?: string; fail?: string; resetKey?: string; children?: ReactNode
}) {
  const [url, setUrl] = useState(''), [shown, setShown] = useState(false), [busy, setBusy] = useState(false)
  useEffect(() => { setUrl(''); setShown(false) }, [resetKey])
  async function reveal(copy: boolean) {
    setBusy(true)
    try {
      const u = url || await fetchUrl(); setUrl(u)
      if (copy) { await navigator.clipboard.writeText(u); toast({ kind: 'ok', text: copied }) } else setShown((s) => !s)
    } catch (e) { toast({ kind: 'err', text: fail || (e as Error).message || 'Could not get the overlay URL.' }) }
    setBusy(false)
  }
  const what = name ? `${name} URL` : label
  return (
    <div className="al-src">
      <code className="al-src-url" aria-label={label}>{placeholder ?? (shown && url ? url : mask)}</code>
      <button type="button" className="btn btn-ghost" aria-label={`${shown ? 'Hide' : 'Reveal'} ${what}`} disabled={disabled || busy} onClick={() => reveal(false)}><i className={shown ? 'fas fa-eye-slash' : 'fas fa-eye'} /> {shown ? 'Hide' : 'Reveal'}</button>
      <button type="button" className="btn btn-ghost" aria-label={`Copy ${what}`} disabled={disabled || busy} onClick={() => reveal(true)}><i className="fas fa-copy" /> Copy</button>
      {children}
    </div>
  )
}
