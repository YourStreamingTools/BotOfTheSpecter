import { useEffect, useMemo, useRef, useState } from 'react'
import Modal from '../components/Modal'
import Combobox, { type ComboOption } from '../components/Combobox'
import { get } from '../bot'
import { hasConsent } from '../consent'
import { Switch, ToastBar, VarsHelp, send, type Toast } from './commands/shared'

// Port of dashboard/known_users.php via public/api/welcome/{known,send,banned,ban_cache}.php.
const API = '/api/welcome/known.php'
const MAX = 255
const PAGE = 50
type User = { id: number | string; username: string; first_seen: string; last_seen: string; welcome_message: string | null; status: string }
type StatusF = 'all' | 'on' | 'off'
type MsgF = 'all' | 'custom' | 'none' | 'banned'
type Filter = { status: StatusF; msg: MsgF }
const hasEmoji = (s: string) => /[\u{10000}-\u{10FFFF}]/u.test(s)
const short = (d: string) => (d ? d.slice(0, 16) : 'Unknown')

// Saved in a cookie only after cookie consent; otherwise it lives in page state.
function readFilter(): Filter {
  const d: Filter = { status: 'all', msg: 'all' }
  if (!hasConsent()) return d
  try {
    const m = document.cookie.match(/(?:^|; )ku_filter=([^;]*)/)
    const v = m ? JSON.parse(decodeURIComponent(m[1])) : {}
    return { status: ['all', 'on', 'off'].includes(v.status) ? v.status : 'all', msg: ['all', 'custom', 'none', 'banned'].includes(v.msg) ? v.msg : 'all' }
  } catch { return d }
}
function saveFilter(f: Filter) {
  if (!hasConsent()) return
  document.cookie = `ku_filter=${encodeURIComponent(JSON.stringify(f))}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax; Secure`
}

export default function WelcomeMessages() {
  const [users, setUsers] = useState<User[] | null>(null)
  const [err, setErr] = useState('')
  const [warn, setWarn] = useState('')
  const [def, setDef] = useState<string | null>(null)
  const [banned, setBanned] = useState<Record<string, boolean>>({})
  const [banTotal, setBanTotal] = useState(0)
  const [banDone, setBanDone] = useState(0)
  const [toast, setToast] = useState<Toast>(null)
  const [edit, setEdit] = useState<User | null>(null)
  const [del, setDel] = useState<User | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [f, setF] = useState<Filter>(readFilter)
  const [q, setQ] = useState('')
  const [limit, setLimit] = useState(PAGE)
  const started = useRef(false)

  async function load() {
    try {
      const [list, page] = await Promise.all([
        get<{ success: boolean; users: User[] }>(`${API}?ajax_action=list`),
        get<{ success: boolean; banned: Record<string, boolean>; cache_warning: string | null; defaults?: { welcome: string } | null }>(API).catch(() => null),
      ])
      if (!list.success) throw new Error()
      setUsers(list.users); setErr('')
      if (page?.cache_warning) setWarn(page.cache_warning)
      if (page?.defaults?.welcome) setDef(page.defaults.welcome)
      if (!started.current) { started.current = true; checkBans(list.users, page?.banned ?? {}) }
    } catch { setErr('Could not load users. Try again in a moment.') }
  }
  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Same as live: cached ban flags first, then Helix in batches of 10 for the rest, then save to the cache.
  async function checkBans(list: User[], cache: Record<string, boolean>) {
    setBanned({ ...cache })
    const todo = list.map((u) => u.username).filter((n) => n && !(n in cache))
    setBanTotal(todo.length); setBanDone(0)
    const batches: string[][] = []
    for (let i = 0; i < todo.length; i += 10) batches.push(todo.slice(i, i + 10))
    let next = 0
    async function worker() {
      while (next < batches.length) {
        const batch = batches[next++]
        const res: Record<string, boolean> = {}
        try {
          const r = await send<{ bannedUsers?: Record<string, boolean> }>('/api/welcome/banned.php', { usernames: JSON.stringify(batch) })
          for (const n of batch) res[n] = r.bannedUsers?.[n] === true
          fetch('/api/welcome/ban_cache.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(res) }).catch(() => {})
        } catch { for (const n of batch) res[n] = false }
        setBanned((b) => ({ ...b, ...res })); setBanDone((d) => d + batch.length)
      }
    }
    await Promise.all([worker(), worker(), worker()])
  }

  async function post(body: Record<string, string>) {
    const r = await send<{ success: boolean; error?: string | null }>(API, body, true)
    if (!r.success) throw new Error(r.error || 'That did not save. Try again.')
  }
  async function toggle(u: User) {
    const on = u.status !== 'True'; const id = String(u.id); setBusy(id)
    setUsers((l) => l && l.map((x) => (String(x.id) === id ? { ...x, status: on ? 'True' : 'False' } : x)))
    try { await post({ username: u.username, status: on ? 'True' : 'False' }); setToast({ kind: 'ok', text: on ? 'Welcome messages turned on for this viewer.' : 'Welcome messages turned off for this viewer.' }) }
    catch (e) { setUsers((l) => l && l.map((x) => (String(x.id) === id ? { ...x, status: on ? 'False' : 'True' } : x))); setToast({ kind: 'err', text: (e as Error).message }) }
    finally { setBusy(null) }
  }
  async function test(u: User) {
    const id = String(u.id); setBusy('t' + id)
    try {
      const r = await send<{ success: boolean; message?: string }>('/api/welcome/send.php', { username: u.username, message: u.welcome_message ?? '' })
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? 'Test sent.' : 'Failed to send test message.') })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Failed to send test message.' }) } finally { setBusy(null) }
  }
  async function remove(u: User) {
    setDel(null)
    try { await post({ deleteUserId: String(u.id) }); setUsers((l) => l && l.filter((x) => x.id !== u.id)); setToast({ kind: 'ok', text: 'Viewer removed.' }) }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
  }

  function filt(n: Partial<Filter>) { const x = { ...f, ...n }; setF(x); saveFilter(x); setLimit(PAGE) }
  const all = users ?? []
  const onN = all.filter((u) => u.status === 'True').length
  const byStatus = all.filter((u) => f.status === 'all' || (u.status === 'True') === (f.status === 'on'))
  const msgTest = (u: User, m: MsgF) => m === 'all' || (m === 'custom' ? !!u.welcome_message?.trim() : m === 'none' ? !u.welcome_message?.trim() : banned[u.username] === true)
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return byStatus.filter((u) => msgTest(u, f.msg) && (!n || u.username.toLowerCase().includes(n) || (u.welcome_message ?? '').toLowerCase().includes(n)))
  }, [byStatus, f.msg, q, banned]) // eslint-disable-line react-hooks/exhaustive-deps
  const msgOpts: ComboOption[] = ([['all', 'All messages'], ['custom', 'Custom message'], ['none', 'Default message'], ['banned', 'Banned']] as [MsgF, string][])
    .map(([v, l]) => ({ value: v, label: `${l} (${byStatus.filter((u) => msgTest(u, v)).length})` }))
  const checking = banTotal > 0 && banDone < banTotal

  return (
    <div className="page cmds ku">
      <div className="section-head"><h2><i className="fas fa-handshake" /> Welcome Messages</h2></div>
      <VarsHelp where="welcome message">
        <ol className="cmd-howto">
          <li>A message set here replaces your default welcome message for that viewer.</li>
          <li><code>(user)</code> is the viewer's name. <code>(shoutout)</code> also gives them a shoutout. A message that is only <code>(shoutout)</code> gives the shoutout but posts no welcome line, so add text, for example <code>Welcome back, (user)! (shoutout)</code>.</li>
          <li>Test posts the message in your chat now, as the bot.</li>
        </ol>
      </VarsHelp>
      <ToastBar t={toast} onClose={() => setToast(null)} />
      {warn && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{warn}</span></div>}
      <section className="card cmd-lib">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-users" /> Known users</h3>{checking && <span className="muted small" role="status">Checking bans {banDone}/{banTotal}</span>}</div>
        {users && users.length > 0 && (
          <div className="tm-filters">
            <div className="seg tm-seg" role="group" aria-label="Status">
              {([['all', 'All', all.length], ['on', 'Active', onN], ['off', 'Inactive', all.length - onN]] as [StatusF, string, number][]).map(([v, l, n]) => <button key={v} type="button" aria-pressed={f.status === v} className={f.status === v ? 'on' : ''} onClick={() => filt({ status: v })}>{l} <span className="tm-n">{n}</span></button>)}
            </div>
            <div className="tm-trig"><Combobox label="Welcome message" searchable={false} options={msgOpts} value={f.msg} onChange={(v) => filt({ msg: v as MsgF })} /></div>
            <label className="media-search tm-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search users or messages" aria-label="Search known users" value={q} onChange={(e) => { setQ(e.target.value); setLimit(PAGE) }} /></label>
          </div>
        )}
        {users && users.length > 0 && shown.length !== all.length && <p className="muted small tm-count" aria-live="polite">Showing {shown.length} of {all.length}</p>}
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!users && !err && <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {users && !users.length && <p className="muted media-empty">There are no users to display.</p>}
        {users && users.length > 0 && !shown.length && <div className="media-empty tm-empty"><p className="muted">No users match these filters.</p><button type="button" className="btn btn-quiet" onClick={() => { setQ(''); filt({ status: 'all', msg: 'all' }) }}>Clear filters</button></div>}
        {shown.length > 0 && (
          <ul className="cmd-list">
            {shown.slice(0, limit).map((u) => {
              const on = u.status === 'True'; const id = String(u.id); const ban = banned[u.username]; const custom = !!u.welcome_message?.trim()
              return (
                <li key={id} className={`cmd-row${on ? '' : ' is-off'}`}>
                  <div className="cmd-main">
                    <div className="cmd-name"><b className="ku-name">{u.username}</b>{ban === true && <span className="pill pill-err">Banned</span>}{ban === undefined && checking && <span className="skel ku-ban-skel" aria-hidden="true" />}</div>
                    {custom ? <p className="cmd-resp ku-msg"><span className="pill pill-accent ku-kind">Custom</span>{u.welcome_message}</p>
                      : <p className="cmd-resp ku-msg ku-def"><span className="pill ku-kind">Default</span>{def ? <i>{def}</i> : <span>Default welcome message</span>}</p>}
                    <div className="cmd-meta"><span className="muted small">First seen {short(u.first_seen)}</span><span className="muted small">Last seen {short(u.last_seen)}</span></div>
                  </div>
                  <div className="cmd-side">
                    <Switch on={on} busy={busy === id} label={`Welcome ${u.username}: ${on ? 'active' : 'inactive'}`} onChange={() => toggle(u)} />
                    <button type="button" className="icon-btn" aria-label={`Edit welcome message for ${u.username}`} title="Edit" onClick={() => setEdit(u)}><i className="fas fa-pencil" /></button>
                    <button type="button" className="icon-btn" aria-label={`Test welcome message for ${u.username}`} title={on ? 'Send a test to chat' : 'User is inactive'} disabled={!on || busy === 't' + id} onClick={() => test(u)}><i className={busy === 't' + id ? 'fas fa-spinner fa-spin' : 'fas fa-paper-plane'} /></button>
                    <button type="button" className="icon-btn icon-err" aria-label={`Remove ${u.username}`} title="Remove" onClick={() => setDel(u)}><i className="fas fa-trash" /></button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
        {shown.length > limit && <div className="ku-more"><button type="button" className="btn btn-quiet" onClick={() => setLimit((l) => l + PAGE)}>Show {Math.min(PAGE, shown.length - limit)} more</button></div>}
      </section>
      {edit && <Modal title={`Welcome message for ${edit.username}`} icon="fas fa-pencil" onClose={() => setEdit(null)} wide><EditForm u={edit} onSave={async (m) => { try { await post({ userId: String(edit.id), newWelcomeMessage: m }); setUsers((l) => l && l.map((x) => (x.id === edit.id ? { ...x, welcome_message: m.trim() } : x))); setToast({ kind: 'ok', text: 'Welcome message saved.' }); setEdit(null) } catch (e) { return (e as Error).message } return '' }} /></Modal>}
      {del && (
        <Modal title="Remove viewer?" icon="fas fa-trash" onClose={() => setDel(null)}>
          <p><b>{del.username}</b> and their welcome message will be removed. This cannot be undone.</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => remove(del)}><i className="fas fa-trash" /> Remove viewer</button></div>
        </Modal>
      )}
    </div>
  )
}

function EditForm({ u, onSave }: { u: User; onSave: (m: string) => Promise<string> }) {
  const [m, setM] = useState(u.welcome_message ?? '')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const emoji = hasEmoji(m)
  const n = m.length
  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (emoji || n > MAX) return
    setBusy(true); setMsg(await onSave(m)); setBusy(false)
  }
  return (
    <form className="cmd-form" onSubmit={submit}>
      <div className="fld fld-wide">
        <label htmlFor="ku-m">Welcome message</label>
        <textarea id="ku-m" className="input" rows={3} maxLength={MAX} placeholder="Leave empty to use your default welcome message" value={m} onChange={(e) => setM(e.target.value)} />
        <small className={`counter${n >= 240 ? ' over' : n >= 200 ? ' warn' : ''}`}>{n}/{MAX} characters</small>
        {emoji && <small className="counter over">Emoji aren't supported in welcome messages.</small>}
      </div>
      {msg && <p className="counter over fld-wide" role="status">{msg}</p>}
      <div className="modal-acts fld-wide"><button className="btn" disabled={busy || emoji || n > MAX}><i className="fas fa-floppy-disk" /> {busy ? 'Saving' : 'Save message'}</button></div>
    </form>
  )
}
