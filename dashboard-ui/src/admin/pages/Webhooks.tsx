import { useState } from 'react'
import Modal from '../../components/Modal'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/webhooks.php: intro card, Create / Edit a Webhook form
// (same fields and conditional rules), Existing Webhooks table with Copy URL,
// Send a test, Edit, Enable/Disable, Regenerate secret and Delete.
type Wh = { id: number; slug: string; name: string; service: string; event_name: string; scope: string; target_username: string; verify_mode: string; secret_last4: string; secret_header: string; enabled: number; received_count: number; last_received_at: string | null; url: string; key_state: string | null; key_label: string | null; key_help: string | null; last_status: string | null; last_status_label: string | null; last_error: string | null; last_attempt_at: string | null }
type R = { success: boolean; message?: string; secret?: string | null; secret_header?: string; url?: string }
type F = { id: string; name: string; slug: string; service: string; event: string; scope: string; target: string; verify: string; header: string; secret: string }
const API = '/api/webhooks.php'
const EMPTY: F = { id: '', name: '', slug: '', service: '', event: '', scope: 'channel', target: '', verify: 'secret', header: '', secret: '' }
const hex = (n: number) => Array.from(crypto.getRandomValues(new Uint8Array(n)), (b) => b.toString(16).padStart(2, '0')).join('')
const SCOPES: [string, string][] = [['channel', 'Channel'], ['global', 'Global'], ['discord_logs', 'Discord Logs']]
const MODES: [string, string][] = [['secret', 'Shared secret'], ['hmac', 'HMAC signature'], ['none', 'None (rely on the secret URL)']]

export default function Webhooks() {
  const { data, err, reload } = useLoad<{ webhooks: Wh[]; discord_log_event: string }>(API)
  const [f, setF] = useState<F>(EMPTY), [saving, setSaving] = useState(false), [busy, setBusy] = useState(0)
  const [shown, setShown] = useState<{ title: string; message?: string; url?: string; secret?: string | null; header?: string } | null>(null)
  const [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)
  const logEvent = data?.discord_log_event ?? 'WEBHOOK_LOG'
  const none = f.verify === 'none', logs = f.scope === 'discord_logs'
  const set = (p: Partial<F>) => setF((o) => {
    const n = { ...o, ...p }
    if (p.scope !== undefined) n.event = n.scope === 'discord_logs' ? logEvent : (o.event === logEvent ? '' : n.event)
    return n
  })
  const eleven = () => { if (f.service.trim().toLowerCase() === 'elevenlabs') set({ verify: 'hmac', header: 'ElevenLabs-Signature', ...(f.scope === 'channel' ? { scope: 'discord_logs' } : {}) }) }
  const fail = (x: unknown) => setToast({ kind: 'err', text: (x as Error).message })
  const act = async (body: Record<string, string | number>, ok: (r: R) => void | Promise<void>) => {
    try { const r = await post<R>(API, body); if (!r.success) return setToast({ kind: 'err', text: r.message || 'Request failed. Please try again.' }); await ok(r) } catch (x) { fail(x) }
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setSaving(true)
    const body: Record<string, string | number> = { name: f.name, service: f.service, event_name: logs ? logEvent : f.event, scope: f.scope, target_username: f.target, verify_mode: f.verify, secret_header: f.header, secret: none ? '' : f.secret }
    if (f.id) Object.assign(body, { update_webhook: 1, webhook_id: f.id }); else Object.assign(body, { create_webhook: 1, slug: f.slug })
    await act(body, async (r) => { setShown({ title: f.id ? 'Webhook Updated' : 'Webhook Created', message: r.message, url: r.url, secret: r.secret, header: r.secret_header }); setF(EMPTY); await reload() })
    setSaving(false)
  }
  const edit = (w: Wh) => { setF({ id: String(w.id), name: w.name, slug: w.slug, service: w.service, event: w.event_name, scope: w.scope, target: w.target_username, verify: w.verify_mode, header: w.secret_header, secret: '' }); document.getElementById('wh-form')?.scrollIntoView({ behavior: 'smooth' }) }
  const copy = async (u: string) => { try { await navigator.clipboard.writeText(u); setToast({ kind: 'ok', text: 'URL copied.' }) } catch { setToast({ kind: 'err', text: 'Failed to copy to clipboard.' }) } }

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {shown && (
        <Modal title={shown.title} icon="fas fa-satellite-dish" onClose={() => setShown(null)}>
          {shown.message && <p>{shown.message}</p>}
          {shown.url && <div className="fld"><span className="fld-label">Receiver URL</span><code className="adm-box adm-code">{shown.url}</code></div>}
          {shown.secret && <>
            <div className="fld"><span className="fld-label">Send it in this header</span><code className="adm-box adm-code">{shown.header}</code></div>
            <div className="fld"><span className="fld-label">Secret</span><code className="adm-box adm-code">{shown.secret}</code></div>
            <div className="notice notice-warn"><span>Copy this secret now - it will not be shown again.</span></div>
          </>}
          <div className="modal-acts">
            {shown.secret && <button type="button" className="btn btn-ghost" onClick={() => copy(shown.secret!)}><i className="fas fa-copy" /> Copy secret</button>}
            <button type="button" className="btn" onClick={() => setShown(null)}>OK</button>
          </div>
        </Modal>
      )}
      <section className="card">
        <h2 className="card-h"><i className="fas fa-satellite-dish" /> Custom Webhooks</h2>
        <p className="muted">Create inbound webhook receivers without editing or restarting the API server. External services POST to the generated URL; the payload is verified and forwarded to the WebSocket as the event you configure.</p>
      </section>
      <section className="card" id="wh-form">
        <h2 className="card-h">{f.id ? 'Edit Webhook' : 'Create a Webhook'}</h2>
        <form className="adm-form" onSubmit={submit}>
          <label className="fld"><span className="fld-label">Name</span><input className="input" required placeholder="e.g. My Donation Service" value={f.name} onChange={(e) => set({ name: e.target.value })} /></label>
          <div className="fld"><label className="fld-label" htmlFor="f_slug">Slug</label>
            <div className="adm-inline"><input id="f_slug" className="input adm-code" disabled={!!f.id} placeholder="e.g. my-donations" value={f.slug} onChange={(e) => set({ slug: e.target.value })} />
              {!f.id && <button type="button" className="btn btn-ghost" onClick={() => set({ slug: hex(16) })}><i className="fas fa-dice" /> Generate secret URL</button>}</div>
            <small className="muted">{none ? 'With Secret URL, the slug is the secret. Generate a random value so the receiver URL cannot be guessed. It cannot be changed after creation.' : 'Used in the URL: /webhook/{slug}. Lowercase letters, numbers and hyphens only. Cannot be changed after creation.'}</small></div>
          <label className="fld"><span className="fld-label">Service</span><input className="input" required placeholder="e.g. MyDonations" value={f.service} onChange={(e) => set({ service: e.target.value })} onBlur={eleven} />
            <small className="muted">A label for the source. Tags the forwarded event and identifies the service for global webhooks.</small></label>
          {!logs && <label className="fld"><span className="fld-label">WebSocket Event</span><input className="input adm-code" required placeholder="e.g. CUSTOM_DONATION" value={f.event} onChange={(e) => set({ event: e.target.value })} />
            <small className="muted">The event name emitted to clients. Automatically uppercased with spaces converted to underscores.</small></label>}
          <label className="fld"><span className="fld-label">Routing</span><select className="input" value={f.scope} onChange={(e) => set({ scope: e.target.value })}>{SCOPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            <small className="muted">Channel routes to a single streamer. Global routes to admin global-listeners. Discord Logs posts only to the operator Discord webhook-logs channel. Global and Discord Logs need an admin API key whose service matches this service.</small></label>
          {f.scope === 'channel' && <label className="fld"><span className="fld-label">Target Channel (username)</span><input className="input" placeholder="e.g. botofthespecter" value={f.target} onChange={(e) => set({ target: e.target.value })} />
            <small className="muted">The streamer whose overlays/bot receive the event. Required for channel-scoped webhooks.</small></label>}
          <label className="fld"><span className="fld-label">Verification</span><select className="input" value={f.verify} onChange={(e) => set({ verify: e.target.value })}>{MODES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
          {!none && <label className="fld"><span className="fld-label">Secret Header</span><input className="input adm-code" placeholder={f.verify === 'hmac' ? 'X-Webhook-Signature' : 'X-Webhook-Secret'} value={f.header} onChange={(e) => set({ header: e.target.value })} />
            <small className="muted">The HTTP header the sender uses. Defaults to X-Webhook-Secret (or X-Webhook-Signature for HMAC). Use ElevenLabs-Signature for ElevenLabs.</small>
            <small className="muted">OpenAI: choose HMAC signature, set the header to webhook-signature and paste the whsec_ signing secret OpenAI gives you. Use the Discord logs scope for a Discord-only notice.</small></label>}
          {!none && <div className="fld"><label className="fld-label" htmlFor="f_secret">Shared secret (optional)</label>
            <div className="adm-inline"><input id="f_secret" className="input adm-code" autoComplete="off" placeholder="Paste the provider secret, or leave blank to generate one" value={f.secret} onChange={(e) => set({ secret: e.target.value })} />
              <button type="button" className="btn btn-ghost" onClick={() => set({ secret: hex(24) })}><i className="fas fa-dice" /> Generate</button></div>
            <small className="muted">Paste the secret from the provider (ElevenLabs generates theirs). Leave blank to mint a new secret.</small></div>}
          <div className="row-btns">
            <button type="submit" className="btn" disabled={saving}><i className={f.id ? 'fas fa-save' : 'fas fa-plus'} /> {f.id ? 'Save Changes' : 'Create Webhook'}</button>
            {f.id && <button type="button" className="btn btn-ghost" onClick={() => setF(EMPTY)}>Cancel</button>}
          </div>
        </form>
      </section>
      <section className="card">
        <h2 className="card-h">Existing Webhooks</h2>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.webhooks.length === 0 ? <div className="notice notice-info">No webhooks created yet.</div> : (
          <div className="bp-table adm-left adm-wide"><table>
            <thead><tr><th>Name</th><th>Receiver URL</th><th>Routing</th><th>Verification</th><th>Enabled</th><th>Received</th><th>Delivery</th><th>Actions</th></tr></thead>
            <tbody>{data.webhooks.map((w) => (
              <tr key={w.id}>
                <td><strong>{w.name}</strong><div className="muted adm-sub">{w.service} · <code>{w.event_name}</code></div></td>
                <td className="adm-key"><div className="adm-inline"><code className="adm-code adm-url">{w.url}</code><button type="button" className="btn btn-ghost" title="Copy URL" aria-label={`Copy URL for ${w.name}`} onClick={() => copy(w.url)}><i className="fas fa-copy" /></button></div></td>
                <td>{w.scope === 'global' ? <span className="pill pill-info">Global</span> : w.scope === 'discord_logs' ? <span className="pill pill-warn">Discord Logs</span> : <><span className="pill pill-info">Channel</span><div className="muted adm-sub">{w.target_username}</div></>}</td>
                <td><code>{w.verify_mode}</code>{w.secret_last4 && <div className="muted adm-sub adm-code">••••{w.secret_last4}</div>}</td>
                <td><span className={`pill ${w.enabled ? 'pill-ok' : 'pill-off'}`}>{w.enabled ? 'Enabled' : 'Disabled'}</span></td>
                <td><strong>{w.received_count}</strong><div className="muted adm-sub">{w.last_received_at ?? 'Never'}</div></td>
                <td>
                  {w.key_state && <span className={`pill ${w.key_state === 'ready' ? 'pill-ok' : 'pill-err'}`} title={w.key_help ?? ''}>{w.key_label}</span>}{' '}
                  {w.last_status ? <><span className={`pill ${w.last_status === 'ok' ? 'pill-ok' : 'pill-err'}`}>{w.last_status_label}</span>
                    {w.last_error && <div className="muted adm-sub">{w.last_error}</div>}{w.last_attempt_at && <div className="muted adm-sub">{w.last_attempt_at}</div>}</> : <span className="muted adm-sub">No attempts yet</span>}
                </td>
                <td><div className="bp-acts">
                  <button type="button" className="btn btn-ghost" title="Send a test" aria-label={`Send a test to ${w.name}`} disabled={busy === w.id} onClick={async () => { setBusy(w.id); await act({ test_webhook: 1, webhook_id: w.id }, async (r) => { setShown({ title: 'Test Sent', message: r.message }); await reload() }); setBusy(0) }}><i className="fas fa-paper-plane" /></button>
                  <button type="button" className="btn btn-ghost" title="Edit" aria-label={`Edit ${w.name}`} onClick={() => edit(w)}><i className="fas fa-edit" /></button>
                  <button type="button" className="btn btn-ghost" title={w.enabled ? 'Disable' : 'Enable'} aria-label={`${w.enabled ? 'Disable' : 'Enable'} ${w.name}`} onClick={() => act({ toggle_webhook: 1, webhook_id: w.id, enabled: w.enabled ? 0 : 1 }, reload)}><i className={`fas ${w.enabled ? 'fa-toggle-on' : 'fa-toggle-off'}`} /></button>
                  {w.verify_mode !== 'none' && <button type="button" className="btn btn-ghost" title="Regenerate secret" aria-label={`Regenerate secret for ${w.name}`} onClick={() => setAsk({ title: 'Regenerate secret?', text: <p>The old secret for "{w.name}" will stop working immediately.</p>, ok: 'Regenerate', danger: true, run: () => act({ regenerate_secret: 1, webhook_id: w.id }, async (r) => { setShown({ title: 'New Secret', message: r.message, secret: r.secret, header: r.secret_header }); await reload() }) })}><i className="fas fa-sync" /></button>}
                  <button type="button" className="btn btn-danger" title="Delete" aria-label={`Delete ${w.name}`} onClick={() => setAsk({ title: 'Delete webhook?', text: <p>This will permanently delete "{w.name}".</p>, ok: 'Delete', danger: true, run: () => act({ delete_webhook: 1, webhook_id: w.id }, async (r) => { setToast({ kind: 'ok', text: r.message || 'Webhook deleted.' }); await reload() }) })}><i className="fas fa-trash" /></button>
                </div></td>
              </tr>))}</tbody>
          </table></div>
        ))}
      </section>
    </div>
  )
}
