import { useEffect, useState } from 'react'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'
import { Confirm } from './insights/shared'
import { TokenField, Pill } from './tokenField'

// Port of dashboard/streamlabs.php. Endpoint: public/api/streamlabs/streamlabs.php
// (scripts/sync-live-api.sh). The OAuth callback is the live page (its registered
// redirect URI), so linking happens there. Unlink is a POST here.
const API = '/api/streamlabs/streamlabs.php'
type Don = { name: string | null; currency: string; amount: number | string; message: string | null; created_at: number | null; created_at_formatted: string }
type P = { success: boolean; error?: string; linked: boolean; donations: Don[]; donations_ok: boolean; socket_token: string | null; access_token: string | null; act_as: boolean; link_url: string }
const money = (a: number | string, c: string) => { const n = Number(a); if (!isFinite(n)) return `${c} ${a}`; try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format(n) } catch { return `${c} ${n.toFixed(2)}` } }

export default function StreamLabs() {
  const [p, setP] = useState<P | null>(null), [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState(false), [busy, setBusy] = useState(false)
  const load = () => get<P>(API, 40000).then((r) => r.success === false ? setErr(r.error || 'StreamLabs could not load right now.') : (setErr(''), setP(r))).catch(() => setErr('StreamLabs could not load right now.'))
  useEffect(() => { load() }, [])
  const unlink = async () => {
    setBusy(true)
    try { const r = await send<{ success: boolean; message?: string; error?: string }>(API, { action: 'unlink' }); setToast(r.success ? { kind: 'ok', text: r.message || 'StreamLabs account successfully unlinked.' } : { kind: 'err', text: r.error || 'Failed to unlink StreamLabs account.' }); if (r.success) await load() }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Failed to unlink StreamLabs account.' }) }
    setBusy(false); setAsk(false)
  }
  const dons = (p?.donations ?? []).slice().sort((a, b) => (b.created_at ?? 0) - (a.created_at ?? 0))
  return <div className="page sl-page">
    <ToastBar t={toast} onClose={() => setToast(null)} />
    <div className="notice notice-ok"><i className="fas fa-circle-check" /> <span><b>StreamLabs API Enabled.</b> We have been approved and the API is unlocked - you may connect away and enjoy real-time notices about donations. If you have any issues while this is still under review and testing, please log a support ticket on our Discord server.</span></div>
    {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
    {!p && !err && <section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading StreamLabs...</p></section>}
    {p && <>
      <section className="card">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-gift" /> StreamLabs Integration</h3>
          <div className="tk-pills"><Pill k={p.linked ? 'pill-ok' : 'pill-err'}>{p.linked ? 'Linked' : 'Not Linked'}</Pill>
            {p.linked && !p.act_as && <button type="button" className="btn btn-danger" onClick={() => setAsk(true)}><i className="fas fa-unlink" /> Unlink</button>}</div>
        </div>
        {p.linked ? <p>Your StreamLabs account is successfully linked to your profile and ready to track donations.</p> : <>
          <p>Connect your StreamLabs account to enable donation tracking and integration features.</p>
          <p className="muted">Link your StreamLabs account to automatically track and display donations on your dashboard.</p>
          {p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>Act As mode is active. Linking StreamLabs is disabled for acting users.</span></div>
            : <><div className="row-btns"><a className="btn" href={p.link_url}><i className="fas fa-link" /> Link StreamLabs Account</a></div>
              <p className="muted small">Linking opens the classic dashboard, which handles the StreamLabs sign-in. Come back here when it is done.</p></>}
        </>}
      </section>
      {p.linked && <>
        {p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>Tokens are hidden and unlinking is disabled while acting as another user.</span></div>
          : <div className="tk-grid">
            <section className="card"><h3 className="card-h"><i className="fas fa-key" /> Access Token</h3><TokenField id="sl_access" label="Access Token" what="Access Token" value={p.access_token || ''} toast={setToast} /></section>
            <section className="card"><h3 className="card-h"><i className="fas fa-plug" /> Socket Token (Real-time Events)</h3>{p.socket_token ? <TokenField id="sl_socket" label="Socket Token" what="Socket Token" value={p.socket_token} toast={setToast} /> : <p className="muted">The socket token could not be fetched from StreamLabs right now.</p>}</section>
          </div>}
        <section className="card"><div className="card-title-row"><h3 className="card-h"><i className="fas fa-hand-holding-dollar" /> Recent Donations</h3>{dons.length > 0 && <Pill k="pill-muted">Latest</Pill>}</div>
          {!p.donations_ok && <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>StreamLabs did not return your donations right now. Try again later.</span></div>}
          {dons.length ? <table className="bp-table st-table tk-table"><thead><tr><th>Donor</th><th className="tk-num">Amount</th><th>Message</th><th>Date</th></tr></thead>
            <tbody>{dons.map((d, i) => <tr key={i}><td data-l="Donor"><b>{d.name || 'Anonymous'}</b></td><td data-l="Amount" className="tk-num">{money(d.amount, d.currency || 'USD')}</td><td data-l="Message" className="tk-msg">{d.message || <span className="muted">No message</span>}</td><td data-l="Date">{d.created_at_formatted}</td></tr>)}</tbody></table>
            : p.donations_ok && <div className="tk-empty"><i className="fas fa-inbox" /><b>No donations yet</b><span className="muted">When you receive donations, they will appear here.</span></div>}
        </section>
      </>}
    </>}
    {ask && <Confirm title="Unlink StreamLabs Account?" yes="Unlink" busy={busy} onClose={() => setAsk(false)} onYes={unlink}><p>Are you sure you want to unlink your StreamLabs account? This will remove all associated tokens and data.</p></Confirm>}
  </div>
}
