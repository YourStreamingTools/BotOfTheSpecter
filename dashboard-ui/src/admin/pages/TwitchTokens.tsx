import { useEffect, useRef, useState } from 'react'
import Modal from '../../components/Modal'
import { UrlReveal } from '../../components/UrlReveal'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Res, type Toast } from '../ui'

// Port of dashboard/admin/twitch_tokens.php: Generate App Access Token (with the Learn more modal),
// Validate Access Token, Twitch Chat Token, stored User Tokens and Custom Bot Tokens.
// Differences: stored tokens never reach the browser (validation runs server side), the client
// secret is not sent to the page (left blank, the server uses the configured one). Like the old page,
// user and custom bot tokens are validated automatically on load; renewals are a button press and confirm first.
type V = { success: boolean; state?: 'none' | 'invalid' | 'valid' | 'valid_no_expiry'; expires_in?: number; login?: string; scopes?: string[]; error?: string }
type C = { bot_username: string; bot_channel_id: string; module: boolean; verified: boolean | null; has_token: boolean; expires: string }
type Data = { client_id: string; has_secret: boolean; redirect_uri: string | null; beta_redirect_uri: string | null; users: { twitch_user_id: string; username: string }[]; custom: C[]; L: Record<string, string> }
type St = { s: 'busy' | 'valid' | 'invalid' | 'none' | 'renewing' | 'renewed' | 'failed' | 'err'; exp?: number; note?: string }
const API = '/api/twitch_tokens.php'
// Same breakdown as the old page: months (30 days), days, hours, minutes
const hrs = (s: number) => {
  const u: [number, string][] = [[2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']]
  let r = Math.max(0, Math.floor(s)); const out: string[] = []
  for (const [n, w] of u) { const v = Math.floor(r / n); r %= n; if (v) out.push(`${v} ${w}${v > 1 ? 's' : ''}`) }
  return out.join(', ') || `${r} second${r === 1 ? '' : 's'}`
}

export default function TwitchTokens() {
  const { data, err, reload } = useLoad<Data>(API)
  const [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null), [info, setInfo] = useState(false)
  const [cid, setCid] = useState<string | null>(null), [secret, setSecret] = useState('')
  const [gen, setGen] = useState<{ token: string; expires_in: number } | null>(null), [genBusy, setGenBusy] = useState(false)
  const [vt, setVt] = useState(''), [vRes, setVRes] = useState<{ ok: boolean; text: string; v?: Record<string, unknown> } | null>(null), [vBusy, setVBusy] = useState(false)
  const [chat, setChat] = useState<St | null>(null), [rows, setRows] = useState<Record<string, St>>({}), [cust, setCust] = useState<Record<string, St>>({})
  const [allBusy, setAllBusy] = useState(false), [custBusy, setCustBusy] = useState(false)
  const auto = useRef(false)
  useEffect(() => {
    if (!data || auto.current) return
    auto.current = true
    validateAll()
    checkChat()
    validateAllCustom()
  })
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string) => L[k] ?? k
  const clientId = cid ?? data.client_id
  const toSt = (r: V): St => !r.success ? { s: 'err', note: r.error } : r.state === 'none' ? { s: 'none' } : r.state === 'invalid' ? { s: 'invalid' } : { s: 'valid', exp: r.expires_in }
  const pill = (st?: St) => !st ? <span className="pill">{l('status_not_validated')}</span>
    : st.s === 'busy' ? <span className="pill">{l('status_validating')}</span> : st.s === 'renewing' ? <span className="pill pill-warn">{l('status_renewing')}</span>
    : st.s === 'valid' ? <span className="pill pill-ok">{l('status_valid')}</span> : st.s === 'renewed' ? <span className="pill pill-ok">{l('status_renewed')}</span>
    : st.s === 'invalid' ? <span className="pill pill-err">{l('status_invalid')}</span> : st.s === 'none' ? <span className="pill">{l('status_no_token')}</span>
    : st.s === 'failed' ? <span className="pill pill-err" title={st.note}>{l('status_renew_failed')}</span> : <span className="pill pill-err" title={st.note}>{l('status_error')}</span>
  // Custom bots store an expiry date; show the time left like the user tokens
  const left = (d?: string) => { if (!d) return '-'; const t = Date.parse(d.replace(' ', 'T')); if (Number.isNaN(t)) return d; const sec = (t - Date.now()) / 1000; return sec > 0 ? hrs(sec) : 'Expired' }
  const expTxt = (st?: St) => st?.exp ? hrs(st.exp) : st?.s === 'valid' ? '-' : ''

  const generate = async () => {
    setGenBusy(true); setGen(null)
    try { const r = await post<Res & { access_token?: string; expires_in?: number; error?: string }>(API, { generate_token: 1, client_id: clientId, client_secret: secret }); if (r.success && r.access_token) setGen({ token: r.access_token, expires_in: r.expires_in || 0 }); else setToast({ kind: 'err', text: r.error || l('js_err_generating') }) }
    catch { setToast({ kind: 'err', text: l('js_err_generating') }) } finally { setGenBusy(false) }
  }
  const validatePasted = async () => {
    if (!vt.trim()) { setToast({ kind: 'err', text: l('js_missing_token_text') }); return }
    setVBusy(true)
    try { const r = await post<Res & { validation?: Record<string, unknown>; error?: string }>(API, { validate_token: 1, access_token: vt.trim() }); setVRes(r.success ? { ok: true, text: l('js_token_validated'), v: r.validation } : { ok: false, text: r.error || l('js_invalid_token') }) }
    catch { setVRes({ ok: false, text: l('js_err_validating') }) } finally { setVBusy(false) }
  }
  const checkChat = async () => { setChat({ s: 'busy' }); try { setChat(toSt(await post<V>(API, { badm_validate_chat: 1 }))) } catch { setChat({ s: 'err' }) } }
  const renewChat = () => setAsk({ title: l('renew_chat_button'), ok: l('renew_chat_button'), danger: true, text: 'This mints a new app access token and saves it as the live bot chat token. The bots pick it up from the database.', run: async () => {
    setChat({ s: 'renewing' })
    try { const r = await post<Res & { expires_in?: number; error?: string }>(API, { renew_chat_token: 1, client_id: clientId, client_secret: secret }); setChat(r.success ? { s: 'renewed', exp: r.expires_in } : { s: 'failed', note: r.error }); setToast({ kind: r.success ? 'ok' : 'err', text: r.success ? l('js_chat_generated_title') : (r.error || l('js_err_chat_generate')) }) }
    catch { setChat({ s: 'err' }) }
  } })
  const checkUser = async (id: string) => { setRows((m) => ({ ...m, [id]: { s: 'busy' } })); try { const r = toSt(await post<V>(API, { badm_validate_user: 1, twitch_user_id: id })); setRows((m) => ({ ...m, [id]: r })); return r } catch { setRows((m) => ({ ...m, [id]: { s: 'err' } })); return null } }
  const renewUser = async (id: string) => { setRows((m) => ({ ...m, [id]: { s: 'renewing' } })); try { const r = await post<Res & { expires_in?: number; error?: string }>(API, { renew_token: 1, twitch_user_id: id }); setRows((m) => ({ ...m, [id]: r.success ? { s: 'renewed', exp: r.expires_in } : { s: 'failed', note: r.error } })); return r.success } catch { setRows((m) => ({ ...m, [id]: { s: 'err' } })); return false } }
  const askRenewUser = (id: string, name: string) => setAsk({ title: l('btn_renew'), ok: l('btn_renew'), danger: true, text: <>Renew the stored Twitch token for <strong>{name}</strong> with its refresh token? The new token replaces the live one.</>, run: () => { renewUser(id) } })
  const validateAll = async () => { setAllBusy(true); const q = data.users.map((u) => u.twitch_user_id); await Promise.all([0, 1, 2].map(async () => { while (q.length) await checkUser(q.shift()!) })); setAllBusy(false) }
  const invalid = data.users.filter((u) => rows[u.twitch_user_id]?.s === 'invalid' || rows[u.twitch_user_id]?.s === 'failed')
  const renewInvalid = () => setAsk({ title: l('renew_invalid_button'), ok: l('renew_invalid_button'), danger: true, text: `Renew ${invalid.length} invalid token(s)?`, run: () => { const q = invalid.map((u) => u.twitch_user_id); setAllBusy(true); Promise.all([0, 1, 2].map(async () => { while (q.length) await renewUser(q.shift()!) })).finally(() => setAllBusy(false)) } })
  const ck = (c: C) => `${c.module ? 'm' : 'c'}:${c.bot_channel_id || c.bot_username}`
  const checkCustom = async (c: C) => { const k = ck(c); setCust((m) => ({ ...m, [k]: { s: 'busy' } })); try { const r = toSt(await post<V>(API, { badm_validate_custom: 1, bot_channel_id: c.bot_channel_id, bot_username: c.bot_username, module: c.module ? '1' : '0' })); setCust((m) => ({ ...m, [k]: r })) } catch { setCust((m) => ({ ...m, [k]: { s: 'err' } })) } }
  const doRenewCustom = async (c: C) => {
    const k = ck(c); setCust((m) => ({ ...m, [k]: { s: 'renewing' } }))
    try { const r = await post<Res & { expires_at?: string; error?: string }>(API, { renew_custom: 1, bot_channel_id: c.bot_channel_id, bot_username: c.bot_username }); setCust((m) => ({ ...m, [k]: r.success ? { s: 'renewed', note: r.expires_at } : { s: 'failed', note: r.error } })) }
    catch { setCust((m) => ({ ...m, [k]: { s: 'err' } })) }
  }
  const renewCustom = (c: C) => setAsk({ title: l('btn_renew'), ok: l('btn_renew'), danger: true, text: <>Renew the stored token for custom bot <strong>{c.bot_username}</strong>? The new token replaces the live one.</>, run: () => { doRenewCustom(c) } })
  const validateAllCustom = async () => { setCustBusy(true); const q = data.custom.filter((c) => c.has_token); await Promise.all([0, 1, 2].map(async () => { while (q.length) await checkCustom(q.shift()!) })); setCustBusy(false) }
  const invalidCustom = data.custom.filter((c) => cust[ck(c)]?.s === 'invalid' || cust[ck(c)]?.s === 'failed')
  const renewInvalidCustom = () => setAsk({ title: l('renew_invalid_button'), ok: l('renew_invalid_button'), danger: true, text: `Renew ${invalidCustom.length} invalid custom bot token(s)?`, run: () => { const q = [...invalidCustom]; setCustBusy(true); Promise.all([0, 1, 2].map(async () => { while (q.length) await doRenewCustom(q.shift()!) })).finally(() => setCustBusy(false)) } })

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fab fa-twitch" /> {l('page_title')}</h2>
        <p className="adm-gap">{l('intro')}</p>
        <p className="adm-gap"><button type="button" className="btn btn-ghost" onClick={() => setInfo(true)}><i className="fas fa-info-circle" /> {l('learn_more')}</button></p>
        <div className="adm-svc">
          <h3 className="adm-h3">{l('credentials_heading')}</h3>
          <p className="muted">{l('credentials_intro')}</p>
          <label className="fld"><span className="fld-label">{l('client_id_label')}</span><input className="input" value={clientId} placeholder={l('client_id_placeholder')} onChange={(e) => setCid(e.target.value)} /><span className="muted">{l('client_id_help')}</span></label>
          <label className="fld"><span className="fld-label">{l('client_secret_label')}</span><input className="input" type="password" autoComplete="off" value={secret} placeholder={data.has_secret ? 'Configured on the server (leave blank to use it)' : l('client_secret_placeholder')} onChange={(e) => setSecret(e.target.value)} /><span className="muted">{l('client_secret_help')}</span></label>
          <p><button type="button" className="btn" disabled={genBusy} onClick={generate}><i className={`fas ${genBusy ? 'fa-spinner fa-spin' : 'fa-key'}`} /> {l('generate_button')}</button></p>
          <div className="notice notice-info"><div><strong>{l('redirect_setup_heading')}</strong>
            <p><strong>{l('redirect_current_status_label')}</strong> {l('redirect_current_status_text')}</p>
            <p><strong>{l('redirect_app_tokens_label')}</strong> {l('redirect_app_tokens_text')}</p>
            <p><strong>{l('redirect_user_auth_label')}</strong> {l('redirect_user_auth_text')}</p></div></div>
        </div>
        {gen && <div className="notice notice-ok adm-gap"><div className="adm-min0"><strong>{l('js_token_generated')}</strong>
          <UrlReveal mask={'\u2022'.repeat(16)} label="App access token" name="App access token" copied="Token copied." fetchUrl={async () => gen.token} toast={setToast} />
          <p className="muted">{l('js_expires_in_seconds').replace(':seconds', String(gen.expires_in)).replace(':hours', String(Math.floor(gen.expires_in / 3600)))}</p></div></div>}
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-check" /> {l('validate_heading')}</h2>
        <p className="muted adm-gap">{l('validate_intro')}</p>
        <label className="fld"><span className="fld-label">{l('access_token_label')}</span><input className="input" type="password" autoComplete="off" value={vt} placeholder={l('access_token_placeholder')} onChange={(e) => setVt(e.target.value)} /><span className="muted">{l('access_token_help')}</span></label>
        <button type="button" className="btn" disabled={vBusy} onClick={validatePasted}><i className="fas fa-check" /> {l('validate_button')}</button>
        {vRes && <div className={`notice ${vRes.ok ? 'notice-ok' : 'notice-error'} adm-gap`}><div className="adm-min0"><strong>{vRes.text}</strong>
          {vRes.v && <dl className="adm-kv">{(['login', 'user_id', 'client_id', 'expires_in'] as const).filter((k) => vRes.v![k] !== undefined).map((k) => <div key={k}><dt>{k}</dt><dd>{k === 'expires_in' ? hrs(Number(vRes.v![k])) : String(vRes.v![k])}</dd></div>)}
            {Array.isArray(vRes.v.scopes) && <div><dt>scopes</dt><dd>{(vRes.v.scopes as string[]).join(', ') || '-'}</dd></div>}</dl>}</div></div>}
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-comments" /> {l('chat_heading')}</h2>
        <p className="muted adm-gap">{l('chat_intro')}</p>
        <p><strong>{l('status_label')}</strong> {pill(chat || undefined)}</p>
        <p className="adm-gap"><strong>{l('expires_in_label')}</strong> {expTxt(chat || undefined) || '-'}</p>
        <div className="adm-inline">
          <button type="button" className="btn" onClick={checkChat}><i className="fas fa-check" /> {l('validate_chat_button')}</button>
          <button type="button" className="btn btn-warn" onClick={renewChat}><i className="fas fa-sync-alt" /> {l('renew_chat_button')}</button>
        </div>
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-users" /> {l('user_tokens_heading')}</h2>
        <p className="muted adm-gap">{l('user_tokens_intro')}</p>
        <div className="notice notice-info adm-gap"><div><strong>{l('user_info_heading')}</strong><ul>
          {['user_info_authenticated', 'user_info_scopes', 'user_info_renewal', 'user_info_reauth'].map((k) => <li key={k}>{l(k)}</li>)}</ul></div></div>
        <div className="adm-inline adm-gap">
          <button type="button" className="btn" disabled={allBusy} onClick={validateAll}><i className={`fas ${allBusy ? 'fa-spinner fa-spin' : 'fa-check-circle'}`} /> {l('validate_all_button')}</button>
          <button type="button" className="btn btn-danger" disabled={!invalid.length || allBusy} onClick={renewInvalid}><i className="fas fa-sync" /> {l('renew_invalid_button')}{invalid.length ? ` (${invalid.length})` : ''}</button>
        </div>
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_username')}</th><th>{l('th_status')}</th><th>{l('th_expires_in')}</th><th>{l('th_actions')}</th></tr></thead>
          <tbody>{data.users.length === 0 ? <tr><td colSpan={4}>{l('no_tokens')}</td></tr> : data.users.map((u) => (
            <tr key={u.twitch_user_id}><td>{u.username}</td><td>{pill(rows[u.twitch_user_id])}</td><td>{expTxt(rows[u.twitch_user_id])}</td>
              <td><div className="adm-inline"><button type="button" className="btn btn-ghost" onClick={() => checkUser(u.twitch_user_id)}>{l('btn_validate')}</button><button type="button" className="btn btn-warn" onClick={() => askRenewUser(u.twitch_user_id, u.username)}>{l('btn_renew')}</button></div></td></tr>))}</tbody>
        </table></div>
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-robot" /> {l('custom_heading')}</h2>
        <p className="muted adm-gap">{l('custom_intro')}</p>
        <div className="adm-inline adm-gap">
          <button type="button" className="btn" disabled={custBusy} onClick={validateAllCustom}><i className={`fas ${custBusy ? 'fa-spinner fa-spin' : 'fa-check-circle'}`} /> {l('validate_all_button')}</button>
          <button type="button" className="btn btn-danger" disabled={!invalidCustom.length || custBusy} onClick={renewInvalidCustom}><i className="fas fa-sync" /> {l('renew_invalid_button')}{invalidCustom.length ? ` (${invalidCustom.length})` : ''}</button>
        </div>
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_bot_username')}</th><th>{l('th_bot_channel_id')}</th><th>{l('th_status')}</th><th>{l('th_expires_at')}</th><th>{l('th_actions')}</th></tr></thead>
          <tbody>{data.custom.length === 0 ? <tr><td colSpan={5}>{l('no_custom_bots')}</td></tr> : data.custom.map((c) => { const st = cust[ck(c)]; return (
            <tr key={ck(c)}><td>{c.bot_username} {c.module && <small className="muted">{l('module_tag')}</small>}</td><td>{c.bot_channel_id}</td>
              <td>{st ? pill(st) : !c.has_token ? <>{l('status_not_validated')}{c.verified && <> (<span className="adm-ok">{l('verified')}</span>)</>}</> : pill()}</td>
              <td>{st?.exp ? hrs(st.exp) : left(st?.s === 'renewed' && st.note ? st.note : c.expires)}</td>
              <td><div className="adm-inline"><button type="button" className="btn btn-ghost" disabled={!c.has_token} onClick={() => checkCustom(c)}>{l('btn_validate')}</button><button type="button" className="btn btn-warn" onClick={() => renewCustom(c)}>{l('btn_renew')}</button></div></td></tr>) })}</tbody>
        </table></div>
      </section>

      {info && <Modal title={l('learn_more')} icon="fas fa-info-circle" onClose={() => setInfo(false)}>
        <h3 className="adm-h3">{l('modal_how_to_heading')}</h3><p>{l('modal_how_to_intro')}</p>
        <ol><li>{l('modal_step_registered_app_prefix')} <a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener">{l('modal_dev_console')}</a></li><li>{l('modal_step_client_credentials')}</li><li>{l('modal_step_oauth_endpoint')}</li></ol>
        <p>{l('modal_client_credentials_note')}</p>
        <h3 className="adm-h3">{l('modal_redirect_heading')}</h3>
        <p><strong>{l('modal_redirect_app_label')}</strong> {l('modal_redirect_app_text')}</p>
        <p><strong>{l('modal_redirect_user_label')}</strong> {l('modal_redirect_user_text')}</p>
        <p><strong>{l('modal_current_config_label')}</strong></p>
        <ul><li>{l('modal_production_label')} <code>{data.redirect_uri || l('not_configured')}</code></li><li>{l('modal_beta_label')} <code>{data.beta_redirect_uri || l('not_configured')}</code></li></ul>
        <h3 className="adm-h3">{l('modal_badge_heading')}</h3><p>{l('modal_badge_intro')}</p>
        <ul><li>{l('modal_badge_send_api')}</li><li>{l('modal_badge_use_app_token')}</li><li>{l('modal_badge_scope_prefix')} <code>channel:bot</code> {l('modal_badge_scope_suffix')}</li><li>{l('modal_badge_not_broadcaster')}</li></ul>
        <div className="modal-acts"><button type="button" className="btn btn-ok" onClick={() => setInfo(false)}>{l('got_it')}</button></div>
      </Modal>}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
