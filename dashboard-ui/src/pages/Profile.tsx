import { useEffect, useMemo, useState } from 'react'
import Combobox, { timezoneOptions } from '../components/Combobox'
import { get, post } from '../bot'
import { getConsent, onConsentChange, setConsent } from '../consent'

const API = '/api/profile/profile.php'
const OLD = 'https://dashboard.botofthespecter.com'

type Page = {
  success: boolean; user_id: number; username: string; display_name: string; email: string; profile_image: string
  joined: string; last_login: string; is_technical: boolean; api_key: string
  timezone: string; timezones: string[]; weather_location: string; heartrate_code: string | null
  language: string; languages: { name: string; code: string }[]; has_app_password: boolean
  custom_bot: { username: string; channel_id: string; verified: boolean } | null
  youtube_enabled: boolean; cookie_consent: string | null
}
type ListData = {
  success: boolean
  storage: { used: number; max: number; used_formatted: string; max_formatted: string; percent: number; tier: string; beta_access: boolean }
  links: Record<'discord' | 'spotify' | 'streamelements' | 'streamlabs' | 'kick' | 'youtube', boolean>
}
type StoragePart = { key: string; label: string; bytes: number }
type Result = { success: boolean; message?: string; kind?: string; logged_out?: boolean; error?: string; bot_id?: string }

type Svc = { key: keyof ListData['links']; name: string; icon: string; href: string; action: string }
const SERVICES: Svc[] = [
  { key: 'discord', name: 'Discord', icon: 'fa-brands fa-discord', href: `${OLD}/discordbot.php`, action: 'disconnect_discord' },
  { key: 'spotify', name: 'Spotify', icon: 'fa-brands fa-spotify', href: `${OLD}/spotifylink.php`, action: 'disconnect_spotify' },
  { key: 'streamelements', name: 'StreamElements', icon: 'fa-solid fa-globe', href: `${OLD}/streamelements.php`, action: 'disconnect_streamelements' },
  { key: 'streamlabs', name: 'StreamLabs', icon: 'fa-solid fa-gift', href: `${OLD}/streamlabs.php`, action: 'disconnect_streamlabs' },
  { key: 'kick', name: 'Kick', icon: 'fa-solid fa-k', href: `${OLD}/kicklink.php`, action: 'disconnect_kick' },
  { key: 'youtube', name: 'YouTube', icon: 'fa-brands fa-youtube', href: `${OLD}/streaming.php#youtube`, action: 'disconnect_youtube' },
]

export default function Profile() {
  const [page, setPage] = useState<Page | null>(null)
  const [list, setList] = useState<ListData | null>(null)
  const [err, setErr] = useState('')
  const [listErr, setListErr] = useState(false)
  const [parts, setParts] = useState<StoragePart[] | null>(null)
  const [toast, setToast] = useState<{ ok: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState('')
  const [showKey, setShowKey] = useState(false)
  const [form, setForm] = useState({ timezone: '', weather: '', heart: '', language: '', pw: '', pw2: '', botName: '', botId: '' })

  async function load() {
    try {
      const p = await get<Page>(API)
      if (!p.success) throw new Error('bad')
      setPage({ ...p, cookie_consent: getConsent() })
      setForm((f) => ({ ...f, timezone: p.timezone, weather: p.weather_location || '', heart: p.heartrate_code || '', language: p.language, botName: p.custom_bot?.username || '', botId: p.custom_bot?.channel_id || '' }))
      setErr('')
    } catch { setErr('Could not load your profile. Please refresh the page.') }
  }
  async function loadList() {
    setListErr(false)
    try {
      const l = await get<ListData>(`${API}?ajax_action=list`, 25000)
      if (!l.success) throw new Error('bad')
      setList(l)
      get<{ success: boolean; parts: StoragePart[] }>('/api/profile/storage.php', 25000).then((b) => setParts(b.success ? b.parts : null)).catch(() => setParts(null))
    } catch { setListErr(true) }
  }
  useEffect(() => { load(); loadList() }, [])

  async function act(action: string, fields: Record<string, string> = {}, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return
    setBusy(action)
    try {
      const r = await post<Result>(API, { action, ...fields })
      if (r.logged_out) { window.location.href = '/'; return }
      setToast({ ok: !!r.success, text: r.message || (r.success ? 'Saved.' : 'Something went wrong.') })
      await load()
      if (action.startsWith('disconnect_')) await loadList()
    } catch { setToast({ ok: false, text: 'Request failed. Please try again.' }) }
    setBusy('')
  }

  async function exportData() {
    if (!page || !window.confirm('Request an export of your data? You will receive it by email when it is ready.')) return
    setBusy('export')
    try {
      const r = await post<{ success: boolean; msg?: string }>('/api/profile/export_user_data.php', { username: page.username })
      setToast({ ok: !!r.success, text: r.success ? 'Your export has been queued. You will get an email when it is ready.' : (r.msg || 'Could not start the export.') })
    } catch { setToast({ ok: false, text: 'Could not start the export. Please contact support.' }) }
    setBusy('')
  }

  async function resolveBot() {
    if (!form.botName.trim()) return
    setBusy('resolve')
    try {
      const r = await post<Result>(API, { action: 'resolve_bot_id', bot_username: form.botName.trim() })
      if (r.success && r.bot_id) setForm((f) => ({ ...f, botId: r.bot_id! }))
      else setToast({ ok: false, text: r.error || 'Could not find that Twitch user.' })
    } catch { setToast({ ok: false, text: 'Lookup failed.' }) }
    setBusy('')
  }

  function setCookie(v: 'accepted' | 'declined') { setConsent(v) }
  useEffect(() => onConsentChange((v) => setPage((p) => p ? { ...p, cookie_consent: v } : p)), [])

  const tzOpts = useMemo(() => timezoneOptions(page?.timezones ?? []), [page])
  if (err) return <section className="card notice notice-err"><i className="fa-solid fa-triangle-exclamation" /> <span>{err}</span></section>
  if (!page) return <div className="profile-page"><section className="card"><div className="skel-list"><span className="skel" /><span className="skel" /><span className="skel" /></div></section></div>

  const f = form
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value })
  const cookie = page.cookie_consent
  return (
    <div className="profile-page">
      {toast && (
        <div className={`notice ${toast.ok ? 'notice-ok' : 'notice-err'}`}>
          <i className={`fa-solid ${toast.ok ? 'fa-circle-check' : 'fa-triangle-exclamation'}`} /> <span>{toast.text}</span>
          <button className="ghost sm" onClick={() => setToast(null)} aria-label="Dismiss"><i className="fa-solid fa-xmark" /></button>
        </div>
      )}

      <section className="card profile-head">
        <img src={page.profile_image} alt="" />
        <div className="kv-wrap">
          <h2>{page.display_name || page.username}</h2>
          <dl className="kv">
            <dt>Username</dt><dd>{page.username}</dd>
            <dt>Display name</dt><dd>{page.display_name || '-'}</dd>
            <dt>Email</dt><dd>{page.email || '-'}</dd>
            <dt>Joined</dt><dd>{page.joined}</dd>
            <dt>Last login</dt><dd>{page.last_login}</dd>
          </dl>
        </div>
        <div className="profile-head-actions">
          <label className="toggle"><input type="checkbox" checked={page.is_technical} disabled={busy === 'update_technical_mode'}
            onChange={(e) => act('update_technical_mode', { is_technical: e.target.checked ? '1' : '0' })} />
            <span>Technical terms<small>Show technical details such as PIDs and raw status values.</small></span></label>
          <button className="btn btn-ghost" disabled={busy === 'export'} onClick={exportData}><i className="fa-solid fa-file-export" /> Export my data</button>
        </div>
      </section>

      <div className="profile-grid">
        <section className="card">
          <h3><i className="fa-solid fa-key" /> API access</h3>
          <p className="muted small">Your API key is used to authenticate external services with BotOfTheSpecter.</p>
          <div className="inline-field">
            <input className="input mono" readOnly type={showKey ? 'text' : 'password'} value={page.api_key} />
            <button className="btn btn-ghost" onClick={() => setShowKey(!showKey)} title={showKey ? 'Hide' : 'Show'}><i className={`fa-solid ${showKey ? 'fa-eye-slash' : 'fa-eye'}`} /></button>
            <button className="btn btn-ghost" onClick={() => navigator.clipboard?.writeText(page.api_key).then(() => setToast({ ok: true, text: 'API key copied.' }))} title="Copy"><i className="fa-solid fa-copy" /></button>
          </div>
          <button className="btn btn-danger" disabled={busy === 'regenerate_api_key'} onClick={() => act('regenerate_api_key', {}, 'Regenerate your API key? Anything using the old key will stop working until you update it.')}>
            <i className="fa-solid fa-rotate" /> Regenerate key</button>
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-clock" /> Time zone</h3>
          <p className="muted small">Set your preferred time zone for accurate time-based features.</p>
          <div className="inline-field">
            <Combobox label="Time zone" placeholder="Search city or region" value={f.timezone} options={tzOpts} onChange={(v) => set('timezone')({ target: { value: v } })} />
            <button className="btn" disabled={busy === 'update_timezone'} onClick={() => act('update_timezone', { timezone: f.timezone })}>Save</button>
          </div>
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-language" /> Language</h3>
          <p className="muted small">Choose the language for the dashboard.</p>
          <div className="inline-field">
            <Combobox label="Language" searchable={false} value={f.language} options={page.languages.map((l) => ({ value: l.code, label: l.name }))} onChange={(v) => set('language')({ target: { value: v } })} />
            <button className="btn" disabled={busy === 'update_language'} onClick={() => act('update_language', { language: f.language })}>Save</button>
          </div>
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-cloud-sun" /> Weather location</h3>
          <p className="muted small">Set your default city for weather commands.</p>
          <div className="inline-field">
            <input className="input" value={f.weather} onChange={set('weather')} placeholder="City" />
            <button className="btn" disabled={busy === 'update_weather_location'} onClick={() => act('update_weather_location', { weather_location: f.weather })}>Save</button>
          </div>
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-heart-pulse" /> Heart rate code</h3>
          <p className="muted small">Heart Rate in chat via Specter is powered by <a href="https://www.hyperate.io/" target="_blank" rel="noopener">HypeRate</a>.</p>
          <div className="inline-field">
            <input className="input" value={f.heart} onChange={set('heart')} placeholder="HypeRate code" />
            <button className="btn" disabled={busy === 'update_heartrate_code'} onClick={() => act('update_heartrate_code', { heartrate_code: f.heart })}>Save</button>
          </div>
        </section>

        <section className="card">
          <h3 className="card-title-row"><span><i className="fa-solid fa-database" /> Storage usage</span>
            {list && <span className="pill pill-info">{list.storage.beta_access ? 'Beta access' : !list.storage.tier || list.storage.tier === 'None' ? 'Free plan' : `Tier ${String(list.storage.tier).replace('000', '')}`}</span>}</h3>
          {listErr ? <div className="notice notice-err"><i className="fa-solid fa-triangle-exclamation" /> <span>Could not load storage.</span> <button className="btn sm" onClick={loadList}>Retry</button></div>
            : !list ? <div className="skel-list"><span className="skel" /><span className="skel" /></div> : <StorageCard s={list.storage} parts={parts} />}
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-cookie-bite" /> Cookie consent</h3>
          <p className="muted small">{cookie === 'accepted' ? 'You have accepted cookies. Some features may use cookies to enhance your experience.'
            : cookie === 'declined' ? 'You have declined cookies. Some features may be limited or not remember your preferences.'
              : 'You have not set your cookie consent preference yet. You may be prompted to do so.'}</p>
          <span className={`pill ${cookie === 'accepted' ? 'pill-ok' : cookie === 'declined' ? 'pill-live' : 'pill-warn'}`}>{cookie === 'accepted' ? 'Accepted' : cookie === 'declined' ? 'Declined' : 'Not set'}</span>
          <div><button className={`btn ${cookie === 'declined' ? '' : 'btn-danger'}`} onClick={() => setCookie(cookie === 'declined' ? 'accepted' : 'declined')}>{cookie === 'declined' ? 'Accept cookies' : 'Decline cookies'}</button></div>
        </section>

        <section className="card">
          <h3 className="card-title-row"><span><i className="fa-solid fa-mobile-screen" /> App password</span><span className="pill pill-off">Mobile app: Coming soon</span></h3>
          <p className="muted small">Set a password to protect access to the BotOfTheSpecter mobile app.</p>
          <span className={`pill ${page.has_app_password ? 'pill-ok' : 'pill-off'}`}>{page.has_app_password ? 'Password set' : 'No password'}</span>
          <input className="input" type="password" autoComplete="new-password" placeholder="New password (6+ characters)" value={f.pw} onChange={set('pw')} />
          <input className="input" type="password" autoComplete="new-password" placeholder="Confirm password" value={f.pw2} onChange={set('pw2')} />
          <div className="row-btns">
            <button className="btn" disabled={busy === 'set_app_password' || f.pw.length < 6} onClick={async () => { await act('set_app_password', { app_password: f.pw, app_password_confirm: f.pw2 }); setForm((x) => ({ ...x, pw: '', pw2: '' })) }}>Save password</button>
            {page.has_app_password && <button className="btn btn-danger" disabled={busy === 'clear_app_password'} onClick={() => act('clear_app_password', {}, 'Remove your app password?')}>Remove</button>}
          </div>
        </section>
      </div>

      <section className="card">
        <h3><i className="fa-solid fa-link" /> Connected accounts</h3>
        <div className="svc-links">
          <div className="svc-link">
            <div className="svc-link-head"><i className="fa-brands fa-twitch" /><b>Twitch</b></div>
            <div className="svc-link-pill"><span className="pill pill-ok">Connected</span></div>
            <p className="svc-link-sub">Your sign-in account.</p>
            <button className="btn btn-ghost" disabled={busy === 'disconnect_twitch'} onClick={() => act('disconnect_twitch', {}, 'Log out of the dashboard?')}><i className="fa-solid fa-right-from-bracket" /> Log out</button>
          </div>
          {SERVICES.map((s) => {
            const comingSoon = s.key === 'kick' || (s.key === 'youtube' && !page.youtube_enabled)
            const linked = list?.links[s.key]
            return (
              <div className="svc-link" key={s.key}>
                <div className="svc-link-head"><i className={s.icon} /><b title={s.name}>{s.name}</b></div>
                <div className="svc-link-pill">
                  {comingSoon ? <span className="pill pill-off">Coming soon</span>
                    : listErr ? <span className="pill pill-warn">Unknown</span>
                      : !list ? <span className="pill pill-off">Checking...</span>
                        : <span className={`pill ${linked ? 'pill-ok' : 'pill-off'}`}>{linked ? 'Connected' : 'Not connected'}</span>}
                </div>
                <p className="svc-link-sub" aria-hidden="true">&nbsp;</p>
                {comingSoon ? <button className="btn btn-ghost" disabled><i className="fa-solid fa-clock" /> Coming soon</button>
                  : !list ? <button className="btn btn-ghost" disabled>{listErr ? 'Status unavailable' : 'Loading...'}</button>
                    : linked
                      ? <button className="btn btn-danger" disabled={busy === s.action} onClick={() => act(s.action, {}, `Disconnect ${s.name}?`)}><i className="fa-solid fa-link-slash" /> Disconnect</button>
                      : <a className="btn" href={s.href}><i className="fa-solid fa-link" /> Connect</a>}
              </div>
            )
          })}
        </div>
        {listErr && <div className="notice notice-err"><i className="fa-solid fa-triangle-exclamation" /> <span>Could not load link status.</span> <button className="btn sm" onClick={loadList}>Retry</button></div>}
        <p className="muted small">Connecting opens the main dashboard to finish linking. Come back here afterwards and refresh to see the new status.</p>
      </section>

      <section className="card">
        <h3><i className="fa-solid fa-robot" /> Custom bot</h3>
        <p className="muted small">Use your own Twitch bot account instead of BotOfTheSpecter.</p>
        {page.custom_bot && (page.custom_bot.verified
          ? <span className="pill pill-ok"><i className="fa-solid fa-check" /> Verified</span>
          : <div className="notice notice-warn"><i className="fa-solid fa-circle-info" /> <span><b>To verify this bot:</b> open <a href="https://mybot.specterbotsystems.com/custombot.php" target="_blank" rel="noopener">mybot.specterbotsystems.com/custombot.php</a> in an incognito or private window, sign in with the bot account, and complete the verification within 24 hours. If it is not verified in time, the bot is removed and you will need to add it again. Using a private window makes sure you do not sign in with your main account.</span></div>)}
        <div className="inline-field">
          <input className="input" placeholder="Bot username" value={f.botName} onChange={set('botName')} />
          <button className="btn btn-ghost" disabled={busy === 'resolve'} onClick={resolveBot}><i className={`fa-solid ${busy === 'resolve' ? 'fa-spinner fa-spin' : 'fa-magnifying-glass'}`} /> Look up ID</button>
        </div>
        <input className="input mono" placeholder="Bot Twitch ID (looked up automatically if empty)" value={f.botId} onChange={set('botId')} />
        <div><button className="btn" disabled={busy === 'save_custom_bot' || !f.botName.trim()} onClick={() => act('save_custom_bot', { bot_username: f.botName.trim(), bot_channel_id: f.botId.trim() })}>Save custom bot</button></div>
      </section>
    </div>
  )
}

function fmtBytes(b: number) {
  if (b >= 1024 ** 3) return `${(b / 1024 ** 3).toFixed(2)} GB`
  if (b >= 1024 ** 2) return `${(b / 1024 ** 2).toFixed(1)} MB`
  if (b >= 1024) return `${(b / 1024).toFixed(0)} KB`
  return `${b} B`
}
const PART_COLOR: Record<string, string> = { walkons: 'var(--accent)', sounds: '#2f9be0', media: '#2f9be0', videos: '#e0812f', music: '#2fb67a' }

function StorageCard({ s, parts }: { s: ListData['storage']; parts: StoragePart[] | null }) {
  const pct = Math.max(0, Math.min(100, s.percent))
  const level = pct >= 90 ? 'err' : pct >= 70 ? 'warn' : 'ok'
  const r = 52, c = 2 * Math.PI * r
  const free = Math.max(0, (s.max ?? 0) - (s.used ?? 0))
  const total = parts?.reduce((a, p) => a + p.bytes, 0) ?? 0
  return (
    <div className="storage">
      <div className="storage-top">
        <div className={`gauge gauge-${level}`} role="img" aria-label={`${pct}% of storage used`}>
          <svg viewBox="0 0 120 120"><circle className="gauge-track" cx="60" cy="60" r={r} /><circle className="gauge-fill" cx="60" cy="60" r={r} strokeDasharray={c} strokeDashoffset={c * (1 - pct / 100)} /></svg>
          <div className="gauge-mid"><b>{Math.round(pct)}%</b><small>used</small></div>
        </div>
        <div className="storage-side">
          <p className="storage-of"><b>{s.used_formatted}</b> of {s.max_formatted}</p>
          <dl className="storage-stats">
            <div><dt>Used</dt><dd>{s.used_formatted}</dd></div>
            <div><dt>Free</dt><dd>{fmtBytes(free)}</dd></div>
            <div><dt>Plan limit</dt><dd>{s.max_formatted}</dd></div>
          </dl>
        </div>
      </div>
      {level !== 'ok' && <div className={`notice notice-${level}`}><i className="fa-solid fa-triangle-exclamation" /> <span>{level === 'err' ? 'Storage is almost full. Remove files or upgrade your plan to upload more.' : 'Storage is filling up.'}</span></div>}
      {parts && total > 0 && (
        <div className="storage-break">
          <div className="seg-bar" aria-hidden="true">{parts.filter((p) => p.bytes > 0).map((p) => <span key={p.key} style={{ width: `${(p.bytes / total) * 100}%`, background: PART_COLOR[p.key] }} />)}</div>
          <ul className="legend">{parts.map((p) => <li key={p.key}><i style={{ background: PART_COLOR[p.key] }} />{p.label}<b>{fmtBytes(p.bytes)}</b></li>)}</ul>
        </div>
      )}
      {parts && total === 0 && <p className="muted small">No uploaded files yet.</p>}
      <a className="btn btn-ghost storage-manage" href="/media" onClick={(e) => { e.preventDefault(); history.pushState(null, '', '/media'); dispatchEvent(new PopStateEvent('popstate')) }}><i className="fa-solid fa-folder-open" /> Manage files</a>
    </div>
  )
}
