import { useEffect, useState } from 'react'
import Modal from '../components/Modal'
import Pager from '../components/Pager'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/bingo.php: Stream Bounty Integration card with Call Random /
// Call All / Start Vote in the header, the API key required notice, Twitch
// Extension Configuration (API key with show / hide, Save), the how-to steps,
// Bingo Games History (Game ID, Start, End, Events, Status, View Winners /
// View Players) and the two modals. Game calls go from the browser to
// api.stream-bingo.com as on live. Endpoint: public/api/bingo. No timers.
const API = '/api/bingo/'
type Game = { game_id: string; start_time: string; end_time: string | null; events_count: number; status: string }
type Page = { success: boolean; api_key: string; twitch_user_id: string; games: Game[]; page?: number; pages?: number; total?: number }
type Player = { player_name: string; joined_at: string }
type Winner = { player_name: string; rank: number; timestamp: string }
const RANK = ['', '1st Place', '2nd Place', '3rd Place', '4th Place', '5th Place']
const keyOk = (k: string) => /^[A-Za-z0-9._~-]{1,200}$/.test(k.trim())

function ListModal({ kind, game, onClose }: { kind: 'players' | 'winners'; game: string; onClose: () => void }) {
  const [rows, setRows] = useState<(Player | Winner)[] | null>(null), [err, setErr] = useState('')
  useEffect(() => { get<{ success: boolean; players?: Player[]; winners?: Winner[] }>(`${API}${kind}.php?game_id=${encodeURIComponent(game)}`).then((r) => { if (!r.success) throw new Error(); setRows((kind === 'players' ? r.players : r.winners) || []) }).catch(() => setErr(kind === 'players' ? 'Error loading players' : 'Error loading winners')) }, [kind, game])
  return <Modal title={`${kind === 'players' ? 'Game Players' : 'Game Winners'} - ${game}`} icon={kind === 'players' ? 'fas fa-users' : 'fas fa-trophy'} onClose={onClose}>
    <div aria-busy={!rows && !err}>
      {err ? <p className="mp-bad">{err}</p> : !rows ? Array.from({ length: 4 }, (_, i) => <div key={i} className="bg-row" aria-hidden="true"><span className="bp-skel" /><span className="bp-skel" style={{ width: '25%' }} /></div>)
        : rows.length === 0 ? <p className="muted">{kind === 'players' ? 'No players joined this game' : 'No winners for this game yet'}</p>
        : <>{kind === 'players' && <p className="muted small">{rows.length} player{rows.length === 1 ? '' : 's'}</p>}
          {rows.map((r, i) => { const w = r as Winner, p = r as Player
            return <div key={i} className="bg-row"><span className="bg-who">{kind === 'winners' ? <span className={`pill ${w.rank === 1 || w.rank === 3 ? 'pill-warn' : w.rank === 2 ? 'pill-off' : ''}`}>{RANK[w.rank] || `${w.rank}th Place`}</span> : <span className="pill">{i + 1}</span>}<b>{r.player_name}</b></span><small className="muted">{kind === 'winners' ? w.timestamp : p.joined_at}</small></div> })}</>}
    </div>
    <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={onClose}>Close</button></div>
  </Modal>
}

export default function Bingo() {
  const [page, setPage] = useState<Page | null>(null), [err, setErr] = useState(''), [key, setKey] = useState(''), [show, setShow] = useState(false)
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null), [modal, setModal] = useState<{ kind: 'players' | 'winners'; game: string } | null>(null)
  const [pgBusy, setPgBusy] = useState(false)
  const load = (n = page?.page || 1) => get<Page>(`${API}bingo.php?page=${n}`).then((r) => { if (!r.success) throw new Error(); setPage(r); setKey(r.api_key) })
  // History paging keeps any API key typed but not saved yet.
  const goPage = (n: number) => { setPgBusy(true); get<Page>(`${API}bingo.php?page=${n}`).then((r) => { if (!r.success) throw new Error(); setPage((o) => o ? { ...o, games: r.games, page: r.page, pages: r.pages, total: r.total } : r) }).catch(() => setToast({ kind: 'err', text: 'Could not load that page of games.' })).finally(() => setPgBusy(false)) }
  useEffect(() => { load(1).catch(() => setErr('The Stream Bingo page could not load. Refresh to try again.')) }, [])
  async function saveKey(e: React.FormEvent) {
    e.preventDefault(); if (!keyOk(key)) return; setBusy('key')
    try { const r = await send<{ success: boolean; error?: string; message?: string }>(API + 'bingo.php', { api_key: key.trim() }); if (!r.success) throw new Error(r.error || 'Please enter a valid API Key.'); setToast({ kind: 'ok', text: r.message || 'API Key saved successfully!' }); await load() }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setBusy('') }
  }
  async function call(k: string, path: string, ok: string, bad: string) {
    if (!page) return; setBusy(k)
    try { const r = await fetch(`https://api.stream-bingo.com/games/${path.replace('{id}', encodeURIComponent(page.twitch_user_id)).replace('{key}', encodeURIComponent(page.api_key))}`, { method: 'POST' }); if (!r.ok) throw new Error(`HTTP ${r.status}`); setToast({ kind: 'ok', text: ok }) }
    catch (x) { setToast({ kind: 'err', text: bad + (x as Error).message }) } finally { setBusy('') }
  }
  if (err) return <div className="page cmds mp bg"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp bg"><section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p></section></div>
  const has = !!page.api_key, dirty = key.trim() !== page.api_key
  return (
    <div className="page cmds mp bg">
      <div className="pg-head"><h2><i className="fas fa-trophy" /> Stream Bingo</h2><p className="muted">Manage bingo games for the Stream Bounty integration.</p></div>
      <section className="card" aria-label="Stream Bounty Integration">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-trophy" /> Stream Bounty Integration</h3>
          <div className="bg-calls">
            <button type="button" className="btn" disabled={!has || !!busy} onClick={() => call('r', 'events/{id}/{key}/callrandom', 'Random number called successfully!', 'Error calling random number: ')}><i className={busy === 'r' ? 'fas fa-spinner fa-spin' : 'fas fa-shuffle'} /> Call Random</button>
            <button type="button" className="btn" disabled={!has || !!busy} onClick={() => call('a', 'events/{id}/{key}/callall', 'All numbers called successfully!', 'Error calling all numbers: ')}><i className={busy === 'a' ? 'fas fa-spinner fa-spin' : 'fas fa-list-check'} /> Call All</button>
            <button type="button" className="btn" disabled={!has || !!busy} onClick={() => call('v', 'voting/{id}/{key}/start', 'Vote started successfully!', 'Error starting vote: ')}><i className={busy === 'v' ? 'fas fa-spinner fa-spin' : 'fas fa-check-to-slot'} /> Start Vote</button>
          </div></div>
        {!has && <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span><b>API Key Required:</b> enter your Stream Bounty API key below to enable the bingo game controls.</span></div>}
        <div className="bg-grid">
          <section className="card bg-inner" aria-label="Twitch Extension Configuration">
            <h3 className="card-h"><i className="fas fa-wrench" /> Twitch Extension Configuration</h3>
            <p className="muted small">Your Stream Bounty API key, used for the bingo games.</p>
            <form onSubmit={saveKey} className="cr-form">
              <label className="fld"><span className="fld-label">API Key</span>
                <span className="bg-key"><input className="input" type={show ? 'text' : 'password'} autoComplete="off" spellCheck={false} maxLength={200} placeholder="Enter your API Key" value={key} aria-invalid={!!key && !keyOk(key)} onChange={(e) => setKey(e.target.value)} />
                  <button type="button" className="btn btn-ghost" aria-label={show ? 'Hide API key' : 'Show API key'} aria-pressed={show} onClick={() => setShow(!show)}><i className={show ? 'fas fa-eye-slash' : 'fas fa-eye'} /></button></span>
                {key && !keyOk(key) && <span className="mp-bad small">Paste the key exactly as Stream Bounty shows it.</span>}</label>
              <div><button type="submit" className="btn" disabled={!keyOk(key) || !dirty || busy === 'key'}><i className={busy === 'key' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save API Key</button></div>
            </form>
          </section>
          <section className="card bg-inner" aria-label="How to get your API key">
            <h3 className="card-h"><i className="fas fa-question-circle" /> How to Get Your Stream Bounty API Key</h3>
            <ol className="bg-steps">
              <li><b>Open your Twitch Stream Dashboard:</b> <a href="https://dashboard.twitch.tv/" target="_blank" rel="noreferrer">dashboard.twitch.tv</a></li>
              <li><b>Extensions:</b> click "Extensions" in the left menu.</li>
              <li><b>My Extensions:</b> open the "My Extensions" tab.</li>
              <li>Find the activated Stream Bounty extension.</li>
              <li><b>Settings:</b> click the cog on the Stream Bounty extension.</li>
              <li><b>Bot Integration:</b> at the bottom of the left menu, click "Bot Integration".</li>
              <li><b>Copy the API Key:</b> click the copy icon in "API Key Management".</li>
              <li><b>Paste and Save:</b> paste it in the API Key field and click "Save API Key".</li>
            </ol>
          </section>
        </div>
        <h3 className="card-h bg-histh"><i className="fas fa-history" /> Bingo Games History</h3>
        <div className="bp-table bg-table"><table>
          <thead><tr><th>Game ID</th><th>Start Time</th><th>End Time</th><th>Events</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>{page.games.length === 0 ? <tr><td colSpan={6} className="muted bp-empty">No bingo games found</td></tr>
            : page.games.map((g) => <tr key={g.game_id}>
              <td data-l="Game ID" className="bg-id">{g.game_id}</td><td data-l="Start Time">{g.start_time}</td><td data-l="End Time">{g.end_time ?? 'Ongoing'}</td>
              <td data-l="Events" className="bp-pts">{g.events_count}</td>
              <td data-l="Status"><span className={`pill ${g.status === 'active' ? 'pill-ok' : 'pill-off'}`}>{g.status.charAt(0).toUpperCase() + g.status.slice(1)}</span></td>
              <td><div className="ps-acts rf-row"><button type="button" className="btn btn-ghost" onClick={() => setModal({ kind: 'winners', game: g.game_id })}><i className="fas fa-trophy" /> View Winners</button><button type="button" className="btn btn-ghost" onClick={() => setModal({ kind: 'players', game: g.game_id })}><i className="fas fa-users" /> View Players</button></div></td></tr>)}</tbody></table></div>
        <Pager page={page.page || 1} pages={page.pages || 1} onPage={goPage} busy={pgBusy} label="Bingo games history pages" />
      </section>
      {modal && <ListModal kind={modal.kind} game={modal.game} onClose={() => setModal(null)} />}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
