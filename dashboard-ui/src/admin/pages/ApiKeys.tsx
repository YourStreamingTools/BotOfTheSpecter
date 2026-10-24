import { useState } from 'react'
import Modal from '../../components/Modal'
import { UrlReveal } from '../../components/UrlReveal'
import { Confirm, getJson, LoadErr, Loading, post, Switch, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/api_keys.php: intro card, Create New API Key (service,
// WebSocket access, Global access), Existing API Keys table (key with show/copy,
// WebSocket and Global switches, Regenerate, Delete). Keys load only on Reveal/Copy.
type Key = { service: string; websocket_access: number; websocket_global: number }
type R = { success: boolean; message?: string; api_key?: string | null; websocket_access?: number; websocket_global?: number }
const API = '/api/api_keys.php'
const MASK = '••••••••••••••••'

export default function ApiKeys() {
  const { data, err, reload, setData } = useLoad<{ keys: Key[]; error: string }>(API)
  const [service, setService] = useState(''), [wsA, setWsA] = useState(false), [wsG, setWsG] = useState(false), [creating, setCreating] = useState(false)
  const [shown, setShown] = useState<{ title: string; key: string } | null>(null)
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const fail = (x: unknown) => setToast({ kind: 'err', text: (x as Error).message })

  const create = async (e: React.FormEvent) => {
    e.preventDefault(); setCreating(true)
    try {
      const r = await post<R>(API, { create_key: 1, service, websocket_access: wsA ? 1 : 0, websocket_global: wsA && wsG ? 1 : 0 })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while creating the API key' })
      setService(''); setWsA(false); setWsG(false); setShown({ title: 'API Key Created', key: r.api_key ?? '' }); await reload()
    } catch (x) { fail(x) } finally { setCreating(false) }
  }
  const setWs = async (k: Key, access: boolean, global: boolean) => {
    setBusy(k.service)
    try {
      const r = await post<R>(API, { update_ws: 1, service: k.service, websocket_access: access ? 1 : 0, websocket_global: access && global ? 1 : 0 })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while updating WebSocket access' })
      setData((d) => ({ ...d, keys: d.keys.map((x) => (x.service === k.service ? { ...x, websocket_access: r.websocket_access ?? 0, websocket_global: r.websocket_global ?? 0 } : x)) }))
      setToast({ kind: 'ok', text: r.message || 'WebSocket access updated' })
    } catch (x) { fail(x) } finally { setBusy('') }
  }
  const regen = (k: Key) => setAsk({
    title: 'Regenerate API Key?', text: <p>Are you sure you want to regenerate the API key for "{k.service}"? The old key will stop working immediately.</p>, ok: 'Yes, regenerate it', danger: true,
    run: async () => {
      try {
        const r = await post<R>(API, { regenerate_key: 1, service: k.service })
        if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while regenerating the API key' })
        setShown({ title: 'API Key Regenerated', key: r.api_key ?? '' }); await reload()
      } catch (x) { fail(x) }
    },
  })
  const del = (k: Key) => setAsk({
    title: 'Delete API Key?', text: <p>Are you sure you want to delete the API key for "{k.service}"? This action cannot be undone.</p>, ok: 'Yes, delete it', danger: true,
    run: async () => {
      try {
        const r = await post<R>(API, { delete_key: 1, service: k.service })
        if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while deleting the API key' })
        setData((d) => ({ ...d, keys: d.keys.filter((x) => x.service !== k.service) })); setToast({ kind: 'ok', text: r.message || 'Deleted' })
      } catch (x) { fail(x) }
    },
  })
  const reveal = async (svc: string) => {
    const r = await getJson<R>(`${API}?reveal=${encodeURIComponent(svc)}`)
    if (!r.success || !r.api_key) throw new Error(r.message || 'Could not load the key.')
    return r.api_key
  }

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {shown && (
        <Modal title={shown.title} icon="fas fa-key" onClose={() => setShown(null)}>
          <p>Your new API key:</p>
          <code className="adm-box adm-code">{shown.key}</code>
          <div className="notice notice-warn"><span>Make sure to copy this key now. You won't be able to see it again!</span></div>
          <p className="muted">You can view this key anytime from the table below.</p>
          <div className="modal-acts">
            <button type="button" className="btn btn-ghost" onClick={async () => { try { await navigator.clipboard.writeText(shown.key); setToast({ kind: 'ok', text: 'API key copied.' }) } catch { setToast({ kind: 'err', text: 'Failed to copy to clipboard' }) } }}><i className="fas fa-copy" /> Copy</button>
            <button type="button" className="btn" onClick={() => setShown(null)}>OK</button>
          </div>
        </Modal>
      )}
      <section className="card">
        <h2 className="card-h"><i className="fas fa-key" /> API Key Management</h2>
        <p className="muted">Manage admin API keys for different services. Mark a key for WebSocket access, and choose whether that access is global.</p>
      </section>
      <section className="card">
        <h2 className="card-h">Create New API Key</h2>
        <form className="adm-form" onSubmit={create}>
          <label className="fld"><span className="fld-label">Service Name</span>
            <input className="input" required placeholder="Enter service name" value={service} onChange={(e) => setService(e.target.value)} />
            <small className="muted">Enter a unique name for the service that will use this API key (e.g. FreeStuff, GitHub, bots for the bot-host control API)</small></label>
          <div className="adm-sw"><Switch on={wsA} label="WebSocket access" onChange={(v) => { setWsA(v); if (!v) setWsG(false) }} /><div><strong>WebSocket access</strong><small className="muted">Allow this key to connect to the WebSocket server and to send events.</small></div></div>
          <div className="adm-sw"><Switch on={wsA && wsG} busy={!wsA} label="Global access" onChange={setWsG} /><div><strong>Global access</strong><small className="muted">Send this key's events to every channel and allow it to register as a global listener. Global access requires WebSocket access.</small></div></div>
          <div><button type="submit" className="btn" disabled={creating}><i className="fas fa-plus" /> Generate API Key</button></div>
        </form>
      </section>
      <section className="card">
        <h2 className="card-h">Existing API Keys</h2>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.error ? <div className="notice notice-error">{data.error}</div> : data.keys.length === 0 ? <div className="notice notice-info">No API keys found. Create one above.</div> : (
          <div className="bp-table adm-left"><table>
            <thead><tr><th>Service</th><th>API Key</th><th>WebSocket</th><th>Global</th><th>Actions</th></tr></thead>
            <tbody>{data.keys.map((k) => (
              <tr key={k.service}>
                <td><strong>{k.service}</strong></td>
                <td className="adm-key"><UrlReveal mask={MASK} label="API key" name={k.service} copied="API key copied." fail="Failed to copy to clipboard" fetchUrl={() => reveal(k.service)} toast={setToast} /></td>
                <td><span className="adm-lbl-m">WebSocket</span><Switch on={!!k.websocket_access} busy={busy === k.service} label={`WebSocket access for ${k.service}`} onChange={(v) => setWs(k, v, !!k.websocket_global)} /></td>
                <td><span className="adm-lbl-m">Global</span><Switch on={!!k.websocket_global} busy={busy === k.service || !k.websocket_access} label={`Global access for ${k.service}`} onChange={(v) => setWs(k, true, v)} /></td>
                <td><div className="bp-acts">
                  <button type="button" className="btn btn-ghost" onClick={() => regen(k)}><i className="fas fa-sync" /> Regenerate</button>
                  <button type="button" className="btn btn-danger" onClick={() => del(k)}><i className="fas fa-trash" /> Delete</button>
                </div></td>
              </tr>))}</tbody>
          </table></div>
        ))}
      </section>
    </div>
  )
}
