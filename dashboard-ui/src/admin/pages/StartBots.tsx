import { useEffect, useRef, useState } from 'react'
import Modal from '../../components/Modal'
import { openStream, startStream, stripAnsi } from '../termStream'
import { Confirm, getJson, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Res, type Toast } from '../ui'

// Port of dashboard/admin/start_bots.php: intro, missing-bots banner, toolbar (Refresh All,
// Refresh Status, Refresh Token Status, Refresh Mod Status, Restart All, search) and the users
// table with bot status, type, uptime, token, mod status and per-row actions, plus the console.
// Like the old page, every user's token is validated on load and an invalid one is renewed straight
// away, because that token is what the mod status lookup uses. Mod status follows each token check.
type U = { id: number; username: string; twitch_user_id: string; display_name: string; profile_image: string; custom_enabled: boolean }
type Inv = { username: string; bot_type?: string; pid?: number | string; uptime_seconds?: number | string | null; uptime_human?: string; custom?: boolean; last_seen_ago_seconds?: number; last_seen_at?: string; currently_running?: boolean; was_running?: boolean }
type Running = { success: boolean; message?: string; bots: Inv[]; snapshot: { missing?: Inv[] } | null }
type Tok = { state: 'idle' | 'busy' | 'ok' | 'bad' | 'renewing' | 'renewed' | 'failed' | 'err'; text?: string }
type Mod = { state: 'busy' | 'mod' | 'banned' | 'not' | 'skip' | 'err'; reason?: string }
const API = '/api/start_bots.php', BOT_ID = '971436498'
const fmt = (s: string, ...v: (string | number)[]) => { let i = 0; return s.replace(/%s/g, () => String(v[i++] ?? '')) }
const lower = (s?: string) => String(s || '').toLowerCase()

export default function StartBots() {
  const { data, err, reload } = useLoad<{ users: U[]; L: Record<string, string> }>(API)
  const [inv, setInv] = useState<Running | null>(null), [invErr, setInvErr] = useState('')
  const [tok, setTok] = useState<Record<string, Tok>>({}), [mod, setMod] = useState<Record<string, Mod>>({})
  const [busy, setBusy] = useState<Record<string, string>>({}), [custom, setCustom] = useState<Record<string, boolean>>({})
  const [q, setQ] = useState(''), [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  const [con, setCon] = useState<{ user: string; status: string; tone: string; text: string } | null>(null), closeCon = useRef<() => void>(() => {}), conOut = useRef<HTMLPreElement>(null)
  const [restartAll, setRestartAll] = useState('')
  const ready = !!data

  const loadInv = async () => {
    setInvErr('')
    try { const r = await getJson<Running>(`${API}?get_running_bots=1`); if (!r.success) throw new Error(r.message || 'Error'); setInv(r); return r }
    catch (e) { setInvErr((e as Error).message); return null }
  }
  const checkMod = async (id: string) => {
    if (id === BOT_ID) { setMod((m) => ({ ...m, [id]: { state: 'skip' } })); return }
    setMod((m) => ({ ...m, [id]: { state: 'busy' } }))
    try { const r = await post<Res & { is_mod?: boolean; is_banned?: boolean; ban_reason?: string }>(API, { check_bot_mod_status: 1, twitch_user_id: id }); setMod((m) => ({ ...m, [id]: !r.success ? { state: 'err' } : r.is_mod ? { state: 'mod' } : r.is_banned ? { state: 'banned', reason: r.ban_reason } : { state: 'not' } })) }
    catch { setMod((m) => ({ ...m, [id]: { state: 'err' } })) }
  }
  const allMods = async (users: U[]) => { const queue = users.filter((u) => u.twitch_user_id).map((u) => u.twitch_user_id); await Promise.all([0, 1, 2].map(async () => { while (queue.length) await checkMod(queue.shift()!) })) }
  useEffect(() => { if (!ready) return; loadInv(); allTokens(data!.users); return () => closeCon.current() }, [ready]) // eslint-disable-line react-hooks/exhaustive-deps
  // validate() is declared after the early returns; it only reads state setters and L, which exist by then
  const allTokens = async (users: U[]) => { const queue = users.filter((u) => u.twitch_user_id).map((u) => u.twitch_user_id); await Promise.all([0, 1, 2].map(async () => { while (queue.length) await validateRef.current(queue.shift()!) })) }
  const validateRef = useRef<(id: string) => Promise<void>>(async () => {})
  useEffect(() => { if (conOut.current) conOut.current.scrollTop = conOut.current.scrollHeight }, [con?.text])

  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string, ...v: (string | number)[]) => fmt(L[k] ?? k, ...v)
  const typeLabel = (t?: string) => ({ beta: l('type_beta'), stable: l('type_stable'), custom: l('type_custom'), v6: l('type_v6') } as Record<string, string>)[lower(t)] || l('label_unknown')
  const typeTone = (t?: string) => ({ beta: 'info', stable: 'ok', custom: 'accent', v6: '' } as Record<string, string>)[lower(t)] ?? 'warn'
  const upt = (sec: number) => { if (!Number.isFinite(sec) || sec < 0) return l('label_unknown'); const t = Math.floor(sec); if (t < 60) return `${t}s`; const d = Math.floor(t / 86400), h = Math.floor((t % 86400) / 3600), m = Math.floor((t % 3600) / 60); return d ? `${d}d ${h}h ${m}m` : h ? `${h}h ${m}m` : `${m}m` }
  const ago = (sec?: number) => Number(sec) < 10 ? '<10s' : upt(Number(sec))
  const find = (u: string): Inv | null => {
    if (!inv) return null
    const live = inv.bots.find((b) => lower(b.username) === lower(u)); if (live) return { ...live, currently_running: true }
    const miss = (inv.snapshot?.missing || []).find((b) => lower(b.username) === lower(u)); return miss ? { ...miss, currently_running: false, was_running: true } : null
  }
  const missing = inv?.snapshot?.missing || []
  const running = data.users.map((u) => ({ u, i: find(u.username) })).filter((x) => x.i?.currently_running)
  const wantCustom = (u: U, i: Inv | null) => custom[u.username] ?? !!(i?.currently_running && (i.custom || lower(i.bot_type) === 'custom'))
  const setB = (u: string, v: string) => setBusy((b) => ({ ...b, [u]: v }))

  const validate = async (id: string) => {
    setTok((t) => ({ ...t, [id]: { state: 'busy' } }))
    try {
      const r = await post<Res & { valid?: boolean; expires_in?: number; is_mod?: boolean; is_banned?: boolean; ban_reason?: string }>(API, { validate_user_token: 1, twitch_user_id: id })
      if (r.success && r.valid) {
        setTok((t) => ({ ...t, [id]: { state: 'ok', text: l('token_valid', Math.floor((r.expires_in || 0) / 3600)) } }))
        // validate_user_token reports is_mod false when its mod lookup fails, so only trust a yes or a ban here and recheck the rest.
        if (typeof r.is_mod !== 'undefined' && id !== BOT_ID) { if (r.is_mod) setMod((m) => ({ ...m, [id]: { state: 'mod' } })); else if (r.is_banned) setMod((m) => ({ ...m, [id]: { state: 'banned', reason: r.ban_reason } })); else checkMod(id) } else checkMod(id)
        return
      }
      setTok((t) => ({ ...t, [id]: { state: 'renewing' } }))
      const n = await post<Res & { expires_in?: number }>(API, { renew_user_token: 1, twitch_user_id: id })
      setTok((t) => ({ ...t, [id]: n.success ? { state: 'renewed', text: n.expires_in ? l('token_renewed_hours', Math.floor(n.expires_in / 3600)) : l('token_renewed') } : { state: 'failed', text: n.message } }))
      checkMod(id)
    } catch { setTok((t) => ({ ...t, [id]: { state: 'err' } })) }
  }
  validateRef.current = validate
  const refreshTokens = () => setAsk({ title: l('btn_refresh_token_status'), ok: l('btn_refresh_token_status'), text: <>This validates every user's tokens. Expired or invalid tokens are renewed automatically, the same as the old page. Continue for {data.users.length} user(s)?</>, run: async () => {
    setToast({ kind: 'ok', text: l('toast_refreshing_tokens', data.users.length) })
    await allTokens(data.users)
    setToast({ kind: 'ok', text: l('toast_token_refresh_complete') })
  } })
  const refreshStatus = async () => { setToast({ kind: 'ok', text: l('toast_refreshing_status') }); const r = await loadInv(); if (r) setToast({ kind: 'ok', text: l('toast_status_refreshed') }) }
  const refreshMods = async () => { setToast({ kind: 'ok', text: l('toast_refreshing_mods', data.users.length) }); await allMods(data.users); setToast({ kind: 'ok', text: l('toast_mod_refresh_complete') }) }
  const refreshAll = async () => { setToast({ kind: 'ok', text: l('toast_refreshing_all') }); await loadInv(); await allTokens(data.users) }
  const pollOne = async (u: string, type: string) => { for (let a = 0; a < 3; a++) { await new Promise((r) => setTimeout(r, 1500)); try { const c = await getJson<{ running?: boolean }>(`${API}?check_one_bot=1&username=${encodeURIComponent(u)}&bot_type=${encodeURIComponent(type)}`); if (c.running) break } catch { /* keep polling */ } } await loadInv() }

  const start = (u: U, type: string, i: Inv | null) => {
    const live = i?.currently_running ? lower(i.bot_type) : ''
    const go = async () => {
      setB(u.username, 'start')
      try {
        const body: Record<string, string | number> = { start_user_bot: 1, username: u.username, bot_type: type }
        if (u.custom_enabled) body.use_custom = wantCustom(u, i) ? '1' : '0'
        const r = await post(API, body)
        setToast(r.success ? { kind: 'ok', text: l('toast_started', typeLabel(type), u.display_name) } : { kind: 'err', text: l('toast_start_failed', u.display_name, r.message || l('could_not_start')) })
        if (r.success) await pollOne(u.username, type)
      } catch { setToast({ kind: 'err', text: l('toast_error_starting', u.display_name) }) } finally { setB(u.username, '') }
    }
    setAsk(live && live !== type
      ? { title: l('switch_title'), ok: l('switch_confirm'), danger: true, text: l('switch_text_stop_start', typeLabel(live), typeLabel(type)), run: go }
      : { title: `${type === 'beta' ? l('btn_start_beta') : l('btn_start_stable')}?`, ok: type === 'beta' ? l('btn_start_beta') : l('btn_start_stable'), text: <>Start the {typeLabel(type)} bot for <strong>{u.display_name}</strong> on the live bots server.</>, run: go })
  }
  const switchType = (u: U, i: Inv, target: string) => setAsk({ title: l('switch_title'), ok: l('switch_confirm_target', typeLabel(target)), danger: true, text: l('switch_text_target', typeLabel(target)), run: async () => {
    setB(u.username, 'switch')
    try {
      const body: Record<string, string | number> = { start_user_bot: 1, username: u.username, bot_type: target === 'custom' ? 'beta' : target }
      if (u.custom_enabled) body.use_custom = target === 'custom' ? '1' : '0'
      const r = await post(API, body)
      setToast(r.success ? { kind: 'ok', text: l('toast_started', typeLabel(target), u.display_name) } : { kind: 'err', text: l('toast_start_failed', u.display_name, r.message || l('could_not_start')) })
      if (r.success) await pollOne(u.username, String(body.bot_type))
    } catch { setToast({ kind: 'err', text: l('toast_error_starting', u.display_name) }) } finally { setB(u.username, '') }
    void i
  } })
  const restart = (u: U, i: Inv) => setAsk({ title: l('restart_confirm_title'), ok: l('restart_confirm_btn'), danger: true, text: <>{l('restart_confirm_text')} <strong>{u.display_name}</strong> ({typeLabel(i.bot_type)}, PID {i.pid})</>, run: async () => {
    setB(u.username, 'restart'); setToast({ kind: 'ok', text: l('toast_restarting', typeLabel(i.bot_type), u.display_name) })
    try {
      const body: Record<string, string | number> = { restart_bot: 1, username: u.username, bot_type: String(i.bot_type || 'stable'), pid: Number(i.pid) || 0 }
      if (u.custom_enabled) body.use_custom = wantCustom(u, i) ? '1' : '0'
      const r = await post(API, body); setToast({ kind: r.success ? 'ok' : 'err', text: `${u.username}: ${r.message || l(r.success ? 'restart_success_default' : 'restart_failed_default')}` })
      if (r.success) await pollOne(u.username, String(i.bot_type || 'stable'))
    } catch { setToast({ kind: 'err', text: l('network_error_restart', u.username) }) } finally { setB(u.username, '') }
  } })
  const stop = (u: U, i: Inv) => setAsk({ title: l('stop_confirm_title'), ok: l('stop_confirm_btn'), danger: true, text: l('stop_confirm_text', u.display_name), run: async () => {
    setB(u.username, 'stop'); setToast({ kind: 'ok', text: l('toast_stopping', u.display_name) })
    try { const r = await post(API, { stop_bot: 1, username: u.username, bot_type: lower(i.bot_type) || 'stable', pid: i.was_running ? 0 : Number(i.pid) || 0 }); setToast({ kind: r.success ? 'ok' : 'err', text: r.message || l(r.success ? 'stop_success' : 'stop_failed_default') }); await loadInv() }
    catch { setToast({ kind: 'err', text: l('network_error_stop', u.username) }) } finally { setB(u.username, '') }
  } })
  const makeMod = (u: U) => setAsk({ title: l('btn_make_mod'), ok: l('btn_make_mod'), text: <>{l('btn_make_mod_title')} in <strong>{u.display_name}</strong>'s channel?</>, run: async () => {
    setMod((m) => ({ ...m, [u.twitch_user_id]: { state: 'busy' } }))
    try { const r = await post(API, { make_bot_mod: 1, twitch_user_id: u.twitch_user_id }); setToast({ kind: r.success ? 'ok' : 'err', text: r.success ? l('make_mod_success_text') : `${l('make_mod_failed_title')}: ${r.message || l('make_mod_failed_html')}` }) }
    catch { setToast({ kind: 'err', text: l('make_mod_error_text') }) }
    await checkMod(u.twitch_user_id)
  } })
  const doRestartAll = () => {
    if (!running.length) { setToast({ kind: 'err', text: l('toast_no_running') }); return }
    setAsk({ title: l('restart_all_title'), ok: l('restart_all_confirm'), danger: true, text: l('restart_all_text', running.length), run: async () => {
      const ok: string[] = [], bad: string[] = []
      for (let n = 0; n < running.length; n++) {
        const { u, i } = running[n]; setRestartAll(l('restart_all_progress', u.username, n + 1, running.length))
        try { const r = await post(API, { restart_bot: 1, username: u.username, bot_type: String(i!.bot_type || 'stable'), pid: Number(i!.pid) || 0 }); (r.success ? ok : bad).push(u.username) } catch { bad.push(u.username) }
        await new Promise((r) => setTimeout(r, 300))
      }
      setRestartAll(''); await loadInv()
      setToast(bad.length ? { kind: 'err', text: `${l('restart_all_issues_title')}. ${l('restart_all_issues_success', ok.join(', ') || '-')} ${l('restart_all_issues_failed', bad.join(', '))}` } : { kind: 'ok', text: `${l('restart_all_done_title')} ${l('restart_all_done_html', ok.length)}` })
    } })
  }
  const attach = async (u: U) => {
    closeCon.current()
    setCon({ user: u.username, status: l('console_connecting'), tone: 'info', text: '' })
    try {
      const r = await startStream('bots', `tail -n 200 -f /home/botofthespecter/logs/crash/${u.username.replace(/[^A-Za-z0-9_]/g, '')}.log 2>/dev/null`, { safe: false })
      if (!r.ok || !r.stream) throw new Error(r.error || l('console_stream_disconnected'))
      setCon((c) => c && { ...c, status: l('console_live'), tone: 'ok' })
      closeCon.current = openStream(r.stream, {
        out: (t) => { if (!t.startsWith('Executing on ')) setCon((c) => c && { ...c, text: c.text + stripAnsi(t) + '\n' }) },
        done: () => setCon((c) => c && { ...c, status: l('console_stream_ended'), tone: 'warn' }),
        lost: () => setCon((c) => c && { ...c, status: l('console_stream_disconnected'), tone: 'err' }),
      })
    } catch (e) { setCon((c) => c && { ...c, status: (e as Error).message, tone: 'err' }) }
  }
  const closeConsole = () => { closeCon.current(); closeCon.current = () => {}; setCon(null) }

  const term = q.trim().toLowerCase()
  const rows = data.users.filter((u) => !term || u.username.toLowerCase().includes(term) || u.display_name.toLowerCase().includes(term) || u.twitch_user_id.includes(term))
  const tokPill = (t?: Tok) => !t || t.state === 'idle' ? <span className="pill" title="Use Refresh Token Status to check">Not checked</span>
    : t.state === 'busy' ? <span className="pill">{l('token_validating')}</span> : t.state === 'renewing' ? <span className="pill pill-warn">{l('token_invalid_renewing')}</span>
    : t.state === 'ok' || t.state === 'renewed' ? <span className="pill pill-ok"><i className="fas fa-check-circle" /> {t.text}</span>
    : t.state === 'failed' ? <span className="pill pill-err" title={t.text}>{l('token_renewal_failed')}</span> : <span className="pill pill-err">{l('error')}</span>
  const modPill = (id: string, running: boolean) => { const m = mod[id]; return !m || m.state === 'busy' ? <span className="bp-skel adm-skel-pill" aria-label={l('status_checking')} />
    : m.state === 'skip' ? <span className="pill pill-info">{l('mod_skipped')}</span> : m.state === 'mod' ? <span className="pill pill-ok"><i className="fas fa-check-circle" /> {l('mod_is_moderator')}</span>
    : m.state === 'banned' ? <span className="pill pill-err" title={l('mod_reason_prefix') + (m.reason || l('mod_no_reason'))}><i className="fas fa-ban" /> {l('mod_banned')}</span>
    : m.state === 'not' ? <span className="pill pill-warn"><i className="fas fa-exclamation-triangle" /> {running ? l('mod_running_without_mod') : l('mod_not_a_moderator')}</span> : <span className="pill pill-err">{l('mod_check_failed')}</span> }

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-play-circle" /> {l('page_title')}</h2>
        <p className="muted adm-gap">{l('intro')}</p>
        {missing.length > 0 && <div className="notice notice-warn adm-gap adm-banner" role="status">
          <div><strong><i className="fas fa-exclamation-triangle" /> {l('snapshot_banner_title')}</strong>
            <p>{l('snapshot_banner_text', missing.length)}</p>
            <p className="muted">{missing.slice(0, 12).map((m) => l('snapshot_list_item', m.username || '?', typeLabel(m.bot_type), ago(m.last_seen_ago_seconds))).join(' \u00b7 ')}{missing.length > 12 ? ` \u00b7 +${missing.length - 12}` : ''}</p></div>
          <button type="button" className="btn btn-warn" onClick={refreshStatus}><i className="fas fa-tasks" /> {l('btn_refresh_status')}</button>
        </div>}
        {invErr && <div className="notice notice-error adm-gap">{invErr}</div>}
        <div className="adm-inline adm-gap">
          <button type="button" className="btn btn-ghost" onClick={refreshAll}><i className="fas fa-sync-alt" /> {l('btn_refresh_all')}</button>
          <button type="button" className="btn" onClick={refreshStatus}><i className="fas fa-tasks" /> {l('btn_refresh_status')}</button>
          <button type="button" className="btn btn-ghost" onClick={refreshTokens}><i className="fas fa-key" /> {l('btn_refresh_token_status')}</button>
          <button type="button" className="btn btn-ghost" onClick={refreshMods}><i className="fas fa-user-shield" /> {l('btn_refresh_mod_status')}</button>
          <button type="button" className="btn btn-warn" disabled={!inv || !running.length || !!restartAll} onClick={doRestartAll}><i className={`fas ${restartAll ? 'fa-spinner fa-spin' : 'fa-redo-alt'}`} /> {restartAll || l('btn_restart_all')}</button>
        </div>
        <div className="adm-inline adm-gap"><input className="input" type="search" value={q} placeholder={l('search_placeholder')} aria-label={l('search_placeholder')} onChange={(e) => setQ(e.target.value)} /></div>
        <div className="bp-table adm-left adm-sb"><table>
          <thead><tr><th>{l('th_user')}</th><th>{l('th_twitch_id')}</th><th>{l('th_bot_status')}</th><th>{l('th_bot_type')}</th><th>{l('th_running_for')}</th><th>{l('th_token_status')}</th><th>{l('th_mod_status')}</th><th>{l('th_actions')}</th></tr></thead>
          <tbody>{rows.map((u) => {
            const i = find(u.username), live = !!i?.currently_running, was = !!i?.was_running, rt = lower(i?.bot_type), b = busy[u.username], m = mod[u.twitch_user_id]
            const up = i && live ? (i.uptime_seconds !== null && i.uptime_seconds !== undefined && i.uptime_seconds !== '' && Number.isFinite(Number(i.uptime_seconds)) ? upt(Number(i.uptime_seconds)) : (i.uptime_human || l('label_unknown'))) : ''
            return (
              <tr key={u.id}>
                <td><div className="adm-user">{u.profile_image && <img src={u.profile_image} alt="" width={32} height={32} loading="lazy" />}<span>{u.display_name}</span></div></td>
                <td>{u.twitch_user_id}</td>
                <td>{!inv ? (invErr ? <span className="pill pill-err">{l('error')}</span> : <span className="bp-skel adm-skel-pill" aria-label={l('status_checking')} />)
                  : live ? <span className="pill pill-ok"><i className="fas fa-check-circle" /> {l('status_running_pid', String(i!.pid))}</span>
                  : was ? <span className="pill pill-warn" title={l('status_last_seen_ago', ago(i!.last_seen_ago_seconds))}><i className="fas fa-exclamation-triangle" /> {l('status_was_running', ago(i!.last_seen_ago_seconds))}</span>
                  : <span className="pill pill-err"><i className="fas fa-times-circle" /> {l('status_not_running')}</span>}</td>
                <td>{inv && (live || was ? <span className={`pill pill-${typeTone(i!.bot_type)}`}>{typeLabel(i!.bot_type || 'stable')}</span> : <span className="pill">{l('status_bot_not_running')}</span>)}</td>
                <td>{inv && (live ? <span className="pill pill-info">{up}</span> : was ? <span className="pill pill-warn" title={i!.last_seen_at || ''}>{l('status_last_seen_ago', ago(i!.last_seen_ago_seconds))}</span> : <span className="pill">-</span>)}</td>
                <td>{tokPill(tok[u.twitch_user_id])}</td>
                <td>{modPill(u.twitch_user_id, live)}</td>
                <td><div className="adm-sb-acts">
                  {m?.state === 'not' && <button type="button" className="btn btn-warn" title={l('btn_make_mod_title')} onClick={() => makeMod(u)}><i className="fas fa-user-shield" /> <span className="lbl">{l('btn_make_mod')}</span></button>}
                  {u.custom_enabled && <label className="adm-check" title={l('custom_toggle_title')}><input type="checkbox" checked={wantCustom(u, i)} onChange={(e) => setCustom((c) => ({ ...c, [u.username]: e.target.checked }))} /> {l('custom_toggle')}</label>}
                  {inv && !live && <>
                    <button type="button" className="btn btn-ok" disabled={!!b} onClick={() => start(u, 'stable', i)}><i className="fas fa-play" /> <span className="lbl">{l('btn_start_stable')}</span></button>
                    <button type="button" className="btn" disabled={!!b} onClick={() => start(u, 'beta', i)}><i className="fas fa-flask" /> <span className="lbl">{l('btn_start_beta')}</span></button></>}
                  {live && <>
                    <button type="button" className="btn btn-warn" disabled={!!b} onClick={() => restart(u, i!)}><i className={`fas ${b === 'restart' ? 'fa-spinner fa-spin' : 'fa-sync-alt'}`} /> <span className="lbl">{l('btn_restart')}</span></button>
                    <button type="button" className="btn btn-ghost" disabled={!!b} onClick={() => switchType(u, i!, rt === 'beta' || rt === 'custom' ? 'stable' : 'beta')}><i className="fas fa-exchange-alt" /> <span className="lbl">{rt === 'beta' || rt === 'custom' ? l('switch_to_stable') : l('switch_to_beta')}</span></button>
                    {(rt === 'custom' || u.custom_enabled) && <button type="button" className="btn" disabled={!!b} onClick={() => switchType(u, i!, rt === 'custom' ? 'beta' : 'custom')}><i className="fas fa-user-astronaut" /> <span className="lbl">{rt === 'custom' ? l('switch_to_beta') : l('switch_to_custom')}</span></button>}</>}
                  {(live || was) && <button type="button" className="btn btn-danger" disabled={!!b} onClick={() => stop(u, i!)}><i className={`fas ${b === 'stop' ? 'fa-spinner fa-spin' : 'fa-stop'}`} /> <span className="lbl">{l('btn_stop')}</span></button>}
                  {live && <button type="button" className="btn btn-ghost" title={l('btn_attach_console_title')} onClick={() => attach(u)}><i className="fas fa-terminal" /> <span className="lbl">{l('btn_attach_console')}</span></button>}
                </div></td>
              </tr>)
          })}</tbody>
        </table></div>
      </section>
      {con && <Modal title={l('console_title_prefix', con.user)} icon="fas fa-terminal" wide onClose={closeConsole}>
        <p><span className={`pill pill-${con.tone}`}>{con.status}</span></p>
        <pre ref={conOut} className="adm-log adm-out adm-console">{con.text}</pre>
        <div className="modal-acts"><button type="button" className="btn btn-danger" title={l('console_close_title')} onClick={closeConsole}><i className="fas fa-times" /> {l('console_close')}</button></div>
      </Modal>}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
