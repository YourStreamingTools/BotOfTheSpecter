import { useEffect, useRef, useState } from 'react'
import { getJson, LoadErr, Loading, useLoad } from '../ui'

// Port of dashboard/admin/logs.php: category, then user + type / system type / token type,
// Reload, Auto Refresh (10s) and the log pane. The server returns escaped, highlighted HTML.
type Group = { label: string; options: { value: string; label: string }[] }
type Data = { users: string[]; system_types: Group[]; L: Record<string, string> }
type LogRes = { data?: string; empty?: boolean; error?: string; path?: string }
const API = '/api/logs.php'
const USER_TYPES = [['bot', 'type_bot'], ['chat', 'type_chat'], ['twitch', 'type_twitch'], ['api', 'type_api'], ['chat_history', 'type_chat_history'], ['event_log', 'type_event'], ['websocket', 'type_websocket'], ['system', 'type_system'], ['integrations', 'type_integrations'], ['crash', 'type_crash']]
const TOKEN_TYPES = [['spotify_refresh', 'token_spotify'], ['refresh_streamelements_tokens', 'token_streamelements'], ['refresh_discord_tokens', 'token_discord'], ['custom_bot_token_refresh_cron', 'token_custom_bot_cron'], ['custom_bot_token_refresh', 'token_custom_bot_manual'], ['refresh_twitch_app_token', 'token_twitch_app'], ['youtube_refresh', 'token_youtube']]

export default function Logs() {
  const { data, err, reload } = useLoad<Data>(API)
  const [cat, setCat] = useState(''), [user, setUser] = useState(''), [type, setType] = useState('')
  const [html, setHtml] = useState(''), [busy, setBusy] = useState(false), [auto, setAuto] = useState(false)
  const pane = useRef<HTMLDivElement>(null)
  const L = data?.L ?? {}, l = (k: string) => L[k] ?? k
  const ready = cat === 'audit' || (cat === 'user' && !!user && !!type) || ((cat === 'system' || cat === 'token') && !!type)

  const fetchLog = async () => {
    if (!ready) return
    setBusy(true)
    const url = cat === 'audit' ? `${API}?admin_audit_log=1` : cat === 'user' ? `${API}?admin_log_user=${encodeURIComponent(user)}&admin_log_type=${encodeURIComponent(type)}`
      : cat === 'system' ? `${API}?admin_system_log_type=${encodeURIComponent(type)}` : `${API}?admin_token_log_type=${encodeURIComponent(type)}`
    const pre = cat === 'user' ? 'user' : cat === 'system' ? 'system' : 'token'
    let out: string
    try {
      const j = await getJson<LogRes>(url)
      const path = j.path ? ` ${l('js_path_label')} ${j.path}` : ''
      if (j.error) out = cat === 'audit' ? l('js_audit_load_failed')
        : j.error === 'not_found' ? l(`js_${pre}_not_found`) + (cat === 'system' ? path : '')
        : j.error === 'permission_denied' && cat !== 'user' ? l('js_permission_denied') + (cat === 'system' ? path : '')
        : j.error === 'connection_failed' ? l('js_connection_failed') : l('js_unknown_error')
      else if (j.empty) out = cat === 'audit' ? l('audit_none') : l(`js_${pre}_file_empty`)
      else if (!j.data || !j.data.trim()) out = cat === 'audit' ? l('js_audit_empty') : l(`js_${pre}_empty_or_not_found`)
      else out = j.data
    } catch { out = l('js_connection_failed') }
    setHtml(out); setBusy(false)
    requestAnimationFrame(() => { if (pane.current) pane.current.scrollTop = cat === 'audit' ? 0 : pane.current.scrollHeight })
  }
  // A new selection loads straight away (as the old selects did) and stops auto refresh.
  useEffect(() => { setAuto(false); setHtml(''); if (ready) void fetchLog() }, [cat, user, type]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!auto) return; const t = setInterval(() => void fetchLog(), 10000); return () => clearInterval(t) }, [auto, cat, user, type]) // eslint-disable-line react-hooks/exhaustive-deps

  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const pick = (c: string) => { setCat(c); setUser(''); setType('') }
  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-clipboard-list" /> {l('heading')}</h2>
        <div className="adm-filters adm-gap">
          <select className="input" aria-label={l('cat_select')} value={cat} onChange={(e) => pick(e.target.value)}>
            <option value="">{l('cat_select')}</option>
            {['audit', 'system', 'user', 'token'].map((c) => <option key={c} value={c}>{l('cat_' + c)}</option>)}
          </select>
          {(cat === '' || cat === 'user') && <select className="input" aria-label={l('choose_user')} disabled={cat !== 'user'} value={user} onChange={(e) => { setUser(e.target.value); if (!e.target.value) setType('') }}>
            <option value="">{cat === 'user' ? l('choose_user') : l('select_category_first')}</option>
            {cat === 'user' && data.users.map((u) => <option key={u} value={u}>{u}</option>)}
          </select>}
          {cat === 'user' && <select className="input" aria-label={l('type_select')} disabled={!user} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">{l('type_select')}</option>
            {USER_TYPES.map(([v, k]) => <option key={v} value={v}>{l(k)}</option>)}
          </select>}
          {cat === 'system' && <select className="input" aria-label={l('system_type_select')} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">{l('system_type_select')}</option>
            {data.system_types.map((g) => <optgroup key={g.label} label={g.label}>{g.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</optgroup>)}
          </select>}
          {cat === 'token' && <select className="input" aria-label={l('token_select')} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">{l('token_select')}</option>
            {TOKEN_TYPES.map(([v, k]) => <option key={v} value={v}>{l(k)}</option>)}
          </select>}
          <button type="button" className="btn" disabled={!ready || busy} onClick={() => void fetchLog()}><i className="fas fa-sync-alt" /> {l('btn_reload')}</button>
          <button type="button" className={`btn ${auto ? 'btn-danger' : 'btn-ghost'}`} disabled={!ready} aria-pressed={auto} onClick={() => setAuto((a) => !a)}><i className={auto ? 'fas fa-stop' : 'fas fa-play'} /> {l(auto ? 'btn_stop_auto_refresh' : 'btn_auto_refresh')}</button>
        </div>
        <div ref={pane} className="adm-log" aria-busy={busy} aria-live="polite">
          {busy && !html ? <div className="adm-log-skel" aria-hidden="true">{[90, 70, 80, 55, 85, 40, 75, 60].map((w, i) => <span key={i} className="bp-skel" style={{ width: `${w}%` }} />)}</div>
            : <div dangerouslySetInnerHTML={{ __html: html }} />}
        </div>
      </section>
    </div>
  )
}
