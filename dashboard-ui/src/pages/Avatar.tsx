import { useEffect, useRef, useState } from 'react'
import Combobox from '../components/Combobox'
import { UrlReveal } from '../components/UrlReveal'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'
import { SpecterAvatarWeather } from './avatar/weather.gen.js'

// Port of dashboard/avatar.php. Settings, frames and weather come from
// public/api/avatar/avatar.php (scripts/sync-live-api.sh). Mouth states go out
// through relay.php so the channel key stays on the server.
const API = '/api/avatar/avatar.php'
const RELAY = '/api/avatar/relay.php'
type Slot = 'idle_open' | 'idle_blink' | 'talk_open' | 'talk_blink'
type Settings = { enabled: number; position: string; pos_x: number; pos_y: number; scale: number; flip: number; mic_threshold: number; attack_ms: number; release_ms: number; blink_enabled: number; blink_interval_min: number; blink_interval_max: number; bounce_enabled: number; bounce_intensity: number; weather: string; weather_intensity: number }
type Page = { success: boolean; has_key: boolean; settings: Settings; frames: Record<Slot, string>; names: Record<Slot, string>; limits: { min_px: number; max_px: number; max_bytes: number } }
type Storage = { storage_used: number; max_storage: number; storage_percentage: number }
type Opt = { value: string; label: string }
const SLOTS: [Slot, string][] = [['idle_open', 'Mouth closed, eyes open'], ['idle_blink', 'Mouth closed, eyes closed'], ['talk_open', 'Mouth open, eyes open'], ['talk_blink', 'Mouth open, eyes closed']]
const POS: Opt[] = [{ value: 'bottom-right', label: 'Bottom right' }, { value: 'bottom-left', label: 'Bottom left' }, { value: 'top-right', label: 'Top right' }, { value: 'top-left', label: 'Top left' }, { value: 'custom', label: 'Custom' }]
const WEATHER: Opt[] = [{ value: 'off', label: 'Off' }, { value: 'winter', label: 'Winter, snow' }, { value: 'spring', label: 'Spring, petals' }, { value: 'summer', label: 'Summer, fireflies' }, { value: 'autumn', label: 'Autumn, leaves' }, { value: 'rain', label: 'Rain' }]
// [key, min, max, decimals]
const NUMS: [keyof Settings, number, number, number][] = [['pos_x', 0, 4000, 0], ['pos_y', 0, 4000, 0], ['scale', 0.2, 3, 2], ['attack_ms', 0, 2000, 0], ['release_ms', 0, 5000, 0], ['blink_interval_min', 1, 60, 0], ['blink_interval_max', 1, 120, 0]]
const mb = (b: number) => `${(b / 1024 / 1024).toFixed(1)} MB`
const tabId = () => Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('')

// Sends mouth states to the overlay through the server. Exactly one poll loop
// per tab (a reopen reuses it), and stop() ends it, so polls can never stack.
class Relay {
  tab = tabId(); open = false; active = false; polling = false; state = 'idle'
  opening: Promise<boolean> | null = null; sending = false; pending: string | null = null
  onLink: (ok: boolean) => void
  constructor(onLink: (ok: boolean) => void) { this.onLink = onLink }
  private async post(op: string, extra: Record<string, string> = {}) {
    const r = await fetch(RELAY, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ op, tab: this.tab, ...extra }).toString() })
    return { status: r.status, body: await r.json().catch(() => ({})) as { success?: boolean } }
  }
  private ensure(): Promise<boolean> {
    if (this.open) return Promise.resolve(true)
    if (!this.opening) this.opening = this.post('open').then((r) => { this.open = !!r.body.success; this.onLink(this.open); if (this.open) this.poll(); return this.open }).catch(() => { this.onLink(false); return false }).finally(() => { this.opening = null })
    return this.opening
  }
  private async poll() {
    if (this.polling) return
    this.polling = true
    try {
      while (this.active && this.open) {
        try {
          const r = await fetch(`${RELAY}?op=poll&tab=${this.tab}`, { credentials: 'same-origin' })
          if (!this.active) break
          if (r.status === 410) { this.open = false; await this.ensure(); if (this.open) this.send(this.state) }
          else if (r.ok) { const j = await r.json().catch(() => ({})) as { request?: boolean }; if (j.request) this.send(this.state) }
          else await new Promise((s) => setTimeout(s, 3000))
        } catch { await new Promise((s) => setTimeout(s, 3000)) }
      }
    } finally { this.polling = false }
  }
  start() { this.active = true; return this.ensure() }
  // Only the newest state matters; one request in flight at a time.
  async send(state: string) {
    this.state = state
    if (!this.active) return
    if (this.sending) { this.pending = state; return }
    this.sending = true
    try {
      for (let s: string | null = state, tries = 0; s && tries < 2; ) {
        if (!(await this.ensure())) break
        const r = await this.post('state', { state: s })
        if (r.status === 410) { this.open = false; tries++; continue }
        this.onLink(r.status === 200)
        s = this.pending; this.pending = null; tries = 0
      }
    } catch { this.onLink(false) }
    this.sending = false
  }
  stop() {
    this.active = false; this.pending = null
    if (this.open) { this.open = false; navigator.sendBeacon(RELAY, new URLSearchParams({ op: 'close', tab: this.tab })) }
  }
}

export default function Avatar() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [f, setF] = useState<Settings | null>(null)
  const [store, setStore] = useState<Storage | null>(null)
  useEffect(() => {
    get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r); setF(r.settings) }).catch(() => setErr('The avatar page could not load. Refresh to try again.'))
    get<Storage & { success: boolean }>(`${API}?ajax_action=list`).then((r) => { if (r.success) setStore(r) }).catch(() => {})
  }, [])
  if (err) return <div className="page cmds mp av"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page || !f) return <div className="page cmds mp av"><section className="card"><p className="muted">Loading</p></section></div>
  return (
    <div className="page cmds mp av">
      <Driver page={page} f={f} toast={setToast} />
      <Frames page={page} store={store} onUp={(slot, url, name, s) => { setPage({ ...page, frames: { ...page.frames, [slot]: url }, names: { ...page.names, [slot]: name } }); if (s) setStore(s) }} toast={setToast} />
      <SettingsCard page={page} f={f} setF={setF} onSaved={(s) => setPage({ ...page, settings: s })} toast={setToast} />
      <WeatherCard page={page} f={f} setF={setF} onSaved={(w, n) => setPage({ ...page, settings: { ...page.settings, weather: w, weather_intensity: n } })} toast={setToast} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

function Driver({ page, f, toast }: { page: Page; f: Settings; toast: (t: Toast) => void }) {
  const fr = useRef(f); fr.current = f
  const relay = useRef<Relay | null>(null)
  const canvas = useRef<HTMLCanvasElement>(null), weather = useRef<InstanceType<typeof SpecterAvatarWeather> | null>(null)
  const audio = useRef<{ ctx: AudioContext; stream: MediaStream; an: AnalyserNode; raf: number; rel: number | null } | null>(null)
  const [mouth, setMouth] = useState<'idle' | 'talking'>('idle'), [blink, setBlink] = useState(false)
  const [running, setRunning] = useState(false), [status, setStatus] = useState<'idle' | 'starting' | 'listening' | 'denied'>('idle'), [link, setLink] = useState(true)
  const [testing, setTesting] = useState(false)
  const mouthRef = useRef(mouth); mouthRef.current = mouth
  useEffect(() => {
    const r = new Relay(setLink); relay.current = r
    if (canvas.current) weather.current = new SpecterAvatarWeather(canvas.current)
    const bye = () => stopMic()
    window.addEventListener('pagehide', bye)
    return () => { window.removeEventListener('pagehide', bye); stopMic(); r.stop(); weather.current?.destroy(); weather.current = null }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps
  // Preview blink, from the unsaved settings like the live page.
  useEffect(() => {
    if (!f.blink_enabled) { setBlink(false); return }
    let t = 0, u = 0, alive = true
    const next = () => {
      const lo = Math.max(1, f.blink_interval_min || 3), hi = Math.max(lo, f.blink_interval_max || 6)
      t = window.setTimeout(() => { if (!alive) return; setBlink(true); u = window.setTimeout(() => { setBlink(false); next() }, 120) }, (lo + Math.random() * (hi - lo)) * 1000)
    }
    next()
    return () => { alive = false; clearTimeout(t); clearTimeout(u); setBlink(false) }
  }, [f.blink_enabled, f.blink_interval_min, f.blink_interval_max])
  const fm = page.frames
  const src = mouth === 'talking' ? (blink && fm.talk_blink ? fm.talk_blink : fm.talk_open || fm.talk_blink) : (blink && fm.idle_blink ? fm.idle_blink : fm.idle_open || fm.idle_blink)
  useEffect(() => {
    const w = weather.current; if (!w) return
    w.set({ kind: src ? f.weather : 'off', intensity: f.weather_intensity }); w.resize()
  }, [src, f.weather, f.weather_intensity])
  function setState(s: 'idle' | 'talking') { if (mouthRef.current === s) return; mouthRef.current = s; setMouth(s); relay.current?.send(s) }
  async function startMic() {
    setStatus('starting')
    try {
      const ctx = new AudioContext()
      const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: false, noiseSuppression: false, autoGainControl: false } })
      if (ctx.state === 'suspended') await ctx.resume()
      const srcNode = ctx.createMediaStreamSource(stream), an = ctx.createAnalyser(); an.fftSize = 2048
      const mute = ctx.createGain(); mute.gain.value = 0
      srcNode.connect(an); an.connect(mute); mute.connect(ctx.destination)
      const a = { ctx, stream, an, raf: 0, rel: null as number | null }
      audio.current = a
      relay.current?.start(); relay.current?.send('idle'); mouthRef.current = 'idle'; setMouth('idle')
      const buf = new Uint8Array(an.fftSize)
      const tick = () => {
        if (audio.current !== a) return
        an.getByteTimeDomainData(buf)
        let sum = 0; for (let i = 0; i < buf.length; i++) { const v = (buf[i] - 128) / 128; sum += v * v }
        const rms = Math.sqrt(sum / buf.length)
        if (rms >= fr.current.mic_threshold) { if (a.rel) { clearTimeout(a.rel); a.rel = null } setState('talking') }
        else if (mouthRef.current === 'talking' && !a.rel) a.rel = window.setTimeout(() => { a.rel = null; setState('idle') }, fr.current.release_ms)
        a.raf = requestAnimationFrame(tick)
      }
      a.raf = requestAnimationFrame(tick)
      setRunning(true); setStatus('listening')
    } catch { stopMic(); setStatus('denied') }
  }
  function stopMic() {
    const a = audio.current
    if (a) {
      audio.current = null; cancelAnimationFrame(a.raf); if (a.rel) clearTimeout(a.rel)
      a.stream.getTracks().forEach((t) => t.stop()); a.ctx.close().catch(() => {})
      relay.current?.send('idle'); relay.current?.stop()
    }
    mouthRef.current = 'idle'; setMouth('idle'); setRunning(false); setStatus((s) => (s === 'denied' ? s : 'idle'))
  }
  async function test() {
    const r = relay.current; if (!r || testing) return
    setTesting(true)
    const was = r.active
    if (!(await r.start())) { toast({ kind: 'err', text: 'Could not reach the overlay. Try again.' }); if (!was) r.stop(); setTesting(false); return }
    mouthRef.current = 'talking'; setMouth('talking'); r.send('talking')
    window.setTimeout(() => { mouthRef.current = 'idle'; setMouth('idle'); r.send('idle'); if (!audio.current) window.setTimeout(() => { if (!audio.current) r.stop() }, 1500); setTesting(false) }, 1000)
  }
  const pill = status === 'denied' ? ['pill-err', 'fas fa-microphone-slash', 'Mic blocked'] : running && !link ? ['pill-err', 'fas fa-plug-circle-xmark', 'Not reaching overlay'] : status === 'listening' ? ['pill-ok', 'fas fa-circle', mouth === 'talking' ? 'Talking' : 'Listening'] : status === 'starting' ? ['pill-warn', 'fas fa-spinner fa-spin', 'Starting'] : ['pill-off', 'fas fa-stop', 'Mic off']
  return (
    <section className="card av-drive" aria-label="Avatar">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-user-astronaut" /> Avatar <span className={`pill ${pill[0]}`} aria-live="polite"><i className={`${pill[1]} cc-pi`} /> {pill[2]}</span></h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">Add as an OBS browser source. It contains your channel key, so keep it out of screen shares.</span></div>
          <div className="mp-row-r mp-urlbox"><UrlReveal mask="https://overlay.botofthespecter.com/avatar.php?code=••••••••••••" disabled={!page.has_key} toast={toast}
            fetchUrl={async () => { const r = await get<{ success: boolean; url?: string; message?: string }>(`${API}?beta=url`); if (!r.success || !r.url) throw new Error(r.message || 'Could not get the overlay URL.'); return r.url }} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Microphone</span><span className={status === 'denied' ? 'mp-bad' : 'muted'}>{status === 'denied' ? 'Allow the microphone for this site in the address bar, then start again.' : 'OBS cannot hear your mic, so keep this tab open while you stream. It drives the mouth on the overlay.'}</span></div>
          <div className="mp-row-r cc-ctl av-ctl">
            <button type="button" className="btn btn-ghost" disabled={!page.has_key || testing} onClick={test}><i className={testing ? 'fas fa-spinner fa-spin' : 'fas fa-comment-dots'} /> Test talking</button>
            {running ? <button type="button" className="btn btn-danger" onClick={stopMic}><i className="fas fa-stop" /> Stop mic</button>
              : <button type="button" className="btn" disabled={!page.has_key || status === 'starting'} onClick={startMic}><i className="fas fa-play" /> Start mic</button>}</div></div>
      </div>
      <h4 className="mp-h">Live preview</h4>
      <div className="av-stage">
        <div className="av-char" style={{ transform: f.flip ? 'scaleX(-1)' : undefined }}>
          {src ? <img className="av-img" src={src} alt="Avatar preview" /> : null}
          <canvas ref={canvas} className="av-weather" width={1} height={1} aria-hidden="true" style={{ display: src && f.weather !== 'off' ? undefined : 'none' }} />
        </div>
        {!src && <span className="muted">Upload a frame below to see your avatar here.</span>}
      </div>
    </section>
  )
}

function Frames({ page, store, onUp, toast }: { page: Page; store: Storage | null; onUp: (slot: Slot, url: string, name: string, s?: Storage) => void; toast: (t: Toast) => void }) {
  const [busy, setBusy] = useState<Slot | null>(null)
  const input = useRef<HTMLInputElement>(null), pick = useRef<Slot>('idle_open')
  const L = page.limits
  async function upload(file: File) {
    const slot = pick.current
    if (!/\.(png|webp)$/i.test(file.name)) { toast({ kind: 'err', text: 'Choose a PNG or WebP image.' }); return }
    if (file.size > L.max_bytes) { toast({ kind: 'err', text: `Images must be ${mb(L.max_bytes)} or smaller.` }); return }
    setBusy(slot)
    try {
      const fd = new FormData(); fd.append('action', 'upload'); fd.append('slot', slot); fd.append('image', file)
      const r = await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })
      if (r.status === 401) throw new Error('Your session expired. Sign in again.')
      const j = await r.json() as { success: boolean; url?: string; filename?: string; error?: string; message?: string } & Partial<Storage>
      if (!j.success || !j.url) throw new Error(j.message || (j.error && j.error !== 'invalid_slot' ? j.error : 'Upload failed. Try again.'))
      onUp(slot, j.url, j.filename || file.name, typeof j.storage_used === 'number' ? { storage_used: j.storage_used, max_storage: j.max_storage!, storage_percentage: j.storage_percentage! } : undefined)
      toast({ kind: 'ok', text: 'Frame uploaded. The overlay picks it up when it next loads.' })
    } catch (e) { toast({ kind: 'err', text: (e as Error).message || 'Upload failed. Try again.' }) }
    setBusy(null)
  }
  const p = store ? Math.max(0, Math.min(100, store.storage_percentage)) : 0, level = p >= 90 ? 'err' : p >= 70 ? 'warn' : 'ok'
  return (
    <section className="card mp-lists av-frames" aria-label="Avatar frames">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-images" /> Frames <span className="pill">{SLOTS.filter(([s]) => page.names[s]).length} of 4</span></h3></div>
      <p className="mp-lhelp muted">Four matching transparent PNG or WebP images with the same framing, so only the mouth and eyes change. {L.min_px} to {L.max_px} px per side, up to {mb(L.max_bytes)} each.</p>
      <input ref={input} type="file" hidden accept="image/png,image/webp" onChange={(e) => { const fl = e.target.files?.[0]; e.target.value = ''; if (fl) upload(fl) }} />
      <ul className="mp-llist">{SLOTS.map(([slot, label]) => (
        <li key={slot} className="mp-lrow av-frow">
          <span className="av-thumb">{page.frames[slot] ? <img src={page.frames[slot]} alt="" /> : <i className="fas fa-image muted" aria-hidden="true" />}</span>
          <span className="av-ftxt"><b>{label}</b><span className="muted">{page.names[slot] || 'Not uploaded'}</span></span>
          <button type="button" className="btn btn-ghost" disabled={busy !== null} aria-label={`${page.names[slot] ? 'Replace' : 'Upload'} ${label}`} onClick={() => { pick.current = slot; input.current?.click() }}>
            <i className={busy === slot ? 'fas fa-spinner fa-spin' : 'fas fa-upload'} /> {busy === slot ? 'Uploading' : page.names[slot] ? 'Replace' : 'Upload'}</button>
        </li>))}</ul>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Storage</span><span className="muted">Frames count toward your media library allowance.</span></div>
          <div className="mp-row-r mu-store">{store ? <><span className={`mu-bar mu-bar-${level}`} role="progressbar" aria-label="Storage used" aria-valuenow={Math.round(p)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${p}%` }} /></span><span className="mp-hint muted">{mb(store.storage_used)} of {mb(store.max_storage)}</span></> : <span className="mp-hint muted">Checking</span>}</div></div>
      </div>
    </section>
  )
}

function SettingsCard({ page, f, setF, onSaved, toast }: { page: Page; f: Settings; setF: (s: Settings) => void; onSaved: (s: Settings) => void; toast: (t: Toast) => void }) {
  const s = page.settings
  const strs = (x: Settings) => Object.fromEntries(NUMS.map(([k]) => [k, String(x[k])]))
  const [raw, setRaw] = useState<Record<string, string>>(() => strs(s))
  const [saving, setSaving] = useState(false)
  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setF({ ...f, [k]: v })
  const badNum = (k: keyof Settings) => {
    const [, lo, hi, d] = NUMS.find(([n]) => n === k)!, v = raw[k] ?? ''
    if (!(d ? /^\d+(\.\d{1,2})?$/ : /^\d+$/).test(v) || Number(v) < lo || Number(v) > hi) return `Enter ${lo} to ${hi}.`
    if (k === 'blink_interval_max' && Number(v) < Number(raw.blink_interval_min)) return 'Must be at least the first number.'
    return ''
  }
  const custom = f.position === 'custom'
  const used = NUMS.filter(([k]) => (k !== 'pos_x' && k !== 'pos_y') || custom)
  const bad = used.some(([k]) => badNum(k))
  const keys: (keyof Settings)[] = ['enabled', 'position', 'flip', 'mic_threshold', 'blink_enabled', 'bounce_enabled', 'bounce_intensity']
  const dirty = keys.some((k) => f[k] !== s[k]) || NUMS.some(([k]) => raw[k] !== String(s[k]))
  async function save() {
    setSaving(true)
    const next: Settings = { ...f, ...Object.fromEntries(NUMS.map(([k]) => [k, Number(raw[k])])), weather: s.weather, weather_intensity: s.weather_intensity }
    try {
      const body: Record<string, string> = { action: 'save' }
      for (const k of ['enabled', 'position', 'pos_x', 'pos_y', 'scale', 'flip', 'mic_threshold', 'attack_ms', 'release_ms', 'blink_enabled', 'blink_interval_min', 'blink_interval_max', 'bounce_enabled', 'bounce_intensity'] as (keyof Settings)[]) body[k] = String(next[k])
      const r = await send<{ success: boolean; message?: string; overlay_told?: boolean }>(API, body)
      if (!r.success) throw new Error(r.message || 'Could not save settings.')
      setF({ ...next, weather: f.weather, weather_intensity: f.weather_intensity }); onSaved(next)
      toast({ kind: 'ok', text: r.overlay_told ? 'Avatar settings saved.' : 'Avatar settings saved. Refresh the overlay in OBS to see them.' })
    } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  const num = (k: keyof Settings, label: string, unit: string, help: string) => {
    const [, lo, hi, d] = NUMS.find(([n]) => n === k)!, msg = badNum(k)
    return (
      <div className="mp-row"><div className="mp-row-l"><label htmlFor={`av-${k}`}>{label}</label><span id={`av-${k}-h`} className={msg ? 'mp-bad' : 'muted'}>{msg || help}</span></div>
        <div className="mp-row-r"><span className={`mp-numbox${msg ? ' is-bad' : ''}`}><input id={`av-${k}`} type="number" inputMode={d ? 'decimal' : 'numeric'} step={d ? 0.05 : 1} min={lo} max={hi} aria-invalid={!!msg} aria-describedby={`av-${k}-h`} value={raw[k] ?? ''}
          onChange={(e) => { const v = e.target.value; setRaw({ ...raw, [k]: v }); if (v !== '' && !isNaN(Number(v))) set(k, Number(v) as never) }} /><span className="mp-unit">{unit}</span></span></div></div>
    )
  }
  const sw = (k: keyof Settings, label: string, help: string) => (
    <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{label}</span><span className="muted">{help}</span></div><div className="mp-row-r"><Switch on={!!f[k]} label={label} onChange={(v) => set(k, (v ? 1 : 0) as never)} /></div></div>
  )
  const range = (k: keyof Settings, label: string, help: string, min: number, max: number, step: number, show: (v: number) => string) => (
    <div className="mp-row"><div className="mp-row-l"><label htmlFor={`av-${k}`}>{label}</label><span className="muted">{help}</span></div>
      <div className="mp-row-r mp-volr"><input id={`av-${k}`} type="range" min={min} max={max} step={step} value={Number(f[k])}
        onChange={(e) => set(k, Number(e.target.value) as never)} /><b className="mp-volv">{show(Number(f[k]))}</b></div></div>
  )
  return (
    <section className="card mp-settings" aria-label="Avatar settings">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Avatar settings <span className={`pill ${s.enabled ? 'pill-ok' : 'pill-off'}`}>{s.enabled ? 'Overlay on' : 'Overlay off'}</span></h3></div>
      <div className="mp-rows">
        {sw('enabled', 'Show the avatar on the overlay', 'When off, the overlay stays hidden even while the mic runs.')}
        <h4 className="mp-h">Placement</h4>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Position</span><span className="muted">Corner of the browser source.</span></div><div className="mp-row-r mp-sel"><Combobox label="Position" searchable={false} value={f.position} onChange={(v) => set('position', v)} options={POS} /></div></div>
        {custom && num('pos_x', 'X offset', 'px', 'From the left edge.')}
        {custom && num('pos_y', 'Y offset', 'px', 'From the top edge.')}
        {num('scale', 'Scale', '×', '1 is the image at full size.')}
        {sw('flip', 'Flip horizontally', 'Mirrors the avatar.')}
        <h4 className="mp-h">Mouth</h4>
        {range('mic_threshold', 'Mic threshold', 'How loud you need to be to open the mouth. Lower is more sensitive.', 0.01, 0.5, 0.005, (v) => v.toFixed(3))}
        {num('attack_ms', 'Attack', 'ms', 'How quickly the mouth opens when you start speaking.')}
        {num('release_ms', 'Release', 'ms', 'How long the mouth stays open after you stop.')}
        <h4 className="mp-h">Blink and bounce</h4>
        {sw('blink_enabled', 'Blink', 'Uses the eyes-closed frames.')}
        {!!f.blink_enabled && num('blink_interval_min', 'Blink every, from', 'sec', 'Shortest gap between blinks.')}
        {!!f.blink_enabled && num('blink_interval_max', 'Blink every, to', 'sec', 'Longest gap between blinks.')}
        {sw('bounce_enabled', 'Idle bounce', 'A gentle bob while idle.')}
        {!!f.bounce_enabled && range('bounce_intensity', 'Bounce intensity', '0 is still.', 0, 10, 1, (v) => String(v))}
      </div>
      <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
        <span className={`muted small mp-save-msg${bad ? ' is-bad' : ''}`}>{bad ? <span className="mp-bad">Fix the highlighted field to save.</span> : 'Saving updates the overlay live.'}</span>
        {dirty && <span className="pill pill-warn">Unsaved changes</span>}
        <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => { setF({ ...s, weather: f.weather, weather_intensity: f.weather_intensity }); setRaw(strs(s)) }}><i className="fas fa-rotate-left" /> Discard</button>
        <button type="button" className="btn" disabled={!dirty || saving || bad} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Save'}</button>
      </div>
    </section>
  )
}

// Weather saves straight away, like the live page.
function WeatherCard({ page, f, setF, onSaved, toast }: { page: Page; f: Settings; setF: (s: Settings) => void; onSaved: (w: string, n: number) => void; toast: (t: Toast) => void }) {
  const [busy, setBusy] = useState(false)
  const timer = useRef(0)
  useEffect(() => () => clearTimeout(timer.current), [])
  async function save(weather: string, intensity: number) {
    setBusy(true)
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'save_weather', weather, weather_intensity: String(intensity) })
      if (!r.success) throw new Error(r.message || 'Could not save the weather.')
      onSaved(weather, intensity)
    } catch (e) { toast({ kind: 'err', text: (e as Error).message }); setF({ ...f, weather: page.settings.weather, weather_intensity: page.settings.weather_intensity }) }
    setBusy(false)
  }
  const on = f.weather !== 'off'
  return (
    <section className="card mp-settings" aria-label="Weather">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-cloud-sun-rain" /> Weather <span className={`pill ${page.settings.weather !== 'off' ? 'pill-ok' : 'pill-off'}`}>{busy ? 'Saving' : WEATHER.find((w) => w.value === page.settings.weather)?.label ?? 'Off'}</span></h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Effect</span><span className="muted">Particles inside the avatar's box, on the overlay and the preview. Saved right away.</span></div>
          <div className="mp-row-r mp-sel"><Combobox label="Weather effect" searchable={false} value={f.weather} onChange={(v) => { setF({ ...f, weather: v }); save(v, f.weather_intensity) }} options={WEATHER} /></div></div>
        {on && <div className="mp-row"><div className="mp-row-l"><label htmlFor="av-wi">Intensity</label><span className="muted">How many particles.</span></div>
          <div className="mp-row-r mp-volr"><input id="av-wi" type="range" min={1} max={10} step={1} value={f.weather_intensity}
            onChange={(e) => { const v = Number(e.target.value); setF({ ...f, weather_intensity: v }); clearTimeout(timer.current); timer.current = window.setTimeout(() => save(f.weather, v), 400) }} /><b className="mp-volv">{f.weather_intensity}</b></div></div>}
      </div>
    </section>
  )
}
