import { useEffect, useRef, useState } from 'react'
import { get } from '../../bot'
import Modal from '../../components/Modal'
import { ToastBar, send, type Toast } from '../commands/shared'
import { API, SkelRows } from './shared'

// Port of dashboard/moderation.php: Channel Moderation heading, the three
// warning tiles, "How to issue a warning", the Warning log (search, Clear,
// user filter links, delete one warning with a confirm, 500 row note) and the
// Twitch Ban list (first Helix page painted at once, the rest loaded in the
// background, 25 per page with Previous / Page x of y / Next).
// Endpoint: public/api/community/moderation.php.
const URL_ = `${API}/moderation.php`
// Newest ban first. Re-run as each background page arrives so page 1 always
// holds the newest bans loaded so far. Stable sort keeps Twitch order on ties.
const banTime = (b: { created_at?: string }) => { const t = Date.parse(b.created_at ?? ''); return Number.isNaN(t) ? 0 : t }
const sortBans = <T extends { created_at?: string }>(l: T[]) => [...l].sort((a, b) => banTime(b) - banTime(a))
type W = { id: number; user_id: string; user_name: string; warned_by_name: string; reason: string; when_display: string }
type L = { success: boolean; unavailable?: boolean; total: number; unique: number; week: number; warnings: W[] }
type B = { user_id: string; user_login: string; user_name: string; reason: string; moderator_name: string; created_at?: string; banned_display: string; is_timeout: boolean; expires_display: string }
type BR = { success: boolean; bans?: B[]; cursor?: string; token_invalid?: boolean }
const PER = 25, MAXREQ = 50

export default function Moderation() {
  const [filter, setFilter] = useState<{ q: string; user: string }>({ q: '', user: '' }), [qIn, setQIn] = useState('')
  const [l, setL] = useState<L | null>(null), [lErr, setLErr] = useState(false), [del, setDel] = useState<W | null>(null), [busy, setBusy] = useState(false), [toast, setToast] = useState<Toast>(null)
  const [bans, setBans] = useState<B[]>([]), [bState, setBState] = useState<{ loading: boolean; first: boolean; msg: string; truncated: boolean; partial: boolean }>({ loading: true, first: true, msg: '', truncated: false, partial: false }), [bPage, setBPage] = useState(0)
  const load = (f = filter) => {
    const p = new URLSearchParams({ ajax_action: 'list' }); if (f.q) p.set('q', f.q); else if (f.user) p.set('user', f.user)
    return get<L>(`${URL_}?${p}`, 30000).then((r) => { if (!r.success) throw new Error(); setL(r); setLErr(false) }).catch((x) => { if ((x as Error).message !== 'http_401') setLErr(true) })
  }
  useEffect(() => { load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps
  const alive = useRef(true)
  useEffect(() => {
    alive.current = true
    let all: B[] = [], n = 0
    const step = (cursor: string) => {
      n++
      get<BR>(`${URL_}?ajax_action=bans${cursor ? `&after=${encodeURIComponent(cursor)}` : ''}`, 20000).then((r) => {
        if (!alive.current) return
        if (!r.success) { setBState((s) => ({ ...s, loading: false, first: false, msg: all.length ? '' : r.token_invalid ? 'Twitch rejected the request. Log out and back in to refresh your permissions.' : 'Could not load the ban list from Twitch. Please try again.', partial: all.length > 0 })); return }
        all = sortBans(all.concat(r.bans ?? [])); setBans(all)
        if (!r.cursor) { setBState((s) => ({ ...s, loading: false, first: false })); return }
        if (n >= MAXREQ) { setBState((s) => ({ ...s, loading: false, first: false, truncated: true })); return }
        setBState((s) => ({ ...s, first: false })); step(r.cursor)
      }).catch((x) => { if (!alive.current || (x as Error).message === 'http_401') return; setBState((s) => ({ ...s, loading: false, first: false, msg: all.length ? '' : 'Could not load the ban list from Twitch. Please try again.', partial: all.length > 0 })) })
    }
    step('')
    return () => { alive.current = false }
  }, [])
  async function remove(w: W) {
    setDel(null)
    setBusy(true)
    try {
      const r = await send<{ success: boolean; message?: string; error?: string }>(URL_, { action: 'delete_warning', warning_id: String(w.id) })
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || r.error || 'Could not update warnings. Please try again.' }); await load()
    } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setBusy(false) }
  }
  const pages = Math.max(1, Math.ceil(bans.length / PER)), cur = Math.min(bPage, pages - 1), pageRows = bans.slice(cur * PER, cur * PER + PER)
  const bStatus = bState.loading && bans.length ? `Loading the full list in the background... ${bans.length} so far.`
    : !bState.loading && bans.length ? `${bans.length} banned or timed-out users.${bState.truncated ? ` Only the first ${bans.length} are shown.` : ''}${bState.partial ? ` Some could not be loaded from Twitch, so only ${bans.length} are shown.` : ''}` : ''
  const filtered = !!(filter.q || filter.user), stat = (v: number | undefined) => (lErr ? '-' : l ? v : null)
  const ban = (b: B) => { const login = b.user_login, name = b.user_name || login
    return <tr key={b.user_id + b.banned_display}>
      <td data-l="User"><strong>{name}</strong>{login && login.toLowerCase() !== name.toLowerCase() && <div className="muted small">{login}</div>}</td>
      <td data-l="Reason" className="cm-wrap">{b.reason || <span className="muted">No reason given</span>}</td>
      <td data-l="Banned by">{b.moderator_name}</td><td data-l="Banned">{b.banned_display}</td>
      <td data-l="Expires">{b.is_timeout ? <span className="pill pill-warn">Timeout until {b.expires_display}</span> : <span className="pill pill-err">Permanent</span>}</td></tr> }
  return (
    <div className="page cmds cm">
      <div className="pg-head"><h2><i className="fas fa-gavel" /> Channel Moderation</h2></div>
      <div className="stat-grid small" aria-busy={!l && !lErr}>
        {([['Total warnings', l?.total], ['Users warned', l?.unique], ['Last 7 days', l?.week]] as const).map(([lab, v]) => <div key={lab} className="card stat cm-stat"><div>{stat(v) === null ? <span className="skel w-55" /> : <b>{stat(v)}</b>}<span>{lab}</span></div></div>)}
      </div>
      <section className="card" aria-label="How to issue a warning">
        <h3 className="card-h"><i className="fas fa-terminal" /> How to issue a warning</h3>
        <p>From chat (moderator or broadcaster):</p>
        <ul className="cm-list"><li><code>!warn @username Reason for the warning here</code></li><li><code>!warning @username Reason for the warning here</code> <span className="muted">(alias)</span></li></ul>
        <p className="muted">Only users with the mod permission (or the broadcaster) can issue warnings. You can disable the command under Built-in Commands if needed.</p>
      </section>
      <section className="card" aria-label="Warning log">
        <h3 className="card-h"><i className="fas fa-triangle-exclamation" /> Warning log</h3>
        <form className="cm-searchrow" onSubmit={(e) => { e.preventDefault(); setFilter({ q: qIn.trim(), user: '' }) }}>
          <label className="fld cm-grow"><span className="fld-label">Search</span><input className="input cm-in" type="text" placeholder="Username, mod, or reason..." value={qIn} onChange={(e) => setQIn(e.target.value)} /></label>
          <div className="row-btns"><button type="submit" className="btn cm-btn"><i className="fas fa-search" /> Search</button>
            {filtered && <button type="button" className="btn btn-ghost cm-btn" onClick={() => { setQIn(''); setFilter({ q: '', user: '' }) }}>Clear</button>}</div>
        </form>
        {lErr || l?.unavailable ? <p className="muted">Something went wrong loading the warning log. Refresh to try again.</p> : <>
          <div className="bp-table cm-table"><table>
            <thead><tr><th>User</th><th>Reason</th><th>Warned by</th><th>When</th><th className="cm-c">Actions</th></tr></thead>
            <tbody aria-busy={!l}>{!l ? <SkelRows cols={5} /> : l.warnings.length === 0 ? <tr><td colSpan={5} className="cm-none">No warnings recorded yet.</td></tr>
              : l.warnings.map((w) => <tr key={w.id}>
                <td data-l="User"><a href="#" title="Filter log to this user" onClick={(e) => { e.preventDefault(); setQIn(''); setFilter({ q: '', user: w.user_name }) }}><strong>{w.user_name}</strong></a>{w.user_id && <div className="muted small">ID: {w.user_id}</div>}</td>
                <td data-l="Reason" className="cm-wrap">{w.reason}</td><td data-l="Warned by">{w.warned_by_name}</td><td data-l="When">{w.when_display}</td>
                <td className="cm-c"><button type="button" className="btn btn-danger cm-icon" title="Delete this warning" aria-label={`Delete this warning for ${w.user_name}`} onClick={() => setDel(w)}><i className="fas fa-trash" /></button></td></tr>)}</tbody></table></div>
          {l && l.warnings.length >= 500 && <p className="muted small">Showing the 500 most recent matching warnings. Narrow your search if you need older entries.</p>}
        </>}
      </section>
      <section className="card" aria-label="Ban list">
        <h3 className="card-h"><i className="fas fa-ban" /> Ban list</h3>
        {bStatus && <p className="muted small" aria-live="polite">{bStatus}</p>}
        <div className="bp-table cm-table"><table>
          <thead><tr><th>User</th><th>Reason</th><th>Banned by</th><th>Banned</th><th>Expires</th></tr></thead>
          <tbody aria-busy={bState.first}>{bState.first ? <SkelRows cols={5} /> : bState.msg ? <tr><td colSpan={5} className="cm-none">{bState.msg}</td></tr>
            : bans.length === 0 ? <tr><td colSpan={5} className="cm-none">Nobody is banned in this channel.</td></tr> : pageRows.map(ban)}</tbody></table></div>
        {(bans.length > PER || (bState.loading && !bState.first)) && <nav className="pager" aria-label="Ban list">
          <button type="button" className="btn btn-ghost" disabled={cur === 0} onClick={() => setBPage(cur - 1)}><i className="fas fa-chevron-left" /> Previous</button>
          <span className="btn static">Page {cur + 1} of {pages}{bState.loading ? '+' : ''}</span>
          <button type="button" className="btn btn-ghost" disabled={cur >= pages - 1} onClick={() => setBPage(cur + 1)}>Next <i className="fas fa-chevron-right" /></button>
        </nav>}
      </section>
      {del && <Modal title="Delete this warning?" icon="fas fa-triangle-exclamation" onClose={() => setDel(null)}>
        <p>Delete this warning for <strong>{del.user_name}</strong>? This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy} onClick={() => remove(del)}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Delete</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
