import { useEffect, useState, type ReactNode } from 'react'
import Modal from '../../components/Modal'
import { getJson, useLoad, Loading, LoadErr } from '../ui'

// Port of dashboard/admin/discordbot_overview.php: search, config cards with names
// resolved per user (3 at a time), totals, and the full-config modal.
type Cfg = Record<string, any> & { user_id: number; username: string; is_linked: boolean; tracked_streams: { username: string; stream_url: string }[]; server_management_settings: Record<string, any> | [] }
type Names = { account?: string; guild?: string; channels?: Record<string, { name: string; type: number }>; roles?: Record<string, string>; token?: string } | null
const API = '/api/discordbot_overview.php'
const cache: Record<number, Promise<Names>> = {}
const loadNames = (id: number) => (cache[id] ??= getJson<Names>(`${API}?names=${id}`).catch(() => null))


export default function DiscordOverview() {
  const { data, err, reload } = useLoad<{ configs: Cfg[]; L: Record<string, string> }>(API)
  const [q, setQ] = useState(''), [dq, setDq] = useState('')
  const [names, setNames] = useState<Record<number, Names>>({})
  const [open, setOpen] = useState<Cfg | null>(null)
  useEffect(() => { const t = setTimeout(() => setDq(q.trim().toLowerCase()), 220); return () => clearTimeout(t) }, [q])
  useEffect(() => {
    if (!data) return
    const list = [...data.configs]; let stop = false
    const worker = async (): Promise<void> => { const c = list.shift(); if (!c || stop) return; const n = await loadNames(c.user_id); if (!stop) setNames((m) => ({ ...m, [c.user_id]: n })); return worker() }
    for (let i = 0; i < 3; i++) void worker()
    return () => { stop = true }
  }, [data])
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string) => L[k] ?? k
  const cfgs = data.configs
  const shown = dq ? cfgs.filter((c) => c.username.toLowerCase().includes(dq)) : cfgs

  const ref = (kind: string, id: unknown, n: Names | undefined): ReactNode => {
    const v = String(id ?? '')
    if (!/^[0-9]{5,25}$/.test(v)) return v
    if (!n) return <span className="adm-code">{v}</span>
    let text: ReactNode = null
    if (kind === 'guild') text = n.guild || null
    else if (kind === 'channel' && n.channels?.[v]) { const ch = n.channels[v]; const voice = ch.type === 2 || ch.type === 13; text = voice ? <><i className="fas fa-volume-up" /> {ch.name}</> : '#' + ch.name }
    else if (kind === 'role' && n.roles?.[v]) text = '@' + n.roles[v]
    if (text === null) {
      const missing = (kind === 'channel' && n.channels && Object.keys(n.channels).length) || (kind === 'role' && n.roles && Object.keys(n.roles).length)
      return <span className="adm-code">{missing ? `${v} (${l('name_not_found')})` : v}</span>
    }
    return <span title={v}>{text}</span>
  }
  const acct = (n: Names | undefined) => n === undefined ? <span className="bp-skel" /> : (n?.account || '-')
  const notSet = <em className="muted">{l('not_set')}</em>
  const features = (c: Cfg) => {
    const f: string[] = []
    if (c.stream_alert_channel_id) f.push(l('feature_stream_alerts'))
    if (c.time_now_channel_id) f.push(l('feature_time_now'))
    if (c.moderation_channel_id) f.push(l('feature_moderation'))
    if (c.alert_channel_id) f.push(l('feature_alerts'))
    if (c.member_streams_id) f.push(l('feature_stream_monitoring'))
    const m = c.server_management_settings as Record<string, any>
    if (m && !Array.isArray(m) && Object.keys(m).length) {
      ;([['welcome_message_configuration_channel', 'welcome_message'], ['auto_role_assignment_configuration_role_id', 'auto_role'], ['message_tracking_configuration', 'message_tracking'], ['role_tracking_configuration', 'role_tracking'], ['role_history_configuration', 'role_history'], ['user_tracking_configuration', 'user_tracking'], ['server_role_management_configuration', 'server_role_mgmt'], ['reaction_roles_configuration', 'reaction_roles'], ['rules_configuration', 'rules'], ['stream_schedule_configuration', 'stream_schedule']] as const)
        .forEach(([k, n]) => { if (m[k]) f.push(l('feature_' + n)) })
    }
    return f
  }
  const total = (fn: (c: Cfg) => boolean) => cfgs.filter(fn).length

  return (
    <div className="cmds adm">
      <section className="card">
        <div className="card-head">
          <h2 className="card-h"><i className="fab fa-discord" /> {l('title')}</h2>
          <div className="adm-inline">
            <input className="input" type="search" value={q} placeholder={l('search_placeholder')} aria-label={l('search_placeholder')} onChange={(e) => setQ(e.target.value)} />
            <button type="button" className="btn btn-ghost" title={l('clear_title')} onClick={() => setQ('')}>{l('clear')}</button>
          </div>
        </div>
        <p className="muted adm-gap">{l('intro')}</p>
        {cfgs.length === 0 ? <div className="notice notice-info">{l('empty_state')}</div> : <>
          <div className="adm-cards">
            {shown.map((c) => { const n = names[c.user_id]; const f = features(c); return (
              <div key={c.user_id} className="adm-card">
                <div className="adm-card-head"><strong>{c.username}</strong>
                  <span className={`pill ${c.is_linked ? 'pill-ok' : 'pill-warn'}`}>{l(c.is_linked ? 'status_linked' : 'status_not_linked')}</span>
                  {n?.token === 'expired' && <span className="pill pill-err">{l('token_expired')}</span>}
                </div>
                <div className="adm-card-body">
                  <p><strong>{l('card_discord_user')}</strong> {acct(n)}</p>
                  <p><strong>{l('card_guild_id')}</strong> {c.guild_id ? ref('guild', c.guild_id, n) : notSet}</p>
                  <p><strong>{l('card_live_channel')}</strong> {c.live_channel_id ? ref('channel', c.live_channel_id, n) : notSet}</p>
                  <p><strong>{l('card_time_now_channel')}</strong> {c.time_now_channel_id ? ref('channel', c.time_now_channel_id, n) : notSet}</p>
                  {c.tracked_streams.length > 0 && <p><strong>{l('card_tracked_streams')}</strong> <span className="pill pill-info">{c.tracked_streams.length}</span></p>}
                  {f.length > 0 && <div className="adm-gap"><p><strong>{l('card_enabled_features')}</strong></p><div className="adm-chips">{f.map((x) => <span key={x} className="pill">{x}</span>)}</div></div>}
                </div>
                <button type="button" className="btn btn-ghost adm-card-foot" onClick={() => setOpen(c)}>{l('view_full_config')}</button>
              </div>) })}
          </div>
          <div className="adm-totals">
            <span><strong>{l('stat_total_users')}</strong> {cfgs.length}</span>
            <span><strong>{l('stat_linked_users')}</strong> {total((c) => c.is_linked)}</span>
            <span><strong>{l('stat_with_guild')}</strong> {total((c) => !!c.guild_id)}</span>
            <span className="adm-totals-end"><strong>{l('stat_total_tracked_streams')}</strong> {cfgs.reduce((a, c) => a + c.tracked_streams.length, 0)}</span>
          </div>
        </>}
      </section>
      {open && <FullConfig c={open} n={names[open.user_id]} l={l} refFn={ref} acct={acct} onClose={() => setOpen(null)} />}
    </div>
  )
}

function FullConfig({ c, n, l, refFn, acct, onClose }: { c: Cfg; n: Names | undefined; l: (k: string) => string; refFn: (k: string, id: unknown, n: Names | undefined) => ReactNode; acct: (n: Names | undefined) => ReactNode; onClose: () => void }) {
  const [nm, setNm] = useState<Names | undefined>(n)
  useEffect(() => { if (nm === undefined) void loadNames(c.user_id).then(setNm) }, [c.user_id, nm])
  const j = (k: string) => l('js_' + k)
  const yn = (v: unknown) => j(v ? 'val_yes' : 'val_no')
  const ns = <em className="muted">{j('val_not_set')}</em>
  const r = (kind: string, v: unknown) => v ? refFn(kind, v, nm) : ns
  const Row = ({ k, v }: { k: string; v: ReactNode }) => <tr><th scope="row">{j('lbl_' + k)}</th><td>{v}</td></tr>
  const Sec = ({ t, children }: { t: string; children: ReactNode }) => <><h3 className="adm-h3">{j('sec_' + t)}</h3><table className="adm-kv"><tbody>{children}</tbody></table></>
  const m = (Array.isArray(c.server_management_settings) ? {} : c.server_management_settings) as Record<string, any>
  const parse = (v: unknown): Record<string, any> | null => { try { return typeof v === 'string' ? JSON.parse(v) : (v as Record<string, any>) } catch { return null } }
  const Sub = ({ t }: { t: string }) => <tr><td colSpan={2}><strong className="adm-accent">{j('sub_' + t)}</strong></td></tr>
  const status = (s: string, em = true) => <Row k="status" v={em ? <em>{j(s)}</em> : j(s)} />
  const block = (key: string, sub: string, rows: (o: Record<string, any>) => ReactNode, failKey = 'val_configured') => {
    const v = m[key]
    if (!v) return <><Sub t={sub} />{status('val_not_configured')}</>
    const o = parse(v)
    return <><Sub t={sub} />{o ? rows(o) : status(failKey, false)}</>
  }
  const chanBlock = (key: string, sub: string, extra: (o: Record<string, any>) => ReactNode) => {
    const v = m[key]
    if (v) { const o = parse(v); return <><Sub t={sub} />{o && o.channel_id ? <><Row k="channel" v={refFn('channel', o.channel_id, nm)} /><Row k="title" v={o.title || ns} /><Row k="color" v={o.color || j('val_default')} />{extra(o)}</> : status('val_not_configured')}</> }
    if (m[key + '_channel_id']) { const p = key + '_'; return <><Sub t={sub} /><Row k="channel" v={refFn('channel', m[p + 'channel_id'], nm)} /><Row k="title" v={m[p + 'title'] || ns} /><Row k="color" v={m[p + 'colour'] || j('val_default')} />{extra({ accept_role_id: m[p + 'accept_role_id'], timezone: m[p + 'timezone'] })}</> }
    return <><Sub t={sub} />{status('val_not_configured')}</>
  }
  return (
    <Modal title={c.username + j('modal_title_suffix')} icon="fab fa-discord" onClose={onClose}>
      <div className="adm-modal-scroll">
        <Sec t="basic_info"><Row k="twitch_username" v={c.username} /><Row k="discord_linked" v={<span className={`pill ${c.is_linked ? 'pill-ok' : 'pill-warn'}`}>{yn(c.is_linked)}</span>} /><Row k="discord_username" v={acct(nm)} /></Sec>
        <Sec t="server_config"><Row k="guild_id" v={c.guild_id ? refFn('guild', c.guild_id, nm) : ns} /><Row k="manual_ids_mode" v={j(c.manual_ids ? 'val_enabled' : 'val_disabled')} /></Sec>
        <Sec t="stream_config">
          <Row k="live_channel_id" v={r('channel', c.live_channel_id)} /><Row k="time_now_channel_id" v={r('channel', c.time_now_channel_id)} />
          <Row k="online_text" v={c.online_text || ns} /><Row k="offline_text" v={c.offline_text || ns} />
          <tr><td colSpan={2}><hr /></td></tr>
          <Row k="stream_alert_channel" v={r('channel', c.stream_alert_channel_id)} /><Row k="alert_everyone" v={yn(c.stream_alert_everyone)} /><Row k="custom_role_alerts" v={r('role', c.stream_alert_custom_role)} />
        </Sec>
        <Sec t="other_channels"><Row k="moderation_channel" v={r('channel', c.moderation_channel_id)} /><Row k="alert_channel" v={r('channel', c.alert_channel_id)} /><Row k="stream_monitoring_channel" v={r('channel', c.member_streams_id)} /></Sec>
        {c.tracked_streams.length > 0 && <><h3 className="adm-h3">{j('sec_tracked_streams')}</h3>
          <table className="adm-kv"><thead><tr><th>{j('th_username')}</th><th>{j('th_stream_url')}</th></tr></thead><tbody>
            {c.tracked_streams.map((s) => <tr key={s.username}><td>{s.username}</td><td>{s.stream_url ? <a href={s.stream_url} target="_blank" rel="noopener noreferrer" className="adm-url">{s.stream_url}</a> : <em>{j('val_no_url')}</em>}</td></tr>)}
          </tbody></table></>}
        {Object.keys(m).length > 0 && <Sec t="server_mgmt">
          <Sub t="welcome_message" /><Row k="channel" v={r('channel', m.welcome_message_configuration_channel)} />
          {(m.welcome_message_configuration_message || m.welcome_message_configuration_default || m.welcome_message_configuration_embed) && <>
            <Row k="use_default" v={yn(m.welcome_message_configuration_default)} /><Row k="use_embed" v={yn(m.welcome_message_configuration_embed)} />
            <Row k="custom_message" v={m.welcome_message_configuration_message ? <em>{j('val_configured')}</em> : j('val_not_set')} />
            <Row k="color" v={m.welcome_message_configuration_colour || j('val_default')} /></>}
          <Sub t="auto_role" /><Row k="role_id" v={r('role', m.auto_role_assignment_configuration_role_id)} />
          {block('message_tracking_configuration', 'message_tracking', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="log_channel" v={r('channel', o.log_channel_id)} /><Row k="track_edits" v={yn(o.track_edits)} /><Row k="track_deletes" v={yn(o.track_deletes)} /></>, 'val_enabled')}
          {block('role_tracking_configuration', 'role_tracking', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="log_channel" v={r('channel', o.log_channel_id)} /><Row k="track_additions" v={yn(o.track_additions)} /><Row k="track_removals" v={yn(o.track_removals)} /></>, 'val_enabled')}
          {block('role_history_configuration', 'role_history', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="retention_days" v={o.retention_days || '30'} /></>)}
          {block('server_role_management_configuration', 'server_role_mgmt', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="log_channel" v={r('channel', o.log_channel_id)} /><Row k="track_creation" v={yn(o.track_creation)} /><Row k="track_deletion" v={yn(o.track_deletion)} /><Row k="track_edits" v={yn(o.track_edits)} /></>)}
          {block('user_tracking_configuration', 'user_tracking', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="log_channel" v={r('channel', o.log_channel_id)} />{['joins', 'leaves', 'nickname_changes', 'username_changes', 'avatar_changes', 'status_changes'].map((x) => <Row key={x} k={'track_' + x} v={yn(o['track_' + x])} />)}</>)}
          {block('reaction_roles_configuration', 'reaction_roles', (o) => <><Row k="enabled" v={yn(o.enabled)} /><Row k="channel_id" v={r('channel', o.channel_id)} /><Row k="message_id" v={o.message_id || ns} /><Row k="allow_multiple" v={yn(o.allow_multiple)} /><Row k="mappings_configured" v={yn(o.mappings)} /></>)}
          {chanBlock('rules_configuration', 'rules_config', (o) => <Row k="accept_role" v={r('role', o.accept_role_id)} />)}
          {chanBlock('stream_schedule_configuration', 'stream_schedule', (o) => <Row k="timezone" v={o.timezone || 'UTC'} />)}
        </Sec>}
      </div>
      <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={onClose}>{l('close')}</button></div>
    </Modal>
  )
}
