import { useEffect, useId, useRef, useState } from 'react'

// Live Twitch user search (public/api/twitch/users.php). Shared by User
// Commands and the Media walk-on form. The value is always a lowercase login.
export type TwitchUser = { id: string; login: string; display_name: string; avatar: string; type: '' | 'partner' | 'affiliate'; live?: boolean }
type Opt = { login: string; user?: TwitchUser }
type Check = { state: 'idle' | 'checking' | 'found' | 'missing' | 'error'; user?: TwitchUser }
const API = '/api/twitch/users.php'
const clean = (s: string) => s.trim().replace(/^@/, '').toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 25)

export default function TwitchUserPicker({ value, onChange, label, id, placeholder = 'Search Twitch users', required, onCheck }: {
  value: string; onChange: (login: string) => void; label: string; id?: string; placeholder?: string; required?: boolean; onCheck?: (c: Check) => void
}) {
  const auto = useId()
  const inputId = id ?? `tw-${auto}`
  const listId = `${inputId}-list`
  const [open, setOpen] = useState(false)
  const [opts, setOpts] = useState<Opt[]>([])
  const [seen, setSeen] = useState<Opt[]>([])
  const [loading, setLoading] = useState(false)
  const [err, setErr] = useState('')
  const [active, setActive] = useState(-1)
  const [check, setCheck] = useState<Check>({ state: 'idle' })
  const wrap = useRef<HTMLDivElement>(null)
  const list = useRef<HTMLUListElement>(null)
  const seq = useRef(0)
  const all = [...opts, ...seen]
  const q = clean(value)

  // Debounced search, 2 characters minimum.
  useEffect(() => {
    if (!open || q.length < 2) { setOpts([]); setSeen([]); setErr(''); setLoading(false); return }
    const n = ++seq.current
    setLoading(true)
    const t = setTimeout(async () => {
      try {
        const r = await fetch(`${API}?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' })
        const j = await r.json() as { success: boolean; users?: TwitchUser[]; seen?: string[]; error?: string }
        if (n !== seq.current) return
        if (!j.success) { setErr(r.status === 401 ? 'Your session expired. Sign in again.' : j.error || 'Search failed.'); setOpts([]); setSeen([]) }
        else {
          const tw = (j.users ?? []).map((u) => ({ login: u.login, user: u }))
          const have = new Set(tw.map((o) => o.login))
          setOpts(tw); setSeen((j.seen ?? []).filter((l) => !have.has(l)).map((l) => ({ login: l }))); setErr('')
        }
        setActive(-1)
      } catch { if (n === seq.current) setErr('Search failed.') }
      if (n === seq.current) setLoading(false)
    }, 300)
    return () => clearTimeout(t)
  }, [q, open])

  // Exact-login confirmation, debounced, runs on the saved value too.
  useEffect(() => {
    if (q.length < 2) { setCheck({ state: 'idle' }); return }
    setCheck({ state: 'checking' })
    let gone = false
    const t = setTimeout(async () => {
      try {
        const r = await fetch(`${API}?login=${encodeURIComponent(q)}`, { credentials: 'same-origin' })
        const j = await r.json() as { success: boolean; found?: boolean; user?: TwitchUser }
        if (!gone) setCheck(!j.success ? { state: 'error' } : j.found && j.user ? { state: 'found', user: j.user } : { state: 'missing' })
      } catch { if (!gone) setCheck({ state: 'error' }) }
    }, 450)
    return () => { gone = true; clearTimeout(t) }
  }, [q])
  useEffect(() => { onCheck?.(check) }, [check]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return
    const out = (e: MouseEvent | TouchEvent) => { if (wrap.current && !wrap.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', out); document.addEventListener('touchstart', out)
    return () => { document.removeEventListener('mousedown', out); document.removeEventListener('touchstart', out) }
  }, [open])
  // Keep the active option visible by scrolling only the list.
  useEffect(() => {
    const l = list.current, el = l?.querySelector<HTMLElement>(`[data-i="${active}"]`)
    if (!l || !el) return
    if (el.offsetTop < l.scrollTop) l.scrollTop = el.offsetTop
    else if (el.offsetTop + el.offsetHeight > l.scrollTop + l.clientHeight) l.scrollTop = el.offsetTop + el.offsetHeight - l.clientHeight
  }, [active])

  function pick(o: Opt) { onChange(o.login); setOpen(false); setActive(-1) }
  function key(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setActive((a) => Math.min(all.length - 1, a + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(-1, a - 1)) }
    else if (e.key === 'Enter' && open && active >= 0 && all[active]) { e.preventDefault(); pick(all[active]) }
    else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); setOpen(false) }
    else if (e.key === 'Tab') setOpen(false)
  }
  const show = open && q.length >= 2
  const opt = (o: Opt, i: number) => (
    <li key={(o.user ? 't:' : 's:') + o.login} id={`${listId}-${i}`} data-i={i} role="option" aria-selected={i === active} className={`tw-opt${i === active ? ' on' : ''}`}
      onMouseDown={(e) => e.preventDefault()} onClick={() => pick(o)} onMouseMove={() => setActive(i)}>
      {o.user?.avatar ? <img src={o.user.avatar} alt="" className="tw-av" loading="lazy" /> : <span className="tw-av tw-av-none" aria-hidden="true"><i className="fas fa-user" /></span>}
      <span className="tw-names">
        <b>{o.user?.display_name || o.login}{o.user?.type === 'partner' && <i className="fas fa-circle-check tw-verified" title="Verified partner" aria-label="Verified partner" />}</b>
        <small>@{o.login}{o.user?.type === 'affiliate' ? ' · Affiliate' : ''}{o.user?.live ? ' · Live' : ''}</small>
      </span>
    </li>
  )
  return (
    <div className="tw-pick" ref={wrap}>
      <div className="tw-input">
        <span aria-hidden="true">@</span>
        <input id={inputId} className="input" role="combobox" aria-label={label} aria-expanded={show} aria-controls={listId} aria-autocomplete="list"
          aria-activedescendant={show && active >= 0 ? `${listId}-${active}` : undefined} aria-describedby={`${inputId}-st`}
          autoComplete="off" autoCapitalize="none" spellCheck={false} required={required} placeholder={placeholder} value={value}
          onChange={(e) => { onChange(e.target.value.replace(/^@/, '')); setOpen(true) }} onFocus={() => setOpen(true)} onKeyDown={key} />
        {loading && <i className="fas fa-spinner fa-spin tw-spin" aria-hidden="true" />}
      </div>
      {show && (
        <div className="tw-pop">
          <ul ref={list} id={listId} role="listbox" aria-label="Twitch users">
            {opts.length > 0 && <li role="presentation" className="tw-group">Twitch</li>}
            {opts.map((o, i) => opt(o, i))}
            {seen.length > 0 && <li role="presentation" className="tw-group">Seen in your chat</li>}
            {seen.map((o, i) => opt(o, opts.length + i))}
          </ul>
          {!loading && !err && !all.length && <p className="tw-empty">No Twitch user found</p>}
          {loading && !all.length && <p className="tw-empty">Searching</p>}
          {err && <p className="tw-empty tw-err">{err}</p>}
        </div>
      )}
      <small id={`${inputId}-st`} className={`tw-status tw-${check.state}`} aria-live="polite">
        {check.state === 'found' && check.user && <><i className="fas fa-circle-check" /> Twitch user found: {check.user.display_name || check.user.login}</>}
        {check.state === 'missing' && <><i className="fas fa-triangle-exclamation" /> No Twitch user found with the login "{q}". You can still save it.</>}
        {check.state === 'checking' && 'Checking Twitch'}
        {check.state === 'error' && 'Could not check this name on Twitch right now.'}
      </small>
    </div>
  )
}
export { clean as cleanLogin }
