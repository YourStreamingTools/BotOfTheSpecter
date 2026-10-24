import { hasConsent } from '../consent'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  botAction, fetchBotStatus, fetchLimits, fetchList, fetchOverview, fetchService, saveUseCustom, saveUseCustomModule,
  type BotStatus, type BotType, type Limits, type ListStatus, type Overview, type Service,
} from '../bot'

const BOTS: Array<[BotType, string]> = [['v6', 'Version 6'], ['beta', 'Beta'], ['stable', 'Stable']]
const DESC: Record<BotType, string> = {
  stable: 'The stable version is well-tested and reliable for everyday use. We recommend this version for every stream.',
  beta: 'The beta version contains new features that are still being tested. Recommended for testing new functionality.',
  v6: 'Version 6 is a complete rewrite and is currently in early development.',
  kick: 'The Kick bot runs in your Kick channel. You can run it at the same time as your Twitch bot.',
}
const NAME: Record<BotType, string> = { stable: 'Stable bot', beta: 'Beta bot', v6: 'Version 6 bot', kick: 'Kick bot' }
const SERVICES: Array<[string, string, string]> = [
  ['api', 'API Service', 'fa-solid fa-server'], ['database', 'Database Service', 'fa-solid fa-database'],
  ['websocket', 'Notification Service', 'fa-solid fa-bell'], ['bots', 'Bot Server', 'fa-solid fa-robot'],
  ['discordbot', 'Discord Bot', 'fa-brands fa-discord'],
]
const LIMITS: Array<[keyof Limits, string]> = [['shazam', 'Song ID requests'], ['exchangerate', 'Exchange rate requests'], ['weather', 'Weather requests']]
const KEY = 'sp-beta-bot'

function readBot(): BotType {
  try { const v = localStorage.getItem(KEY); if (v === 'stable' || v === 'beta' || v === 'v6' || v === 'kick') return v } catch { /* ignore */ }
  return 'v6'
}
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))
const stripTags = (s: string) => s.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]+>/g, '')

type Toast = { kind: 'info' | 'ok' | 'err'; text: string } | null

export default function Bot() {
  const [bot, setBotState] = useState<BotType>(readBot())
  const [ov, setOv] = useState<Overview | null>(null)
  const [list, setList] = useState<ListStatus | null>(null)
  const [status, setStatus] = useState<BotStatus | null>(null)
  const [limits, setLimits] = useState<Limits | null>(null)
  const [services, setServices] = useState<Record<string, Service | 'error' | undefined>>({})
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState<'run' | 'stop' | null>(null)
  const [toast, setToast] = useState<Toast>(null)
  const [useCustom, setUseCustom] = useState(false)
  const [useSelf, setUseSelf] = useState(false)
  const [useModule, setUseModule] = useState(false)
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const setBot = (b: BotType) => { if (hasConsent()) { try { localStorage.setItem(KEY, b) } catch { /* ignore */ } } setStatus(null); setBotState(b) }

  const loadStatus = useCallback(async (b: BotType) => {
    try { const s = await fetchBotStatus(b); if (alive.current) setStatus(s); return s } catch { if (alive.current) setStatus({ success: false } as BotStatus); return null }
  }, [])

  useEffect(() => {
    fetchOverview().then((o) => {
      setOv(o); setUseCustom(o.use_custom === 1); setUseSelf(o.use_self === 1); setUseModule(o.use_custom_module === 1)
    }).catch(() => setErr('Could not load your bot settings.'))
    fetchList().then(setList).catch(() => setList(null))
    fetchLimits().then(setLimits).catch(() => setLimits(null))
    SERVICES.forEach(([svc]) => fetchService(svc)
      .then((d) => setServices((p) => ({ ...p, [svc]: d })))
      .catch(() => setServices((p) => ({ ...p, [svc]: 'error' }))))
  }, [])

  useEffect(() => {
    loadStatus(bot)
    const id = setInterval(() => { if (!busy) loadStatus(bot) }, 30000)
    return () => clearInterval(id)
  }, [bot, busy, loadStatus])

  const isKick = bot === 'kick'
  // Only a confirmed answer from Twitch counts as "not a moderator". A failed
  // check (bot_mod_unknown), a network error or an expired session (401, handled
  // by the session guard) leaves list unset or unknown, so no warning shows.
  const isMod = list && list.success && !list.bot_mod_unknown ? list.bot_is_mod : true
  const actingAs = ov?.is_acting_as ?? false

  const blocker: string | null =
    actingAs ? 'Bot controls are disabled while acting as another user.'
    : isKick && ov && !ov.kick_connected ? 'Connect your Kick account to start the Kick bot.'
    : !isKick && list && list.success && list.bot_is_banned ? 'The bot is banned from your channel.'
    : !isKick && !isMod ? 'The bot is not a moderator on your channel. Make the bot a moderator to start it.'
    : bot === 'beta' && list && !list.beta_access ? 'You need an active subscription to start the beta bot.'
    : null

  // Restart works like the old dashboard's Stop then Run: bot_action.php "stop", wait until the
  // status shows it stopped, then "run". A plain "run" on a running bot is a no-op in the bots API
  // (state already_running), which is why the old Restart button did nothing.
  async function act(action: 'run' | 'stop' | 'restart') {
    const starts = action !== 'stop'
    if (starts && blocker) return setToast({ kind: 'err', text: blocker })
    if (actingAs) return setToast({ kind: 'err', text: 'Bot controls are disabled while acting as another user.' })
    if (starts && !isKick && !isMod) return setToast({ kind: 'err', text: 'The bot is not a moderator on your channel. Please make the bot a moderator to start it.' })
    if (starts && bot === 'beta' && list && !list.beta_access) return setToast({ kind: 'err', text: 'You need an active subscription to access this version of the bot.' })
    if (starts && isKick && ov && !ov.kick_connected) return setToast({ kind: 'err', text: 'Your Kick account is not connected.' })
    setBusy(action === 'stop' ? 'stop' : 'run')
    const opts = { useCustom: (bot === 'stable' || bot === 'beta' || bot === 'v6') && useCustom, useSelf, loadModule: useModule }
    const waitFor = async (want: boolean) => {
      for (let i = 0; i < 6 && alive.current; i++) {
        await sleep(i === 0 ? 1500 : 2500)
        const s = await loadStatus(bot)
        if (s && s.running === want) return true
      }
      return false
    }
    try {
      if (action === 'restart') {
        setToast({ kind: 'info', text: `${NAME[bot]}: restarting, stop command sent...` })
        const r = await botAction('stop', bot, opts)
        if (!r.success) return setToast({ kind: 'err', text: `${NAME[bot]} restart failed while stopping: ${stripTags(r.message || 'unknown error')}` })
        if (!(await waitFor(false))) return setToast({ kind: 'err', text: `${NAME[bot]} did not stop in time, so it was not started again. Check the status in a moment.` })
        if (!alive.current) return
        setToast({ kind: 'info', text: `${NAME[bot]}: stopped, start command sent...` })
      } else setToast({ kind: 'info', text: `${NAME[bot]}: ${action === 'run' ? 'start' : 'stop'} command sent...` })
      const want = action !== 'stop'
      const r = await botAction(want ? 'run' : 'stop', bot, opts)
      if (!r.success) {
        setToast({ kind: 'err', text: `${NAME[bot]} ${action === 'restart' ? 'restart failed while starting' : action + ' failed'}: ${stripTags(r.message || 'unknown error')}` })
      } else {
        const ok = await waitFor(want)
        setToast(ok
          ? { kind: 'ok', text: action === 'restart' ? `${NAME[bot]} restarted and is running.` : `${NAME[bot]} is now ${want ? 'running' : 'stopped'}.` }
          : { kind: 'info', text: `${NAME[bot]}: command accepted, status not confirmed yet. It will refresh shortly.` })
      }
    } catch {
      setToast({ kind: 'err', text: `${NAME[bot]} ${action} timed out. Check the status again in a moment.` })
    } finally {
      setBusy(null)
    }
  }

  async function toggleCustom(on: boolean) {
    setUseCustom(on); if (on) setUseSelf(false)
    const r = await saveUseCustom(on ? { use_custom: '1', use_self: '0' } : { use_custom: '0' }).catch(() => null)
    if (!r?.success) { setUseCustom(!on); setToast({ kind: 'err', text: 'Could not save the custom bot setting.' }) }
  }
  async function toggleSelf(on: boolean) {
    setUseSelf(on); if (on) setUseCustom(false)
    const r = await saveUseCustom(on ? { use_self: '1', use_custom: '0' } : { use_self: '0' }).catch(() => null)
    if (!r?.success) { setUseSelf(!on); setToast({ kind: 'err', text: 'Could not save the Use Self setting.' }) }
  }
  async function toggleModule(on: boolean) {
    setUseModule(on)
    const r = await saveUseCustomModule(on).catch(() => null)
    if (!r?.success) { setUseModule(!on); setToast({ kind: 'err', text: 'Could not save the custom module setting.' }) }
    else setToast({ kind: 'info', text: `Custom module ${on ? 'on' : 'off'}. Restart the bot to apply.` })
  }

  const running = status?.success ? status.running : null
  const tech = ov?.is_technical ?? false

  return (
    <div className="bot-page">
      {list && list.bot_mod_message && !isKick && (
        <div className="card notice notice-error"><i className="fa-solid fa-triangle-exclamation" /> <span>{stripTags(list.bot_mod_message)}</span></div>
      )}
      {err && <div className="card notice notice-error"><i className="fa-solid fa-triangle-exclamation" /> {err}</div>}
      {toast && (
        <div className={`card notice notice-${toast.kind}`} role="status">
          <i className={`fa-solid ${toast.kind === 'err' ? 'fa-circle-xmark' : toast.kind === 'ok' ? 'fa-circle-check' : 'fa-circle-info'}`} />
          <span>{toast.text}</span>
          <button className="ghost sm" onClick={() => setToast(null)} aria-label="Dismiss"><i className="fa-solid fa-xmark" /></button>
        </div>
      )}

      <section className="card bot-main">
        <div className="bot-head">
          <h2><i className="fa-solid fa-robot" /> Bot Control</h2>
          <div className="bot-pickers">
            <div className="seg" role="tablist" aria-label="Platform">
              <button className={!isKick ? 'on' : ''} onClick={() => isKick && setBot('v6')}><i className="fa-brands fa-twitch" /> Twitch</button>
              <button className={isKick ? 'on' : ''} onClick={() => !isKick && setBot('kick')}><i className="fa-solid fa-k" /> Kick</button>
            </div>
            {!isKick && (
              <div className="seg" role="tablist" aria-label="Bot version">
                {BOTS.map(([v, l]) => <button key={v} className={bot === v ? 'on' : ''} onClick={() => bot !== v && setBot(v)}>{l}</button>)}
              </div>
            )}
          </div>
        </div>

        <p className="muted">{DESC[bot]}</p>
        {bot === 'v6' && (
          <p className="muted"><i className="fa-solid fa-flask" /> Version 6 is a complete rewrite in early development. Features are being added over time.</p>
        )}
        {isKick && ov && (ov.kick_connected
          ? <p className="muted">Kick channel: <b>{ov.kick_channel_name}</b></p>
          : <div className="notice notice-warn"><i className="fa-solid fa-plug-circle-xmark" /> <span>Your Kick account is not connected.</span> <a className="btn sm" href="https://dashboard.botofthespecter.com/kicklink.php"><i className="fa-solid fa-link" /> Connect Kick</a></div>)}
        {bot === 'beta' && list && !list.beta_access && (
          <div className="notice notice-warn"><i className="fa-solid fa-lock" /> <span>You need an active subscription to access this version of the bot. See <a href="/premium" onClick={(e) => { if (e.metaKey || e.ctrlKey || e.shiftKey) return; e.preventDefault(); window.history.pushState({}, '', '/premium'); window.dispatchEvent(new PopStateEvent('popstate')); window.scrollTo(0, 0) }}>Premium</a>.</span></div>
        )}

        {!isKick && (
          <div className="toggles">
            {ov?.has_verified_custom_bot && (bot === 'stable' || bot === 'beta' || bot === 'v6') && (
              <label className="toggle"><input type="checkbox" checked={useCustom} onChange={(e) => toggleCustom(e.target.checked)} />
                <span><b>Custom bot name</b><small>{ov.custom_bot_username}</small></span></label>
            )}
            <label className="toggle"><input type="checkbox" checked={useSelf} onChange={(e) => toggleSelf(e.target.checked)} />
              <span><b>Use Self</b><small>Send bot messages from your own Twitch account</small></span></label>
            {status?.customModuleAvailable && (
              <label className="toggle"><input type="checkbox" checked={useModule} onChange={(e) => toggleModule(e.target.checked)} />
                <span><b>Custom module</b><small>Load your channel module on start</small></span></label>
            )}
          </div>
        )}
        {useCustom && (bot === 'stable' || bot === 'beta' || bot === 'v6') && <div className="notice notice-info"><i className="fa-solid fa-circle-info" /> <span>When using a custom bot name, BotOfTheSpecter must still remain a moderator in your channel.</span></div>}
        {useSelf && !isKick && <div className="notice notice-info"><i className="fa-solid fa-circle-info" /> <span>When Use Self is enabled the bot will send messages using your connected Twitch account. The Specter account must still be a moderator for some features to work.</span></div>}

        <div className="bot-status">
          <div className={`status-orb ${running === null ? 'unknown' : running ? 'on' : 'off'}`}>
            <i className={`fa-solid ${status === null ? 'fa-spinner fa-spin' : running ? 'fa-heart-pulse' : 'fa-power-off'}`} />
          </div>
          <div>
            <span className="muted">Status</span>
            <b>{status === null ? 'Checking...' : !status.success ? 'Unknown' : running ? 'Running' : 'Stopped'}</b>
            {tech && status?.running && status.pid > 0 && <small className="muted">PID {status.pid}</small>}
          </div>
          <div className="bot-buttons">
            {running
              ? <button className="btn btn-danger" disabled={!!busy || actingAs} title={actingAs ? 'Bot controls are disabled while acting as another user.' : undefined} onClick={() => act('stop')}>
                  <i className={`fa-solid ${busy === 'stop' ? 'fa-spinner fa-spin' : 'fa-stop'}`} /> Stop bot</button>
              : null}
            <button className="btn" disabled={!!busy || status === null || !!blocker} title={blocker ?? undefined} onClick={() => act(running ? 'restart' : 'run')}>
              <i className={`fa-solid ${busy === 'run' ? 'fa-spinner fa-spin' : running ? 'fa-rotate' : 'fa-play'}`} /> {running ? 'Restart bot' : 'Start bot'}
            </button>
          </div>
        </div>
      </section>

      <div className="bot-grid">
        <section className="card">
          <h3><i className="fa-solid fa-code-branch" /> Version</h3>
          {status === null ? <div className="skel-list"><span className="skel" /><span className="skel" /><span className="skel" /></div> : (
            <dl className="kv">
              <dt>Last updated</dt><dd>{status.lastModified ?? '-'}</dd>
              <dt>Last run</dt><dd>{status.lastRun ?? '-'}</dd>
              <dt>Running version</dt><dd>{status.version || '-'}</dd>
              <dt>Latest version</dt><dd>{status.latestVersion || '-'}</dd>
              {bot === 'stable' && <><dt>Update notes</dt><dd><a href="https://changelog.botofthespecter.com/" target="_blank" rel="noopener">View changelog</a></dd></>}
            </dl>
          )}
          {status?.success && (status.versionOutdated
            ? <><span className="pill pill-warn"><i className="fa-solid fa-arrow-up" /> Update available: v{status.latestVersion}</span><p className="muted small">Restart your bot to update.</p></>
            : status.codeUpdateAvailable
              ? <><span className="pill pill-warn"><i className="fa-solid fa-rotate" /> New changes ready</span><p className="muted small">Restart your bot to load the latest changes.</p></>
              : <span className="pill pill-ok"><i className="fa-solid fa-check" /> Up to date</span>)}
        </section>

        <section className="card">
          <div className="card-head">
            <h3><i className="fa-solid fa-tower-broadcast" /> Channel status</h3>
            {list && <span className={`pill ${list.stream.final === 'True' ? 'pill-live' : 'pill-off'}`}><span className="dot" /> {list.stream.final === 'True' ? 'Online' : 'Offline'}</span>}
          </div>
          {list === null ? <div className="skel-list"><span className="skel" /></div> : (
            <>
              <p className="muted small">This status combines information from Twitch and the Specter systems.</p>
              {tech && (
                <dl className="kv">
                  <dt title={list.tooltips.db}>Database</dt><dd>{streamLabel(list.stream.db, 'Unknown')}</dd>
                  <dt title={list.tooltips.ssh}>Bot server</dt><dd>{streamLabel(list.stream.ssh, 'Unknown')}</dd>
                  <dt title={list.tooltips.twitch}>Twitch</dt><dd>{streamLabel(list.stream.twitch, 'Offline')}</dd>
                </dl>
              )}
            </>
          )}
        </section>

        <section className="card">
          <h3><i className="fa-solid fa-gauge" /> API limits</h3>
          {limits === null ? <div className="skel-list"><span className="skel" /><span className="skel" /><span className="skel" /></div> : (
            <div className="limits">
              {LIMITS.map(([k, label]) => {
                const l = limits[k]; const pct = l.requests_remaining == null ? 0 : Math.max(0, Math.min(100, (l.requests_remaining / l.requests_limit) * 100))
                return (
                  <div key={k}>
                    <div className="limit-head"><span>{label}</span><b>{l.requests_remaining ?? '--'} / {l.requests_limit}</b></div>
                    <div className="bar"><span style={{ width: `${pct}%` }} /></div>
                    <small className="muted">Updated: {l.last_updated ? new Date(l.last_updated).toLocaleString() : '--'}</small>
                  </div>
                )
              })}
            </div>
          )}
        </section>
      </div>

      <section>
        <div className="section-head"><h3>Services</h3>{tech && <span className="muted small">Measured from our Australian datacenter</span>}</div>
        <div className="svc-grid">
          {SERVICES.map(([svc, label, icon]) => {
            const d = services[svc]
            const st = d === undefined ? 'loading' : d === 'error' ? 'ERROR' : d.status
            return (
              <div className={`card svc svc-${st === 'OK' ? 'ok' : st === 'DISABLED' ? 'off' : st === 'loading' ? 'loading' : 'bad'}`} key={svc}>
                <i className={icon} />
                <div>
                  <b>{label}</b>
                  <span>{st === 'loading' ? 'Checking...' : st === 'OK' ? 'Running normally' : st === 'DISABLED' ? 'Monitoring disabled' : (d !== 'error' && d?.message) || 'Status unknown'}</span>
                  {tech && d && d !== 'error' && (
                    <small className="muted">{d.latency_ms != null ? `${d.latency_ms}ms` : ''}{d.pid ? ` · PID ${d.pid}${d.pid_running ? '' : ' (dead)'}` : ''}</small>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </section>
    </div>
  )
}

// Twitch check returns 'True' or null (null = not live); db/ssh return 'True', 'False' or null.
function streamLabel(v: string | null, ifNull: string) {
  return v === 'True' ? 'Online' : v === 'False' ? 'Offline' : ifNull
}
