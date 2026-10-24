import { Children, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { get } from '../bot'
import Modal from '../components/Modal'
import Combobox, { type ComboOption } from '../components/Combobox'
import { Switch, ToastBar, send, type Toast } from './commands/shared'
import { Confirm } from './insights/shared'

// Port of dashboard/discordbot.php: link state, server pick, Twitch online
// alert, stream monitoring, audit log channels, the server management
// toggles and every feature card, plus the custom embed builder.
// Endpoints: public/api/discord/ (scripts/sync-live-api.sh). Linking still
// runs through StreamersConnect and returns to the live discordbot.php.
const API = '/api/discord/discord.php'
const CFG = '/api/discord/channel-config.php'
const SET = '/api/discord/server-settings.php'
const INVITE = 'https://discord.com/oauth2/authorize?client_id=1170683250797187132&permissions=581651049737302&integration_type=0&scope=bot'
type Ch = { id: string; name: string; type?: number }
type V = Record<string, string | number | boolean | null>
type Page = { success: boolean; message: string; error: string | null; act_as: boolean; is_linked: boolean; needs_relink: boolean; needs_reauth: boolean; expires_str: string; auth_url: string; use_manual_ids: boolean; has_guild: boolean; guilds: { id: string; name: string }[]; channels: Ch[]; voice_channels: Ch[]; roles: Ch[]; streamers: { username: string; stream_url: string }[]; features: Record<string, boolean | number>; values: V }
type Embed = { id: number; embed_name: string; title?: string | null; description?: string | null; color?: string | null; url?: string | null; thumbnail_url?: string | null; image_url?: string | null; footer_text?: string | null; footer_icon_url?: string | null; author_name?: string | null; author_url?: string | null; author_icon_url?: string | null; timestamp_enabled?: number | boolean; fields?: string | null }
type Field = { name: string; value: string; inline: boolean }
const FEATURES: [string, string][] = [['welcomeMessage', 'Welcome Message'], ['autoRole', 'Auto Role on Join'], ['roleHistory', 'Role History'], ['messageTracking', 'Message Tracking'], ['roleTracking', 'Role Tracking'], ['serverRoleManagement', 'Server Role Management'], ['userTracking', 'User Tracking'], ['reactionRoles', 'Reaction Roles'], ['rulesConfiguration', 'Rules Configuration'], ['streamSchedule', 'Stream Schedule'], ['embedBuilder', 'Embed Builder'], ['freeGames', 'Free Games']]
const CLEAR: Record<string, [string, string, string]> = {
  welcomeMessage: ['Clear Welcome Message?', 'welcome message', 'clear_welcome_message'], autoRole: ['Clear Auto Role Configuration?', 'auto role', 'clear_auto_role'],
  roleHistory: ['Clear Role History?', 'role history', 'clear_role_history'], messageTracking: ['Clear Message Tracking?', 'message tracking', 'clear_message_tracking'],
  roleTracking: ['Clear Role Tracking?', 'role tracking', 'clear_role_tracking'], serverRoleManagement: ['Clear Server Role Management?', 'server role management', 'clear_server_role_management'],
  userTracking: ['Clear User Tracking?', 'user tracking', 'clear_user_tracking'], reactionRoles: ['Clear Reaction Roles?', 'reaction roles', 'clear_reaction_roles'],
  rulesConfiguration: ['Clear Rules Configuration?', 'rules', 'clear_rules'], streamSchedule: ['Clear Stream Schedule?', 'stream schedule', 'clear_stream_schedule'], freeGames: ['Clear Free Games Configuration?', 'free games', 'clear_freestuff'],
}
const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))
const on = (v: unknown) => v === true || v === 1 || v === '1'
const errText = (e: unknown) => { const m = (e as Error).message; return m && !m.startsWith('http_') ? m : 'That did not work. Please try again.' }

function Check({ id, label, checked, onChange, disabled, help }: { id: string; label: ReactNode; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean; help?: ReactNode }) {
  if (help) return <div className="st-opt"><Check id={id} label={label} checked={checked} onChange={onChange} disabled={disabled} /><p className="muted small st-opt-help">{help}</p></div>
  return <label className="dc-check" htmlFor={id}><input id={id} type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} /> <span>{label}</span></label>
}
// Two-column masonry: each card goes into whichever column is currently
// shorter (greedy, in page order), re-measured on resize and content change.
const GAP = 16
export function Masonry({ children }: { children: ReactNode }) {
  const items = Children.toArray(children)
  const box = useRef<HTMLDivElement>(null)
  const [cols, setCols] = useState(2), [place, setPlace] = useState<number[]>([])
  const st = useRef({ cols, place }); st.current = { cols, place }
  const measure = () => {
    const el = box.current; if (!el) return
    const n = el.clientWidth >= 900 ? 2 : 1
    const hs: number[] = []
    el.querySelectorAll<HTMLElement>(':scope > .dc-mcol > .dc-mitem').forEach((x) => { hs[Number(x.dataset.i)] = x.offsetHeight })
    const tot = Array(n).fill(0), next = hs.map((h) => { const c = tot.indexOf(Math.min(...tot)); tot[c] += h + GAP; return c })
    if (n !== st.current.cols) setCols(n)
    if (next.join() !== st.current.place.join()) setPlace(next)
  }
  useLayoutEffect(measure)
  useEffect(() => {
    const el = box.current; if (!el) return
    const ro = new ResizeObserver(() => measure())
    ro.observe(el); el.querySelectorAll(':scope > .dc-mcol > .dc-mitem').forEach((x) => ro.observe(x))
    return () => ro.disconnect()
  }, [items.length, cols, place.join()]) // eslint-disable-line react-hooks/exhaustive-deps
  return <div ref={box} className="dc-masonry" style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}>
    {Array.from({ length: cols }, (_, c) => <div key={c} className="dc-mcol">{items.map((it, i) => (place[i] ?? 0) % cols === c && <div key={i} className="dc-mitem" data-i={i}>{it}</div>)}</div>)}
  </div>
}
function Colour({ id, value, onChange }: { id: string; value: string; onChange: (v: string) => void }) {
  const [h, setH] = useState(value)
  useEffect(() => setH(value), [value])
  return <div className="dc-colour"><input type="color" className="dc-color" aria-label="Pick colour" value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#5865f2'} onChange={(e) => onChange(e.target.value)} /><input id={id} className="input mono dc-hex" maxLength={7} spellCheck={false} value={h} onChange={(e) => { const x = e.target.value.trim(); setH(x); if (/^#[0-9a-f]{6}$/i.test(x)) onChange(x.toLowerCase()) }} aria-invalid={!/^#[0-9a-f]{6}$/i.test(h)} /></div>
}
function Fld({ id, label, help, children, req }: { id?: string; label: ReactNode; help?: ReactNode; children: ReactNode; req?: boolean }) {
  return <div className="fld dc-fld">{id ? <label htmlFor={id}>{label}{req && <span className="dc-req"> *</span>}</label> : <span className="fld-label">{label}</span>}{help && <span className="muted small">{help}</span>}{children}</div>
}

export default function DiscordBot() {
  const [p, setP] = useState<Page | null>(null), [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null)
  const [busy, setBusy] = useState(''), [ask, setAsk] = useState<{ title: string; body: ReactNode; yes: string; danger?: boolean; run: () => void } | null>(null)
  const [showStreamers, setShowStreamers] = useState(false)
  async function load() {
    try { const r = await get<Page>(API, 30000); if (!r.success && r.error && !r.values) throw new Error(r.error); setP(r); setErr('') }
    catch (e) { setErr(errText(e) === 'That did not work. Please try again.' ? 'The Discord Bot settings could not load. Refresh to try again.' : errText(e)) }
  }
  useEffect(() => { load() }, [])
  // Form saves post to the page itself (like the live forms) and get the page back.
  async function form(key: string, body: Record<string, string | boolean>) {
    setBusy(key)
    try {
      const b: Record<string, string> = {}
      for (const [k, v] of Object.entries(body)) { if (v === false) continue; b[k] = v === true ? '1' : v }
      const r = await send<Page>(API, b)
      if (r.values) setP(r)
      if (r.error || !r.success) setToast({ kind: 'err', text: r.error || 'That did not save. Please try again.' })
      else setToast({ kind: 'ok', text: r.message || 'Saved.' })
    } catch (e) { setToast({ kind: 'err', text: errText(e) }) }
    setBusy('')
  }
  async function cfg(key: string, body: Record<string, unknown>, after?: () => void) {
    setBusy(key)
    try {
      const res = await fetch(CFG, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      if (res.status === 401) throw new Error('Your session expired. Sign in again.')
      const r = await res.json() as { success: boolean; message?: string; error?: string }
      if (!r.success) throw new Error(r.message || r.error || 'That did not save. Please try again.')
      setToast({ kind: 'ok', text: r.message || 'Configuration saved successfully' }); after?.()
    } catch (e) { setToast({ kind: 'err', text: `Failed to save: ${errText(e)}` }) }
    setBusy('')
  }
  async function feature(name: string, value: boolean) {
    if (!p) return
    setP({ ...p, features: { ...p.features, [name]: value } })
    try {
      const res = await fetch(SET, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ setting: name, value }) })
      const r = await res.json() as { success: boolean; message?: string; error?: string }
      if (!r.success) throw new Error(r.message || r.error)
      setToast({ kind: 'ok', text: 'Setting updated successfully' })
    } catch (e) { setP((x) => x && { ...x, features: { ...x.features, [name]: !value } }); setToast({ kind: 'err', text: errText(e) }) }
  }
  if (!p) return <div className="page cmds dc">{err ? <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div> : <section className="card" aria-busy="true">{[0, 1, 2].map((i) => <span key={i} className="skel skel-line" />)}</section>}</div>
  const v = p.values, linked = p.is_linked && !p.needs_relink, ready = linked && p.has_guild && !p.act_as
  const guildName = p.guilds.find((g) => g.id === s(v.existingGuildId))?.name
  const clear = (f: string) => setAsk({ title: CLEAR[f][0], body: <p>This will permanently delete all {CLEAR[f][1]} configuration and settings. You cannot undo this.</p>, yes: 'Yes, Clear All Data', run: () => cfg('clr-' + f, { action: CLEAR[f][2] }, () => { if (['reactionRoles', 'rulesConfiguration', 'streamSchedule'].includes(f)) feature(f, false); load() }) })
  const pick = { p, ready }
  return (
    <div className="page cmds dc">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card dc-hero" aria-label="Discord link">
        <div className="card-title-row"><h3 className="card-h"><i className="fab fa-discord" /> Discord Integration {p.is_linked ? <span className="pill pill-ok"><i className="fas fa-check-circle" /> Connected</span> : <span className="pill pill-warn"><i className="fas fa-exclamation-triangle" /> Not Connected</span>}</h3></div>
        {p.error && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{p.error}</span></div>}
        {!p.is_linked ? <div className="dc-link">
          <h4>{p.needs_relink ? (p.needs_reauth ? 'New Permissions Required' : 'Reconnection Required') : 'Link Your Discord Account'}</h4>
          <p className="muted">{p.needs_relink ? (p.needs_reauth ? "We've added new features that need more Discord permissions. Re-authorize your account to use server management and server selection." : 'Your Discord account was linked using our previous system. Reconnect it to use the latest features.') : "Link your Discord account to get Specter alerts in your Discord server, and a voice channel that updates when you go live."}</p>
          {p.act_as ? <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>Act As mode is active. Discord linking is disabled for acting users.</span></div>
            : p.auth_url && <div className="row-btns"><a className={`btn${p.needs_relink ? ' btn-warn' : ''}`} href={p.auth_url}><i className={p.needs_relink ? 'fas fa-sync-alt' : 'fab fa-discord'} /> {p.needs_relink ? (p.needs_reauth ? 'Grant New Permissions' : 'Reconnect Discord Account') : 'Link Discord'}</a></div>}
        </div> : <div className="dc-link">
          <h4><i className="fas fa-check-circle mp-green" /> Your Discord account is linked!</h4>
          {p.expires_str && <p className="muted"><b>Token Status:</b> Valid for {p.expires_str}</p>}
          <div className="row-btns">
            <a className="btn" href={INVITE} target="_blank" rel="noopener noreferrer"><i className="fas fa-plus-circle" /> Invite Bot</a>
            <button type="button" className="btn btn-danger" disabled={p.act_as || busy === 'disc'} onClick={() => setAsk({ title: 'Disconnect Discord Account?', body: <p>This revokes all tokens and removes your Discord integration.</p>, yes: 'Yes, Disconnect', run: () => form('disc', { disconnect_discord: '1' }) })}><i className="fas fa-unlink" /> Disconnect</button>
          </div>
        </div>}
      </section>

      {p.is_linked && <ServerCard p={p} busy={busy === 'guild'} save={(g) => form('guild', { guild_id: g })} />}

      <section className="card" aria-label="Discord Server Management">
        <div className="card-title-row"><h3 className="card-h"><i className="fab fa-discord" /> Discord Server Management</h3></div>
        {!linked ? <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span><b>Account Not Linked:</b> Link your Discord account to use server management features.</span></div>
          : !p.has_guild ? <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span><b>Guild ID Required:</b> Pick your Discord server above to turn on server management features.</span></div>
          : <p className="muted">Welcome messages, automatic roles, message tracking and more{guildName ? ` for ${guildName}` : ''}.</p>}
        <div className="dc-toggles">{FEATURES.map(([k, l]) => <div key={k} className="dc-toggle"><span>{l}</span><span className={`pill ${on(p.features[k]) ? 'pill-ok' : 'pill-off'}`}>{on(p.features[k]) ? 'Enabled' : 'Disabled'}</span><Switch on={on(p.features[k])} label={l} busy={!ready} onChange={(x) => feature(k, x)} /></div>)}</div>
      </section>

      <Masonry>
        <OnlineAlert {...pick} busy={busy === 'online'} save={(b) => form('online', b)} />
        <div className="dc-col">
          <section className="card dc-card" aria-label="Twitch Stream Monitoring">
            <h3 className="card-h"><i className="fa-brands fa-twitch" /> Twitch Stream Monitoring</h3>
            <p className="muted">Add Twitch streamers to monitor and get notified in your Discord server when they go live.</p>
            <AddStreamer disabled={!linked || p.act_as} busy={busy === 'add'} save={(u) => form('add', { monitor_username: u })} />
            <button type="button" className="btn btn-ghost" onClick={() => setShowStreamers(true)}><i className="fa-solid fa-people-group" /> View Tracked Streamers ({p.streamers.length})</button>
            <ChannelForm {...pick} k="mon" busy={busy === 'mon'} fields={[['twitch_stream_monitor_id', 'Twitch Stream Monitoring Channel', 'Channel to post when tracked Twitch users go live', 'existingTwitchStreamMonitoringID', false]]} btn="Save Monitoring Channel" icon="fas fa-wifi" save={(b) => form('mon', { ...b, guild_id: s(v.existingGuildId), save_stream_monitoring: '1' })} />
          </section>
          <section className="card dc-card" aria-label="Twitch Event/Action Audit Log">
            <h3 className="card-h"><i className="fas fa-clipboard-list" /> Twitch Event/Action Audit Log</h3>
            <p className="muted">Log Twitch moderation actions and events to Discord channels.</p>
            <ChannelForm {...pick} k="audit" busy={busy === 'audit'} fields={[['mod_channel_id', 'Twitch Moderation Actions Channel', 'Moderation actions are logged here, e.g. bans, timeouts, message deletions', 'existingModerationChannelID', true], ['alert_channel_id', 'Twitch Event Alerts Channel', 'Get a Discord notification for Twitch events, e.g. Followers, Subscriptions, Bits', 'existingAlertChannelID', true]]} btn="Save Audit Log Channels" icon="fas fa-bell" save={(b) => form('audit', { ...b, guild_id: s(v.existingGuildId), save_alert_channels: '1' })} />
          </section>
        </div>
      </Masonry>

      {ready && <Masonry>
        {on(p.features.welcomeMessage) && <Welcome {...pick} busy={busy === 'wel'} clear={() => clear('welcomeMessage')} save={(b) => cfg('wel', { action: 'save_welcome_message', ...b })} />}
        {on(p.features.autoRole) && <Feature title="Auto Role Assignment Configuration" icon="fas fa-user-plus" intro="Give new members a role automatically when they join." clear={() => clear('autoRole')}>
          <SimpleRole {...pick} busy={busy === 'ar'} save={(id) => cfg('ar', { action: 'save_auto_role', auto_role_id: id })} />
        </Feature>}
        {on(p.features.roleHistory) && <Feature title="Role History Configuration" icon="fas fa-history" intro="Give members their roles back when they rejoin, and choose how long role records are kept." clear={() => clear('roleHistory')}>
          <RoleHistory v={v} busy={busy === 'rh'} save={(b) => form('rh', { ...b, save_role_history: '1' })} />
        </Feature>}
        {on(p.features.messageTracking) && <Tracking {...pick} k="mt" title="Message Tracking Configuration" icon="fas fa-comment-dots" intro="Log edited and deleted messages in your Discord server." clear={() => clear('messageTracking')} busy={busy === 'mt'}
          ch={['message_tracking_log_channel_id', 'Message Log Channel', 'Channel where message edit/delete logs are sent', 'existingMessageTrackingLogChannel']} label="Tracking Options"
          opts={[['track_message_edits', 'Track message edits', 'existingMessageTrackingEdits'], ['track_message_deletes', 'Track message deletions', 'existingMessageTrackingDeletes']]} btn="Save Message Tracking Settings" save={(b) => form('mt', { ...b, save_message_tracking: '1' })} />}
        {on(p.features.roleTracking) && <Tracking {...pick} k="rt" title="Role Tracking Configuration" icon="fas fa-user-tag" intro="Log role assignments and removals in your Discord server." clear={() => clear('roleTracking')} busy={busy === 'rt'}
          ch={['role_tracking_log_channel_id', 'Role Log Channel', 'Channel where role change logs are sent', 'existingRoleTrackingLogChannel']} label="Tracking Options"
          opts={[['track_role_additions', 'Track role additions', 'existingRoleTrackingAdditions'], ['track_role_removals', 'Track role removals', 'existingRoleTrackingRemovals']]} btn="Save Role Tracking Settings" save={(b) => form('rt', { ...b, save_role_tracking: '1' })} />}
        {on(p.features.serverRoleManagement) && <Tracking {...pick} k="srm" title="Server Role Management Configuration" icon="fas fa-users-cog" intro="Log role creation, deletion and edits in your Discord server." clear={() => clear('serverRoleManagement')} busy={busy === 'srm'}
          ch={['server_mgmt_log_channel_id', 'Server Management Log Channel', 'Channel where server role management logs are sent', 'existingServerRoleManagementLogChannel']} label="Management Options"
          opts={[['track_role_creation', 'Track role creation', 'existingRoleCreationTracking'], ['track_role_deletion', 'Track role deletion', 'existingRoleDeletionTracking'], ['track_role_edits', 'Track role edits', 'existingRoleEditTracking']]} btn="Save Server Role Management Settings" save={(b) => form('srm', { ...b, save_server_role_management: '1' })} />}
        {on(p.features.userTracking) && <Tracking {...pick} k="ut" title="User Tracking Configuration" icon="fas fa-user-check" intro="Log joins, leaves, nickname, username, avatar and status changes in your Discord server." clear={() => clear('userTracking')} busy={busy === 'ut'}
          ch={['user_tracking_log_channel_id', 'User Tracking Log Channel', 'Channel where user tracking logs are sent', 'existingUserTrackingLogChannel']} label="Tracking Options"
          opts={[['track_user_joins', 'Track user joins', 'existingUserJoinTracking'], ['track_user_leaves', 'Track user leaves', 'existingUserLeaveTracking'], ['track_user_nickname', 'Track nickname changes', 'existingUserNicknameTracking'], ['track_user_username', 'Track username changes', 'existingUserUsernameTracking'], ['track_user_avatar', 'Track avatar changes', 'existingUserAvatarTracking'], ['track_user_status', 'Track status changes', 'existingUserStatusTracking']]} btn="Save User Tracking Settings" save={(b) => form('ut', { ...b, save_user_tracking: '1' })} />}
        {on(p.features.freeGames) && <Feature title="Free Games Configuration" icon="fas fa-gamepad" intro="Get notified in your Discord server when free games are available." clear={() => clear('freeGames')}>
          <ChannelForm {...pick} k="fg" busy={busy === 'fg'} fields={[['freestuff_channel_id', 'Discord Channel', 'Channel where free game announcements are posted', 'existingFreestuffChannelID', true]]} btn="Save Free Games Settings" icon="fas fa-save" save={(b) => form('fg', { ...b, save_freestuff_settings: '1' })} />
        </Feature>}
      </Masonry>}
      {ready && <div className="dc-wide">
        {on(p.features.reactionRoles) && <ReactionRoles {...pick} busy={busy} clear={() => clear('reactionRoles')} cfg={cfg} ask={setAsk} toast={setToast} />}
        {on(p.features.rulesConfiguration) && <Rules {...pick} busy={busy} clear={() => clear('rulesConfiguration')} cfg={cfg} ask={setAsk} toast={setToast} />}
        {on(p.features.streamSchedule) && <Schedule {...pick} busy={busy} clear={() => clear('streamSchedule')} cfg={cfg} ask={setAsk} toast={setToast} />}
        {on(p.features.embedBuilder) && <Embeds {...pick} toast={setToast} cfg={cfg} busy={busy} ask={setAsk} />}
      </div>}

      {showStreamers && <Modal title="Saved Streamers List" icon="fas fa-people-group" onClose={() => setShowStreamers(false)} wide>
        {!p.streamers.length ? <p className="muted">No streamers saved yet.</p> : <ul className="dc-list">{p.streamers.map((x) => <li key={x.username} className="dc-row"><span className="dc-row-main"><b>{x.username}</b><a className="muted small" href={x.stream_url.startsWith('https://www.twitch.tv/') ? x.stream_url : `https://www.twitch.tv/${encodeURIComponent(x.username)}`} target="_blank" rel="noopener noreferrer">{x.stream_url}</a></span>
          <button type="button" className="btn btn-danger" disabled={p.act_as || busy === 'rm' + x.username} onClick={() => setAsk({ title: 'Remove Tracked Streamer?', body: <p>Remove <b>{x.username}</b> from your tracked streamers? The bot stops posting when they go live.</p>, yes: 'Yes, Remove', run: () => form('rm' + x.username, { remove_streamer: x.username }) })}><i className="fas fa-trash" /> Remove</button></li>)}</ul>}
      </Modal>}
      {ask && <Confirm title={ask.title} yes={ask.yes} danger={ask.danger !== false} onClose={() => setAsk(null)} onYes={() => { const r = ask.run; setAsk(null); r() }}>{ask.body}</Confirm>}
    </div>
  )
}

type Pick = { p: Page; ready: boolean }
function useOpts(p: Page, kind: 'channels' | 'voice' | 'roles', cur: string): ComboOption[] {
  return useMemo(() => {
    const list = kind === 'roles' ? p.roles.filter((r) => r.name !== '@everyone') : kind === 'voice' ? p.voice_channels : p.channels
    const o = [{ value: '', label: kind === 'roles' ? 'Select a role...' : 'Select a channel...' }, ...list.map((c) => ({ value: c.id, label: kind === 'roles' ? c.name : `${c.type === 5 ? '\u{1F4E2} ' : '#'}${c.name}` }))]
    if (cur && !list.some((c) => c.id === cur)) o.push({ value: cur, label: `Unknown (${cur})` })
    return o
  }, [p, kind, cur])
}
function IdPick({ p, kind, id, label, value, onChange }: { p: Page; kind: 'channels' | 'voice' | 'roles'; id: string; label: string; value: string; onChange: (v: string) => void }) {
  const opts = useOpts(p, kind, value)
  const list = kind === 'roles' ? p.roles : kind === 'voice' ? p.voice_channels : p.channels
  if (p.use_manual_ids || !list.length) return <input id={id} className="input" inputMode="numeric" maxLength={22} placeholder={p.use_manual_ids ? (kind === 'roles' ? 'Role ID' : 'Channel ID (right-click the channel, Copy Channel ID)') : 'e.g. 123456789123456789'} value={value} onChange={(e) => onChange(e.target.value.replace(/\D/g, ''))} />
  return <Combobox label={label} value={value} onChange={onChange} options={opts} />
}
const idOk = (x: string) => x === '' || /^\d{15,22}$/.test(x)

function ServerCard({ p, busy, save }: { p: Page; busy: boolean; save: (g: string) => void }) {
  const [g, setG] = useState(s(p.values.existingGuildId))
  const linked = !p.needs_relink, opts = p.guilds.map((x) => ({ value: x.id, label: x.name }))
  const canPick = linked && (p.use_manual_ids || p.guilds.length > 0) && !p.act_as
  return (
    <section className="card dc-card" aria-label="Discord Server Configuration">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-server" /> Discord Server Configuration <span className="pill pill-info">Required</span></h3></div>
      <p className="muted">Pick your Discord server to turn on all Discord Bot features, including Server Management and Event Channels.</p>
      <Fld id="dc-guild" label="Discord Server" help={p.use_manual_ids ? 'Manual ID mode: right-click your server name, then Copy Server ID (Developer Mode required).' : !linked ? 'Link your Discord account to see your servers.' : !p.guilds.length ? 'No servers found where you are the Owner, or servers are still loading.' : undefined}>
        {p.use_manual_ids ? <input id="dc-guild" className="input" inputMode="numeric" maxLength={22} placeholder="e.g. 123456789123456789" value={g} onChange={(e) => setG(e.target.value.replace(/\D/g, ''))} />
          : <Combobox label="Discord Server" value={g} onChange={setG} options={[{ value: '', label: 'Select a Discord Server...' }, ...opts]} />}
      </Fld>
      <div className="row-btns"><button type="button" className="btn" disabled={!canPick || busy || !idOk(g) || g === s(p.values.existingGuildId)} onClick={() => save(g)}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-save'} /> Save Server Configuration</button></div>
      {p.use_manual_ids ? <div className="notice notice-info"><i className="fas fa-keyboard" /> <span><b>Manual Mode:</b> Paste channel IDs into the fields below (one ID per field).</span></div>
        : p.channels.length ? <div className="notice notice-ok"><i className="fas fa-list" /> <span><b>Pick From Server:</b> Choose channels from the lists below.</span></div>
        : p.has_guild ? <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span><b>Can't load channels:</b> Reconnect Discord or check the bot's server permissions.</span></div>
        : <div className="notice notice-warn"><i className="fas fa-server" /> <span><b>No server selected:</b> Pick a Discord server above to turn on channel lists.</span></div>}
    </section>
  )
}

function Feature({ title, icon, intro, clear, children }: { title: string; icon: string; intro: string; clear?: () => void; children: ReactNode }) {
  return <section className="card dc-card" aria-label={title}>
    <div className="card-title-row"><h3 className="card-h"><i className={icon} /> {title}</h3>{clear && <button type="button" className="btn btn-ghost" aria-label={`Clear ${title}`} title="Clear all data and turn this feature off" onClick={clear}><i className="fas fa-trash" /></button>}</div>
    <p className="muted">{intro}</p>
    {children}
  </section>
}
function SaveBtn({ busy, disabled, label, icon = 'fas fa-save', onClick }: { busy: boolean; disabled?: boolean; label: string; icon?: string; onClick: () => void }) {
  return <button type="button" className="btn" disabled={busy || disabled} onClick={onClick}><i className={busy ? 'fas fa-spinner fa-spin' : icon} /> {label}</button>
}

// One or more channel pickers with a save button (posted as a live form).
function ChannelForm({ p, ready, k, fields, btn, icon, busy, save }: Pick & { k: string; fields: [string, string, string, string, boolean][]; btn: string; icon: string; busy: boolean; save: (b: Record<string, string>) => void }) {
  const [f, setF] = useState<Record<string, string>>(() => Object.fromEntries(fields.map(([n, , , e]) => [n, s(p.values[e])])))
  const bad = fields.some(([n, , , , req]) => !idOk(f[n]) || (req && !f[n]))
  return <>
    {fields.map(([n, l, h, , req]) => <Fld key={n} id={`${k}-${n}`} label={l} help={h} req={req}><IdPick p={p} kind="channels" id={`${k}-${n}`} label={l} value={f[n]} onChange={(x) => setF({ ...f, [n]: x })} /></Fld>)}
    <div className="row-btns"><SaveBtn busy={busy} disabled={!ready || bad} label={btn} icon={icon} onClick={() => save(f)} /></div>
  </>
}

function AddStreamer({ disabled, busy, save }: { disabled: boolean; busy: boolean; save: (u: string) => void }) {
  const [u, setU] = useState('')
  const ok = /^[A-Za-z0-9_]{1,25}$/.test(u)
  return <form className="dc-inline" onSubmit={(e) => { e.preventDefault(); if (ok && !disabled) { save(u); setU('') } }}>
    <Fld id="dc-mon" label="Twitch Username"><input id="dc-mon" className="input" maxLength={25} placeholder="e.g. botofthespecter" value={u} onChange={(e) => setU(e.target.value.replace(/\s/g, ''))} /></Fld>
    <button type="submit" className="btn" disabled={disabled || !ok || busy}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add Streamer</button>
  </form>
}

function OnlineAlert({ p, ready, busy, save }: Pick & { busy: boolean; save: (b: Record<string, string | boolean>) => void }) {
  const v = p.values
  const [f, setF] = useState({ stream_channel_id: s(v.existingStreamAlertChannelID), stream_alert_custom_role: s(v.existingStreamAlertCustomRole), live_channel_id: s(v.existingLiveChannelId), time_now_channel_id: s(v.existingTimeNowChannelId), online_text: s(v.existingOnlineText), offline_text: s(v.existingOfflineText) })
  const [every, setEvery] = useState(on(v.existingStreamAlertEveryone))
  const set = (k: keyof typeof f, x: string) => setF({ ...f, [k]: x })
  const bad = !f.stream_channel_id || !f.live_channel_id || ![f.stream_channel_id, f.stream_alert_custom_role, f.live_channel_id, f.time_now_channel_id].every(idOk)
  return (
    <section className="card dc-card" aria-label="Twitch Online Alert">
      <h3 className="card-h"><i className="fas fa-broadcast-tower" /> Twitch Online Alert</h3>
      <p className="muted">Choose the Discord channels for stream online alerts and the voice channel status when you go live on Twitch.</p>
      <Fld id="oa-stream" label="Stream Online Alerts Channel" help="For stream online notifications of your channel" req><IdPick p={p} kind="channels" id="oa-stream" label="Stream Online Alerts Channel" value={f.stream_channel_id} onChange={(x) => set('stream_channel_id', x)} /></Fld>
      <Fld label="@everyone Mention for Stream Alerts" help="Mention @everyone when posting stream online alerts"><Check id="oa-every" label="Enable @everyone mention" checked={every} onChange={setEvery} /></Fld>
      {!every && <Fld id="oa-role" label="Custom Role Mention for Stream Alerts" help="Pick a role to mention instead of @everyone"><IdPick p={p} kind="roles" id="oa-role" label="Custom Role Mention" value={f.stream_alert_custom_role} onChange={(x) => set('stream_alert_custom_role', x)} /></Fld>}
      <Fld id="oa-live" label="Live Status Channel" help="The voice channel to update with live status" req><IdPick p={p} kind="voice" id="oa-live" label="Live Status Channel" value={f.live_channel_id} onChange={(x) => set('live_channel_id', x)} /></Fld>
      <Fld id="oa-time" label="Time Now Channel" help="Optional voice channel renamed every 10 minutes with the current time in your profile timezone. Discord limits renames to 2 per 10 minutes."><IdPick p={p} kind="voice" id="oa-time" label="Time Now Channel" value={f.time_now_channel_id} onChange={(x) => set('time_now_channel_id', x)} /></Fld>
      <div className="dc-two">
        <Fld id="oa-on" label="Online Text" help="Voice channel name while you are live"><input id="oa-on" className="input" maxLength={20} placeholder="e.g. Stream Online" value={f.online_text} onChange={(e) => set('online_text', e.target.value)} /><span className="muted small">{f.online_text.length}/20 characters</span></Fld>
        <Fld id="oa-off" label="Offline Text" help="Voice channel name while you are offline"><input id="oa-off" className="input" maxLength={20} placeholder="e.g. Stream Offline" value={f.offline_text} onChange={(e) => set('offline_text', e.target.value)} /><span className="muted small">{f.offline_text.length}/20 characters</span></Fld>
      </div>
      {p.use_manual_ids && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span><b>How to get Channel IDs:</b> turn on Developer Mode in Discord (User Settings, Advanced, Developer Mode), right-click the channel, pick "Copy Channel ID" and paste it above.</span></div>}
      <div className="row-btns"><SaveBtn busy={busy} disabled={!ready || bad} label="Save Stream & Live Status" icon="fas fa-cog" onClick={() => save({ ...f, stream_alert_custom_role: every ? '' : f.stream_alert_custom_role, stream_alert_everyone: every, save_stream_online: '1' })} /></div>
    </section>
  )
}

function Welcome({ p, busy, clear, save }: Pick & { busy: boolean; clear: () => void; save: (b: Record<string, unknown>) => void }) {
  const v = p.values
  const [ch, setCh] = useState(s(v.existingWelcomeChannelID)), [msg, setMsg] = useState(s(v.existingWelcomeMessage))
  const [def, setDef] = useState(on(v.existingWelcomeUseDefault)), [emb, setEmb] = useState(on(v.existingWelcomeEmbed)), [col, setCol] = useState(s(v.existingWelcomeColour) || '#00d1b2')
  return <Feature title="Welcome Message Configuration" icon="fas fa-door-open" intro="Greet new members with a welcome message when they join your Discord server." clear={clear}>
    <Fld id="wl-ch" label="Welcome Channel" help="Channel where welcome messages are sent"><IdPick p={p} kind="channels" id="wl-ch" label="Welcome Channel" value={ch} onChange={setCh} /></Fld>
    <Check id="wl-def" label="Use default welcome message" checked={def} onChange={setDef} />
    <Check id="wl-emb" label="Enable Embed Message" checked={emb} onChange={setEmb} />
    {emb && <Fld id="wl-col" label="Embed Colour" help="The colour for the embed border and accent"><Colour id="wl-col" value={col} onChange={(v) => setCol(v)} /></Fld>}
    <Fld id="wl-msg" label="Custom Welcome Message" help={<>Use <code>(user)</code> to insert the member's username</>}><textarea id="wl-msg" className="input" rows={4} maxLength={2000} disabled={def} placeholder="Welcome (user) to our server, we're so glad you joined us!" value={msg} onChange={(e) => setMsg(e.target.value)} /></Fld>
    <div className="row-btns"><SaveBtn busy={busy} disabled={!ch || !idOk(ch) || (!def && !msg.trim())} label="Save Welcome Message Configuration" onClick={() => save({ welcome_channel_id: ch, welcome_message: def ? '' : msg, welcome_message_configuration_default: def, welcome_message_configuration_embed: emb, welcome_message_configuration_colour: col })} /></div>
  </Feature>
}

function SimpleRole({ p, busy, save }: Pick & { busy: boolean; save: (id: string) => void }) {
  const [r, setR] = useState(s(p.values.existingAutoRoleID))
  return <>
    <Fld id="ar-role" label="Auto Role" help="Role given to new members"><IdPick p={p} kind="roles" id="ar-role" label="Auto Role" value={r} onChange={setR} /></Fld>
    <div className="row-btns"><SaveBtn busy={busy} disabled={!r || !idOk(r)} label="Save Auto Role Settings" onClick={() => save(r)} /></div>
  </>
}

function RoleHistory({ v, busy, save }: { v: V; busy: boolean; save: (b: Record<string, string | boolean>) => void }) {
  const [en, setEn] = useState(on(v.existingRoleHistoryEnabled)), [days, setDays] = useState(s(v.existingRoleHistoryRetention) || '30')
  const bad = !/^\d{1,3}$/.test(days) || Number(days) < 1 || Number(days) > 365
  return <>
    <Fld label="Enable Role Restoration" help="Members get their previous roles back when they rejoin"><Check id="rh-en" label="Restore all previous roles when a member rejoins" checked={en} onChange={setEn} /></Fld>
    <Fld id="rh-days" label="History Retention Period (Days)" help="How long role history is kept after a member leaves (1 to 365 days)"><input id="rh-days" className="input dc-num" type="number" inputMode="numeric" min={1} max={365} value={days} aria-invalid={bad} onChange={(e) => setDays(e.target.value)} /></Fld>
    <div className="row-btns"><SaveBtn busy={busy} disabled={bad} label="Save Role History Settings" onClick={() => save({ restore_roles: en, history_retention_days: days })} /></div>
  </>
}

function Tracking({ p, k, title, icon, intro, clear, busy, ch, label, opts, btn, save }: Pick & { k: string; title: string; icon: string; intro: string; clear: () => void; busy: boolean; ch: [string, string, string, string]; label: string; opts: [string, string, string][]; btn: string; save: (b: Record<string, string | boolean>) => void }) {
  const [c, setC] = useState(s(p.values[ch[3]]))
  const [o, setO] = useState<Record<string, boolean>>(() => Object.fromEntries(opts.map(([n, , e]) => [n, on(p.values[e])])))
  return <Feature title={title} icon={icon} intro={intro} clear={clear}>
    <Fld id={`${k}-ch`} label={ch[1]} help={ch[2]}><IdPick p={p} kind="channels" id={`${k}-ch`} label={ch[1]} value={c} onChange={setC} /></Fld>
    <Fld label={label}><div className="dc-checks">{opts.map(([n, l]) => <Check key={n} id={`${k}-${n}`} label={l} checked={o[n]} onChange={(x) => setO({ ...o, [n]: x })} />)}</div></Fld>
    <div className="row-btns"><SaveBtn busy={busy} disabled={!idOk(c)} label={btn} onClick={() => save({ [ch[0]]: c, ...o })} /></div>
  </Feature>
}

type CardProps = Pick & { busy: string; clear: () => void; cfg: (k: string, b: Record<string, unknown>, after?: () => void) => void; ask: (a: { title: string; body: ReactNode; yes: string; danger?: boolean; run: () => void }) => void; toast: (t: Toast) => void }
function ReactionRoles({ p, busy, clear, cfg, ask, toast }: CardProps) {
  const v = p.values
  const [ch, setCh] = useState(s(v.existingReactionRolesChannelID)), [msg, setMsg] = useState(s(v.existingReactionRolesMessage)), [map, setMap] = useState(s(v.existingReactionRolesMappings)), [multi, setMulti] = useState(on(v.existingAllowMultipleReactions))
  const body = { reaction_roles_channel_id: ch, reaction_roles_message: msg, reaction_roles_mappings: map, allow_multiple_reactions: multi }
  const mapBad = map.trim() !== '' && map.trim().split('\n').some((l) => l.trim() && !l.includes('@'))
  function check(send: boolean) {
    if (!ch) return toast({ kind: 'err', text: 'Please select a reaction roles channel' }), false
    if (send && !msg.trim()) return toast({ kind: 'err', text: 'Please enter a reaction roles message' }), false
    if (send && !map.trim()) return toast({ kind: 'err', text: 'Please enter reaction role mappings' }), false
    if (mapBad) return toast({ kind: 'err', text: 'Invalid reaction role mapping format. Use: :emoji: Description @RoleName' }), false
    return true
  }
  return <Feature title="Reaction Roles Configuration" icon="fas fa-hand-pointer" intro="Let members pick their own roles with reactions." clear={clear}>
    <Fld id="rr-ch" label="Reaction Roles Channel" help="Channel where the reaction roles message is posted"><IdPick p={p} kind="channels" id="rr-ch" label="Reaction Roles Channel" value={ch} onChange={setCh} /></Fld>
    <Fld id="rr-msg" label="Reaction Roles Message" help="Shown above the reaction roles. Leave empty for no message."><textarea id="rr-msg" className="input" rows={4} maxLength={2000} placeholder="To join any of the following roles, use the icons below. Click on the boxes below to get the roles!" value={msg} onChange={(e) => setMsg(e.target.value)} /></Fld>
    <Fld id="rr-map" label="Reaction Role Mappings" help={<>Format: <code>:emoji: Description @RoleName [color]</code>, one per line. Colors: blue/primary, gray/secondary, green/success, red/danger (optional, defaults to blue).</>}><textarea id="rr-map" className="input mono" rows={8} maxLength={4000} aria-invalid={mapBad} placeholder={':thumbsup: Thumbs Up @Role1 [green]\n:heart: Love @Role2 [red]\n:star: VIP @Role3 [blue]\nMember Role @Role4 [gray]'} value={map} onChange={(e) => setMap(e.target.value)} /></Fld>
    <Check id="rr-multi" label="Allow users to select multiple roles" checked={multi} onChange={setMulti} help="If unchecked, users can only have one role from this set." />
    <div className="row-btns">
      <SaveBtn busy={busy === 'rr'} label="Save Reaction Roles Settings" onClick={() => { if (check(false)) cfg('rr', { action: 'save_reaction_roles', ...body }) }} />
      <SaveBtn busy={busy === 'rrs'} label="Send Message to Channel" icon="fas fa-paper-plane" onClick={() => { if (check(true)) ask({ title: 'Send Reaction Roles Message?', body: <p>This posts the message to the selected Discord channel and adds the reaction emojis for members to pick their roles.</p>, yes: 'Yes, Send Message', danger: false, run: () => cfg('rrs', { action: 'send_reaction_roles_message', ...body }) }) }} />
    </div>
  </Feature>
}

function Rules({ p, busy, clear, cfg, ask, toast }: CardProps) {
  const v = p.values
  const [ch, setCh] = useState(s(v.existingRulesChannelID)), [title, setTitle] = useState(s(v.existingRulesTitle)), [txt, setTxt] = useState(s(v.existingRulesContent)), [col, setCol] = useState(s(v.existingRulesColor) || '#5865f2')
  const [assign, setAssign] = useState(!!s(v.existingRulesAcceptRoleID)), [role, setRole] = useState(s(v.existingRulesAcceptRoleID))
  const body = { rules_channel_id: ch, rules_title: title, rules_content: txt, rules_color: col, rules_accept_role_id: assign ? role : '' }
  function check() {
    if (!ch) return toast({ kind: 'err', text: 'Please select a rules channel' }), false
    if (!title.trim()) return toast({ kind: 'err', text: 'Please enter a rules title' }), false
    if (!txt.trim()) return toast({ kind: 'err', text: 'Please enter at least one rule' }), false
    if (assign && !role) return toast({ kind: 'err', text: 'Please select a role for rule acceptance' }), false
    return true
  }
  return <Feature title="Rules Configuration" icon="fas fa-gavel" intro="Post an embed with your server rules." clear={clear}>
    <Fld id="ru-ch" label="Rules Channel" help="Channel where the rules message is posted"><IdPick p={p} kind="channels" id="ru-ch" label="Rules Channel" value={ch} onChange={setCh} /></Fld>
    <Fld id="ru-t" label="Rules Title" help="Shown at the top of the rules embed"><input id="ru-t" className="input" maxLength={256} placeholder="e.g. Server Rules" value={title} onChange={(e) => setTitle(e.target.value)} /></Fld>
    <Fld id="ru-c" label="Rules Content" help="Numbered lists, bullet points or any format. Discord markdown is supported."><textarea id="ru-c" className="input" rows={8} maxLength={4000} placeholder={'1. Be respectful to all members\n2. No spamming or advertising\n3. Keep content appropriate\n4. Follow Discord\'s Terms of Service'} value={txt} onChange={(e) => setTxt(e.target.value)} /></Fld>
    <Fld id="ru-col" label="Embed Color" help="Colour of the rules embed border"><Colour id="ru-col" value={col} onChange={(v) => setCol(v)} /></Fld>
    <Check id="ru-as" label="Assign role on rule acceptance" checked={assign} onChange={setAssign} />
    {assign && <Fld id="ru-role" label="Rules Acceptance Role" help="Given to members who react to accept the rules"><IdPick p={p} kind="roles" id="ru-role" label="Rules Acceptance Role" value={role} onChange={setRole} /></Fld>}
    <div className="row-btns">
      <SaveBtn busy={busy === 'ru'} label="Save Rules Configuration" onClick={() => { if (check()) cfg('ru', { action: 'save_rules', ...body }) }} />
      <SaveBtn busy={busy === 'rus'} label="Send Rules to Channel" icon="fas fa-paper-plane" onClick={() => { if (check()) ask({ title: 'Send Rules Message?', body: <p>This posts an embed with your server rules to the selected Discord channel.</p>, yes: 'Yes, Send Rules', danger: false, run: () => cfg('rus', { action: 'send_rules_message', ...body }) }) }} />
    </div>
  </Feature>
}

function Schedule({ p, busy, clear, cfg, ask, toast }: CardProps) {
  const v = p.values
  const [ch, setCh] = useState(s(v.existingStreamScheduleChannelID)), [title, setTitle] = useState(s(v.existingStreamScheduleTitle)), [txt, setTxt] = useState(s(v.existingStreamScheduleContent))
  const [tz, setTz] = useState(s(v.existingStreamScheduleTimezone)), [col, setCol] = useState(s(v.existingStreamScheduleColor) || '#9146ff')
  const body = { stream_schedule_channel_id: ch, stream_schedule_title: title, stream_schedule_content: txt, stream_schedule_color: col, stream_schedule_timezone: tz.trim() }
  function check(save: boolean) {
    if (!ch) return toast({ kind: 'err', text: 'Please select a schedule channel' }), false
    if (!title.trim()) return toast({ kind: 'err', text: 'Please enter a schedule title' }), false
    if (!txt.trim()) return toast({ kind: 'err', text: 'Please enter your stream schedule' }), false
    if (save && !tz.trim()) return toast({ kind: 'err', text: 'Please enter a timezone' }), false
    return true
  }
  return <Feature title="Stream Schedule Configuration" icon="fas fa-calendar-alt" intro="Post an embed with your streaming schedule." clear={clear}>
    <Fld id="ss-ch" label="Schedule Channel" help="Channel where the stream schedule is posted"><IdPick p={p} kind="channels" id="ss-ch" label="Schedule Channel" value={ch} onChange={setCh} /></Fld>
    <Fld id="ss-t" label="Schedule Title" help="Shown at the top of the schedule embed"><input id="ss-t" className="input" maxLength={256} placeholder="e.g. Weekly Stream Schedule" value={title} onChange={(e) => setTitle(e.target.value)} /></Fld>
    <Fld id="ss-c" label="Stream Schedule Content" help={<>Emojis, bullet points or any format. Discord markdown is supported. <a href="https://support.discord.com/hc/en-us/articles/210298617" target="_blank" rel="noopener noreferrer">View markdown guide</a></>}><textarea id="ss-c" className="input" rows={8} maxLength={4000} placeholder={'Monday: 7:00 PM - 10:00 PM EST - Variety Gaming\nWednesday: 8:00 PM - 11:00 PM EST - Just Chatting\n\nOr use Discord markdown:\n**Monday** - 7:00 PM EST'} value={txt} onChange={(e) => setTxt(e.target.value)} /></Fld>
    <Fld id="ss-tz" label="Timezone" help="Shown in the footer"><input id="ss-tz" className="input" maxLength={64} placeholder="e.g. EST, PST, UTC, etc." value={tz} onChange={(e) => setTz(e.target.value)} /></Fld>
    <Fld id="ss-col" label="Embed Color" help="Colour of the schedule embed border"><Colour id="ss-col" value={col} onChange={(v) => setCol(v)} /></Fld>
    <div className="row-btns">
      <SaveBtn busy={busy === 'ss'} label="Save Schedule Configuration" onClick={() => { if (check(true)) cfg('ss', { action: 'save_stream_schedule', ...body }) }} />
      <SaveBtn busy={busy === 'sss'} label="Send Schedule to Channel" icon="fas fa-paper-plane" onClick={() => { if (check(false)) ask({ title: 'Send Stream Schedule?', body: <p>This posts an embed with your streaming schedule to the selected Discord channel.</p>, yes: 'Yes, Send Schedule', danger: false, run: () => cfg('sss', { action: 'send_stream_schedule_message', ...body }) }) }} />
    </div>
  </Feature>
}

const EMPTY_E: Embed = { id: 0, embed_name: '', title: '', description: '', color: '#5865f2', url: '', thumbnail_url: '', image_url: '', footer_text: '', footer_icon_url: '', author_name: '', author_url: '', author_icon_url: '', timestamp_enabled: false }
function Embeds({ p, toast, cfg, busy, ask }: Pick & { toast: (t: Toast) => void; cfg: CardProps['cfg']; busy: string; ask: CardProps['ask'] }) {
  const [list, setList] = useState<Embed[] | null>(null), [edit, setEdit] = useState<Embed | null>(null), [fields, setFields] = useState<Field[]>([]), [sendId, setSendId] = useState(0), [sendCh, setSendCh] = useState('')
  async function load() { try { const r = await get<{ success: boolean; embeds?: Embed[]; message?: string }>('/api/discord/embeds.php'); if (!r.success) throw new Error(r.message); setList(r.embeds ?? []) } catch (e) { setList([]); toast({ kind: 'err', text: errText(e) }) } }
  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function open(id: number) {
    if (!id) { setEdit({ ...EMPTY_E }); setFields([]); return }
    try { const r = await get<{ success: boolean; embed?: Embed; message?: string }>(`/api/discord/embed.php?id=${id}`); if (!r.success || !r.embed) throw new Error(r.message); setEdit(r.embed); let f: Field[] = []; try { f = JSON.parse(r.embed.fields || '[]') } catch { /* none */ } setFields(Array.isArray(f) ? f.map((x) => ({ name: s(x.name), value: s(x.value), inline: !!x.inline })) : []) }
    catch (e) { toast({ kind: 'err', text: errText(e) }) }
  }
  const E = edit, set = (k: keyof Embed, x: string | boolean) => setEdit((o) => o && { ...o, [k]: x })
  const urlBad = (x: unknown) => !!s(x) && !/^https?:\/\/\S+$/i.test(s(x))
  const bad = !E || !s(E.embed_name).trim() || (!s(E.title).trim() && !s(E.description).trim() && !fields.length) || ['url', 'thumbnail_url', 'image_url', 'footer_icon_url', 'author_url', 'author_icon_url'].some((k) => urlBad(E[k as keyof Embed])) || fields.some((f) => !f.name.trim() || !f.value.trim())
  function save() {
    if (!E) return
    const n = (k: keyof Embed) => s(E[k]).trim() || null
    cfg('emb', { action: 'save_custom_embed', embed_id: E.id, embed_name: s(E.embed_name).trim(), title: n('title'), description: n('description'), color: s(E.color) || '#5865f2', url: n('url'), thumbnail_url: n('thumbnail_url'), image_url: n('image_url'), footer_text: n('footer_text'), footer_icon_url: n('footer_icon_url'), author_name: n('author_name'), author_url: n('author_url'), author_icon_url: n('author_icon_url'), timestamp_enabled: on(E.timestamp_enabled), fields: JSON.stringify(fields) }, () => { setEdit(null); load() })
  }
  const txt = (k: keyof Embed, l: string, ph = '', max = 256, area = false) => <Fld id={`em-${k}`} label={l}>{area ? <textarea id={`em-${k}`} className="input" rows={4} maxLength={4096} placeholder={ph} value={s(E![k])} onChange={(e) => set(k, e.target.value)} /> : <input id={`em-${k}`} className="input" maxLength={max} placeholder={ph} aria-invalid={k.endsWith('url') && urlBad(E![k])} value={s(E![k])} onChange={(e) => set(k, e.target.value)} />}</Fld>
  const mv = (i: number, d: number) => setFields((f) => { const c = [...f]; const j = i + d; if (j < 0 || j >= c.length) return f; [c[i], c[j]] = [c[j], c[i]]; return c })
  return <Feature title="Custom Embed Builder" icon="fas fa-code" intro="Create, manage and send custom Discord embeds to any channel in your server.">
    <h4 className="mp-h">Your Custom Embeds</h4>
    {list === null ? <span className="skel skel-line" /> : !list.length ? <p className="muted">No custom embeds yet.</p> : <ul className="dc-list">{list.map((e) => <li key={e.id} className="dc-row"><span className="dc-row-main"><b>{e.embed_name}</b><span className="muted small">{e.title || 'No title'}</span></span>
      <span className="row-btns"><button type="button" className="btn btn-ghost" onClick={() => open(e.id)}><i className="fas fa-edit" /> Edit</button><button type="button" className="btn btn-ghost" onClick={() => { setSendId(e.id); setSendCh('') }}><i className="fas fa-paper-plane" /> Send</button>
        <button type="button" className="btn btn-danger" disabled={busy === 'del' + e.id} onClick={() => ask({ title: 'Delete Embed?', body: <p>Delete <b>{e.embed_name}</b>? You cannot undo this.</p>, yes: 'Yes, delete it', run: () => cfg('del' + e.id, { action: 'delete_custom_embed', embed_id: e.id }, load) })}><i className="fas fa-trash" /> Delete</button></span></li>)}</ul>}
    <div className="row-btns"><button type="button" className="btn" onClick={() => open(0)}><i className="fas fa-plus" /> Create New Embed</button></div>
    {E && <Modal title={E.id ? 'Edit Custom Embed' : 'Create Custom Embed'} icon="fas fa-code" onClose={() => setEdit(null)} wide>
      <div className="dc-embed">
        <div className="dc-embed-form">
          <Fld id="em-embed_name" label="Embed Name" help="Only you see this name"><input id="em-embed_name" className="input" maxLength={100} placeholder="e.g. Welcome Message, Rules, Announcements" value={s(E.embed_name)} onChange={(e) => set('embed_name', e.target.value)} /></Fld>
          {txt('title', 'Embed Title', 'e.g. Welcome to Our Server!')}
          {txt('description', 'Description', 'Enter the main embed content...', 4096, true)}
          <Fld id="em-color" label="Color"><Colour id="em-color" value={s(E.color) || '#5865f2'} onChange={(v) => set('color', v)} /></Fld>
          {txt('url', 'URL (optional)', 'https://', 2048)}{txt('thumbnail_url', 'Thumbnail URL (optional)', 'https://', 2048)}{txt('image_url', 'Image URL (optional)', 'https://', 2048)}
          {txt('footer_text', 'Footer Text (optional)', 'Footer text', 2048)}{txt('footer_icon_url', 'Footer Icon URL (optional)', 'https://', 2048)}
          {txt('author_name', 'Author Name (optional)', 'Author name')}{txt('author_url', 'Author URL (optional)', 'https://', 2048)}{txt('author_icon_url', 'Author Icon URL (optional)', 'https://', 2048)}
          <Check id="em-ts" label="Include Timestamp" checked={on(E.timestamp_enabled)} onChange={(x) => set('timestamp_enabled', x)} />
          <h4 className="mp-h">Fields</h4>
          {fields.map((f, i) => <div key={i} className="dc-efield">
            <input className="input" aria-label={`Field ${i + 1} name`} maxLength={256} placeholder="Field name" value={f.name} onChange={(e) => setFields(fields.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} />
            <textarea className="input" aria-label={`Field ${i + 1} value`} rows={2} maxLength={1024} placeholder="Field value" value={f.value} onChange={(e) => setFields(fields.map((x, j) => j === i ? { ...x, value: e.target.value } : x))} />
            <div className="row-btns"><Check id={`em-in-${i}`} label="Inline" checked={f.inline} onChange={(x) => setFields(fields.map((y, j) => j === i ? { ...y, inline: x } : y))} />
              <button type="button" className="btn btn-ghost" aria-label="Move up" disabled={!i} onClick={() => mv(i, -1)}><i className="fas fa-arrow-up" /></button><button type="button" className="btn btn-ghost" aria-label="Move down" disabled={i === fields.length - 1} onClick={() => mv(i, 1)}><i className="fas fa-arrow-down" /></button>
              <button type="button" className="btn btn-danger" aria-label="Remove field" onClick={() => setFields(fields.filter((_, j) => j !== i))}><i className="fas fa-trash" /></button></div>
          </div>)}
          <div className="row-btns"><button type="button" className="btn btn-ghost" disabled={fields.length >= 25} onClick={() => setFields([...fields, { name: '', value: '', inline: false }])}><i className="fas fa-plus" /> Add Field</button></div>
        </div>
        <div className="dc-preview" aria-label="Preview"><h4 className="mp-h">Preview</h4>
          <div className="dc-pv" style={{ borderLeftColor: s(E.color) || '#5865f2' }}>
            {s(E.author_name) && <div className="dc-pv-author">{s(E.author_name)}</div>}
            {s(E.title) && <div className="dc-pv-title">{s(E.title)}</div>}
            {s(E.description) && <div className="dc-pv-desc">{s(E.description)}</div>}
            {fields.length > 0 && <div className="dc-pv-fields">{fields.map((f, i) => <div key={i} className={f.inline ? 'inline' : ''}><b>{f.name}</b><span>{f.value}</span></div>)}</div>}
            {(s(E.footer_text) || on(E.timestamp_enabled)) && <div className="dc-pv-foot">{s(E.footer_text)}{s(E.footer_text) && on(E.timestamp_enabled) ? ' • ' : ''}{on(E.timestamp_enabled) ? 'Today' : ''}</div>}
          </div>
        </div>
      </div>
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setEdit(null)}>Cancel</button><SaveBtn busy={busy === 'emb'} disabled={bad} label="Save Embed" onClick={save} /></div>
    </Modal>}
    {sendId > 0 && <Modal title="Send Embed to Channel" icon="fas fa-paper-plane" onClose={() => setSendId(0)}>
      <Fld id="em-send" label="Select Channel"><IdPick p={p} kind="channels" id="em-send" label="Select Channel" value={sendCh} onChange={setSendCh} /></Fld>
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setSendId(0)}>Cancel</button><SaveBtn busy={busy === 'send'} disabled={!sendCh || !idOk(sendCh)} label="Send" icon="fas fa-paper-plane" onClick={() => cfg('send', { action: 'send_custom_embed', embed_id: sendId, channel_id: sendCh }, () => setSendId(0))} /></div>
    </Modal>}
  </Feature>
}
