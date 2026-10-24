import { UrlReveal } from '../components/UrlReveal'
import { useEffect, useRef, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'
import { createCaptionEngine } from './cc/engine.gen.js'
import { useTokenUpkeep } from './cc/useTokenUpkeep'
import { signInNow } from '../session'

// Port of dashboard/closed-captions.php. Settings and corrections come from
// public/api/captions/captions.php, captions go out through relay.php so the
// channel key stays on the server. The captioner itself is the live script
// (src/pages/cc/engine.gen.js), both from scripts/sync-live-api.sh.
const API = '/api/captions/captions.php'
const RELAY = '/api/captions/relay.php'
type Settings = { enabled: number; language: string; font_size: number; text_color: string; background_style: string; position: string; max_lines: number; fade_seconds: number; profanity_filter: number; action_tags_enabled: number; target_language: string; font_family: string; show_confidence: number }
type Corr = { match_text: string; replace_text: string; match_mode: 'word' | 'substring'; case_sensitive: number; enabled: number }
type Opt = { value: string; label: string }
type Page = { success: boolean; has_key: boolean; settings: Settings; corrections: Corr[]; languages: Opt[]; targets: Opt[]; fonts: string[] }
type Engine = NonNullable<ReturnType<typeof createCaptionEngine>>
type Ev = { t: 'c'; text: string; f: boolean; tr?: boolean } | { t: 'x' } | { t: 'a'; text: string }
const LANG = {
  listening: 'Listening', idle: 'Stopped', starting: 'Starting', micDenied: 'Mic blocked', noSpeech: 'No speech heard',
  networkErr: 'Speech service unreachable', notConnected: 'Not connected', previewPlaceholder: 'Captions appear here once you start speaking.',
  soundLoading: 'Sound tags: loading the detector', soundOn: 'Sound tags: on', soundOff: 'Sound tags: off',
  translateDownloading: 'Downloading the translation model: ', translateUnavailable: "This browser can't translate on-device, so captions stay in the spoken language.",
  translatePairUnavailable: "This browser can't translate between these two languages, so captions stay in the spoken language.",
  translateNeedsClick: 'The translation model needs a download. Press Stop, then Start, to allow it. Captions stay in the spoken language until then.',
  translateActive: 'Translating captions to: ',
}
const BG: Opt[] = [{ value: 'box', label: 'Solid box' }, { value: 'outline', label: 'Text outline' }, { value: 'none', label: 'None' }]
const POS: Opt[] = [{ value: 'bottom', label: 'Bottom' }, { value: 'center', label: 'Centre' }, { value: 'top', label: 'Top' }]
const MODES: Opt[] = [{ value: 'word', label: 'Whole word' }, { value: 'substring', label: 'Anywhere' }]
const NUMS: [keyof Settings, number, number][] = [['font_size', 12, 120], ['max_lines', 1, 5], ['fade_seconds', 0, 60]]
const HEX = /^#[0-9A-Fa-f]{6}$/
const tabId = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('')

// Sends caption events in order, one request at a time. A newer caption
// replaces any interim one still waiting, as the overlay only shows the latest.
class Relay {
  tab = tabId(); q: Ev[] = []; busy = false; open = false; active = false; polling = false; opening: Promise<boolean> | null = null
  onLink: (ok: boolean) => void
  constructor(onLink: (ok: boolean) => void) { this.onLink = onLink }
  private async post(op: string, extra: Record<string, string> = {}) {
    const r = await fetch(RELAY, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ op, tab: this.tab, ...extra }).toString() })
    return { status: r.status, body: await r.json().catch(() => ({})) as { success?: boolean; message?: string } }
  }
  private ensure(): Promise<boolean> {
    if (this.open) return Promise.resolve(true)
    if (!this.opening) this.opening = this.post('open').then((r) => { this.open = !!r.body.success; this.onLink(this.open); if (this.open) this.poll(); return this.open }).catch(() => { this.onLink(false); return false }).finally(() => { this.opening = null })
    return this.opening
  }
  // Only one poll loop per tab. A reopen (after a 410) used to start another loop each time,
  // stacking long polls until they filled every PHP worker on web1.
  private async poll() {
    if (this.polling) return
    this.polling = true
    try { await this.pollLoop() } finally { this.polling = false }
  }
  private async pollLoop() {
    while (this.active && this.open) {
      try { const r = await fetch(`${RELAY}?op=poll&tab=${this.tab}`, { credentials: 'same-origin' }); if (r.status === 410) { this.open = false; if (this.active) await this.ensure() } else if (!r.ok) await new Promise((s) => setTimeout(s, 3000)) }
      catch { await new Promise((s) => setTimeout(s, 3000)) }
    }
  }
  start() { this.active = true; return this.ensure() }
  emit(e: Ev) { if (!this.active) return; if (e.t === 'c') this.q = this.q.filter((x) => !(x.t === 'c' && !x.f)); this.q.push(e); this.flush() }
  private async flush() {
    if (this.busy || !this.q.length) return
    this.busy = true
    for (let tries = 0; this.q.length && tries < 2; tries++) {
      if (!(await this.ensure())) break
      const batch = this.q.splice(0, 20)
      try {
        const r = await this.post('send', { events: JSON.stringify(batch) })
        if (r.status === 410) { this.open = false; this.q.unshift(...batch); continue }
        this.onLink(r.status === 200); tries = -1
      } catch { this.onLink(false); break }
    }
    this.busy = false
    if (this.q.length && this.open) setTimeout(() => this.flush(), 1000)
  }
  async stop() {
    const wait = Date.now() + 3000
    while ((this.busy || this.q.length) && Date.now() < wait) await new Promise((s) => setTimeout(s, 50))
    this.active = false
    if (this.open) { this.open = false; navigator.sendBeacon(RELAY, new URLSearchParams({ op: 'close', tab: this.tab })) }
    this.q = []
  }
}

export default function ClosedCaptions() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const upkeep = useTokenUpkeep()
  useEffect(() => { get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r) }).catch(() => setErr('The closed captions page could not load. Refresh to try again.')) }, [])
  const [f, setF] = useState<Settings | null>(null)
  useEffect(() => { if (page) setF(page.settings) }, [page])
  if (err) return <div className="page cmds mp cc"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page || !f) return <div className="page cmds mp cc"><section className="card"><p className="muted">Loading</p></section></div>
  return (
    <div className="page cmds mp cc">
      {upkeep.notice && <div className="notice notice-warn" role="status"><i className="fas fa-clock-rotate-left" /> <span>{upkeep.notice === 'signin' ? 'Twitch needs you to sign in again. Captions keep running until you do.' : 'Your session ended, so captions may stop reaching the overlay. Sign in again when you are ready.'}</span> <button type="button" className="btn" onClick={signInNow}><i className="fa-brands fa-twitch" /> Sign in</button></div>}
      <Captioner page={page} f={f} setF={setF} toast={setToast} />
      <SettingsCard page={page} f={f} setF={setF} onSaved={(s) => setPage({ ...page, settings: s })} toast={setToast} />
      <Corrections page={page} onSaved={(c) => setPage({ ...page, corrections: c })} toast={setToast} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

const isChromium = () => { const n = navigator as Navigator & { userAgentData?: { brands: { brand: string }[] } }; return !!(window as unknown as { chrome?: unknown }).chrome && (n.userAgentData ? n.userAgentData.brands.some((b) => ['Chromium', 'Google Chrome', 'Microsoft Edge', 'Opera'].includes(b.brand)) : /Chrome|Edg|OPR/.test(navigator.userAgent)) }
// Wired to the window so the engine reaches it from tests; never sends anything itself.
declare global { interface Window { __ccEngine?: Engine } }

function Captioner({ page, f, setF, toast }: { page: Page; f: Settings; setF: (s: Settings) => void; toast: (t: Toast) => void }) {
  const preview = useRef<HTMLDivElement>(null)
  const fr = useRef(f); fr.current = f
  const eng = useRef<Engine | null>(null), relay = useRef<Relay | null>(null)
  const [status, setStatus] = useState<{ text: string; state: string }>({ text: LANG.idle, state: 'offline' })
  const [running, setRunning] = useState(false), [notice, setNotice] = useState(''), [link, setLink] = useState(true)
  const [supported, setSupported] = useState(true), [blocked, setBlocked] = useState(false)
  useEffect(() => {
    const r = new Relay(setLink); relay.current = r
    const e = createCaptionEngine({ settings: page.settings, fonts: page.fonts, corrections: page.corrections, lang: LANG, emit: (ev: Ev) => r.emit(ev) },
      { preview: preview.current, get: () => fr.current, status: (text: string, state: string) => setStatus({ text, state }), running: setRunning, notice: setNotice })
    eng.current = e; window.__ccEngine = e ?? undefined
    if (!e) setSupported(false)
    navigator.permissions?.query({ name: 'microphone' as PermissionName }).then((p) => { setBlocked(p.state === 'denied'); p.onchange = () => setBlocked(p.state === 'denied') }).catch(() => {})
    return () => { e?.destroy(); r.stop(); eng.current = null }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { eng.current?.applyFont() }, [f.font_family])
  useEffect(() => { eng.current?.setLanguage() }, [f.language])
  useEffect(() => { eng.current?.repaint() }, [f.show_confidence])
  async function start() {
    if (!eng.current || !relay.current) return
    setLink(true)
    relay.current.start()
    await eng.current.start()
  }
  function stop() { eng.current?.stop(); relay.current?.stop() }
  const micBlocked = status.text === LANG.micDenied || (blocked && !running)
  const pill = micBlocked ? ['pill-err', 'fas fa-microphone-slash', LANG.micDenied] : running && !link ? ['pill-err', 'fas fa-plug-circle-xmark', 'Not reaching overlay'] : status.state === 'online' ? ['pill-ok', 'fas fa-circle', status.text] : status.state === 'warn' ? ['pill-warn', running ? 'fas fa-circle-exclamation' : 'fas fa-spinner fa-spin', status.text] : ['pill-off', 'fas fa-stop', status.text]
  const chrome = isChromium()
  return (
    <section className="card cc-cap" aria-label="Captioner">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-microphone" /> Captioner <span className={`pill ${pill[0]}`} aria-live="polite"><i className={`${pill[1]} cc-pi`} /> {pill[2]}</span></h3></div>
      {!supported ? <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>This browser has no speech recognition. Open this page in Chrome or Edge to caption.</span></div>
        : !chrome && <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>Captioning works best in Chrome or Edge.</span></div>}
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">Add as an OBS browser source. It contains your channel key, so keep it out of screen shares.</span></div>
          <div className="mp-row-r mp-urlbox"><UrlReveal mask="https://overlay.botofthespecter.com/closed-captions.php?code=••••••••••••" disabled={!page.has_key} toast={toast}
            fetchUrl={async () => { const r = await get<{ success: boolean; url?: string; message?: string }>(`${API}?beta=url`); if (!r.success || !r.url) throw new Error(r.message || 'Could not get the overlay URL.'); return r.url }} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Captions</span><span className={micBlocked ? 'mp-bad' : 'muted'}>{micBlocked ? 'Allow the microphone for this site in the address bar, then start again.' : 'Keep this tab open while you stream. Your speech is transcribed in the browser and sent to the overlay.'}</span></div>
          <div className="mp-row-r cc-ctl">{running
            ? <button type="button" className="btn btn-danger" onClick={stop}><i className="fas fa-stop" /> Stop</button>
            : <button type="button" className="btn" disabled={!supported || status.state === 'warn' && status.text === LANG.starting} onClick={start}><i className="fas fa-play" /> Start captioning</button>}</div></div>
      </div>
      <div className="cc-prev-h"><h4 className="mp-h">Live preview</h4><label className="cc-conf-t"><span className="muted small">Confidence</span><Switch on={!!f.show_confidence} label="Show confidence" onChange={(v) => setF({ ...f, show_confidence: v ? 1 : 0 })} /></label></div>
      <div className="cc-preview" ref={preview} aria-live="polite"><span className="cc-preview-placeholder">{LANG.previewPlaceholder}</span></div>
      <div className="cc-foot">
        {!!f.show_confidence && <span className="cc-legend muted small"><span><i className="cc-dot cc-conf-high" /> 80% and up</span><span><i className="cc-dot cc-conf-med" /> 60 to 79%, check</span><span><i className="cc-dot cc-conf-low" /> under 60%, likely misheard</span></span>}
        {notice && <span className="muted small cc-notice"><i className="fas fa-circle-info" /> {notice}</span>}
      </div>
    </section>
  )
}

function SettingsCard({ page, f, setF, onSaved, toast }: { page: Page; f: Settings; setF: (s: Settings) => void; onSaved: (s: Settings) => void; toast: (t: Toast) => void }) {
  const s = page.settings
  const [raw, setRaw] = useState<Record<string, string>>({}), [hex, setHex] = useState(f.text_color)
  const [saving, setSaving] = useState(false)
  useEffect(() => { setRaw(Object.fromEntries(NUMS.map(([k]) => [k, String(s[k])]))); setHex(s.text_color) }, [s])
  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setF({ ...f, [k]: v })
  const badNum = (k: keyof Settings) => { const lim = NUMS.find(([n]) => n === k)!; const v = raw[k] ?? ''; return !/^\d+$/.test(v) || Number(v) < lim[1] || Number(v) > lim[2] }
  const bad = NUMS.some(([k]) => badNum(k)) || !HEX.test(hex)
  const dirty = JSON.stringify(f) !== JSON.stringify(s) || NUMS.some(([k]) => raw[k] !== String(s[k])) || hex.toUpperCase() !== s.text_color.toUpperCase()
  const eng = () => window.__ccEngine
  async function save() {
    setSaving(true)
    const next: Settings = { ...f, ...Object.fromEntries(NUMS.map(([k]) => [k, Number(raw[k])])), text_color: hex.toUpperCase() }
    try {
      const body = Object.fromEntries(Object.entries(next).map(([k, v]) => [k, String(v)]))
      const r = await send<{ success: boolean; message?: string; error?: string; overlay_told?: boolean }>(API, { action: 'save', ...body })
      if (!r.success) throw new Error(r.message || 'Could not save settings.')
      setF(next); onSaved(next); eng()?.saved(next)
      toast({ kind: 'ok', text: r.overlay_told ? 'Caption settings saved.' : 'Caption settings saved. Refresh the overlay in OBS to see the new look.' })
    } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  const num = (k: keyof Settings, label: string, unit: string, help: string) => {
    const lim = NUMS.find(([n]) => n === k)!, invalid = badNum(k)
    return (
      <div className="mp-row"><div className="mp-row-l"><label htmlFor={`cc-${k}`}>{label}</label><span id={`cc-${k}-h`} className={invalid ? 'mp-bad' : 'muted'}>{invalid ? `Enter ${lim[1]} to ${lim[2]}.` : help}</span></div>
        <div className="mp-row-r"><span className={`mp-numbox${invalid ? ' is-bad' : ''}`}><input id={`cc-${k}`} type="number" inputMode="numeric" min={lim[1]} max={lim[2]} aria-invalid={invalid} aria-describedby={`cc-${k}-h`} value={raw[k] ?? ''}
          onChange={(e) => { setRaw({ ...raw, [k]: e.target.value }); if (/^\d+$/.test(e.target.value)) set(k, Number(e.target.value) as never) }} /><span className="mp-unit">{unit}</span></span></div></div>
    )
  }
  const sel = (k: keyof Settings, label: string, help: string, options: Opt[], searchable = false) => (
    <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{label}</span>{help && <span className="muted">{help}</span>}</div><div className="mp-row-r mp-sel"><Combobox label={label} searchable={searchable} value={String(f[k])} onChange={(v) => set(k, v as never)} options={options} /></div></div>
  )
  const sw = (k: keyof Settings, label: string, help: string) => (
    <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{label}</span><span className="muted">{help}</span></div><div className="mp-row-r"><Switch on={!!f[k]} label={label} onChange={(v) => set(k, (v ? 1 : 0) as never)} /></div></div>
  )
  const hexOk = HEX.test(hex)
  return (
    <section className="card mp-settings" aria-label="Caption settings">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Caption settings <span className={`pill ${s.enabled ? 'pill-ok' : 'pill-off'}`}>{s.enabled ? 'Overlay on' : 'Overlay off'}</span></h3></div>
      <div className="mp-rows">
        {sw('enabled', 'Show captions on the overlay', 'When off, the overlay stays hidden even while captioning runs.')}
        <h4 className="mp-h">Language</h4>
        {sel('language', 'Spoken language', 'Drives speech recognition. Changes apply straight away while captioning.', page.languages, true)}
        {sel('target_language', 'Caption language', 'Translates on-device before captions reach the overlay. Needs Chrome 138+ or Edge 148+.', page.targets, true)}
        <h4 className="mp-h">Look</h4>
        {sel('font_family', 'Font', '', page.fonts.map((x) => ({ value: x, label: x })))}
        {num('font_size', 'Font size', 'px', 'Sized for 1080p, scaled at higher resolutions.')}
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="cc-hex">Text colour</label><span id="cc-hex-h" className={hexOk ? 'muted' : 'mp-bad'}>{hexOk ? 'Hex, like #FFFFFF.' : 'Enter a hex colour like #FFFFFF.'}</span></div>
          <div className="mp-row-r ov-colr"><span className="ov-swwrap"><input type="color" className="ov-sw" aria-label="Pick text colour" value={hexOk ? hex.toLowerCase() : f.text_color.toLowerCase()} onChange={(e) => { setHex(e.target.value.toUpperCase()); set('text_color', e.target.value.toUpperCase()) }} /></span>
            <input id="cc-hex" className={`input ov-hexin${hexOk ? '' : ' is-bad'}`} maxLength={7} aria-invalid={!hexOk} aria-describedby="cc-hex-h" value={hex} onChange={(e) => { setHex(e.target.value); if (HEX.test(e.target.value)) set('text_color', e.target.value.toUpperCase()) }} /></div></div>
        {sel('background_style', 'Background', '', BG)}
        {sel('position', 'Position', '', POS)}
        {num('max_lines', 'Maximum lines', 'lines', 'Finished lines kept on screen at once.')}
        {num('fade_seconds', 'Fade after', 'sec', 'Hides the captions after this long without speech. 0 keeps them up.')}
        <h4 className="mp-h">Filters</h4>
        {sw('profanity_filter', 'Profanity filter', 'Masks swear words, like f**k.')}
        {sw('action_tags_enabled', 'Sound tags', 'Adds [LAUGHING], [COUGH], [SNEEZE] or [APPLAUSE]. Loads a small detector in this browser while captioning, from the next Start.')}
      </div>
      <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
        <span className={`muted small mp-save-msg${bad ? ' is-bad' : ''}`}>{bad ? <span className="mp-bad">Fix the highlighted field to save.</span> : 'Saving updates the overlay live.'}</span>
        {dirty && <span className="pill pill-warn">Unsaved changes</span>}
        <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => { setF(s); setRaw(Object.fromEntries(NUMS.map(([k]) => [k, String(s[k])]))); setHex(s.text_color) }}><i className="fas fa-rotate-left" /> Discard</button>
        <button type="button" className="btn" disabled={!dirty || saving || bad} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Save'}</button>
      </div>
    </section>
  )
}

function Corrections({ page, onSaved, toast }: { page: Page; onSaved: (c: Corr[]) => void; toast: (t: Toast) => void }) {
  const list = page.corrections
  const [heard, setHeard] = useState(''), [fix, setFix] = useState(''), [mode, setMode] = useState<'word' | 'substring'>('word')
  const [busy, setBusy] = useState<string | null>(null), [edit, setEdit] = useState<number | null>(null)
  const [fq, setFq] = useState(''), [fs, setFs] = useState<'all' | 'on' | 'off'>('all')
  async function save(next: Corr[], key: string, msg: string, after?: () => void) {
    setBusy(key)
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'save_corrections', rows: JSON.stringify(next) })
      if (!r.success) throw new Error(r.message || 'Could not save corrections.')
      onSaved(next); window.__ccEngine?.setCorrections(next); after?.(); toast({ kind: 'ok', text: msg })
    } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy(null)
  }
  const dupe = (h: string, skip?: number) => list.some((c, i) => i !== skip && c.match_text.toLowerCase() === h.trim().toLowerCase())
  const addBad = heard.trim().length > 255 || fix.trim().length > 255
  const needle = fq.trim().toLowerCase(), nOn = list.filter((c) => c.enabled).length
  const rows = list.map((c, i) => [c, i] as const).filter(([c]) => (fs === 'all' || (fs === 'on') === !!c.enabled) && (!needle || c.match_text.toLowerCase().includes(needle) || c.replace_text.toLowerCase().includes(needle)))
  const hint = (c: Corr) => [c.match_mode === 'substring' ? 'Anywhere' : 'Whole word', c.case_sensitive ? 'Match case' : ''].filter(Boolean).join(', ')
  return (
    <section className="card mp-lists cc-corr" aria-label="Caption corrections">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-spell-check" /> Corrections {list.length > 0 && <span className="pill">{nOn} on</span>}</h3></div>
      <p className="mp-lhelp muted">Fix words the recogniser mishears. Applied to finished captions before they reach the overlay. Matching is exact.</p>
      <form className="mp-ladd cc-add" onSubmit={(e) => { e.preventDefault(); if (heard.trim() && fix.trim() && !addBad && list.length < 300) save([...list, { match_text: heard.trim(), replace_text: fix.trim(), match_mode: mode, case_sensitive: 0, enabled: 1 }], 'add', 'Correction added.', () => { setHeard(''); setFix('') }) }}>
        <input className="input mp-lname" maxLength={255} placeholder="Heard, e.g. specter bot" aria-label="Heard" value={heard} onChange={(e) => setHeard(e.target.value)} />
        <i className="fas fa-arrow-right muted cc-arrow" aria-hidden="true" />
        <input className="input mp-lname" maxLength={255} placeholder="Correct, e.g. SpecterBot" aria-label="Correct" value={fix} onChange={(e) => setFix(e.target.value)} />
        <div className="mp-ltype"><Combobox label="Match" searchable={false} value={mode} onChange={(v) => setMode(v as 'word' | 'substring')} options={MODES} /></div>
        <button type="submit" className="btn" disabled={!heard.trim() || !fix.trim() || addBad || busy === 'add' || list.length >= 300}><i className={busy === 'add' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add</button>
        {heard.trim() && dupe(heard) && <span className="muted small mp-ladd-err">Already in the list. The first match wins.</span>}
        {list.length >= 300 && <span className="mp-bad mp-ladd-err">The list holds up to 300 corrections.</span>}
      </form>
      {list.length > 0 && <div className="mp-lf">
        <div className="mp-fseg" role="group" aria-label="Correction state">{([['all', 'All', list.length], ['on', 'On', nOn], ['off', 'Off', list.length - nOn]] as const).map(([v, l, n]) => <button key={v} type="button" aria-pressed={fs === v} className={fs === v ? 'on' : ''} onClick={() => setFs(v)}>{l}<span className="mp-cnt">{n}</span></button>)}</div>
        <label className="mp-fsearch"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input type="search" placeholder="Search corrections" aria-label="Search corrections" value={fq} onChange={(e) => setFq(e.target.value)} /></label>
      </div>}
      {!list.length ? <div className="mp-lempty"><span className="muted">No corrections yet. Add one above to map a misheard word to its right spelling.</span></div>
        : !rows.length ? <div className="mp-lempty"><span className="muted">No corrections match these filters.</span><button type="button" className="btn btn-quiet" onClick={() => { setFq(''); setFs('all') }}>Clear filters</button></div>
          : <ul className="mp-llist">{rows.map(([c, i]) => (
            <li key={i} className={`mp-lrow cc-crow${c.enabled ? '' : ' is-off'}`}>
              <span className="mp-lv cc-pair" title={`${c.match_text} to ${c.replace_text}`}><span className="cc-heard">{c.match_text}</span><i className="fas fa-arrow-right muted" aria-hidden="true" /><b>{c.replace_text}</b></span>
              <span className="mp-lr">
                <span className="mp-hint muted">{hint(c)}</span>
                <Switch on={!!c.enabled} busy={busy === 's' + i} label={`Use correction for ${c.match_text}`} onChange={(v) => save(list.map((x, j) => (j === i ? { ...x, enabled: v ? 1 : 0 } : x)), 's' + i, v ? 'Correction on.' : 'Correction off.')} />
                <button type="button" className="btn btn-ghost mp-x" aria-label={`Edit correction for ${c.match_text}`} onClick={() => setEdit(i)}><i className="fas fa-pen" /></button>
                <button type="button" className="btn btn-ghost mp-x" aria-label={`Remove correction for ${c.match_text}`} disabled={busy === 'd' + i} onClick={() => save(list.filter((_, j) => j !== i), 'd' + i, 'Correction removed.')}><i className={busy === 'd' + i ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /></button>
              </span>
            </li>))}</ul>}
      {edit !== null && list[edit] && <EditModal c={list[edit]} dupe={(h) => dupe(h, edit)} busy={busy === 'e'} onClose={() => setEdit(null)} onSave={(c) => save(list.map((x, j) => (j === edit ? c : x)), 'e', 'Correction saved.', () => setEdit(null))} />}
    </section>
  )
}

function EditModal({ c, dupe, busy, onClose, onSave }: { c: Corr; dupe: (h: string) => boolean; busy: boolean; onClose: () => void; onSave: (c: Corr) => void }) {
  const [d, setD] = useState(c)
  const ok = d.match_text.trim() && d.replace_text.trim()
  return (
    <Modal title="Edit correction" icon="fas fa-spell-check" onClose={onClose}>
      <div className="mp-rows ov-modal">
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="cc-eh">Heard</label>{dupe(d.match_text) && <span className="muted">Already in the list. The first match wins.</span>}</div><div className="mp-row-r"><input id="cc-eh" className="input ov-in" maxLength={255} value={d.match_text} onChange={(e) => setD({ ...d, match_text: e.target.value })} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="cc-ec">Correct</label></div><div className="mp-row-r"><input id="cc-ec" className="input ov-in" maxLength={255} value={d.replace_text} onChange={(e) => setD({ ...d, replace_text: e.target.value })} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Match</span><span className="muted">Anywhere also fixes it inside longer words.</span></div><div className="mp-row-r mp-sel"><Combobox label="Match" searchable={false} value={d.match_mode} onChange={(v) => setD({ ...d, match_mode: v as Corr['match_mode'] })} options={MODES} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Match case</span><span className="muted">Only fixes it with the same capitals.</span></div><div className="mp-row-r"><Switch on={!!d.case_sensitive} label="Match case" onChange={(v) => setD({ ...d, case_sensitive: v ? 1 : 0 })} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">On</span></div><div className="mp-row-r"><Switch on={!!d.enabled} label="Use this correction" onChange={(v) => setD({ ...d, enabled: v ? 1 : 0 })} /></div></div>
      </div>
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="button" className="btn" disabled={!ok || busy} onClick={() => onSave({ ...d, match_text: d.match_text.trim(), replace_text: d.replace_text.trim() })}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save</button></div>
    </Modal>
  )
}
