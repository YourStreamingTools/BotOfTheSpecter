import { useEffect, useRef, useState } from 'react'
import Modal from '../../components/Modal'
import { ADMIN_NAV, oldUrl } from '../nav'
import { Confirm, getJson, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Res, type Toast } from '../ui'

// Port of dashboard/admin/index.php: heading with quick links, Server Overview (service groups
// with status, PID, uptime and start/stop/restart), Token Management, Exchange Rate Quota,
// Bot Message Counts, AI Platform Stats and Send Bot Message / Shoutout. The User Overview
// card is hidden on the old page (display:none), so it is not shown here either.
type Quota = { count: number | null; reset_day: number | null; days_remaining: number | null; updated: string | null }
type Msg = Record<string, { messages_sent: number | string; last_updated: string }>
type Data = { exchangerate: Quota; bot_messages: Msg; message_systems: Record<string, string>; templates: Record<string, string>; L: Record<string, string>; youtube: string }
type Svc = { status: string; pid: string; uptime_seconds?: number | null }
type Channel = { twitch_user_id: string; twitch_display_name: string; bot_type?: string; is_online: boolean }
const API = '/api/index.php'

// [status key, systemd unit, label key, sub key, icon]
type Def = [string, string, string, string, string]
const GROUPS: [string, string, string, Def[]][] = [
  ['BOTS', 'blue', 'group_bots_host', [
    ['discordbot', 'discordbot.service', 'svc_discord_bot', '', 'fab fa-discord'],
    ['bots_api', 'bots-api.service', 'svc_bots_api', '', 'fas fa-server'],
    ['export_queue_worker', 'export_queue_worker.service', 'svc_export_queue_worker', '', 'fas fa-file-export']]],
  ['API', 'accent', 'group_api_host', [['fastapi', 'fastapi.service', 'svc_api_server', '', 'fas fa-code']]],
  ['WS', 'green', 'group_websocket_host', [
    ['websocket', 'websocket.service', 'svc_websocket_server', '', 'fas fa-plug'],
    ['websocket_control', 'websocket-control.service', 'svc_websocket_control', 'svc_websocket_control_sub', 'fas fa-sliders'],
    ['yourchat_piper', 'yourchat-piper.service', 'svc_yourchat_piper', 'svc_yourchat_piper_sub', 'fas fa-volume-high']]],
  ['SQL', 'amber', 'group_sql_host', [
    ['mysql', 'mysql.service', 'svc_mysql_server', '', 'fas fa-database'],
    ['sql_api', 'sql-api.service', 'svc_sql_api', 'svc_sql_api_sub', 'fas fa-code']]],
  ['CADDY', 'blue', 'group_caddy', [
    ['bots_caddy', 'bots-caddy.service', 'svc_bots_caddy', 'svc_bots_caddy_sub', 'fas fa-shield-alt'],
    ['api_caddy', 'api-caddy.service', 'svc_api_caddy', 'svc_api_caddy_sub', 'fas fa-shield-alt'],
    ['web_caddy', 'caddy.service', 'svc_web_caddy', 'svc_web_caddy_sub', 'fas fa-shield-alt'],
    ['ws_caddy', 'ws-caddy.service', 'svc_ws_caddy', 'svc_ws_caddy_sub', 'fas fa-shield-alt'],
    ['sql_caddy', 'sql-caddy.service', 'svc_sql_caddy', 'svc_sql_caddy_sub', 'fas fa-shield-alt']]],
  ['STREAM', 'info', 'group_stream_host', [
    ['stream_server', 'stream.service', 'svc_stream_server', 'svc_stream_server_sub', 'fas fa-broadcast-tower'],
    ['twitch_recorder', 'twitch-recorder.service', 'svc_twitch_recorder', 'svc_twitch_recorder_sub', 'fas fa-video'],
    ['rerun_scheduler', 'rerun-scheduler.service', 'svc_rerun_scheduler', 'svc_rerun_scheduler_sub', 'fas fa-redo-alt']]],
]
const LINKS: [string, string, string, string][] = [
  ['users.php', 'link_user_management', 'fas fa-users-cog', ''], ['start_bots.php', 'link_start_bots', 'fas fa-play-circle', 'btn-ok'],
  ['logs.php', 'link_logs', 'fas fa-clipboard-list', 'btn-ghost'], ['twitch_tokens.php', 'link_twitch_tokens', 'fab fa-twitch', ''],
  ['discordbot_overview.php', 'link_discord_overview', 'fab fa-discord', 'btn-ghost'], ['websocket_clients.php', 'link_websocket_clients', 'fas fa-plug', 'btn-ok'],
  ['beta_programs.php', 'link_beta_programs', 'fas fa-flask', 'btn-ghost'],
]
const TOKENS: [string, string, string, string][] = [
  ['spotify', 'Spotify', 'refresh_spotify_tokens', 'fab fa-spotify'], ['streamelements', 'StreamElements', 'refresh_streamelements_tokens', 'fas fa-stream'],
  ['discord', 'Discord', 'refresh_discord_tokens', 'fab fa-discord'], ['custom_bot', '', 'refresh_custom_bot_tokens', 'fas fa-robot'],
  ['twitch_app', '', 'refresh_twitch_app_token', 'fab fa-twitch'], ['youtube', '', 'refresh_youtube_tokens', 'fab fa-youtube'],
]
const MSG_ICON: Record<string, string> = { discordbot: 'fab fa-discord', twitch_stable: 'fab fa-twitch', twitch_beta: 'fab fa-twitch', twitch_custom: 'fas fa-robot' }
const linkFor = (old: string) => { const n = ADMIN_NAV.find((x) => 'old' in x && x.old === old) as { path?: string } | undefined; return n?.path || oldUrl(old) }
const fmtDate = (s: string | null | undefined) => { if (!s || s.startsWith('0000')) return ''; const d = new Date(s.replace(' ', 'T')); return isNaN(+d) ? s : d.toLocaleString() }

export default function Home() {
  const { data, err, reload } = useLoad<Data>(API)
  const [svc, setSvc] = useState<Record<string, Svc | null>>({}), [svcBusy, setSvcBusy] = useState<Record<string, boolean>>({})
  const [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  const [out, setOut] = useState<{ title: string; text: string } | null>(null), [tokBusy, setTokBusy] = useState('')
  const [quota, setQuota] = useState<Quota | null>(null), [qBusy, setQBusy] = useState(false)
  const [msgs, setMsgs] = useState<Msg | null>(null)
  const [ai, setAi] = useState<string | null>(null), [aiErr, setAiErr] = useState(false), [aiBusy, setAiBusy] = useState(false)
  const [channels, setChannels] = useState<Channel[] | null>(null), [chErr, setChErr] = useState(false), [offline, setOffline] = useState(false)
  const [channel, setChannel] = useState(''), [tpl, setTpl] = useState(''), [message, setMessage] = useState(''), [sending, setSending] = useState(false)
  const [so, setSo] = useState(''), [soState, setSoState] = useState<'' | 'loading' | 'ok' | 'err'>(''), [soMsg, setSoMsg] = useState(''), [soBusy, setSoBusy] = useState(false)
  const [now, setNow] = useState(Date.now()), loadedAt = useRef<Record<string, number>>({}), soReq = useRef(0)

  const status = async (key: string, retries = 2) => {
    try {
      const d = await getJson<Svc & { error?: string }>(`/api/service_status.php?service=${key}`)
      if (retries > 0 && ['Error', 'Starting', 'Unknown'].includes(d.status)) { setSvc((s) => ({ ...s, [key]: { status: 'Starting', pid: d.pid || 'N/A' } })); setTimeout(() => status(key, retries - 1), 1000); return }
      loadedAt.current[key] = Date.now(); setSvc((s) => ({ ...s, [key]: { status: d.status || 'Error', pid: d.pid ?? 'N/A', uptime_seconds: d.uptime_seconds } }))
    } catch { if (retries > 0) { setTimeout(() => status(key, retries - 1), 1000); return } setSvc((s) => ({ ...s, [key]: { status: 'Error', pid: 'N/A' } })) }
  }
  // Three status checks at a time so 17 SSH lookups do not tie up every PHP worker at once.
  const allStatus = async () => {
    const keys = GROUPS.flatMap(([, , , defs]) => defs.map(([k]) => k)); setSvc({})
    const queue = [...keys]; await Promise.all([0, 1, 2].map(async () => { while (queue.length) await status(queue.shift()!) }))
  }
  const loadAi = async () => { setAiBusy(true); setAiErr(false); try { const r = await getJson<{ success: boolean; html?: string }>(`${API}?ajax=ai_platform_stats`); if (!r.success) throw new Error(); setAi(r.html || '') } catch { setAiErr(true) } finally { setAiBusy(false) } }
  const loadMsgs = async () => { try { const r = await getJson<{ botMessageStats?: Msg }>(`${API}?ajax=bot_message_counts`); if (r.botMessageStats) setMsgs(r.botMessageStats) } catch { /* keep the last counts */ } }
  const loadChannels = async (off: boolean) => {
    setChannels(null); setChErr(false)
    try { const r = await getJson<{ channels?: Channel[]; error?: string }>(`${API}?ajax=online_channels${off ? '&include_offline=1' : ''}`); if (r.error) throw new Error(r.error); setChannels(r.channels || []) }
    catch { setChErr(true); setChannels([]) }
  }
  const ready = !!data
  useEffect(() => { if (!ready) return; (async () => { await loadChannels(false); loadAi(); await allStatus() })(); const a = setInterval(() => setNow(Date.now()), 30000), b = setInterval(loadMsgs, 60000); return () => { clearInterval(a); clearInterval(b) } }, [ready]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (channel && channels && !channels.some((c) => c.twitch_user_id === channel)) setChannel('') }, [channels, channel])

  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string, v?: string | number) => (L[k] ?? k).replace('%s', String(v ?? ''))
  const q = quota || data.exchangerate, m = msgs || data.bot_messages
  const uptime = (key: string, s: Svc) => {
    if (s.status !== 'Running' || typeof s.uptime_seconds !== 'number') return ''
    const w = Math.max(0, Math.floor(s.uptime_seconds + (now - (loadedAt.current[key] || now)) / 1000)), d = Math.floor(w / 86400), h = Math.floor((w % 86400) / 3600), mi = Math.floor((w % 3600) / 60), se = w % 60
    const p: string[] = []; if (d) p.push(d + l('uptime_days')); if (h) p.push(h + l('uptime_hours')); if (mi) p.push(mi + l('uptime_minutes')); if (!d && !h && (se || !p.length)) p.push(se + l('uptime_seconds'))
    return `${l('uptime_prefix')} ${p.join(' ')}`
  }
  const tone = (s: string) => s === 'Running' ? 'ok' : (s === 'SHUTDOWN' || s === 'Coming soon') ? 'muted' : (s === 'Stopped' || s === 'Failed') ? 'err' : 'warn'
  const control = (key: string, unit: string, label: string, action: 'start' | 'stop' | 'restart') => setAsk({
    title: l('confirm_title'), ok: `${action[0].toUpperCase()}${action.slice(1)} ${label}`, danger: action !== 'start',
    text: <>This will {action} <strong>{label}</strong> ({unit}) on the live server.</>,
    run: async () => {
      setSvcBusy((b) => ({ ...b, [key]: true }))
      try {
        const r = await post<Res & { output?: string }>(API, { service: unit, action })
        if (r.success) { setToast({ kind: 'ok', text: l('service_action_success').replace('{svc}', label).replace('{act}', l({ start: 'action_started', stop: 'action_stopped', restart: 'action_restarted' }[action])) }); setTimeout(() => status(key, action === 'stop' ? 0 : 6), action === 'stop' ? 500 : 2000) }
        else setToast({ kind: 'err', text: `${l('command_failed')}: ${r.output || r.message || l('check_logs')}` })
      } catch (e) { setToast({ kind: 'err', text: `${l('net_error_prefix')} ${(e as Error).message}` }) }
      finally { setSvcBusy((b) => ({ ...b, [key]: false })) }
    } })
  const refreshToken = (key: string, name: string) => setAsk({
    title: l('confirm_title'), ok: l(TOKENS.find((t) => t[0] === key)![2]), text: <>This runs the <strong>{name}</strong> token refresh script on the live server.</>,
    run: async () => {
      setTokBusy(key); setOut({ title: `${name} - ${l('live_output_suffix')}`, text: l('refreshing') })
      try { const r = await post<Res & { output?: string }>(API, { refresh_token_script: key }); const o = (r.output || '').trim(); setOut({ title: `${name} - ${l('live_output_suffix')}`, text: `${o ? o + '\n\n' : ''}${l('process_done')} ${r.success ? l('success') : l('failed')}\n` }) }
      catch (e) { setOut({ title: `${name} - ${l('live_output_suffix')}`, text: `[ERROR] ${(e as Error).message || l('an_error_occurred')}\n` }) }
      finally { setTokBusy('') }
    } })
  const checkQuota = async () => {
    setQBusy(true)
    try { const r = await post<Res & Partial<Quota>>(API, { check_exchangerate_quota: 1 }); if (r.success) { setQuota({ count: r.count ?? q.count, reset_day: r.reset_day ?? q.reset_day, days_remaining: r.days_remaining ?? q.days_remaining, updated: r.updated ?? q.updated }); setToast({ kind: 'ok', text: r.message || l('exchangerate_synced') }) } else setToast({ kind: 'err', text: r.message || l('exchangerate_sync_failed') }) }
    catch (e) { setToast({ kind: 'err', text: `${l('net_error_prefix')} ${(e as Error).message}` }) } finally { setQBusy(false) }
  }
  const chName = channels?.find((c) => c.twitch_user_id === channel)
  const send = () => setAsk({ title: l('confirm_title'), ok: l('send_message_btn'), text: <>Send this message to <strong>{chName?.twitch_display_name || channel}</strong>'s Twitch chat as the bot?<br /><em>{message}</em></>, run: async () => {
    setSending(true)
    try { const r = await post(API, { send_message: 1, channel_id: channel, message }); setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? '' : l('unknown_error')) }); if (r.success) { setMessage(''); setTpl('') } }
    catch (e) { setToast({ kind: 'err', text: `${l('net_error_prefix')} ${(e as Error).message}` }) } finally { setSending(false) }
  } })
  const validateSo = (v: string) => {
    setSo(v); const u = v.trim().replace(/^@+/, ''), id = ++soReq.current
    if (!u) { setSoState(''); setSoMsg(''); return }
    if (!/^[A-Za-z0-9_]{3,25}$/.test(u)) { setSoState('err'); setSoMsg(l('invalid_username_format')); return }
    setSoState('loading'); setSoMsg(l('validating_username'))
    setTimeout(async () => {
      if (id !== soReq.current) return
      try { const r = await getJson<{ valid?: boolean; message?: string }>(`${API}?ajax=validate_shoutout_user&login=${encodeURIComponent(u.toLowerCase())}`); if (id !== soReq.current) return; setSoState(r.valid ? 'ok' : 'err'); setSoMsg(r.valid ? l('username_validated') : (r.message || l('username_validation_failed'))) }
      catch { if (id === soReq.current) { setSoState('err'); setSoMsg(l('unable_validate_username')) } }
    }, 1000)
  }
  const shout = () => setAsk({ title: l('confirm_title'), ok: l('send_shoutout_btn'), text: <>Send a shoutout for <strong>{so.trim()}</strong> in <strong>{chName?.twitch_display_name || channel}</strong>'s channel?</>, run: async () => {
    setSoBusy(true)
    try { const r = await post(API, { send_shoutout: 1, channel_id: channel, shoutout_username: so.trim().replace(/^@+/, '') }); setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? '' : l('fail_send_shoutout')) }); if (r.success) { setSo(''); setSoState(''); setSoMsg('') } }
    catch (e) { setToast({ kind: 'err', text: `${l('net_error_prefix')} ${(e as Error).message}` }) } finally { setSoBusy(false) }
  } })
  const tokenName = (k: string, n: string) => n || (k === 'custom_bot' ? l('custom_bots') : k === 'twitch_app' ? l('twitch_app_token') : data.youtube)

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-shield-alt" /> {l('heading')}</h2>
        <p className="adm-gap">{l('intro')}</p>
        <div className="adm-inline">{LINKS.map(([old, k, ic, cls]) => <a key={old} className={`btn ${cls}`} href={linkFor(old)}><i className={ic} /> {l(k)}</a>)}</div>
      </section>

      <section className="card">
        <div className="card-head"><h2 className="card-h"><i className="fas fa-server" /> {l('server_overview')}</h2>
          <button type="button" className="btn btn-ghost" title={l('refresh_server_status')} onClick={allStatus}><i className="fas fa-sync-alt" /> {l('refresh')}</button></div>
        <div className="adm-groups">{GROUPS.map(([badge, color, g, defs]) => (
          <div key={g} className="adm-group">
            <h3 className="adm-h3"><span className={`pill adm-pill-${color}`}>{badge}</span> {l(g)}</h3>
            <p className="muted adm-gap">{l(`${g}_desc`)}</p>
            <div className="adm-svc-grid">{defs.map(([key, unit, lab, sub, ic]) => {
              const s = svc[key], label = l(lab), st = s?.status, busy = !!svcBusy[key] || !s
              const dead = st === 'SHUTDOWN' || st === 'Coming soon', running = st === 'Running', stopped = st === 'Stopped' || st === 'Failed'
              return (
                <div key={key} className="adm-svc">
                  <div className="adm-svc-top"><i className={`${ic} adm-svc-ic adm-tone-${color}`} /><div className="adm-min0"><strong>{label}</strong>{sub && <span className="muted adm-svc-sub">{l(sub)}</span>}</div></div>
                  <div className="adm-svc-meta" aria-live="polite">
                    {s ? <span className={`adm-svc-st adm-st-${tone(s.status)}`}>{s.status === 'Error' ? l('status_error') : s.status}</span> : <span className="bp-skel" aria-label={l('status_loading')} />}
                    <span className="pill">PID: {s ? s.pid : '...'}</span>
                    {s && uptime(key, s) && <span className="muted">{uptime(key, s)}</span>}
                  </div>
                  <div className="adm-svc-btns">
                    <button type="button" className="btn btn-ok" aria-label={`Start ${label}`} title={`Start ${label}`} disabled={busy || dead || running} onClick={() => control(key, unit, label, 'start')}><i className="fas fa-play" /></button>
                    <button type="button" className="btn btn-danger" aria-label={`Stop ${label}`} title={`Stop ${label}`} disabled={busy || dead || stopped} onClick={() => control(key, unit, label, 'stop')}><i className="fas fa-stop" /></button>
                    <button type="button" className="btn btn-warn" aria-label={`Restart ${label}`} title={`Restart ${label}`} disabled={busy || dead} onClick={() => control(key, unit, label, 'restart')}><i className="fas fa-redo" /></button>
                  </div>
                </div>)
            })}</div>
          </div>))}</div>
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-key" /> {l('token_management')}</h2>
        <div className="adm-svc-grid">{TOKENS.map(([k, n, btn, ic]) => (
          <div key={k} className="adm-svc">
            <div className="adm-svc-top"><i className={`${ic} adm-svc-ic`} /><div><span className="muted adm-svc-sub">{l('token_service')}</span><strong>{tokenName(k, n)}</strong></div></div>
            <button type="button" className="btn btn-block" disabled={!!tokBusy} onClick={() => refreshToken(k, tokenName(k, n))}><i className={`fas ${tokBusy === k ? 'fa-spinner fa-spin' : 'fa-sync'}`} /> {tokBusy === k ? l('refreshing') : l(btn)}</button>
          </div>))}</div>
      </section>

      <section className="card">
        <div className="card-head"><h2 className="card-h"><i className="fas fa-coins" /> {l('exchangerate_quota')}</h2>
          <button type="button" className="btn" disabled={qBusy} onClick={checkQuota}><i className={`fas ${qBusy ? 'fa-spinner fa-spin' : 'fa-sync'}`} /> {qBusy ? l('exchangerate_checking') : l('exchangerate_check_now')}</button></div>
        <div className="adm-stats adm-gap">
          <div className="adm-stat adm-stat-info"><span className="adm-stat-label">{l('exchangerate_remaining')}</span><span className="adm-stat-val">{q.count === null ? '--' : q.count.toLocaleString()}</span></div>
          <div className="adm-stat adm-stat-accent"><span className="adm-stat-label">{l('exchangerate_reset_day')}</span><span className="adm-stat-val">{q.reset_day || '--'}</span></div>
          <div className="adm-stat adm-stat-warn"><span className="adm-stat-label">{l('exchangerate_days_remaining')}</span><span className="adm-stat-val">{q.days_remaining ?? '--'}</span></div>
        </div>
        <p className="muted">{fmtDate(q.updated) ? `${l('last_updated_prefix')} ${fmtDate(q.updated)}` : l('no_data_yet')}</p>
      </section>

      <section className="card">
        <h2 className="card-h"><i className="fas fa-comments" /> {l('bot_message_counts')}</h2>
        <div className="adm-svc-grid">{Object.entries(data.message_systems).map(([k, name]) => { const s = m[k], n = s ? Number(s.messages_sent) : 0; return (
          <div key={k} className="adm-svc">
            <div className="adm-svc-top"><i className={`${MSG_ICON[k] || 'fas fa-robot'} adm-svc-ic`} /><div><span className="muted adm-svc-sub">{l('message_count')}</span><strong>{name}</strong></div></div>
            <div className="adm-count">{n > 0 ? n.toLocaleString() : <span className="muted">{l('not_counting_yet')}</span>}</div>
            <span className="muted">{s?.last_updated ? `${l('last_updated_prefix')} ${fmtDate(s.last_updated)}` : l('no_data_yet')}</span>
          </div>) })}</div>
      </section>

      <div className="adm-cols">
        <section className="card">
          <div className="card-head"><h2 className="card-h"><i className="fas fa-brain" /> {l('ai_platform_stats')}</h2>
            <button type="button" className="btn btn-ghost" disabled={aiBusy} onClick={loadAi}><i className={`fas fa-sync-alt${aiBusy ? ' fa-spin' : ''}`} /> {l('refresh')}</button></div>
          {aiErr ? <div className="notice notice-error">{l('fail_load_ai_stats')}</div> : ai === null ? <p className="muted"><span className="bp-skel" /> {l('loading_ai_stats')}</p>
            : <div className="adm-ai" dangerouslySetInnerHTML={{ __html: ai }} />}
        </section>
        <section className="card">
          <h2 className="card-h"><i className="fas fa-paper-plane" /> {l('send_bot_message')}</h2>
          <form onSubmit={(e) => { e.preventDefault(); if (channel && message.trim()) send() }}>
            <label className="fld"><span className="fld-label">{l('select_channel')}</span>
              <select className="input" value={channel} disabled={!channels || chErr || channels.length === 0} onChange={(e) => setChannel(e.target.value)}>
                {!channels ? <option value="">{l('loading_channels')}</option> : chErr ? <option value="">{l('err_bots_inventory')}</option> : channels.length === 0 ? <option value="">{l(offline ? 'no_channels_found' : 'no_online_channels')}</option>
                  : <><option value="">{l('choose_channel')}</option>{channels.map((c) => <option key={c.twitch_user_id} value={c.twitch_user_id}>{[c.twitch_display_name || c.twitch_user_id, c.bot_type ? `(${c.bot_type})` : '', c.is_online ? '' : l('offline_suffix')].filter(Boolean).join(' ')}</option>)}</>}
              </select><span className="muted">{l('channel_list_help')}</span></label>
            <label className="adm-check"><input type="checkbox" checked={offline} onChange={(e) => { setOffline(e.target.checked); loadChannels(e.target.checked) }} /> {l('include_offline_channels')}</label>
            <label className="fld"><span className="fld-label">{l('template')}</span>
              <select className="input" value={tpl} onChange={(e) => { setTpl(e.target.value); if (e.target.value) setMessage(data.templates[e.target.value]) }}>
                <option value="">{l('choose_template')}</option>{Object.keys(data.templates).map((k) => <option key={k} value={k}>{k}</option>)}
              </select></label>
            <label className="fld"><span className="fld-label">{l('message_label')}</span>
              <textarea className="input" rows={4} maxLength={255} value={message} placeholder={l('message_placeholder')} onChange={(e) => setMessage(e.target.value)} />
              <span className="muted" aria-live="polite">{l('char_count', message.length)}</span></label>
            <button type="submit" className="btn" disabled={!channel || !message.trim() || sending}>{sending ? l('sending') : l('send_message_btn')}</button>
          </form>
          <hr className="adm-hr" />
          <label className="fld"><span className="fld-label">{l('shoutout_username')}</span>
            <input className={`input${soState === 'err' ? ' input-err' : ''}`} value={so} placeholder={l('shoutout_username_placeholder')} onChange={(e) => validateSo(e.target.value)} />
            <span className={soState === 'err' ? 'adm-err' : 'muted'} aria-live="polite">{!channel ? l('select_channel_above') : soMsg || l('channel_will_shoutout')}</span></label>
          <button type="button" className="btn" disabled={!channel || soState !== 'ok' || soBusy} onClick={shout}>{soBusy ? l('sending') : l('send_shoutout_btn')}</button>
        </section>
      </div>

      {out && <Modal title={out.title} icon="fas fa-terminal" wide onClose={() => setOut(null)}>
        <pre className="adm-log adm-out">{out.text}</pre>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setOut(null)}>{l('close')}</button></div></Modal>}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
