import { useEffect, useRef, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/tracked-games.php, same sections in the same order.
// Everything goes through public/api/tracked-games/tracked-games.php
// (scripts/sync-live-api.sh). No timers; search runs only while typing.
const API = '/api/tracked-games/tracked-games.php'
type Game = { id: number; twitch_game_id: string | null; title: string; box_art_url: string | null; status: string; completion_100: boolean; displayed_seconds: number; displayed_time: string }
type Deny = { id: number; twitch_game_id: string | null; title: string }
type Data = { success: boolean; ready: boolean; games: Game[]; denylist: Deny[] }
type Cat = { id: string; name: string; box_art_url: string | null }
type Sess = { id: number; started_at: string; ended_at: string | null; live: boolean; duration: string }
const STATUS: [string, string, string][] = [['playing', 'Playing', 'pill-ok'], ['on_hold', 'On hold', 'pill-warn'], ['finished', 'Finished', 'pill-info'], ['dropped', 'Dropped', 'pill-off']]
const post = async <T extends { success: boolean; error?: string }>(body: Record<string, string>) => {
  const r = await send<T>(API, body)
  if (!r.success) throw new Error(r.error || 'Could not save that change.')
  return r
}

function Search({ id, onPick, label }: { id: string; onPick: (c: Cat) => Promise<void>; label: string }) {
  const [q, setQ] = useState(''), [items, setItems] = useState<Cat[] | null>(null), [open, setOpen] = useState(false)
  const wrap = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const t = q.trim()
    if (t.length < 2) { setItems(null); setOpen(false); return }
    let live = true
    const h = window.setTimeout(() => { get<Cat[]>(`${API}?ajax=search_categories&q=${encodeURIComponent(t)}`).then((r) => { if (live) { setItems(Array.isArray(r) ? r : []); setOpen(true) } }).catch(() => { if (live) setOpen(false) }) }, 250)
    return () => { live = false; clearTimeout(h) }
  }, [q])
  useEffect(() => {
    const off = (e: MouseEvent) => { if (wrap.current && !wrap.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', off)
    return () => document.removeEventListener('mousedown', off)
  }, [])
  return (
    <div className="tg-search" ref={wrap}>
      <input id={id} className="input" autoComplete="off" aria-label={label} placeholder="Search Twitch categories..." value={q} onChange={(e) => setQ(e.target.value)} onFocus={() => items && setOpen(true)} onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false) }} />
      {open && items && <div className="tg-results" role="listbox">
        {items.length ? items.map((c) => <button key={c.id} type="button" role="option" aria-selected={false} className="tg-result" onClick={async () => { setOpen(false); setQ(''); await onPick(c) }}>
          {c.box_art_url ? <img src={c.box_art_url} alt="" /> : <span className="tg-result-ph"><i className="fas fa-gamepad" /></span>}<span>{c.name}</span></button>)
          : <p className="muted tg-none">No matching categories.</p>}
      </div>}
    </div>
  )
}

export default function GameTracker() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [filter, setFilter] = useState('all'), [busy, setBusy] = useState('')
  const [custom, setCustom] = useState<string | null>(null)
  const [del, setDel] = useState<Game | null>(null), [time, setTime] = useState<{ g: Game; h: string; m: string } | null>(null)
  const [sess, setSess] = useState<{ g: Game; rows: Sess[] | null } | null>(null)
  const load = () => get<Data>(API).then((r) => { if (!r.success) throw new Error(); setD(r) }).catch(() => setErr('The Game Tracker could not load. Refresh to try again.'))
  useEffect(() => { load() }, [])
  async function run(k: string, body: Record<string, string>, ok: string) {
    setBusy(k)
    try { await post(body); setToast({ kind: 'ok', text: ok }); await load(); setBusy(''); return true }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }); setBusy(''); return false }
  }
  if (err) return <div className="page cmds mp tg"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!d) return <div className="page cmds mp tg"><section className="card"><p className="muted">Loading</p></section></div>
  const shown = d.games.filter((g) => filter === 'all' || g.status === filter)
  return (
    <div className="page cmds mp tg">
      <div className="tg-head"><h2><i className="fas fa-gamepad" /> Game Tracker</h2>
        <p className="muted">Log the games you play on stream, mark them finished or dropped, and keep a running time. Specter auto-adds the live Twitch category (except denylisted ones) and counts time while that title is live. Chat: <code>!game</code>.</p></div>
      {!d.ready ? <div className="notice"><i className="fas fa-circle-info" /> <span>The game list is still being set up. Refresh this page in a moment.</span></div> : <>
        <section className="card" aria-label="Add a game">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-plus" /> Add a game</h3></div>
          <p className="mp-lhelp muted">Search Twitch categories to add a title, or add a custom name if search misses it.</p>
          <div className="tg-addrow">
            <Search id="tg-search" label="Search Twitch categories to add" onPick={async (c) => { await run('add', { tracker_action: 'add_game', title: c.name, twitch_game_id: c.id, box_art_url: c.box_art_url || '' }, `${c.name} added.`) }} />
            <button type="button" className="btn btn-ghost" onClick={() => setCustom('')}><i className="fas fa-keyboard" /> Add custom title</button>
          </div>
        </section>
        <div className="tg-filters" role="group" aria-label="Filter games">
          {[['all', 'All'], ...STATUS.map(([v, l]) => [v, l])].map(([v, l]) => <button key={v} type="button" className={`btn ${filter === v ? '' : 'btn-ghost'} tg-filter`} aria-pressed={filter === v} onClick={() => setFilter(v)}>{l}</button>)}
        </div>
        {!shown.length && <div className="notice"><i className="fas fa-circle-info" /> <span>No games on the list yet. Search above or wait for the next live category.</span></div>}
        <div className="tg-grid">
          {shown.map((g) => { const s = STATUS.find(([v]) => v === g.status) ?? STATUS[0]
            return <article key={g.id} className="card tg-card">
              <div className="tg-art">{g.box_art_url ? <img src={g.box_art_url} alt="" /> : <i className="fas fa-gamepad" />}</div>
              <div className="tg-body">
                <h3 className="tg-title">{g.title}</h3>
                <div className="tg-meta"><span className={`pill ${s[2]}`}>{s[1]}</span>{g.completion_100 && <span className="pill pill-warn">100%</span>}<b className="tg-time">{g.displayed_time}</b></div>
                <div className="tg-actions">
                  <div className="tg-sel"><Combobox label={`Status of ${g.title}`} searchable={false} value={g.status} onChange={(v) => { if (v !== g.status) run('st' + g.id, { tracker_action: 'update_status', id: String(g.id), status: v }, `${g.title}: ${STATUS.find(([x]) => x === v)?.[1]}.`) }} options={STATUS.map(([value, label]) => ({ value, label }))} /></div>
                  <label className="tg-100"><input type="checkbox" checked={g.completion_100} disabled={busy === '100' + g.id} onChange={(e) => run('100' + g.id, { tracker_action: 'update_100', id: String(g.id), completion_100: e.target.checked ? '1' : '' }, e.target.checked ? `${g.title} marked 100%.` : `${g.title} no longer 100%.`)} /> 100%</label>
                  <button type="button" className="btn btn-ghost" onClick={async () => { setSess({ g, rows: null }); try { const r = await post<{ success: boolean; sessions: Sess[] }>({ tracker_action: 'sessions', id: String(g.id) }); setSess({ g, rows: r.sessions || [] }) } catch (e) { setSess(null); setToast({ kind: 'err', text: (e as Error).message }) } }}>Sessions</button>
                  <button type="button" className="btn btn-ghost" onClick={() => setTime({ g, h: String(Math.floor(g.displayed_seconds / 3600)), m: String(Math.floor((g.displayed_seconds % 3600) / 60)) })}>Edit time</button>
                  <button type="button" className="btn btn-ghost tg-rm" onClick={() => setDel(g)}>Remove</button>
                </div>
              </div>
            </article> })}
        </div>
        <section className="card" aria-label="Auto-add denylist">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-ban" /> Auto-add denylist</h3></div>
          <p className="mp-lhelp muted">These Twitch categories are not auto-added or timed. Defaults include Just Chatting and other non-games; you can remove any of them or add more.</p>
          <div className="tg-addrow"><Search id="tg-deny" label="Search Twitch categories to denylist" onPick={async (c) => { await run('deny', { tracker_action: 'add_denylist', title: c.name, twitch_game_id: c.id }, `${c.name} denylisted.`) }} /></div>
          {d.denylist.length ? <ul className="tg-deny">{d.denylist.map((r) => <li key={r.id}><span>{r.title}</span>
            <button type="button" className="btn btn-ghost" disabled={busy === 'rd' + r.id} onClick={() => run('rd' + r.id, { tracker_action: 'remove_denylist', id: String(r.id) }, `${r.title} removed from the denylist.`)}><i className={busy === 'rd' + r.id ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /> Remove</button></li>)}</ul>
            : <p className="mp-lempty muted">Nothing denylisted, so every category change will auto-add.</p>}
        </section>
      </>}
      {custom !== null && <Modal title="Add custom title" icon="fas fa-keyboard" onClose={() => setCustom(null)}>
        <form onSubmit={async (e) => { e.preventDefault(); const t = custom.trim(); if (!t) return; if (await run('custom', { tracker_action: 'add_game', title: t }, `${t} added.`)) setCustom(null) }}>
          <div className="fld"><label htmlFor="tg-ct">Game title</label><input id="tg-ct" className="input" maxLength={255} data-autofocus value={custom} onChange={(e) => setCustom(e.target.value)} /></div>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setCustom(null)}>Cancel</button><button className="btn" disabled={!custom.trim() || busy === 'custom'}><i className={busy === 'custom' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add</button></div>
        </form></Modal>}
      {time && <Modal title="Edit total time" icon="fas fa-clock" onClose={() => setTime(null)}>
        <form onSubmit={async (e) => { e.preventDefault(); if (await run('time', { tracker_action: 'set_time', id: String(time.g.id), hours: time.h, minutes: time.m }, `${time.g.title} time saved.`)) setTime(null) }}>
          <p className="muted">{time.g.title}</p>
          <div className="tg-timeflds">
            <div className="fld"><label htmlFor="tg-h">Hours</label><span className="mp-numbox"><input id="tg-h" type="number" inputMode="numeric" min={0} max={99999} data-autofocus value={time.h} onChange={(e) => setTime({ ...time, h: e.target.value })} /><span className="mp-unit">h</span></span></div>
            <div className="fld"><label htmlFor="tg-m">Minutes</label><span className="mp-numbox"><input id="tg-m" type="number" inputMode="numeric" min={0} max={59} value={time.m} onChange={(e) => setTime({ ...time, m: e.target.value })} /><span className="mp-unit">min</span></span></div>
          </div>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setTime(null)}>Cancel</button><button className="btn" disabled={busy === 'time'}><i className={busy === 'time' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save</button></div>
        </form></Modal>}
      {sess && <Modal title={`Sessions, ${sess.g.title}`} icon="fas fa-list" onClose={() => setSess(null)} wide>
        {!sess.rows ? <p className="muted">Loading</p> : !sess.rows.length ? <p className="muted">No sessions recorded yet.</p> :
          <div className="tg-table"><table><thead><tr><th>Start</th><th>End</th><th>Time</th></tr></thead><tbody>
            {sess.rows.map((r) => <tr key={r.id}><td>{r.started_at}</td><td>{r.live ? <span className="pill pill-live"><span className="dot" /> Live</span> : r.ended_at || ''}</td><td>{r.duration}</td></tr>)}</tbody></table></div>}
      </Modal>}
      {del && <Modal title="Remove this game?" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p><b>{del.title}</b> and its session log are removed. This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Keep it</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { setDel(null); run('del', { tracker_action: 'delete_game', id: String(del.id) }, `${del.title} removed.`) }}><i className={busy === 'del' ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Remove</button></div></Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
