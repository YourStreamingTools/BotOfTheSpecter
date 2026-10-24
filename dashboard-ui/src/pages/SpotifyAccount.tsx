import { useEffect, useState } from 'react'
import { get } from '../bot'
import { Switch, send, type Toast } from './commands/shared'

// Spotify Account card for the Media Player's Spotify tab: the status, Link /
// Relink and own-app settings from dashboard/spotifylink.php. The OAuth
// callback stays on the live spotifylink.php. Endpoint: public/api/media-player/spotify-account.php.
const API = '/api/media-player/spotify-account.php'
type Acc = { success: boolean; connection_status: 'connected' | 'pending' | 'error' | 'not-connected'; own_client: boolean; client_id: string; has_secret: boolean; auth_url: string; linked_accounts_count: number; max_accounts: number; is_act_as: boolean; message: string; message_kind: '' | 'err' | 'warn'; error?: string }
const BADGE = { connected: ['pill-ok', 'Spotify Connected!'], pending: ['pill-warn', 'Pending Link'], error: ['pill-err', 'Not Connected'], 'not-connected': ['pill-off', 'Not Connected'] } as const

export default function SpotifyAccount({ toast }: { toast: (t: Toast) => void }) {
  const [a, setA] = useState<Acc | null>(null), [err, setErr] = useState('')
  const [cid, setCid] = useState(''), [sec, setSec] = useState(''), [busy, setBusy] = useState('')
  async function load() {
    try { const r = await get<Acc>(API); if (!r.success) throw new Error(r.error); setA(r); setCid(r.client_id); setSec(''); setErr('') }
    catch (e) { setErr((e as Error).message && !(e as Error).message.startsWith('http_') ? (e as Error).message : 'Could not load your Spotify account. Refresh to try again.') }
  }
  useEffect(() => { load() }, [])
  async function act(key: string, body: Record<string, string>) {
    setBusy(key)
    try { const r = await send<{ success: boolean; message?: string; error?: string }>(API, body); if (!r.success) throw new Error(r.error || 'That did not save. Please try again.'); toast({ kind: 'ok', text: r.message || 'Saved.' }); await load() }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  const full = !!a && a.linked_accounts_count >= a.max_accounts
  const cidBad = cid !== '' && !/^[A-Za-z0-9]{1,64}$/.test(cid), secBad = sec !== '' && !/^[A-Za-z0-9]{1,64}$/.test(sec)
  return (
    <section className="card sa-card" aria-label="Spotify account" aria-busy={!a && !err}>
      <div className="card-title-row"><h3 className="card-h"><i className="fab fa-spotify mp-green" /> Spotify Account {a && <span className={`pill ${BADGE[a.connection_status][0]}`}>{BADGE[a.connection_status][1]}</span>}</h3></div>
      {err && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
      {!a && !err && <div className="cmd-list">{[0, 1].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
      {a && <>
        {a.is_act_as && <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>Act As mode is active. Linking Spotify is disabled for acting users.</span></div>}
        {a.message && <div className={`notice notice-${a.message_kind || 'info'}`} role="status"><i className="fas fa-circle-info" /> <span>{a.message}</span></div>}
        {a.connection_status === 'connected' ? <div className="notice notice-ok"><i className="fas fa-check" /> <span>Now you're linked. If this is your first time being linked, please restart the bot so the bot knows about this linking. If the bot stops answering <code>!song</code> or <code>!songrequest</code>, come back here, relink your account and restart the bot.</span></div>
          : <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span><b>Important: Spotify Integration Changes (Effective March 9, 2026)</b><br />Due to Spotify's updated Developer Policy, our platform Spotify client is no longer able to accept new users (Development Mode apps are capped at 5 authorized users). If you were previously linked via our platform account, your slot is still reserved. New users need to create their own Spotify app, which takes only a few minutes. Your Spotify developer account must have Spotify Premium to use Development Mode.</span></div>}
        <p className="muted">Currently, {a.linked_accounts_count} out of {a.max_accounts} accounts are linked.</p>
        {full && !a.own_client && a.connection_status !== 'connected' && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span><b>Platform Spotify account is at capacity ({a.linked_accounts_count}/{a.max_accounts} slots used).</b> No new accounts can be linked via the BotOfTheSpecter platform client. Turn on Use Your Own Spotify Client below and enter your own Spotify app credentials.</span></div>}
        {a.auth_url && !a.is_act_as && <div className="row-btns"><a className="btn" href={a.auth_url}><i className="fab fa-spotify" /> {a.connection_status === 'connected' ? 'Relink Spotify Account' : 'Link Spotify Account'}</a></div>}
        <div className="mp-rows">
          <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Use Your Own Spotify Client</span><span className="muted">Create your own Spotify app and enter the credentials below. It is used only for your channel.</span></div>
            <div className="mp-row-r"><Switch on={a.own_client} label="Enable Own Client" busy={a.is_act_as || busy === 'own'} onChange={(v) => act('own', { action: 'own_client', enabled: v ? '1' : '0' })} /></div></div>
        </div>
        {a.own_client && <form className="sa-form" onSubmit={(e) => { e.preventDefault(); if (cid && !cidBad && !secBad) act('save', { action: 'save_credentials', client_id: cid, client_secret: sec }) }}>
          <a className="btn btn-ghost" href="https://support.botofthespecter.com/index.php#spotify" target="_blank" rel="noopener noreferrer"><i className="fas fa-external-link-alt" /> Get Setup Instructions</a>
          <div className="fld"><label htmlFor="sa-cid">Client ID</label><input id="sa-cid" className="input" autoComplete="off" maxLength={64} placeholder="Your Spotify Client ID" value={cid} aria-invalid={cidBad} disabled={a.is_act_as} onChange={(e) => setCid(e.target.value.trim())} />{cidBad && <span className="mp-bad">Letters and numbers only.</span>}</div>
          <div className="fld"><label htmlFor="sa-sec">Client Secret</label><input id="sa-sec" className="input" type="password" autoComplete="new-password" maxLength={64} placeholder={a.has_secret ? 'Saved. Leave blank to keep it.' : 'Your Spotify Client Secret'} value={sec} aria-invalid={secBad} disabled={a.is_act_as} onChange={(e) => setSec(e.target.value.trim())} />{secBad && <span className="mp-bad">Letters and numbers only.</span>}</div>
          <div className="row-btns"><button type="submit" className="btn" disabled={a.is_act_as || !cid || cidBad || secBad || (!sec && !a.has_secret) || busy === 'save'}><i className={busy === 'save' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save Credentials</button></div>
        </form>}
      </>}
    </section>
  )
}
