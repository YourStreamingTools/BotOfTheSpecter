import { useEffect, useState } from 'react'
import { get } from '../bot'
import { ToastBar, type Toast } from './commands/shared'
import { TokenField, Pill } from './tokenField'

// Port of dashboard/streamelements.php. Endpoint: public/api/streamelements/
// streamelements.php (scripts/sync-live-api.sh). The OAuth callback is the live
// page (its registered redirect URI), so linking happens there.
const API = '/api/streamelements/streamelements.php'
type Tip = { username: string; amount: string; message: string; provider: string; date: string }
type P = { success: boolean; error?: string; linked: boolean; inactive?: boolean; created_at?: string; is_partner?: boolean; suspended?: boolean; expires_text?: string; api_token?: string | null; jwt_token?: string | null; channel_id?: string | null; tips_code?: number | null; tips_failed_message?: string | null; tips?: Tip[]; act_as: boolean; link_url: string }

export default function StreamElements() {
  const [p, setP] = useState<P | null>(null), [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null)
  useEffect(() => { get<P>(API, 30000).then((r) => r.success === false ? setErr(r.error || 'StreamElements could not load right now.') : setP(r)).catch(() => setErr('StreamElements could not load right now.')) }, [])
  return <div className="page se-page">
    <ToastBar t={toast} onClose={() => setToast(null)} />
    {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
    {!p && !err && <section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading StreamElements...</p></section>}
    {p && <>
      <section className="card">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-globe" /> StreamElements Integration</h3>
          <div className="tk-pills">
            {p.linked && <Pill k={p.inactive ? 'pill-warn' : 'pill-ok'} title="Account Status">{p.inactive ? 'Inactive' : 'Active'}</Pill>}
            {p.linked && p.created_at && <Pill k="pill-muted" title="Account Created"><i className="fas fa-calendar" /> {p.created_at}</Pill>}
            {p.is_partner && <Pill k="pill-ok" title="StreamElements Partner"><i className="fas fa-star" /> SE Partner</Pill>}
            {p.suspended && <Pill k="pill-err">Suspended</Pill>}
            <Pill k={p.linked ? 'pill-ok' : 'pill-err'}>{p.linked ? 'Connected' : 'Not Connected'}</Pill>
          </div>
        </div>
        {p.linked ? <>
          <div className="notice notice-ok"><i className="fas fa-check" /> <span>Your StreamElements account is successfully linked to your profile.</span></div>
          {p.expires_text && <div className="notice notice-info"><i className="fas fa-clock" /> <span>{p.expires_text}</span></div>}
          {!p.act_as && <div className="row-btns"><a className="btn btn-ghost" href={p.link_url}><i className="fas fa-link" /> Relink on the classic dashboard</a></div>}
        </> : <>
          <p>Connect your StreamElements account to enable tip tracking and integration features.</p>
          <p className="muted"><b>Available Features:</b></p>
          <ul className="tk-list"><li>Access to your recent tips and donation data</li><li>Integration with bot commands for tip information</li><li>Enhanced stream engagement through tip-based features</li></ul>
          {p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>Act As mode is active. Linking StreamElements is disabled for acting users.</span></div>
            : <><div className="row-btns"><a className="btn" href={p.link_url}><i className="fas fa-link" /> Link StreamElements Account</a></div>
              <p className="muted small">Linking opens the classic dashboard, which handles the StreamElements sign-in. Come back here when it is done.</p></>}
        </>}
      </section>
      {p.linked && <>
        {p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>Tokens are hidden while acting as another user.</span></div>
          : <div className="tk-grid">
            <section className="card"><h3 className="card-h"><i className="fas fa-key" /> API Token</h3>
              <TokenField id="se_api" label="StreamElements API Token" what="API Token" value={p.api_token || ''} toast={setToast}><span className="muted small"><i className="fas fa-triangle-exclamation" /> Keep this token secure and never share it publicly.</span></TokenField></section>
            <section className="card"><h3 className="card-h"><i className="fas fa-shield-halved" /> JWT Token</h3>
              <TokenField id="se_jwt" label="StreamElements JWT Token" what="JWT Token" value={p.jwt_token || ''} toast={setToast}><span className="muted small"><i className="fas fa-circle-info" /> This JWT token is used for WebSocket connections and real-time features.</span></TokenField></section>
          </div>}
        {p.tips && p.tips.length ? <section className="card"><h3 className="card-h"><i className="fas fa-coins" /> Recent Tips</h3>
          <table className="bp-table st-table tk-table"><thead><tr><th>Tipper</th><th className="tk-num">Amount</th><th>Message</th><th>Provider</th><th>Date</th></tr></thead>
            <tbody>{p.tips.map((t, i) => <tr key={i}><td data-l="Tipper"><b>{t.username}</b></td><td data-l="Amount" className="tk-num">{t.amount}</td><td data-l="Message" className="tk-msg">{t.message || <span className="muted">No message</span>}</td><td data-l="Provider">{t.provider}</td><td data-l="Date">{t.date}</td></tr>)}</tbody></table>
        </section> : <section className="card"><h3 className="card-h"><i className="fas fa-coins" /> Tips Status</h3>
          <p className="muted">No recent tips found.</p>
          {p.channel_id ? <><p><b>Channel ID:</b> <code className="tk-code">{p.channel_id}</code></p><p className="muted small">Channel ID is available and we're checking for tips.</p>
            {p.tips_code != null && <p><b>Tips API Response Code:</b> {p.tips_code}</p>}
            {p.tips_failed_message ? <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{p.tips_failed_message}</span></div> : p.tips_code === 200 && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span>Tips API call successful, but no tips found.</span></div>}</>
            : <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>Channel ID not available - this might be why tips aren't loading. The current user API call may have failed or returned no channels.</span></div>}
        </section>}
      </>}
    </>}
  </div>
}
