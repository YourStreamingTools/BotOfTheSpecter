import { useEffect, useState } from 'react'
import Modal from '../../components/Modal'
import { UrlReveal } from '../../components/UrlReveal'
import { Confirm, getJson, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Res, type Toast } from '../ui'

// Port of dashboard/admin/websocket_clients.php: header (Refresh, last updated, 4 stats),
// Registered Users (search, masked keys, View Clients modal) and Global Listeners.
type Client = { name: string; sid: string; key_type?: string; is_admin?: boolean; service?: string; connected_at?: string; last_activity?: string }
type Reg = { ref: string; key_len: number; twitch_display_name: string; client_count: number; key_type: string; is_admin: boolean; service: string }
type Data = { api_error: boolean; last_updated: string; registered: Reg[]; global_listeners: Client[]; L: Record<string, string> }
type UserClients = { success: boolean; error?: string; ref: string; twitch_display_name: string; client_count: number; clients: Client[] }
const API = '/api/websocket_clients.php'
const clean = (n: string) => (n || '').toLowerCase().startsWith('global - ') ? n.substring(9) : (n || '')
const isAdmin = (c: { key_type?: string; is_admin?: boolean }) => c.key_type === 'admin' || (!c.key_type && !!c.is_admin)

export default function WebsocketClients() {
  const { data, err, reload } = useLoad<Data>(API)
  const [busy, setBusy] = useState(false), [failed, setFailed] = useState(false), [ovBusy, setOvBusy] = useState(false)
  const [q, setQ] = useState(''), [dq, setDq] = useState('')
  const [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  const [user, setUser] = useState<UserClients | null>(null), [listener, setListener] = useState<Client | null>(null)
  useEffect(() => { const t = setTimeout(() => setDq(q.trim().toLowerCase()), 200); return () => clearTimeout(t) }, [q])
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string, v?: string | number) => (L[k] ?? k).replace('%s', String(v ?? ''))
  const refresh = async () => { setBusy(true); try { await reload(); setFailed(false) } catch { setFailed(true) } finally { setBusy(false) } }
  const regTotal = data.registered.reduce((a, r) => a + r.client_count, 0)
  const rows = dq ? data.registered.filter((r) => r.twitch_display_name.toLowerCase().includes(dq)) : data.registered
  const badge = (c: { key_type?: string; is_admin?: boolean; service?: string }) => isAdmin(c) ? <span className="pill pill-err" title={c.service || undefined}>{l('badge_admin')}</span> : <span className="pill pill-info">{l('badge_user')}</span>
  const reveal = async (ref: string) => { const r = await getJson<{ success: boolean; api_key?: string; error?: string }>(`${API}?reveal=${ref}`); if (!r.api_key) throw new Error(r.error || 'Not found'); return r.api_key }
  const keyBox = (ref: string, len: number, name: string) => <UrlReveal mask={'\u2022'.repeat(Math.min(len, 16))} label={l('toggle_api_key_aria')} name={name} copied="API key copied." fetchUrl={() => reveal(ref)} toast={setToast} />
  const openUser = async (r: Reg) => {
    try { const d = await getJson<UserClients>(`${API}?user=${r.ref}`); if (!d.success) throw new Error(d.error); setUser({ ...d, ref: r.ref, twitch_display_name: r.twitch_display_name }) }
    catch (e) { setToast({ kind: 'err', text: l('swal_load_failed', (e as Error).message) }) }
  }
  const disconnect = (sid: string) => setAsk({ title: l('swal_disconnect_title'), text: l('swal_disconnect_text'), ok: l('swal_disconnect_confirm'), danger: true, run: async () => {
    try { const r = await post<Res>(API, { disconnect_client: 1, sid }); setToast({ kind: r.success ? 'ok' : 'err', text: r.success ? `${l('swal_disconnected_title')}: ${r.message}` : (r.message || l('swal_disconnect_failed')) }); if (r.success) { setUser(null); await reload() } }
    catch { setToast({ kind: 'err', text: l('swal_disconnect_failed') }) }
  } })
  const refreshOverlays = async (u: UserClients) => {
    setOvBusy(true)
    try { const r = await post<Res>(API, { refresh_overlays: 1, ref: u.ref }); setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? 'Refresh sent.' : 'Refresh failed.') }) }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Refresh failed.' }) }
    finally { setOvBusy(false) }
  }
  const stats: [string, number, string][] = [['stat_total', regTotal + data.global_listeners.length, 'info'], ['stat_registered', regTotal, 'accent'], ['stat_codes', data.registered.length, 'warn'], ['stat_listeners', data.global_listeners.length, 'ok']]

  return (
    <div className="cmds adm">
      <section className="card">
        <div className="card-head">
          <div><h2 className="card-h"><i className="fas fa-plug" /> {l('title')}</h2><p className="muted">{l('subtitle')}</p></div>
          <button type="button" className="btn" disabled={busy} onClick={refresh}><i className={`fas fa-sync-alt${busy ? ' fa-spin' : ''}`} /> {l('refresh')}</button>
        </div>
        {data.api_error ? <div className="notice notice-warn adm-gap"><i className="fas fa-exclamation-triangle" /> {l('api_warning')}</div>
          : <div className={`notice ${failed ? 'notice-error' : 'notice-info'} adm-gap`}>{failed ? `${l('swal_refresh_failed_title')}: ${l('swal_refresh_failed_text')}` : l('last_updated', new Date(data.last_updated).toLocaleString())}</div>}
        <div className="adm-stats">{stats.map(([k, v, tone]) => <div key={k} className={`adm-stat adm-stat-${tone}`}><span className="adm-stat-label">{l(k)}</span><span className="adm-stat-val">{v}</span></div>)}</div>
      </section>
      <section className="card">
        <h2 className="card-h"><i className="fas fa-users" /> {l('registered_heading')}</h2>
        <p className="muted adm-gap">{l('registered_desc')}</p>
        <div className="adm-inline adm-gap">
          <input className="input" type="search" value={q} placeholder={l('search_placeholder')} aria-label={l('search_aria')} onChange={(e) => setQ(e.target.value)} />
          {q && <button type="button" className="btn btn-ghost" aria-label={l('search_clear_aria')} title={l('search_clear_aria')} onClick={() => setQ('')}><i className="fas fa-times" /></button>}
          <span className="muted" aria-live="polite">{l(rows.length === 1 ? 'result_singular' : 'result_plural', rows.length)}</span>
        </div>
        {data.registered.length === 0 ? <div className="notice notice-info">{l('none_connected')}</div> :
          <div className="bp-table adm-left"><table>
            <thead><tr><th>{l('th_display_name')}</th><th>{l('th_api_key')}</th><th>{l('th_connected_clients')}</th><th>{l('th_actions')}</th></tr></thead>
            <tbody>{rows.map((r) => (
              <tr key={r.ref}>
                <td><strong>{r.twitch_display_name}</strong> {badge(r)}</td>
                <td className="adm-key">{keyBox(r.ref, r.key_len, r.twitch_display_name)}</td>
                <td><span className="pill pill-info">{l('client_count', r.client_count)}</span></td>
                <td><button type="button" className="btn btn-ghost" onClick={() => openUser(r)}><i className="fas fa-eye" /> {l('view_clients')}</button></td>
              </tr>))}</tbody>
          </table></div>}
      </section>
      {data.global_listeners.length > 0 && <section className="card">
        <h2 className="card-h"><i className="fas fa-globe" /> {l('global_heading')}</h2>
        <p className="muted adm-gap">{l('global_desc')}</p>
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_listener')}</th><th>{l('th_status')}</th><th>{l('th_socket_id')}</th><th>{l('th_actions')}</th></tr></thead>
          <tbody>{data.global_listeners.map((g) => (
            <tr key={g.sid}>
              <td><strong><i className="fas fa-broadcast-tower adm-ok" /> {clean(g.name)}</strong></td>
              <td><span className="pill pill-ok"><i className="fas fa-circle adm-dot" /> {l('status_active')}</span></td>
              <td className="adm-code">{g.sid}</td>
              <td><div className="bp-acts">
                <button type="button" className="btn btn-ghost" onClick={() => setListener(g)}><i className="fas fa-info" /> {l('details')}</button>
                <button type="button" className="btn btn-danger" onClick={() => disconnect(g.sid)}><i className="fas fa-times" /> {l('disconnect')}</button>
              </div></td>
            </tr>))}</tbody>
        </table></div>
      </section>}
      {user && <Modal title={`${l('modal_title')} ${user.twitch_display_name}`} icon="fas fa-users" xl onClose={() => setUser(null)}>
        <div className="adm-modal-scroll">
          <div className="adm-box adm-gap"><strong>{l('modal_api_key_label')}</strong> {keyBox(user.ref, 32, user.twitch_display_name)}<p className="muted">{user.client_count} {l('modal_connected_clients_suffix')}</p></div>
          <div className="bp-table adm-left"><table>
            <thead><tr><th>{l('th_client_name')}</th><th>{l('th_socket_id')}</th><th>{l('th_admin')}</th><th>{l('th_connected_at')}</th><th>{l('th_last_activity')}</th><th>{l('th_actions')}</th></tr></thead>
            <tbody>{user.clients.map((c) => (
              <tr key={c.sid}><td>{c.name}</td><td className="adm-code adm-nowrap">{c.sid}</td><td>{badge(c)}</td><td className="adm-nowrap">{c.connected_at || l('not_available')}</td><td className="adm-nowrap">{c.last_activity || l('not_available')}</td>
                <td className="adm-nowrap"><button type="button" className="btn btn-danger" onClick={() => disconnect(c.sid)}><i className="fas fa-times" /> {l('disconnect')}</button></td></tr>))}</tbody>
          </table></div>
        </div>
        <div className="modal-acts">
          <button type="button" className="btn" disabled={ovBusy} onClick={() => refreshOverlays(user)}><i className={`fas fa-sync-alt${ovBusy ? ' fa-spin' : ''}`} /> Refresh overlays</button>
          <button type="button" className="btn btn-ghost" data-autofocus onClick={() => setUser(null)}>{l('close')}</button>
        </div>
      </Modal>}
      {listener && <Modal title={l('swal_listener_title')} icon="fas fa-info-circle" onClose={() => setListener(null)}>
        <table className="adm-kv"><tbody>
          <tr><th scope="row">{l('swal_listener_name')}</th><td>{listener.name}</td></tr>
          <tr><th scope="row">{l('swal_socket_id')}</th><td className="adm-code">{listener.sid}</td></tr>
          <tr><th scope="row">{l('swal_type')}</th><td>{l('swal_type_value')}</td></tr>
          <tr><th scope="row">{l('swal_permissions')}</th><td>{l('swal_permissions_value')}</td></tr>
        </tbody></table>
        <div className="modal-acts"><button type="button" className="btn" data-autofocus onClick={() => setListener(null)}>{l('close')}</button></div>
      </Modal>}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
