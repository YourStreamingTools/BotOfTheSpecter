import { useEffect, useRef, useState } from 'react'
import { get } from '../../bot'
import { send, ToastBar, type Toast } from '../commands/shared'
import { Confirm, notOk } from './shared'

// Port of dashboard/notifications.php (+ api/notifications_api.php): stats
// tiles, active WebSocket sessions, disabled / stale sessions, webhook
// subscriptions and the internal websocket clients, refreshing every 10s with
// the same stale-session cleanup. Endpoint: public/api/notifications/notifications.php.
const API = '/api/notifications/notifications.php'
type Sub = { id: string; type: string; version: string; status: string; created_at: string; cost?: number; condition?: Record<string, string>; transport?: { method: string; session_id?: string; callback?: string } }
type Data = { totalCount: number; totalCost: number; maxCost: number; websocketSubsEnabled: Sub[]; websocketSubsDisabled: Sub[]; webhookSubs: Sub[]; sessionGroups: Record<string, Sub[]>; sessionGroupsDisabled: Record<string, Sub[]>; sessionNames: Record<string, string>; userId: string | number; twitchUserId?: string }
type Client = { name?: string; client_name?: string; clientName?: string; sid?: string; id?: string; connectionId?: string; key_type?: string }
type Ask = { kind: 'sub'; id: string; transport: string } | { kind: 'session'; name: string; ids: string[] } | { kind: 'ws'; sid: string }
const obj = <T,>(v: unknown): Record<string, T> => (v && typeof v === 'object' && !Array.isArray(v) ? v as Record<string, T> : {})
const when = (s: string) => { const d = new Date(s); return isNaN(d.getTime()) ? s : d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) }
const pillFor = (s: string) => s === 'enabled' ? 'pill-ok' : s.includes('pending') ? 'pill-warn' : 'pill-err'

function Cond({ c, me }: { c?: Record<string, string>; me: string }) {
  const e = Object.entries(c ?? {}).filter(([, v]) => v !== '' && v !== null && v !== undefined)
  if (!e.length) return <span className="muted">-</span>
  return <>{e.map(([k, v], i) => <span key={k}>{i > 0 && <br />}{k}: {String(v) === me && me ? <b className="cm-yes">YOU</b> : String(v).slice(0, 12)}</span>)}</>
}

function SubTable({ rows, me, onDel, busy, hook }: { rows: Sub[]; me: string; onDel?: (s: Sub) => void; busy: string; hook?: boolean }) {
  return <div className="bp-table cm-table"><table>
    <thead><tr><th>Type</th><th>Version</th>{hook ? <th>Callback URL</th> : <th>Condition</th>}<th>Status</th>{!hook && <th>Created</th>}{onDel && <th className="cm-c">Action</th>}</tr></thead>
    <tbody>{rows.map((s) => <tr key={s.id}>
      <td data-l="Type"><code className="nt-code">{s.type}</code></td>
      <td data-l="Version">v{s.version}</td>
      {hook ? <td data-l="Callback URL" className="cm-wrap">{s.transport?.callback || 'N/A'}</td> : <td data-l="Condition" className="small"><Cond c={s.condition} me={me} /></td>}
      <td data-l="Status"><span className={`pill ${pillFor(s.status)}`}>{s.status}</span></td>
      {!hook && <td data-l="Created">{when(s.created_at)}</td>}
      {onDel && <td className="cm-c"><button type="button" className="btn btn-danger cm-btn" disabled={busy === s.id} onClick={() => onDel(s)}><i className="fas fa-trash" /> Delete</button></td>}
    </tr>)}</tbody>
  </table></div>
}

export default function Notifications() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(''), [upd, setUpd] = useState('')
  const [ws, setWs] = useState<Client[] | null>(null), [wsErr, setWsErr] = useState(''), [wsBusy, setWsBusy] = useState(false)
  const [ask, setAsk] = useState<Ask | null>(null), [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null)
  const deleting = useRef(false)
  const load = () => get<{ success: boolean; data?: Data; error?: string }>(`${API}?action=fetch_subscriptions`, 20000)
    .then((r) => { if (!r.success || !r.data) throw new Error(r.error || 'x'); setD(r.data); setErr(''); setUpd(new Date().toLocaleTimeString()) })
    .catch((e) => { if (notOk(e)) setErr('Could not load your EventSub subscriptions from Twitch. This page will try again shortly.') })
  const loadWs = () => { setWsBusy(true); return get<{ success: boolean; data?: unknown; clients?: unknown; error?: string }>(`${API}?action=fetch_internal_websocket`, 20000)
    .then((r) => { if (!r.success) throw new Error(); const raw = (r.data ?? r.clients) as unknown; const cl = Array.isArray(raw) ? raw : obj<unknown>(raw).clients; const list = Array.isArray(cl) ? cl : Object.values(obj<Client>(cl)); setWs(list as Client[]); setWsErr('') })
    .catch((e) => { if (notOk(e)) setWsErr('Unable to load data.') }).finally(() => setWsBusy(false)) }
  const cleanup = () => { if (deleting.current) return; send<{ success: boolean; deleted?: number; removed?: number }>(API, { action: 'cleanup_sessions' }).then((r) => { const n = Number(r.deleted ?? r.removed ?? 0); if (r.success && n > 0) { setToast({ kind: 'ok', text: `${n} stale session(s) removed` }); load() } }).catch(() => {}) }
  useEffect(() => {
    load(); loadWs()
    const a = setInterval(() => { if (!deleting.current) load() }, 10000)
    const b = setTimeout(cleanup, 2000), c = setInterval(cleanup, 300000)
    return () => { clearInterval(a); clearTimeout(b); clearInterval(c) }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function run() {
    if (!ask) return
    const key = ask.kind === 'sub' ? ask.id : ask.kind === 'ws' ? ask.sid : 'session'
    setBusy(key); deleting.current = true
    try {
      const body: Record<string, string> = ask.kind === 'sub' ? { action: 'delete_subscription', subscription_id: ask.id, transport: ask.transport }
        : ask.kind === 'session' ? { action: 'delete_session', subscription_ids: JSON.stringify(ask.ids) } : { action: 'disconnect_internal_websocket', sid: ask.sid }
      const r = await send<{ success: boolean; message?: string; error?: string }>(API, body)
      setToast(r.success ? { kind: 'ok', text: r.message || 'Done.' } : { kind: 'err', text: r.error || 'That did not work. Please try again.' })
      setAsk(null)
      if (ask.kind === 'ws') loadWs(); else load()
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    finally { setBusy(''); deleting.current = false }
  }
  const me = String(d?.twitchUserId ?? '')
  const groups = Object.entries(obj<Sub[]>(d?.sessionGroups)), stale = Object.entries(obj<Sub[]>(d?.sessionGroupsDisabled))
  const names = obj<string>(d?.sessionNames), conns = groups.length, hooks = d?.webhookSubs ?? []
  const skel = <span className="skel w-55" />
  const tile = (label: string, value: React.ReactNode, sub: React.ReactNode, cls = '') => <div className={`card stat cm-stat ${cls}`}><div><span>{label}</span><b>{d ? value : skel}</b><small className="muted">{sub}</small></div></div>
  return (
    <div className="page cmds cm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <div className="pg-head nt-head">
        <div><h2><i className="fas fa-bell" /> EventSub Notifications</h2>
          <p className="muted">Monitor and manage your Twitch EventSub subscriptions <small>{upd ? `(Last updated: ${upd})` : '(Auto-refreshing every 10s)'}</small></p></div>
        <button type="button" className="btn btn-quiet cm-btn" disabled={wsBusy} onClick={loadWs}><i className="fas fa-plug" /> Refresh Internal Websocket</button>
      </div>
      {err && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
      <div className="stat-grid small">
        {tile('Total Subscriptions', d?.totalCount ?? 0, 'across all transports')}
        {tile('Active WebSocket Subscriptions', d?.websocketSubsEnabled.length ?? 0, <>limit: 300 per connection{groups.map(([id, s], i) => <span key={id} className={`nt-line ${s.length >= 250 ? 'cm-no' : s.length >= 150 ? 'nt-amber' : ''}`}>Connection {i + 1}: {s.length} subscriptions</span>)}{(d?.websocketSubsDisabled.length ?? 0) > 0 && <span className="nt-line cm-no">{d?.websocketSubsDisabled.length} disabled/stale</span>}</>)}
        {tile('Active Connections', conns, 'limit: 3 connections', conns >= 3 ? 'nt-danger' : conns === 2 ? 'nt-warning' : '')}
        {tile('Webhook Subscriptions', hooks.length, 'callback-based')}
        {tile('Cost Usage', d?.totalCost ?? 0, `of ${d?.maxCost ?? 10000} max`)}
      </div>
      {!d && !err ? <section className="card"><div className="adm-log-skel" aria-hidden="true">{[80, 60, 90, 70].map((w, k) => <span key={k} className="bp-skel" style={{ width: `${w}%` }} />)}</div></section> : d && <>
        {groups.length > 0 && <section className="card" aria-label="Active WebSocket Sessions">
          <h3 className="card-h"><i className="fas fa-network-wired" /> Active WebSocket Sessions</h3>
          <div className="notice notice-info"><i className="fas fa-lightbulb" /> <span><b>Tip:</b> Twitch limits you to 3 WebSocket connections. Your bot and YourChat each need their own session, so delete old sessions if you reach the limit.</span></div>
          {groups.map(([id, subs], i) => <div key={id} className="nt-sess">
            <div className="nt-sess-h"><div><p><b>Session Name:</b> {names[id] || `WebSocket Session ${i + 1}`}</p><p className="small muted nt-id"><b>Session ID:</b> <code>{id}</code></p></div><span className="pill pill-info">{subs.length} subscriptions</span></div>
            <SubTable rows={subs} me={me} busy={busy} onDel={(s) => setAsk({ kind: 'sub', id: s.id, transport: 'websocket' })} />
          </div>)}
        </section>}
        {stale.length > 0 && <section className="card nt-stale" aria-label="Disabled / Stale WebSocket Sessions">
          <h3 className="card-h"><i className="fas fa-exclamation-triangle" /> Disabled / Stale WebSocket Sessions</h3>
          <div className="notice notice-warn"><i className="fas fa-info-circle" /> <span><b>Note:</b> These sessions are no longer active and are safe to delete. They do not count toward your connection limits.</span></div>
          {stale.map(([id, subs], i) => { const name = names[id] || `WebSocket Session ${i + 1}`; return <div key={id} className="nt-sess">
            <div className="nt-sess-h"><div><p><b>Session Name:</b> {name}</p><p className="small muted nt-id"><b>Session ID:</b> <code>{id}</code></p></div>
              <button type="button" className="btn btn-danger cm-btn" disabled={busy === 'session'} onClick={() => setAsk({ kind: 'session', name, ids: subs.map((s) => s.id) })}><i className="fas fa-trash-alt" /> Delete All in Session</button></div>
            <div className="nt-dim"><SubTable rows={subs} me={me} busy={busy} onDel={(s) => setAsk({ kind: 'sub', id: s.id, transport: 'websocket' })} /></div>
          </div> })}
        </section>}
        {hooks.length > 0 && <section className="card" aria-label="Webhook Subscriptions">
          <h3 className="card-h"><i className="fas fa-link" /> Webhook Subscriptions</h3>
          <SubTable rows={hooks} me={me} busy={busy} hook onDel={(s) => setAsk({ kind: 'sub', id: s.id, transport: 'webhook' })} />
        </section>}
        {groups.length === 0 && stale.length === 0 && hooks.length === 0 && <section className="card cm-empty"><i className="fas fa-inbox fa-2x" /><p>No EventSub subscriptions found.</p></section>}
      </>}
      <section className="card" aria-label="Internal Websocket Connections">
        <h3 className="card-h"><i className="fas fa-plug" /> Internal Websocket Connections</h3>
        <p className="muted">This shows websocket clients connected to your API key on the internal BotOfTheSpecter websocket service.</p>
        {wsErr ? <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>Failed to load internal websocket data: {wsErr}</span></div>
          : ws === null ? <div className="adm-log-skel" aria-hidden="true">{[70, 50].map((w, k) => <span key={k} className="bp-skel" style={{ width: `${w}%` }} />)}</div>
          : ws.length === 0 ? <p className="cm-none">No active websocket clients for your API key.</p>
          : <><p><b>{ws.length}</b> active internal websocket client(s) for your API key.</p>
            <div className="bp-table cm-table"><table>
              <thead><tr><th>Client Name</th><th>Socket ID</th><th>Admin</th><th className="cm-c">Actions</th></tr></thead>
              <tbody>{ws.map((c, k) => { const sid = String(c.sid ?? c.id ?? c.connectionId ?? ''); return <tr key={sid || k}>
                <td data-l="Client Name">{c.name || c.client_name || c.clientName || 'Unknown Client'}</td>
                <td data-l="Socket ID" className="cm-wrap"><code>{sid}</code></td>
                <td data-l="Admin">{c.key_type === 'admin' ? <span className="pill pill-err">Admin</span> : <span className="pill pill-info">User</span>}</td>
                <td className="cm-c"><button type="button" className="btn btn-danger cm-btn" disabled={!sid || busy === sid} onClick={() => setAsk({ kind: 'ws', sid })}><i className="fas fa-times" /> Disconnect</button></td>
              </tr> })}</tbody>
            </table></div></>}
      </section>
      {ask && <Confirm title={ask.kind === 'ws' ? 'Disconnect client' : 'Delete subscriptions'} yes={ask.kind === 'ws' ? 'Disconnect' : 'Delete'} busy={!!busy} onYes={run} onClose={() => setAsk(null)}>
        {ask.kind === 'sub' ? <p>Are you sure you want to delete this subscription?</p>
          : ask.kind === 'session' ? <><p>Are you sure you want to delete all {ask.ids.length} subscriptions from "{ask.name}"?</p><p>This action cannot be undone.</p></>
          : <p>Disconnect this websocket client?</p>}
      </Confirm>}
    </div>
  )
}
