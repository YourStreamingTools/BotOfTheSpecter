import { useEffect, useState } from 'react'
import { getJson } from '../ui'

// Port of dashboard/admin/event_sub.php: header card (Refresh, description, last
// updated, 8 stat tiles) and the Per-user Twitch Connection Status table.
type U = { id: number; username: string; display_name: string; twitch_user_id: string; ok: boolean; http_code: number; error: string | null; total: number; total_cost: number; max_cost: number; enabled_ws_subs: number; disabled_ws_subs: number; webhook_subs: number; enabled_connections: number; disabled_connections: number }
type S = { users_scanned: number; healthy_users: number; users_with_errors: number; users_with_active_ws: number; total_enabled_connections: number; total_enabled_ws_subs: number; total_disabled_ws_subs: number; total_webhook_subs: number }
type D = { success: boolean; generated_at?: string; summary?: S; users?: U[] }
const API = '/api/event_sub.php?refresh_data=1'

const status = (u: U) => !u.ok ? <span className="pill pill-err">Error</span>
  : u.enabled_connections >= 3 ? <span className="pill pill-warn">High Usage</span>
  : u.enabled_connections > 0 ? <span className="pill pill-ok">Connected</span>
  : <span className="pill pill-info">No Active Connections</span>

export default function EventSub() {
  const [d, setD] = useState<D | null>(null), [busy, setBusy] = useState(true), [failed, setFailed] = useState(false)
  const load = async () => { setBusy(true); try { const r = await getJson<D>(API); setD(r); setFailed(!r.success) } catch { setFailed(true) } finally { setBusy(false) } }
  useEffect(() => { void load() }, [])
  const s = d?.summary
  const tiles: [string, keyof S, string][] = [['Users Scanned', 'users_scanned', 'accent'], ['Healthy Users', 'healthy_users', 'ok'], ['Users With Active WS', 'users_with_active_ws', 'warn'], ['Users With Errors', 'users_with_errors', 'err'], ['Enabled WS Connections', 'total_enabled_connections', ''], ['Enabled WS Subs', 'total_enabled_ws_subs', ''], ['Disabled WS Subs', 'total_disabled_ws_subs', ''], ['Webhook Subs', 'total_webhook_subs', '']]
  const users = d?.users ?? []

  return (
    <div className="cmds adm">
      <section className="card">
        <div className="card-head">
          <h2 className="card-h"><i className="fas fa-bell" /> EventSub Connections</h2>
          <button type="button" className="btn" disabled={busy} onClick={load}><i className={`fas fa-sync-alt${busy ? ' fa-spin' : ''}`} /> {busy ? 'Refreshing...' : 'Refresh'}</button>
        </div>
        <p className="muted adm-gap">Admin overview of Twitch EventSub connections for all users with stored bot tokens.</p>
        <div className={`notice ${failed ? 'notice-error' : 'notice-info'} adm-gap`} aria-busy={busy}>
          {failed ? <span>Failed to load data.</span> : d?.generated_at ? <span>Last Updated: {new Date(d.generated_at).toLocaleString()}</span> : <span className="bp-skel" />}
        </div>
        <div className="adm-stats" aria-busy={busy && !s}>
          {tiles.map(([label, k, tone]) => (
            <div key={k} className={`adm-stat${tone ? ' adm-stat-' + tone : ''}`}><span className="adm-stat-label">{label}</span><span className="adm-stat-val">{s ? s[k] ?? 0 : <span className="bp-skel" />}</span></div>
          ))}
        </div>
      </section>
      <section className="card">
        <h2 className="card-h">Per-user Twitch Connection Status</h2>
        <div className="bp-table adm-left adm-wide"><table>
          <thead><tr><th>User</th><th>Twitch ID</th><th>Status</th><th>Connections</th><th>Enabled WS</th><th>Disabled WS</th><th>Webhook</th><th>Cost</th><th>Error</th></tr></thead>
          <tbody aria-busy={busy && !d}>
            {!d && !failed && Array.from({ length: 6 }, (_, i) => <tr key={i} aria-hidden="true"><td colSpan={9}><span className="bp-skel bp-skel-w" /></td></tr>)}
            {failed && <tr><td colSpan={9} className="adm-err-cell">Failed to load EventSub data.</td></tr>}
            {d && !failed && users.length === 0 && <tr><td colSpan={9} className="muted">No users found.</td></tr>}
            {!failed && users.map((u) => (
              <tr key={u.id}>
                <td><strong>{u.display_name || u.username || 'Unknown'}</strong>{u.username && <div className="muted adm-sub">@{u.username}</div>}</td>
                <td className="adm-code">{u.twitch_user_id || 'Unknown'}</td>
                <td>{status(u)}</td>
                <td><span className="adm-lbl-m">Connections</span>{u.enabled_connections}{u.disabled_connections > 0 ? ` (+${u.disabled_connections} disabled)` : ''}</td>
                <td><span className="adm-lbl-m">Enabled WS</span>{u.enabled_ws_subs}</td>
                <td><span className="adm-lbl-m">Disabled WS</span>{u.disabled_ws_subs}</td>
                <td><span className="adm-lbl-m">Webhook</span>{u.webhook_subs}</td>
                <td><span className="adm-lbl-m">Cost</span>{u.ok ? `${u.total_cost}/${u.max_cost}` : '-'}</td>
                <td className={u.ok ? 'muted' : 'adm-err-cell'}>{u.ok ? '-' : (u.error || `HTTP ${u.http_code || 0}`)}</td>
              </tr>))}
          </tbody>
        </table></div>
      </section>
    </div>
  )
}
