import { useState } from 'react'
import Modal from '../../components/Modal'
import { UrlReveal } from '../../components/UrlReveal'
import { Confirm, getJson, LoadErr, Loading, post, Switch, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/api_keys.php: intro card, Create New API Key (service,
// WebSocket access, Global access, and beta only: Admin / Mobile app / Dashboard
// access with a password), Existing API Keys table (key with show/copy, the
// switches, Sign-in, Regenerate, Delete). Keys load only on Reveal/Copy. App and
// Dashboard access sign in as the key's demo account, a users row made the first
// time either is switched on.
type Key = {
  service: string; websocket_access: number; websocket_global: number
  admin_access: number; app_access: number; dashboard_access: number; channel: string | null; password_set: number
}
type Access = { admin_access: number; app_access: number; dashboard_access: number; channel: string | null; password_set: number }
type R = { success: boolean; message?: string; warning?: string; api_key?: string | null; websocket_access?: number; websocket_global?: number; access?: Access | null }
// The sign-in form: shown in Create and in the per-key Sign-in dialog.
type SignIn = { admin: boolean; app: boolean; dash: boolean; demoName: string; password: string }
const API = '/api/api_keys.php'
const MASK = '••••••••••••••••'
const NEW_SIGNIN: SignIn = { admin: true, app: false, dash: false, demoName: '', password: '' }
// A demo account name from the service name: users.username rules.
const demoFrom = (service: string) => `${service.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 20)}_demo`.replace(/^_demo$/, 'demo')

function SignInFields({ v, set, channel, passwordSet }: { v: SignIn; set: (p: Partial<SignIn>) => void; channel: string | null; passwordSet: boolean }) {
  const needs = v.app || v.dash
  return (
    <>
      <div className="adm-sw"><Switch on={v.admin} label="Admin access" onChange={(x) => set({ admin: x })} /><div><strong>Admin access</strong><small className="muted">Use this key as an admin key, with access to every channel. Turn it off for a key that only signs in as its demo account.</small></div></div>
      <div className="adm-sw"><Switch on={v.app} label="Mobile app access" onChange={(x) => set({ app: x })} /><div><strong>Mobile app access</strong><small className="muted">Sign in to the mobile app with this key as the username and the password below. The app controls the key's demo account.</small></div></div>
      <div className="adm-sw"><Switch on={v.dash} label="Dashboard access" onChange={(x) => set({ dash: x })} /><div><strong>Dashboard access</strong><small className="muted">Sign in to the beta dashboard at /login/key with this key and the password below, as the key's demo account.</small></div></div>
      {needs && (
        <>
          {channel ? <p className="muted">Signs in as the demo account <strong>{channel}</strong>.</p> : (
            <label className="fld"><span className="fld-label">Demo account name</span>
              <input className="input" required pattern="[A-Za-z0-9_]{3,25}" maxLength={25} value={v.demoName} onChange={(e) => set({ demoName: e.target.value })} />
              <small className="muted">A demo account with this username is added to the users table and shows on User Management. 3 to 25 letters, numbers or underscores.</small></label>
          )}
          <label className="fld"><span className="fld-label">Password</span>
            <input className="input" type="password" autoComplete="new-password" minLength={6} required={!passwordSet} placeholder={passwordSet ? 'Leave blank to keep the current password' : 'At least 6 characters'} value={v.password} onChange={(e) => set({ password: e.target.value })} />
          </label>
        </>
      )}
    </>
  )
}

export default function ApiKeys() {
  const { data, err, reload, setData } = useLoad<{ keys: Key[]; error: string }>(API)
  const [service, setService] = useState(''), [wsA, setWsA] = useState(false), [wsG, setWsG] = useState(false), [creating, setCreating] = useState(false)
  const [si, setSi] = useState<SignIn>(NEW_SIGNIN)
  const [edit, setEdit] = useState<{ key: Key; v: SignIn } | null>(null), [saving, setSaving] = useState(false)
  const [shown, setShown] = useState<{ title: string; key: string } | null>(null)
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const fail = (x: unknown) => setToast({ kind: 'err', text: (x as Error).message })

  const create = async (e: React.FormEvent) => {
    e.preventDefault(); setCreating(true)
    try {
      const r = await post<R>(API, { create_key_access: 1, service, websocket_access: wsA ? 1 : 0, websocket_global: wsA && wsG ? 1 : 0, ...accessBody({ ...si, demoName: si.demoName || demoFrom(service) }) })
      if (!r.success) return setToast({ kind: 'err', text: r.message || 'An error occurred while creating the API key' })
      if (r.warning) setToast({ kind: 'err', text: r.warning })
      setService(''); setWsA(false); setWsG(false); setSi(NEW_SIGNIN); setShown({ title: 'API Key Created', key: r.api_key ?? '' }); await reload()
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
  const accessBody = (v: SignIn) => ({ admin_access: v.admin ? 1 : 0, app_access: v.app ? 1 : 0, dashboard_access: v.dash ? 1 : 0, demo_name: v.demoName.trim(), password: v.password })
  const formOf = (k: Key): SignIn => ({ admin: !!k.admin_access, app: !!k.app_access, dash: !!k.dashboard_access, demoName: k.channel ?? demoFrom(k.service), password: '' })
  const saveAccess = async (k: Key, v: SignIn) => {
    const r = await post<R>(API, { update_access: 1, service: k.service, ...accessBody(v) })
    if (!r.success || !r.access) { setToast({ kind: 'err', text: r.message || 'Could not update key access' }); return false }
    const a = r.access
    setData((d) => ({ ...d, keys: d.keys.map((x) => (x.service === k.service ? { ...x, ...a } : x)) }))
    setToast({ kind: 'ok', text: r.message || 'Key access updated' })
    return true
  }
  // Switching app or dashboard on needs a password (and a demo account name the first time), so it opens the dialog.
  const flip = async (k: Key, p: Partial<SignIn>) => {
    const v = { ...formOf(k), ...p }
    if ((v.app || v.dash) && (!k.channel || !k.password_set)) return setEdit({ key: k, v })
    setBusy(k.service)
    try { await saveAccess(k, v) } catch (x) { fail(x) } finally { setBusy('') }
  }
  const saveEdit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!edit) return
    setSaving(true)
    try { if (await saveAccess(edit.key, edit.v)) { setEdit(null); await reload() } } catch (x) { fail(x) } finally { setSaving(false) }
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
      {edit && (
        <Modal title={`Sign-in for ${edit.key.service}`} icon="fas fa-right-to-bracket" onClose={() => setEdit(null)}>
          <form className="adm-form" onSubmit={saveEdit}>
            <SignInFields v={edit.v} set={(p) => setEdit((x) => (x ? { ...x, v: { ...x.v, ...p } } : x))} channel={edit.key.channel} passwordSet={!!edit.key.password_set} />
            <div className="modal-acts">
              <button type="button" className="btn btn-ghost" onClick={() => setEdit(null)}>Cancel</button>
              <button type="submit" className="btn" disabled={saving}><i className="fas fa-save" /> Save</button>
            </div>
          </form>
        </Modal>
      )}
      <section className="card">
        <h2 className="card-h"><i className="fas fa-key" /> API Key Management</h2>
        <p className="muted">Manage admin API keys for different services. Mark a key for WebSocket access, and choose whether that access is global. A key can also sign in to the mobile app and the beta dashboard as its demo account, with a password you can change at any time.</p>
      </section>
      <section className="card">
        <h2 className="card-h">Create New API Key</h2>
        <form className="adm-form" onSubmit={create}>
          <label className="fld"><span className="fld-label">Service Name</span>
            <input className="input" required placeholder="Enter service name" value={service} onChange={(e) => setService(e.target.value)} />
            <small className="muted">Enter a unique name for the service that will use this API key (e.g. FreeStuff, GitHub, bots for the bot-host control API)</small></label>
          <div className="adm-sw"><Switch on={wsA} label="WebSocket access" onChange={(v) => { setWsA(v); if (!v) setWsG(false) }} /><div><strong>WebSocket access</strong><small className="muted">Allow this key to connect to the WebSocket server and to send events.</small></div></div>
          <div className="adm-sw"><Switch on={wsA && wsG} busy={!wsA} label="Global access" onChange={setWsG} /><div><strong>Global access</strong><small className="muted">Send this key's events to every channel and allow it to register as a global listener. Global access requires WebSocket access.</small></div></div>
          <SignInFields v={{ ...si, demoName: si.demoName || demoFrom(service) }} set={(p) => setSi((x) => ({ ...x, ...p }))} channel={null} passwordSet={false} />
          <div><button type="submit" className="btn" disabled={creating}><i className="fas fa-plus" /> Generate API Key</button></div>
        </form>
      </section>
      <section className="card">
        <h2 className="card-h">Existing API Keys</h2>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.error ? <div className="notice notice-error">{data.error}</div> : data.keys.length === 0 ? <div className="notice notice-info">No API keys found. Create one above.</div> : (
          <div className="bp-table adm-left"><table>
            <thead><tr><th>Service</th><th>API Key</th><th>WebSocket</th><th>Global</th><th>Admin</th><th>Mobile app</th><th>Dashboard</th><th>Actions</th></tr></thead>
            <tbody>{data.keys.map((k) => (
              <tr key={k.service}>
                <td><strong>{k.service}</strong></td>
                <td className="adm-key"><UrlReveal mask={MASK} label="API key" name={k.service} copied="API key copied." fail="Failed to copy to clipboard" fetchUrl={() => reveal(k.service)} toast={setToast} /></td>
                <td><span className="adm-lbl-m">WebSocket</span><Switch on={!!k.websocket_access} busy={busy === k.service} label={`WebSocket access for ${k.service}`} onChange={(v) => setWs(k, v, !!k.websocket_global)} /></td>
                <td><span className="adm-lbl-m">Global</span><Switch on={!!k.websocket_global} busy={busy === k.service || !k.websocket_access} label={`Global access for ${k.service}`} onChange={(v) => setWs(k, true, v)} /></td>
                <td><span className="adm-lbl-m">Admin</span><Switch on={!!k.admin_access} busy={busy === k.service} label={`Admin access for ${k.service}`} onChange={(v) => flip(k, { admin: v })} /></td>
                <td><span className="adm-lbl-m">Mobile app</span><Switch on={!!k.app_access} busy={busy === k.service} label={`Mobile app access for ${k.service}`} onChange={(v) => flip(k, { app: v })} /></td>
                <td><span className="adm-lbl-m">Dashboard</span><Switch on={!!k.dashboard_access} busy={busy === k.service} label={`Dashboard access for ${k.service}`} onChange={(v) => flip(k, { dash: v })} /></td>
                <td><div className="bp-acts">
                  {k.channel ? <small className="muted">Demo account <strong>{k.channel}</strong></small> : null}
                  <button type="button" className="btn btn-ghost" onClick={() => setEdit({ key: k, v: formOf(k) })}><i className="fas fa-right-to-bracket" /> Sign-in</button>
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
