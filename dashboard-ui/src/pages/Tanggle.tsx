import { useEffect, useState } from 'react'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'
import Pager from '../components/Pager'

// Port of dashboard/tanggle.php: configuration required notice, Tanggle
// Integration card with Tanggle Configuration (API token with show / hide,
// community UUID, Save), the how-to card while not configured, then Puzzle
// Completion Stats, Active Puzzle, Puzzle Queue tiles and Recent Completed
// Puzzles. Endpoint: public/api/tanggle (the list calls the Tanggle API server
// side, as on live). Loads once; no timers.
const API = '/api/tanggle/tanggle.php'
type Cred = { success: boolean; api_token: string; community_uuid: string; credentials_exist: boolean }
type Room = { preview_url: string; title: string; is_completed: boolean; pieces_completed: number; pieces_count: number; completed_rate: number; grid_x: number; grid_y: number; player_count: number; player_limit: number; creator: string | null; redirect_url: string }
type Q = { preview_url: string; title: string; alt: string; position: number; grid_x: number; grid_y: number; piece_total: number; creator: string | null }
type Done = { room_title: string; room_uuid: string; winner: string; piece_completed: number; piece_count: number; completed_display: string; redirect_url: string }
type List = { success: boolean; error?: string; api_error: string | null; active_room: Room | null; queue_items: Q[]; puzzle_stats: { completed_count: number; last_completed_display: string }; recent_completions: Done[]; completions_total?: number }
type Comp = { rows: Done[]; page: number; pages: number; total: number }
const UUID = /^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/
const tokOk = (t: string) => /^[\x21-\x7E]{1,1000}$/.test(t.trim())
const safeUrl = (u: string) => (/^https:\/\//i.test(u) ? u : '')

export default function Tanggle() {
  const [cred, setCred] = useState<Cred | null>(null), [err, setErr] = useState(''), [tok, setTok] = useState(''), [cu, setCu] = useState(''), [show, setShow] = useState(false)
  const [list, setList] = useState<List | null>(null), [listErr, setListErr] = useState(''), [busy, setBusy] = useState(false), [toast, setToast] = useState<Toast>(null)
  const loadList = () => { setList(null); setListErr(''); get<List>(`${API}?ajax_action=list`, 40000).then((r) => { if (!r.success) throw new Error(r.error); setList(r); const t = r.completions_total ?? r.recent_completions.length; setComp({ rows: r.recent_completions, page: 1, pages: Math.max(1, Math.ceil(t / 10)), total: t }) }).catch((x) => setListErr((x as Error).message || 'Failed to fetch room data from Tanggle API')) }
  const [comp, setComp] = useState<Comp | null>(null), [cBusy, setCBusy] = useState(false)
  // Paging reads only the recorded completions, so the Tanggle API is not called again.
  const goPage = (n: number) => { setCBusy(true); get<{ success: boolean; completions: Done[]; page: number; pages: number; total: number }>(`${API}?ajax_action=completions&page=${n}`).then((r) => { if (!r.success) throw new Error(); setComp({ rows: r.completions, page: r.page, pages: r.pages, total: r.total }) }).catch(() => setToast({ kind: 'err', text: 'Could not load that page of puzzles.' })).finally(() => setCBusy(false)) }
  const load = () => get<Cred>(API).then((r) => { if (!r.success) throw new Error(); setCred(r); setTok(r.api_token); setCu(r.community_uuid); if (r.credentials_exist) loadList() })
  useEffect(() => { load().catch(() => setErr('The Tanggle page could not load. Refresh to try again.')) }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (!tokOk(tok) || !UUID.test(cu.trim())) return; setBusy(true)
    try { const r = await send<{ success: boolean; error?: string; message?: string }>(API, { api_token: tok.trim(), community_uuid: cu.trim() }); if (!r.success) throw new Error(r.error || 'Please enter both API Token and Community UUID.'); setToast({ kind: 'ok', text: r.message || 'Tanggle credentials saved successfully!' }); await load() }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setBusy(false) }
  }
  if (err) return <div className="page cmds mp tg"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!cred) return <div className="page cmds mp tg"><section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p></section></div>
  const has = cred.credentials_exist, dirty = tok.trim() !== cred.api_token || cu.trim() !== cred.community_uuid, ok = tokOk(tok) && UUID.test(cu.trim())
  const room = list?.active_room, apiErr = listErr || list?.api_error
  return (
    <div className="page cmds mp tg">
      <div className="pg-head"><h2><i className="fas fa-puzzle-piece" /> Tanggle Integration</h2><p className="muted">Configure Tanggle puzzle integration settings.</p></div>
      {!has && <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span><b>Configuration Required:</b> enter your Tanggle API credentials below to enable puzzle integration.</span></div>}
      <section className="card" aria-label="Tanggle Integration">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-puzzle-piece" /> Tanggle Integration</h3>{has && <button type="button" className="btn btn-ghost" disabled={!list && !listErr} onClick={loadList}><i className="fas fa-rotate" /> Refresh</button>}</div>
        <section className="card bg-inner" aria-label="Tanggle Configuration">
          <h3 className="card-h"><i className="fas fa-wrench" /> Tanggle Configuration</h3>
          <form onSubmit={save} className="cr-form">
            <label className="fld"><span className="fld-label">API Access Token</span>
              <span className="bg-key"><input className="input" type={show ? 'text' : 'password'} autoComplete="off" spellCheck={false} placeholder="Enter your API Access Token" value={tok} aria-invalid={!!tok && !tokOk(tok)} onChange={(e) => setTok(e.target.value)} />
                <button type="button" className="btn btn-ghost" aria-label={show ? 'Hide API token' : 'Show API token'} aria-pressed={show} onClick={() => setShow(!show)}><i className={show ? 'fas fa-eye-slash' : 'fas fa-eye'} /></button></span></label>
            <label className="fld"><span className="fld-label">Community UUID</span><input className="input" spellCheck={false} maxLength={36} placeholder="Enter your Community UUID" value={cu} aria-invalid={!!cu && !UUID.test(cu.trim())} onChange={(e) => setCu(e.target.value)} />
              <span className={cu && !UUID.test(cu.trim()) ? 'mp-bad small' : 'muted small'}>Example: 12345678-1234-1234-1234-123456789012</span></label>
            <div><button type="submit" className="btn" disabled={!ok || !dirty || busy}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save Credentials</button></div>
          </form>
        </section>
        {!has && <section className="card bg-inner" aria-label="How to get your Tanggle credentials">
          <h3 className="card-h"><i className="fas fa-question-circle" /> How to Get Your Tanggle Credentials</h3>
          <b>API Access Token:</b>
          <ol className="bg-steps">
            <li><b>Open your Tanggle community:</b> <a href="https://tanggle.io" target="_blank" rel="noreferrer">tanggle.io</a></li>
            <li><b>Administration:</b> open your community settings or administration panel.</li>
            <li><b>Integrations:</b> open the "Integrations" section.</li>
            <li><b>Custom tab:</b> click the "Custom" tab.</li>
            <li><b>Copy API Token:</b> click "Copy token".</li>
            <li><b>Paste Here:</b> paste it in the "API Access Token" field above.</li>
          </ol>
          <b className="tg-sub">Community UUID:</b>
          <ol className="bg-steps">
            <li><b>Open your community page</b> on Tanggle.</li>
            <li><b>Check the URL</b> in the address bar.</li>
            <li>It looks like <code>tanggle.io/community/12345678-1234-1234-1234-123456789012</code></li>
            <li><b>Copy the UUID:</b> the long string after <code>/community/</code>.</li>
            <li><b>Paste Here:</b> paste it in the "Community UUID" field above.</li>
          </ol>
        </section>}
        {has && <>
          {apiErr && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span><b>API Error:</b> {apiErr}</span></div>}
          <section className="card bg-inner" aria-label="Puzzle Completion Stats" aria-busy={!list && !listErr}>
            <h3 className="card-h"><i className="fas fa-chart-line" /> Puzzle Completion Stats</h3>
            {!list ? <div className="tg-stats"><span className="bp-skel" /><span className="bp-skel" /></div>
              : <div className="tg-stats"><p><b>Total Completed:</b> <span className="pill pill-ok">{list.puzzle_stats.completed_count}</span></p><p><b>Last Completed:</b> {list.puzzle_stats.last_completed_display}</p>{comp && list.puzzle_stats.completed_count > comp.total && <p className="muted small">{comp.total} have puzzle details below. {list.puzzle_stats.completed_count - comp.total} were counted with !puzzledone, so they have no puzzle details.</p>}</div>}
          </section>
          {!list && !listErr ? <section className="card bg-inner" aria-busy="true"><h3 className="card-h"><i className="fas fa-puzzle-piece" /> Active Puzzle</h3><div className="tg-room"><span className="tg-thumb bp-skel" /><div className="tg-facts"><span className="bp-skel bp-skel-w" /><span className="bp-skel" /><span className="bp-skel" /></div></div></section>
            : room ? <section className="card bg-inner" aria-label="Active Puzzle">
              <h3 className="card-h"><i className="fas fa-puzzle-piece" /> Active Puzzle</h3>
              <div className="tg-room">
                {safeUrl(room.preview_url) ? <img className="tg-thumb" src={safeUrl(room.preview_url)} alt="Puzzle Preview" /> : <span className="tg-thumb" />}
                <div className="tg-facts">
                  <p className="tg-title">{room.title}</p>
                  <p><b>Status:</b> <span className={`pill ${room.is_completed ? 'pill-ok' : 'pill-warn'}`}>{room.is_completed ? 'Completed' : 'In Progress'}</span></p>
                  <p><b>Pieces:</b> {room.pieces_completed} / {room.pieces_count} ({room.completed_rate}%)</p>
                  <div className="seg-bar tg-bar" role="progressbar" aria-valuenow={room.completed_rate} aria-valuemin={0} aria-valuemax={100} aria-label="Pieces placed"><span style={{ width: `${Math.min(100, room.completed_rate)}%`, background: 'var(--accent)' }} /></div>
                  <p><b>Grid:</b> {room.grid_x}x{room.grid_y}</p>
                  <p><b>Players:</b> {room.player_count} / {room.player_limit}</p>
                  {room.creator && <p><b>Creator:</b> {room.creator}</p>}
                  {safeUrl(room.redirect_url) && <a className="btn" href={safeUrl(room.redirect_url)} target="_blank" rel="noreferrer"><i className="fas fa-external-link-alt" /> Open Puzzle</a>}
                </div>
              </div>
            </section>
            : !apiErr && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span><b>No Active Puzzle:</b> there are no active puzzle rooms in your community right now.</span></div>}
          <section className="card bg-inner" aria-label="Puzzle Queue">
            <h3 className="card-h"><i className="fas fa-list" /> Puzzle Queue {list && <span className="muted">({list.queue_items.length} puzzles)</span>}</h3>
            <div className="tg-queue" aria-busy={!list && !listErr}>
              {!list ? !listErr && Array.from({ length: 3 }, (_, i) => <div key={i} className="tg-q" aria-hidden="true"><span className="tg-qimg bp-skel" /><div className="tg-qb"><span className="bp-skel bp-skel-w" /><span className="bp-skel" /></div></div>)
                : list.queue_items.length === 0 ? <p className="muted">The queue is empty.</p>
                : list.queue_items.map((q, i) => <div key={i} className="tg-q">
                  {safeUrl(q.preview_url) ? <img className="tg-qimg" src={safeUrl(q.preview_url)} alt={q.alt} loading="lazy" /> : <span className="tg-qimg" />}
                  <div className="tg-qb"><p className="tg-qt">{q.title}</p><p className="muted small">Position: #{q.position}</p><p className="small"><b>Grid:</b> {q.grid_x}x{q.grid_y} ({q.piece_total} pieces)</p>{q.creator && <p className="small"><b>By:</b> {q.creator}</p>}</div>
                </div>)}
            </div>
          </section>
          <section className="card bg-inner" aria-label="Completed Puzzles">
            <h3 className="card-h"><i className="fas fa-history" /> Completed Puzzles{comp && comp.total > 0 && <> <span className="pill">{comp.total}</span></>}</h3>
            {list && list.recent_completions.length === 0 ? <div className="notice notice-info"><i className="fas fa-circle-info" /> <span><b>No Completion History Yet:</b> completed puzzles appear here after the bot records a room completion.</span></div>
              : <div className="bp-table bg-table"><table>
                <thead><tr><th>Puzzle</th><th>Winner</th><th>Pieces</th><th>Completed</th><th>Link</th></tr></thead>
                <tbody aria-busy={!list && !listErr}>{listErr ? <tr><td colSpan={5} className="mp-bad bp-empty">{listErr}</td></tr>
                  : !list ? Array.from({ length: 3 }, (_, i) => <tr key={i} aria-hidden="true">{[70, 50, 40, 60, 30].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
                  : (comp?.rows ?? list.recent_completions).map((c, i) => <tr key={c.room_uuid || i}>
                    <td data-l="Puzzle" className="tg-puz"><b>{c.room_title}</b><div className="muted small bg-id">{c.room_uuid}</div></td>
                    <td data-l="Winner" className="bp-user">{c.winner}</td><td data-l="Pieces" className="bp-pts">{c.piece_completed} / {c.piece_count}</td><td data-l="Completed">{c.completed_display}</td>
                    <td data-l="Link">{safeUrl(c.redirect_url) ? <a className="btn btn-ghost" href={safeUrl(c.redirect_url)} target="_blank" rel="noreferrer">Open</a> : <span className="muted">N/A</span>}</td></tr>)}</tbody></table></div>}
            {comp && <Pager page={comp.page} pages={comp.pages} onPage={goPage} busy={cBusy} label="Completed puzzles pages" />}
          </section>
        </>}
      </section>
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
