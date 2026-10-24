import { useEffect, useRef, useState, type FormEvent } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { UrlReveal } from '../components/UrlReveal'
import { get } from '../bot'
import { Counter, Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/pet.php. Everything comes from public/api/pet/pet.php
// (scripts/sync-live-api.sh) by request/response; the server tells the overlay.
// The only timer that touches the network is the live stats refresh: one
// interval per page, 30s, skipped while the tab is hidden, cleared on unmount.
const API = '/api/pet/pet.php'
type Stat = 'happiness' | 'hunger' | 'energy'
type Settings = { enabled: number; pet_name: string; idle_animation: string; position: string; scale: number; flip: number; show_stats: number; visible_stats: string[]; bubble_enabled: number; decay_happiness: number; decay_hunger: number; decay_energy: number; start_happiness: number; start_hunger: number; start_energy: number; alert_enabled: number; alert_threshold: number; alert_cooldown_minutes: number; alert_msg_energy: string; alert_msg_hunger: string; alert_msg_happiness: string }
type Anim = { id: number; name: string; frame_width: number; frame_height: number; frame_count: number; fps: number; loop: number; kind: string; url: string; sprite_file: string }
type Trigger = { id: number; trigger_type: string; trigger_value: string; animation: string; bubble_text: string; effect_happiness: number; effect_hunger: number; effect_energy: number; xp: number; cooldown_seconds: number; enabled: number }
type Sched = { id: number; message: string; animation: string; interval_minutes: number; enabled: number }
type Pack = { id: string; base: string; category: string; themes: { id: string; label: string }[]; name: string; desc: string; static: string; anims: { name: string; sheet: string; frames: number; fps: number }[] }
type Live = { stream_online: number; happiness: number; hunger: number; energy: number; xp: number; last_interaction_at: number }
type Page = { success: boolean; has_key: boolean; settings: Settings; alert_defaults: Record<Stat, string>; state: Live; animations: Anim[]; anim_names: string[]; triggers: Trigger[]; schedules: Sched[]; rewards: { reward_id: string; reward_title: string }[]; packs: Pack[]; categories: { id: string; label: string }[]; active_template: string; limits: { min_px: number; max_px: number; max_bytes: number; frames: number; msg: number; min_min: number; max_min: number; rows: number } }
type Storage = { storage_used: number; max_storage: number; storage_percentage: number }
type Opt = { value: string; label: string }
type Res = { success: boolean; error?: string; message?: string }
const POS: Opt[] = [{ value: 'top-left', label: 'Top left' }, { value: 'top-right', label: 'Top right' }, { value: 'bottom-left', label: 'Bottom left' }, { value: 'bottom-right', label: 'Bottom right' }]
const TYPES: Opt[] = [{ value: 'chat_keyword', label: 'Chat keyword' }, { value: 'command', label: 'Command' }, { value: 'redemption', label: 'Channel points' }, { value: 'event', label: 'Twitch event' }, { value: 'interaction', label: 'Interaction' }]
const EVENTS: Opt[] = [{ value: 'follow', label: 'Follow' }, { value: 'sub', label: 'Sub' }, { value: 'raid', label: 'Raid' }, { value: 'cheer', label: 'Cheer' }, { value: 'first_chat', label: 'First chat' }, { value: 'gift_sub', label: 'Gift sub' }]
const INTERACTIONS: Opt[] = [{ value: 'feed', label: 'Feed' }, { value: 'play', label: 'Play' }, { value: 'sad', label: 'Sad' }, { value: 'sleep', label: 'Sleep' }]
const STATS: [string, string][] = [['happiness', 'Happiness'], ['hunger', 'Hunger'], ['energy', 'Energy'], ['xp', 'XP'], ['xp_next', 'XP to next level']]
const LIVE: [Stat, string][] = [['happiness', 'Happiness'], ['hunger', 'Hunger'], ['energy', 'Energy']]
const mb = (b: number) => `${(b / 1024 / 1024).toFixed(1)} MB`
const label = (o: Opt[], v: string) => o.find((x) => x.value === v)?.label ?? v
const post = async (body: Record<string, string>) => {
  const r = await send<Res>(API, body)
  if (!r.success) throw new Error(r.error || r.message || 'Could not save. Try again.')
  return r
}

// One sprite: a GIF plays as is, a sheet steps frame by frame. Honours reduced motion.
function Sprite({ url, kind, frames, fps, size, alt }: { url: string; kind?: string; frames: number; fps: number; size: number; alt: string }) {
  const el = useRef<HTMLSpanElement>(null)
  useEffect(() => {
    const s = el.current
    if (!s || kind === 'gif') return
    const n = Math.max(1, frames)
    s.style.backgroundSize = `${n * 100}% 100%`; s.style.backgroundPosition = '0 0'
    if (n < 2 || matchMedia('(prefers-reduced-motion: reduce)').matches) return
    let f = 0
    const t = window.setInterval(() => { f = (f + 1) % n; s.style.backgroundPosition = `${(f / (n - 1)) * 100}% 0` }, 1000 / Math.max(1, fps || 15))
    return () => clearInterval(t)
  }, [url, kind, frames, fps])
  if (kind === 'gif') return <img className="pet-sprite" src={url} alt={alt} style={{ width: size, height: size }} />
  return <span ref={el} className="pet-sprite" role="img" aria-label={alt} style={{ width: size, height: size, backgroundImage: `url("${url}")` }} />
}

export default function Pet() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [store, setStore] = useState<Storage | null>(null)
  const load = () => get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r) })
  useEffect(() => {
    load().catch(() => setErr('The pet page could not load. Refresh to try again.'))
    get<Storage & { success: boolean }>(`${API}?ajax_action=list`).then((r) => { if (r.success) setStore(r) }).catch(() => {})
  }, [])
  if (err) return <div className="page cmds mp pet"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp pet"><section className="card"><p className="muted">Loading</p></section></div>
  const reload = () => load().catch(() => setToast({ kind: 'err', text: 'Saved, but the page could not refresh. Reload to see it.' }))
  const animOpts = page.anim_names.map((n) => ({ value: n, label: n }))
  return (
    <div className="page cmds mp pet">
      <Overlay page={page} setPage={setPage} toast={setToast} />
      <Templates page={page} reload={reload} toast={setToast} />
      <Stats page={page} />
      <SettingsCard key={JSON.stringify(page.settings)} page={page} animOpts={animOpts} onSaved={(s) => setPage({ ...page, settings: s })} reload={reload} toast={setToast} />
      <Animations page={page} store={store} animOpts={animOpts} reload={reload} setStore={setStore} toast={setToast} />
      <Triggers page={page} animOpts={animOpts} reload={reload} toast={setToast} />
      <Bubbles page={page} animOpts={animOpts} reload={reload} toast={setToast} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

function Overlay({ page, setPage, toast }: { page: Page; setPage: (p: Page) => void; toast: (t: Toast) => void }) {
  const [busy, setBusy] = useState('')
  const on = !!page.settings.enabled
  async function enable(v: boolean) {
    setBusy('en')
    try { await post({ pet_action: 'set_enabled', enabled: v ? '1' : '0' }); setPage({ ...page, settings: { ...page.settings, enabled: v ? 1 : 0 } }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  async function act(k: string, body: Record<string, string>, ok: string) {
    setBusy(k)
    try { await post(body); toast({ kind: 'ok', text: ok }) } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  return (
    <section className="card" aria-label="Pet">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-paw" /> Pet <span className={`pill ${on ? 'pill-ok' : 'pill-off'}`}>{on ? 'Overlay on' : 'Overlay off'}</span></h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">Add as an OBS browser source at 800 × 200 with a transparent background. It contains your channel key, so keep it out of screen shares.</span></div>
          <div className="mp-row-r mp-urlbox"><UrlReveal mask="https://overlay.botofthespecter.com/pet.php?code=••••••••••••" disabled={!page.has_key} toast={toast}
            fetchUrl={async () => { const r = await get<{ success: boolean; url?: string; error?: string }>(`${API}?beta=url`); if (!r.success || !r.url) throw new Error(r.error || 'Could not get the overlay URL.'); return r.url }} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Show the pet on the overlay</span><span className="muted">When off, the overlay stays hidden even if the browser source is loaded. Saved right away.</span></div>
          <div className="mp-row-r"><Switch on={on} busy={busy === 'en'} label="Show the pet on the overlay" onChange={enable} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Try it</span><span className="muted">Plays on your live overlay. Pet the pet runs the Play interaction, so it also changes the stats.</span></div>
          <div className="mp-row-r cc-ctl pet-ctl">
            <button type="button" className="btn btn-ghost" disabled={!!busy} onClick={() => act('idle', { pet_action: 'test_reaction', animation: page.settings.idle_animation || 'idle' }, 'Sent the idle animation to the overlay.')}><i className={busy === 'idle' ? 'fas fa-spinner fa-spin' : 'fas fa-paw'} /> Test idle</button>
            <button type="button" className="btn btn-ghost" disabled={!!busy} onClick={() => act('pet', { pet_action: 'pet_the_pet' }, 'You pet the pet.')}><i className={busy === 'pet' ? 'fas fa-spinner fa-spin' : 'fas fa-hand-holding-heart'} /> Pet the pet</button></div></div>
      </div>
    </section>
  )
}

function Templates({ page, reload, toast }: { page: Page; reload: () => void; toast: (t: Toast) => void }) {
  const byId = Object.fromEntries(page.packs.map((p) => [p.id, p]))
  const tiles = page.packs.filter((p) => p.base === p.id)
  const active = page.active_template, activeBase = byId[active]?.base ?? ''
  const [sel, setSel] = useState(active && byId[active] ? active : tiles[0]?.id ?? '')
  const [anim, setAnim] = useState(0)
  const [cat, setCat] = useState('all'), [q, setQ] = useState('')
  const [busy, setBusy] = useState(false)
  const pack = byId[sel], base = pack ? byId[pack.base] ?? pack : null
  const counts: Record<string, number> = { all: tiles.length }
  tiles.forEach((t) => { counts[t.category] = (counts[t.category] ?? 0) + 1 })
  const shown = tiles.filter((t) => (cat === 'all' || t.category === cat) && (!q.trim() || `${t.name} ${t.id} ${t.themes.map((x) => x.label).join(' ')}`.toLowerCase().includes(q.trim().toLowerCase())))
  async function apply() {
    setBusy(true)
    try { await post({ pet_action: 'apply_template', pack: sel }); toast({ kind: 'ok', text: `${pack.name} applied. The overlay picks it up right away.` }); reload() }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy(false)
  }
  const pick = (id: string) => { setSel(id); setAnim(0) }
  const a = pack?.anims[anim]
  return (
    <section className="card mp-lists" aria-label="Starter pets">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-wand-magic-sparkles" /> Starter pets {active && byId[active] ? <span className="pill pill-ok">Using {byId[active].name}</span> : <span className="pill pill-off">Custom</span>}</h3></div>
      <p className="mp-lhelp muted">These do not count toward your media storage. Applying one replaces the standard animations (idle, happy, hype, sad, sleep, eat, plus a gift box for gift subs).</p>
      {pack && <div className="pet-pick">
        <div className="pet-stage">{a ? <Sprite key={a.sheet} url={a.sheet} frames={a.frames} fps={a.fps} size={128} alt={`${pack.name}, ${a.name}`} /> : <img src={pack.static} alt={pack.name} width={128} height={128} />}</div>
        <div className="pet-pick-r">
          <div className="pet-pick-h"><b>{pack.name}</b></div>
          <span className="muted small">{pack.desc}</span>
          {base && base.themes.length > 1 && <div className="ov-chips pet-chips" role="group" aria-label="Theme"><span className="ov-chips-l muted">Theme</span>{base.themes.map((t) => <button key={t.id} type="button" className={`mu-mode${t.id === sel ? ' on' : ''}`} aria-pressed={t.id === sel} onClick={() => pick(t.id)}>{t.label}</button>)}</div>}
          <div className="ov-chips pet-chips" role="group" aria-label="Preview animation"><span className="ov-chips-l muted">Preview</span>{pack.anims.map((x, i) => <button key={x.name} type="button" className={`mu-mode${i === anim ? ' on' : ''}`} aria-pressed={i === anim} onClick={() => setAnim(i)}>{x.name}</button>)}</div>
          <div><button type="button" className={`btn${sel === active ? ' btn-ghost' : ''}`} disabled={busy || sel === active} onClick={apply}><i className={busy ? 'fas fa-spinner fa-spin' : sel === active ? 'fas fa-check' : 'fas fa-download'} /> {sel === active ? 'In use' : busy ? 'Applying' : `Use ${pack.name}`}</button></div>
        </div>
      </div>}
      <div className="mp-lf">
        <div className="mp-fseg" role="group" aria-label="Category">
          {[{ id: 'all', label: 'All' }, ...page.categories].filter((c) => counts[c.id]).map((c) => <button key={c.id} type="button" className={cat === c.id ? 'on' : ''} aria-pressed={cat === c.id} onClick={() => setCat(c.id)}>{c.label}<span className="mp-cnt muted">{counts[c.id]}</span></button>)}
        </div>
        <label className="mp-fsearch"><i className="fas fa-magnifying-glass" aria-hidden="true" /><input type="search" placeholder="Search pets" aria-label="Search pets" value={q} onChange={(e) => setQ(e.target.value)} /></label>
      </div>
      {shown.length ? <div className="pet-grid">{shown.map((t) => {
        const using = t.id === activeBase, img = using ? byId[active].static : t.static
        return <button key={t.id} type="button" className={`pet-tile${t.id === base?.id ? ' is-sel' : ''}`} aria-pressed={t.id === base?.id} onClick={() => pick(using ? active : t.id)}>
          <img src={img} alt="" width={72} height={72} loading="lazy" /><span>{t.name}</span>{using && <i className="fas fa-circle-check pet-using" aria-label="In use" />}</button>
      })}</div> : <p className="mp-lempty muted"><i className="fas fa-paw" /> No starter pets match. Try another search or category.</p>}
    </section>
  )
}

function Stats({ page }: { page: Page }) {
  const [live, setLive] = useState<Live>(page.state)
  const [rates, setRates] = useState({ happiness: page.settings.decay_happiness, hunger: page.settings.decay_hunger, energy: page.settings.decay_energy })
  const [, tick] = useState(0)
  useEffect(() => { setLive(page.state); setRates({ happiness: page.settings.decay_happiness, hunger: page.settings.decay_hunger, energy: page.settings.decay_energy }) }, [page])
  useEffect(() => {
    let alive = true, inflight = false
    const fetchState = () => {
      if (inflight || document.hidden) return
      inflight = true
      get<Live & { success: boolean; decay_happiness: number; decay_hunger: number; decay_energy: number }>(`${API}?ajax_action=state`)
        .then((r) => { if (alive && r.success) { setLive(r); setRates({ happiness: r.decay_happiness, hunger: r.decay_hunger, energy: r.decay_energy }) } })
        .catch(() => {}).finally(() => { inflight = false })
    }
    const draw = window.setInterval(() => tick((n) => n + 1), 1000)
    const poll = window.setInterval(fetchState, 30000)
    const vis = () => { if (!document.hidden) fetchState() }
    document.addEventListener('visibilitychange', vis)
    return () => { alive = false; clearInterval(draw); clearInterval(poll); document.removeEventListener('visibilitychange', vis) }
  }, [])
  const on = Number(live.stream_online) === 1
  const lastAt = live.last_interaction_at > 1e12 ? live.last_interaction_at : live.last_interaction_at * 1000
  const val = (k: Stat) => {
    const v = Math.max(0, Math.min(100, Math.round(Number(live[k]) || 0)))
    const h = (Date.now() - lastAt) / 3600000
    return on && lastAt && rates[k] && h > 0 ? Math.max(0, Math.min(100, Math.round(v - rates[k] * h))) : v
  }
  const xp = Math.max(0, Math.floor(live.xp || 0)), lv = Math.min(99, 1 + Math.floor(xp / 100)), next = lv >= 99 ? 0 : 100 - (xp % 100)
  return (
    <section className="card mp-settings" aria-label="Current stats">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-heart" /> Current stats <span className={`pill ${on ? 'pill-ok' : 'pill-off'}`}>{on ? 'Live' : 'Offline'}</span></h3></div>
      <p className="mp-lhelp muted">{on ? 'Live: these numbers drop automatically while you are streaming.' : 'Stream offline: stats refill to 100% when the stream ends and stay there until you go live again.'}</p>
      <div className="mp-rows">
        {LIVE.map(([k, l]) => { const v = val(k), lvl = v <= 20 ? 'err' : v <= 50 ? 'warn' : 'ok'
          return <div key={k} className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{l}</span><span className="muted">Drops {rates[k]} per hour while live.</span></div>
            <div className="mp-row-r mu-store"><span className={`mu-bar mu-bar-${lvl}`} role="progressbar" aria-label={l} aria-valuenow={v} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${v}%` }} /></span><b className="pet-val">{v}%</b></div></div> })}
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Level</span><span className="muted">{next ? `${next} XP to the next level.` : 'Top level reached.'}</span></div><div className="mp-row-r"><b>Lv {lv}</b><span className="muted">{xp} XP</span></div></div>
      </div>
    </section>
  )
}

// [key, posted as, min, max, decimals, unit, label, help]
const NUMS: [string, string, number, number, number, string, string, string][] = [
  ['scale', 'scale', 0.25, 3, 2, '×', 'Scale', '1 is the sprite at full size.'],
  ['start_happiness', 'happiness', 0, 100, 0, '%', 'Start happiness', 'Where happiness starts each stream.'],
  ['start_hunger', 'hunger', 0, 100, 0, '%', 'Start hunger', 'Where hunger starts each stream.'],
  ['start_energy', 'energy', 0, 100, 0, '%', 'Start energy', 'Where energy starts each stream.'],
  ['decay_happiness', 'decay_happiness', 0, 99.99, 2, '/ hr', 'Happiness decay', 'Points lost per hour while live.'],
  ['decay_hunger', 'decay_hunger', 0, 99.99, 2, '/ hr', 'Hunger decay', 'Points lost per hour while live.'],
  ['decay_energy', 'decay_energy', 0, 99.99, 2, '/ hr', 'Energy decay', 'Points lost per hour while live.'],
  ['alert_threshold', 'alert_threshold', 0, 100, 0, '%', 'Alert below', 'Posts when a stat drops to this or lower.'],
  ['alert_cooldown_minutes', 'alert_cooldown_minutes', 0, 1440, 0, 'min', 'Alert cooldown', '0 alerts once, then stays quiet until the stat recovers.'],
]
const msgBad = (s: string) => { const l = s.split(/\r?\n/).map((x) => x.trim()).filter(Boolean); return l.length > 10 ? 'Use up to 10 lines.' : l.some((x) => x.length > 200) ? 'Each line must be 200 characters or fewer.' : '' }

function SettingsCard({ page, animOpts, onSaved, reload, toast }: { page: Page; animOpts: Opt[]; onSaved: (s: Settings) => void; reload: () => void; toast: (t: Toast) => void }) {
  const s = page.settings
  const strs = () => Object.fromEntries(NUMS.map(([k]) => [k, String(Number(s[k as keyof Settings]))]))
  const [f, setF] = useState<Settings>(s)
  const [raw, setRaw] = useState<Record<string, string>>(strs)
  const [saving, setSaving] = useState(false)
  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setF({ ...f, [k]: v })
  const badNum = (k: string) => {
    const [, , lo, hi, d] = NUMS.find(([n]) => n === k)!, v = raw[k] ?? ''
    return !(d ? /^\d+(\.\d{1,2})?$/ : /^\d+$/).test(v) || Number(v) < lo || Number(v) > hi ? `Enter ${lo} to ${hi}.` : ''
  }
  const nameBad = !f.pet_name.trim() ? 'Enter a name.' : ''
  const msgs: Stat[] = ['energy', 'hunger', 'happiness']
  const bad = !!nameBad || NUMS.some(([k]) => badNum(k)) || msgs.some((m) => msgBad(f[`alert_msg_${m}`]))
  const plain: (keyof Settings)[] = ['pet_name', 'idle_animation', 'position', 'flip', 'show_stats', 'bubble_enabled', 'alert_enabled', 'alert_msg_energy', 'alert_msg_hunger', 'alert_msg_happiness']
  const dirty = plain.some((k) => f[k] !== s[k]) || f.visible_stats.join() !== s.visible_stats.join() || NUMS.some(([k]) => Number(raw[k]) !== Number(s[k as keyof Settings]) || raw[k] === '')
  const startChanged = (['happiness', 'hunger', 'energy'] as Stat[]).some((k) => Number(raw[`start_${k}`]) !== s[`start_${k}`])
  async function save() {
    setSaving(true)
    try {
      const body: Record<string, string> = { pet_action: 'save_settings', pet_name: f.pet_name.trim(), idle_animation: f.idle_animation, position: f.position, flip: String(f.flip), show_stats: String(f.show_stats), bubble_enabled: String(f.bubble_enabled), alert_enabled: String(f.alert_enabled), visible_stats: STATS.map(([k]) => k).filter((k) => f.visible_stats.includes(k)).join(',') }
      NUMS.forEach(([k, as]) => { body[as] = String(Number(raw[k])) })
      msgs.forEach((m) => { body[`alert_msg_${m}`] = f[`alert_msg_${m}`] })
      await post(body)
      const next = { ...f, pet_name: f.pet_name.trim(), ...Object.fromEntries(NUMS.map(([k]) => [k, Number(raw[k])])) } as Settings
      toast({ kind: 'ok', text: 'Pet settings saved. The overlay updates live.' })
      if (startChanged) reload(); else onSaved(next)
    } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  const num = (k: string) => {
    const [, , lo, hi, d, unit, l, help] = NUMS.find(([n]) => n === k)!, msg = badNum(k)
    return (
      <div className="mp-row"><div className="mp-row-l"><label htmlFor={`pet-${k}`}>{l}</label><span id={`pet-${k}-h`} className={msg ? 'mp-bad' : 'muted'}>{msg || help}</span></div>
        <div className="mp-row-r"><span className={`mp-numbox${msg ? ' is-bad' : ''}`}><input id={`pet-${k}`} type="number" inputMode={d ? 'decimal' : 'numeric'} step={k === 'scale' ? 0.05 : d ? 0.25 : 1} min={lo} max={hi} aria-invalid={!!msg} aria-describedby={`pet-${k}-h`} value={raw[k] ?? ''} onChange={(e) => setRaw({ ...raw, [k]: e.target.value })} /><span className="mp-unit">{unit}</span></span></div></div>
    )
  }
  const sw = (k: keyof Settings, l: string, help: string) => (
    <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{l}</span><span className="muted">{help}</span></div><div className="mp-row-r"><Switch on={!!f[k]} label={l} onChange={(v) => set(k, (v ? 1 : 0) as never)} /></div></div>
  )
  return (
    <section className="card mp-settings" aria-label="Pet settings">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Pet settings</h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="pet-name">Name</label><span id="pet-name-h" className={nameBad ? 'mp-bad' : 'muted'}>{nameBad || 'Used in bubbles and low stat alerts as (pet).'}</span></div>
          <div className="mp-row-r"><input id="pet-name" className={`input mp-lname pet-in${nameBad ? ' is-bad' : ''}`} maxLength={50} aria-invalid={!!nameBad} aria-describedby="pet-name-h" value={f.pet_name} onChange={(e) => set('pet_name', e.target.value)} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Idle animation</span><span className="muted">Plays when nothing else is happening.</span></div><div className="mp-row-r mp-sel"><Combobox label="Idle animation" value={f.idle_animation} onChange={(v) => set('idle_animation', v)} options={animOpts} /></div></div>
        <h4 className="mp-h">Placement</h4>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Position</span><span className="muted">Corner of the browser source.</span></div><div className="mp-row-r mp-sel"><Combobox label="Position" searchable={false} value={f.position} onChange={(v) => set('position', v)} options={POS} /></div></div>
        {num('scale')}
        {sw('flip', 'Flip horizontally', 'Mirrors the pet.')}
        <h4 className="mp-h">On the overlay</h4>
        {sw('bubble_enabled', 'Speech bubbles', 'Shows trigger and bubble line text above the pet.')}
        {sw('show_stats', 'Stat bars', 'Shows the stats you pick under the pet.')}
        {!!f.show_stats && <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Visible stats</span><span className="muted">Pick which bars to show.</span></div>
          <div className="mp-row-r"><div className="mu-modes pet-stats" role="group" aria-label="Visible stats">{STATS.map(([k, l]) => { const on = f.visible_stats.includes(k)
            return <button key={k} type="button" className={`mu-mode${on ? ' on' : ''}`} aria-pressed={on} onClick={() => set('visible_stats', on ? f.visible_stats.filter((x) => x !== k) : [...f.visible_stats, k])}>{on && <i className="fas fa-check" />} {l}</button> })}</div></div></div>}
        <h4 className="mp-h">Stats each stream</h4>
        {num('start_happiness')}{num('start_hunger')}{num('start_energy')}
        {num('decay_happiness')}{num('decay_hunger')}{num('decay_energy')}
        <h4 className="mp-h">Low stat alerts in chat</h4>
        {sw('alert_enabled', 'Post low stat alerts', 'The bot posts in chat when energy, hunger or happiness drops low while you are live.')}
        {!!f.alert_enabled && <>{num('alert_threshold')}{num('alert_cooldown_minutes')}
          {msgs.map((m) => { const k = `alert_msg_${m}` as const, e = msgBad(f[k])
            return <div key={m} className="mp-row pet-msgrow"><div className="mp-row-l"><label htmlFor={`pet-${k}`}>Low {m} message</label><span id={`pet-${k}-h`} className={e ? 'mp-bad' : 'muted'}>{e || 'One per line, one is picked at random. (pet) is the name. Empty uses the default shown.'}</span></div>
              <div className="mp-row-r"><textarea id={`pet-${k}`} className={`input pet-ta${e ? ' is-bad' : ''}`} rows={3} maxLength={2200} aria-invalid={!!e} aria-describedby={`pet-${k}-h`} placeholder={page.alert_defaults[m]} value={f[k]} onChange={(ev) => set(k, ev.target.value)} /></div></div> })}</>}
      </div>
      <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
        <span className={`muted small mp-save-msg${bad ? ' is-bad' : ''}`}>{bad ? <span className="mp-bad">Fix the highlighted field to save.</span> : startChanged ? 'Changing a start value also resets the current stats.' : 'Saving updates the overlay live.'}</span>
        {dirty && <span className="pill pill-warn">Unsaved changes</span>}
        <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => { setF(s); setRaw(strs()) }}><i className="fas fa-rotate-left" /> Discard</button>
        <button type="button" className="btn" disabled={!dirty || saving || bad} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Save'}</button>
      </div>
    </section>
  )
}

function DelModal({ what, name, onClose, onYes }: { what: string; name: string; onClose: () => void; onYes: () => Promise<void> }) {
  return <Modal title={`Delete this ${what}?`} icon="fas fa-trash" onClose={onClose}>
    <p><b>{name}</b> is removed from the pet. This cannot be undone.</p>
    <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={onClose}>Keep it</button><button type="button" className="btn btn-danger" onClick={() => { onClose(); onYes() }}><i className="fas fa-trash" /> Delete</button></div>
  </Modal>
}

function Animations({ page, store, animOpts, reload, setStore, toast }: { page: Page; store: Storage | null; animOpts: Opt[]; reload: () => void; setStore: (s: Storage) => void; toast: (t: Toast) => void }) {
  const [up, setUp] = useState(false), [view, setView] = useState<Anim | null>(null), [del, setDel] = useState<Anim | null>(null)
  const p = store ? Math.max(0, Math.min(100, store.storage_percentage)) : 0, level = p >= 90 ? 'err' : p >= 70 ? 'warn' : 'ok'
  const meta = (a: Anim) => a.kind === 'gif' ? `GIF, ${a.frame_width} × ${a.frame_height}` : `${a.frame_width} × ${a.frame_height}, ${a.frame_count} frames, ${a.fps} fps${a.loop ? '' : ', plays once'}`
  return (
    <section className="card mp-lists" aria-label="Animations">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-film" /> Animations <span className="pill">{page.animations.length}</span></h3>
        <button type="button" className="btn" onClick={() => setUp(true)}><i className="fas fa-upload" /> Upload</button></div>
      <p className="mp-lhelp muted">One sprite sheet or GIF per action. Starter pet animations cannot be deleted; upload your own to replace one. Sheets are one horizontal row of frames; 3840 × 128 (30 frames of 128 × 128 at 15 fps) is recommended.</p>
      {page.animations.length ? <ul className="mp-llist">{page.animations.map((a) => (
        <li key={a.id} className="mp-lrow av-frow">
          <span className="av-thumb pet-thumb" style={a.kind === 'gif' ? undefined : { backgroundImage: `url("${a.url}")`, backgroundSize: `${Math.max(1, a.frame_count) * 100}% 100%` }}>{a.kind === 'gif' && <img src={a.url} alt="" />}</span>
          <span className="av-ftxt"><b>{a.name}</b><span className="muted">{meta(a)}</span></span>
          <span className="mp-lr">
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Preview ${a.name}`} onClick={() => setView(a)}><i className="fas fa-play" /></button>
            {a.sprite_file.startsWith('template:') ? <span className="pill pill-off" title="Starter pet animations cannot be deleted">Starter</span>
              : <button type="button" className="btn btn-ghost mp-x" aria-label={`Delete ${a.name}`} onClick={() => setDel(a)}><i className="fas fa-trash" /></button>}</span>
        </li>))}</ul> : <p className="mp-lempty muted"><i className="fas fa-film" /> No animations yet. Pick a starter pet above or upload your own.</p>}
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Storage</span><span className="muted">Your uploads count toward your media library allowance. Starter pets do not.</span></div>
          <div className="mp-row-r mu-store">{store ? <><span className={`mu-bar mu-bar-${level}`} role="progressbar" aria-label="Storage used" aria-valuenow={Math.round(p)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${p}%` }} /></span><span className="mp-hint muted">{mb(store.storage_used)} of {mb(store.max_storage)}</span></> : <span className="mp-hint muted">Checking</span>}</div></div>
      </div>
      {view && <Modal title={view.name} icon="fas fa-play" onClose={() => setView(null)}>
        <div className="pet-stage"><Sprite url={view.url} kind={view.kind} frames={view.kind === 'gif' ? 1 : view.frame_count} fps={view.fps} size={160} alt={view.name} /></div>
        <p className="muted small">{meta(view)}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setView(null)}>Close</button></div></Modal>}
      {del && <DelModal what="animation" name={del.name} onClose={() => setDel(null)} onYes={async () => { try { await post({ pet_action: 'delete_animation', id: String(del.id) }); toast({ kind: 'ok', text: 'Animation deleted.' }); setDel(null); reload() } catch (e) { toast({ kind: 'err', text: (e as Error).message }) } }} />}
      {up && <UploadModal page={page} animOpts={animOpts} onClose={() => setUp(false)} onDone={() => { setUp(false); reload(); get<Storage & { success: boolean }>(`${API}?ajax_action=list`).then((r) => { if (r.success) setStore(r) }).catch(() => {}) }} toast={toast} />}
    </section>
  )
}

function UploadModal({ page, animOpts, onClose, onDone, toast }: { page: Page; animOpts: Opt[]; onClose: () => void; onDone: () => void; toast: (t: Toast) => void }) {
  const L = page.limits
  const [name, setName] = useState(page.animations.length ? animOpts.find((o) => !page.animations.some((a) => a.name === o.value))?.value ?? 'idle' : 'idle')
  const [file, setFile] = useState<File | null>(null), [url, setUrl] = useState('')
  const [v, setV] = useState({ frame_width: '128', frame_height: '128', frame_count: '30', fps: '15' }), [loop, setLoop] = useState(true)
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  useEffect(() => () => { if (url) URL.revokeObjectURL(url) }, [url])
  const gif = !!file && /\.gif$/i.test(file.name)
  const lim: Record<string, [number, number, string]> = { frame_width: [1, L.max_px, 'Frame width'], frame_height: [1, L.max_px, 'Frame height'], frame_count: [1, L.frames, 'Frames'], fps: [1, 60, 'FPS'] }
  const bad = (k: string) => { const [lo, hi] = lim[k], x = v[k as keyof typeof v]; return !/^\d+$/.test(x) || Number(x) < lo || Number(x) > hi ? `${lo} to ${hi}` : '' }
  const invalid = !file || (!gif && Object.keys(lim).some(bad))
  function choose(fl?: File) {
    setErr('')
    if (!fl) return
    if (!/\.(png|webp|gif)$/i.test(fl.name)) { setErr('Choose a PNG, WebP or GIF image.'); return }
    if (fl.size > L.max_bytes) { setErr(`Images must be ${mb(L.max_bytes)} or smaller.`); return }
    setFile(fl); setUrl(URL.createObjectURL(fl))
  }
  async function submit(e: FormEvent) {
    e.preventDefault(); if (invalid || !file) return
    setBusy(true); setErr('')
    try {
      const fd = new FormData(); fd.set('pet_action', 'upload_animation'); fd.set('name', name); fd.set('loop', loop ? '1' : '0')
      Object.entries(v).forEach(([k, x]) => fd.set(k, x)); fd.set('sprite', file)
      const r = await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })
      if (r.status === 401) throw new Error('Your session expired. Sign in again.')
      const j = await r.json() as Res
      if (!j.success) throw new Error(j.error || 'Upload failed. Try again.')
      toast({ kind: 'ok', text: `${name} uploaded. The overlay picks it up right away.` }); onDone()
    } catch (x) { setErr((x as Error).message || 'Upload failed. Try again.') }
    setBusy(false)
  }
  const n = (k: keyof typeof v) => <div className="fld"><label htmlFor={`pu-${k}`}>{lim[k][2]}</label><input id={`pu-${k}`} className={`input${bad(k) ? ' is-bad' : ''}`} type="number" inputMode="numeric" min={lim[k][0]} max={lim[k][1]} value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />{bad(k) && <small className="counter over">Enter {bad(k)}.</small>}</div>
  return (
    <Modal title="Upload animation" icon="fas fa-upload" onClose={onClose} wide>
      <form className="cmd-form" onSubmit={submit}>
        <div className="fld"><label>Action</label><Combobox label="Action" value={name} onChange={setName} options={animOpts} /></div>
        <div className="fld"><label htmlFor="pu-file">Image</label><input id="pu-file" className="input" type="file" accept="image/png,image/webp,image/gif,.png,.webp,.gif" data-autofocus onChange={(e) => choose(e.target.files?.[0])} /></div>
        <p className="fld-wide muted small">PNG, WebP or GIF, {L.min_px} to {L.max_px} px (GIFs from 32 px), up to {mb(L.max_bytes)}. Replaces the current {name} animation. {gif ? 'GIFs play as they are, so there is no frame size, count or FPS to set.' : ''}</p>
        {!gif && <>{n('frame_width')}{n('frame_height')}{n('frame_count')}{n('fps')}</>}
        <div className="fld tm-status"><span className="lbl">Loop</span><Switch on={loop} label="Loop" onChange={setLoop} /><span>{loop ? 'Loops' : 'Plays once'}</span></div>
        {url && <div className="fld-wide pet-stage"><Sprite key={url + v.frame_count + v.fps} url={url} kind={gif ? 'gif' : 'sheet'} frames={gif ? 1 : Number(v.frame_count) || 1} fps={Number(v.fps) || 15} size={128} alt="Preview" /></div>}
        {err && <p className="fld-wide mp-bad small" role="alert">{err}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || invalid}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-upload'} /> {busy ? 'Uploading' : 'Upload'}</button></div>
      </form>
    </Modal>
  )
}

const effects = (t: { effect_happiness: number; effect_hunger: number; effect_energy: number; xp: number }) => {
  const sg = (n: number) => (n > 0 ? `+${n}` : String(n))
  const p = [t.effect_happiness && `happiness ${sg(t.effect_happiness)}`, t.effect_hunger && `hunger ${sg(t.effect_hunger)}`, t.effect_energy && `energy ${sg(t.effect_energy)}`, t.xp && `${t.xp} XP`].filter(Boolean)
  return p.length ? p.join(', ') : 'no stat change'
}

function Triggers({ page, animOpts, reload, toast }: { page: Page; animOpts: Opt[]; reload: () => void; toast: (t: Toast) => void }) {
  const [type, setType] = useState('all'), [edit, setEdit] = useState<Trigger | null>(null), [del, setDel] = useState<Trigger | null>(null), [busy, setBusy] = useState('')
  const counts: Record<string, number> = { all: page.triggers.length }
  page.triggers.forEach((t) => { counts[t.trigger_type] = (counts[t.trigger_type] ?? 0) + 1 })
  const rows = page.triggers.filter((t) => type === 'all' || t.trigger_type === type)
  const valueLabel = (t: Trigger) => t.trigger_type === 'event' ? label(EVENTS, t.trigger_value) : t.trigger_type === 'interaction' ? label(INTERACTIONS, t.trigger_value) : t.trigger_type === 'redemption' ? page.rewards.find((r) => r.reward_id === t.trigger_value)?.reward_title || t.trigger_value : t.trigger_value
  const body = (t: Trigger) => ({ pet_action: 'save_trigger', id: String(t.id), trigger_type: t.trigger_type, trigger_value: t.trigger_value, animation: t.animation, bubble_text: t.bubble_text, cooldown_seconds: String(t.cooldown_seconds), effect_happiness: String(t.effect_happiness), effect_hunger: String(t.effect_hunger), effect_energy: String(t.effect_energy), xp: String(t.xp), enabled: String(t.enabled) })
  async function act(k: string, b: Record<string, string>, ok: string, re = true) {
    setBusy(k)
    try { await post(b); toast({ kind: 'ok', text: ok }); if (re) reload() } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  const blank: Trigger = { id: 0, trigger_type: 'chat_keyword', trigger_value: '', animation: animOpts.some((o) => o.value === 'happy') ? 'happy' : animOpts[0]?.value ?? 'idle', bubble_text: '', effect_happiness: 0, effect_hunger: 0, effect_energy: 0, xp: 0, cooldown_seconds: 5, enabled: 1 }
  return (
    <section className="card mp-lists" aria-label="Triggers">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-bolt" /> Triggers <span className="pill">{page.triggers.filter((t) => t.enabled).length} on</span></h3>
        <button type="button" className="btn" onClick={() => setEdit(blank)}><i className="fas fa-plus" /> Add trigger</button></div>
      <p className="mp-lhelp muted">Map chat keywords, commands, channel point rewards, Twitch events and interactions to an animation, a bubble and stat changes.</p>
      <div className="mp-lf"><div className="mp-fseg pet-fseg" role="group" aria-label="Trigger type">
        {[{ value: 'all', label: 'All' }, ...TYPES].filter((o) => o.value === 'all' || counts[o.value]).map((o) => <button key={o.value} type="button" className={type === o.value ? 'on' : ''} aria-pressed={type === o.value} onClick={() => setType(o.value)}>{o.label}<span className="mp-cnt muted">{counts[o.value] ?? 0}</span></button>)}</div></div>
      {rows.length ? <ul className="mp-llist">{rows.map((t) => (
        <li key={t.id} className="mp-lrow pet-row">
          <span className="av-ftxt"><b>{label(TYPES, t.trigger_type)}: {valueLabel(t)}</b><span className="muted">Plays {t.animation}{t.bubble_text ? `, says "${t.bubble_text}"` : ''}, {effects(t)}, {t.cooldown_seconds}s cooldown</span></span>
          <span className="mp-lr">
            <Switch on={!!t.enabled} busy={busy === 'e' + t.id} label={`Trigger ${valueLabel(t)} enabled`} onChange={(v) => act('e' + t.id, { ...body(t), enabled: v ? '1' : '0' }, v ? 'Trigger on.' : 'Trigger off.')} />
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Test ${valueLabel(t)} on the overlay`} title="Test on the overlay, also applies the stat changes" disabled={!!busy} onClick={() => act('t' + t.id, { pet_action: 'test_reaction', animation: t.animation, bubble_text: t.bubble_text, trigger_type: t.trigger_type, trigger_value: t.trigger_value, effect_happiness: String(t.effect_happiness), effect_hunger: String(t.effect_hunger), effect_energy: String(t.effect_energy), xp: String(t.xp) }, 'Sent to the overlay.', false)}><i className={busy === 't' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-play'} /></button>
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Edit ${valueLabel(t)}`} onClick={() => setEdit(t)}><i className="fas fa-pen" /></button>
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Delete ${valueLabel(t)}`} onClick={() => setDel(t)}><i className="fas fa-trash" /></button></span>
        </li>))}</ul> : <p className="mp-lempty muted"><i className="fas fa-bolt" /> No triggers yet. Add one so the pet reacts to chat and events.</p>}
      {edit && <TriggerModal t={edit} page={page} animOpts={animOpts} onClose={() => setEdit(null)} onSave={async (b) => { try { await post(b); toast({ kind: 'ok', text: edit.id ? 'Trigger saved.' : 'Trigger added.' }); setEdit(null); reload() } catch (e) { return (e as Error).message } return '' }} />}
      {del && <DelModal what="trigger" name={`${label(TYPES, del.trigger_type)}: ${valueLabel(del)}`} onClose={() => setDel(null)} onYes={async () => { try { await post({ pet_action: 'delete_trigger', id: String(del.id) }); toast({ kind: 'ok', text: 'Trigger deleted.' }); setDel(null); reload() } catch (e) { toast({ kind: 'err', text: (e as Error).message }) } }} />}
    </section>
  )
}

function TriggerModal({ t, page, animOpts, onClose, onSave }: { t: Trigger; page: Page; animOpts: Opt[]; onClose: () => void; onSave: (b: Record<string, string>) => Promise<string> }) {
  const [f, setF] = useState({ ...t, cooldown_seconds: String(t.cooldown_seconds), effect_happiness: String(t.effect_happiness), effect_hunger: String(t.effect_hunger), effect_energy: String(t.effect_energy), xp: String(t.xp) })
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const rewards = page.rewards.map((r) => ({ value: r.reward_id, label: r.reward_title || r.reward_id }))
  const setType = (v: string) => setF({ ...f, trigger_type: v, trigger_value: v === 'event' ? 'follow' : v === 'interaction' ? 'feed' : v === 'redemption' && rewards.length ? rewards[0].value : '' })
  const lim: Record<string, [number, number, string]> = { cooldown_seconds: [0, 86400, 'Cooldown (seconds)'], effect_happiness: [-100, 100, 'Happiness change'], effect_hunger: [-100, 100, 'Hunger change'], effect_energy: [-100, 100, 'Energy change'], xp: [0, 10000, 'XP'] }
  const bad = (k: string) => { const [lo, hi] = lim[k], x = String(f[k as keyof typeof f]); return !/^-?\d+$/.test(x) || Number(x) < lo || Number(x) > hi ? `Enter ${lo} to ${hi}.` : '' }
  const valOk = f.trigger_value.trim().length > 0 && f.trigger_value.length <= 100
  const invalid = !valOk || f.bubble_text.length > 255 || Object.keys(lim).some(bad)
  const n = (k: keyof typeof lim) => <div className="fld"><label htmlFor={`pt-${k}`}>{lim[k][2]}</label><input id={`pt-${k}`} className={`input${bad(k) ? ' is-bad' : ''}`} type="number" inputMode="numeric" min={lim[k][0]} max={lim[k][1]} value={String(f[k as keyof typeof f])} onChange={(e) => setF({ ...f, [k]: e.target.value })} />{bad(k) && <small className="counter over">{bad(k)}</small>}</div>
  const tv = f.trigger_type
  return (
    <Modal title={t.id ? 'Edit trigger' : 'Add trigger'} icon={t.id ? 'fas fa-pen' : 'fas fa-plus'} onClose={onClose} wide>
      <form className="cmd-form" onSubmit={async (e) => { e.preventDefault(); if (invalid) return; setBusy(true); setErr(await onSave({ pet_action: 'save_trigger', id: String(t.id), trigger_type: tv, trigger_value: f.trigger_value.trim(), animation: f.animation, bubble_text: f.bubble_text, cooldown_seconds: f.cooldown_seconds, effect_happiness: f.effect_happiness, effect_hunger: f.effect_hunger, effect_energy: f.effect_energy, xp: f.xp, enabled: String(f.enabled) })); setBusy(false) }}>
        <div className="fld"><label>Type</label><Combobox label="Trigger type" searchable={false} value={tv} onChange={setType} options={TYPES} /></div>
        <div className="fld">{tv === 'event' ? <><label>Event</label><Combobox label="Event" searchable={false} value={f.trigger_value} onChange={(v) => setF({ ...f, trigger_value: v })} options={EVENTS} /></>
          : tv === 'interaction' ? <><label>Interaction</label><Combobox label="Interaction" searchable={false} value={f.trigger_value} onChange={(v) => setF({ ...f, trigger_value: v })} options={INTERACTIONS} /></>
          : tv === 'redemption' && rewards.length ? <><label>Reward</label><Combobox label="Reward" value={f.trigger_value} onChange={(v) => setF({ ...f, trigger_value: v })} options={rewards} /></>
          : <><label htmlFor="pt-v">{tv === 'command' ? 'Command' : tv === 'redemption' ? 'Reward ID' : 'Keyword'}</label><input id="pt-v" className="input" maxLength={100} data-autofocus placeholder={tv === 'command' ? 'e.g. feed' : tv === 'redemption' ? 'Reward ID' : 'e.g. good boy'} value={f.trigger_value} onChange={(e) => setF({ ...f, trigger_value: e.target.value })} /></>}</div>
        <div className="fld"><label>Animation</label><Combobox label="Animation" value={f.animation} onChange={(v) => setF({ ...f, animation: v })} options={animOpts} /></div>
        {n('cooldown_seconds')}
        <div className="fld fld-wide"><label htmlFor="pt-b">Bubble text</label><input id="pt-b" className="input" maxLength={255} placeholder="Optional. {user} is the viewer's name." value={f.bubble_text} onChange={(e) => setF({ ...f, bubble_text: e.target.value })} /><Counter n={f.bubble_text.length} max={255} /></div>
        {n('effect_happiness')}{n('effect_hunger')}{n('effect_energy')}{n('xp')}
        <div className="fld tm-status"><span className="lbl">Status</span><Switch on={!!f.enabled} label="Enabled" onChange={(v) => setF({ ...f, enabled: v ? 1 : 0 })} /><span>{f.enabled ? 'Enabled' : 'Disabled'}</span></div>
        {err && <p className="fld-wide mp-bad small" role="alert">{err}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || invalid}><i className={busy ? 'fas fa-spinner fa-spin' : t.id ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {busy ? 'Saving' : t.id ? 'Save changes' : 'Add trigger'}</button></div>
      </form>
    </Modal>
  )
}

function Bubbles({ page, animOpts, reload, toast }: { page: Page; animOpts: Opt[]; reload: () => void; toast: (t: Toast) => void }) {
  const L = page.limits
  const [edit, setEdit] = useState<Sched | null>(null), [del, setDel] = useState<Sched | null>(null), [busy, setBusy] = useState('')
  const full = page.schedules.length >= L.rows
  const body = (s: Sched) => ({ pet_action: 'save_schedule', id: String(s.id), message: s.message, animation: s.animation, interval_minutes: String(s.interval_minutes), enabled: String(s.enabled) })
  async function act(k: string, b: Record<string, string>, ok: string, re = true) {
    setBusy(k)
    try { await post(b); toast({ kind: 'ok', text: ok }); if (re) reload() } catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  return (
    <section className="card mp-lists" aria-label="Bubble lines">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-comment-dots" /> Bubble lines <span className={`pill${full ? ' pill-warn' : ''}`}>{page.schedules.length} of {L.rows}</span></h3>
        <button type="button" className="btn" disabled={full} onClick={() => setEdit({ id: 0, message: '', animation: animOpts.some((o) => o.value === 'happy') ? 'happy' : animOpts[0]?.value ?? 'idle', interval_minutes: 15, enabled: 1 })}><i className="fas fa-plus" /> Add line</button></div>
      <p className="mp-lhelp muted">The pet says these in its bubble on a timer. They do not go to chat. The first one waits a full interval after the overlay loads.</p>
      {page.schedules.length ? <ul className="mp-llist">{page.schedules.map((s) => (
        <li key={s.id} className="mp-lrow pet-row">
          <span className="av-ftxt"><b>{s.message}</b><span className="muted">Every {s.interval_minutes} min, plays {s.animation}</span></span>
          <span className="mp-lr">
            <Switch on={!!s.enabled} busy={busy === 'e' + s.id} label={`Bubble line ${s.message} enabled`} onChange={(v) => act('e' + s.id, { ...body(s), enabled: v ? '1' : '0' }, v ? 'Bubble line on.' : 'Bubble line off.')} />
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Test "${s.message}" on the overlay`} title="Test on the overlay" disabled={!!busy} onClick={() => act('t' + s.id, { pet_action: 'test_reaction', animation: s.animation, bubble_text: s.message, personalized: '1' }, 'Sent to the overlay.', false)}><i className={busy === 't' + s.id ? 'fas fa-spinner fa-spin' : 'fas fa-play'} /></button>
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Edit "${s.message}"`} onClick={() => setEdit(s)}><i className="fas fa-pen" /></button>
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Delete "${s.message}"`} onClick={() => setDel(s)}><i className="fas fa-trash" /></button></span>
        </li>))}</ul> : <p className="mp-lempty muted"><i className="fas fa-comment-dots" /> No bubble lines yet. Add one, like "We have a Discord".</p>}
      {edit && <BubbleModal s={edit} page={page} animOpts={animOpts} onClose={() => setEdit(null)} onSave={async (b) => { try { await post(b); toast({ kind: 'ok', text: edit.id ? 'Bubble line saved.' : 'Bubble line added.' }); setEdit(null); reload() } catch (e) { return (e as Error).message } return '' }} />}
      {del && <DelModal what="bubble line" name={del.message} onClose={() => setDel(null)} onYes={async () => { try { await post({ pet_action: 'delete_schedule', id: String(del.id) }); toast({ kind: 'ok', text: 'Bubble line deleted.' }); setDel(null); reload() } catch (e) { toast({ kind: 'err', text: (e as Error).message }) } }} />}
    </section>
  )
}

function BubbleModal({ s, page, animOpts, onClose, onSave }: { s: Sched; page: Page; animOpts: Opt[]; onClose: () => void; onSave: (b: Record<string, string>) => Promise<string> }) {
  const L = page.limits
  const [msg, setMsg] = useState(s.message), [anim, setAnim] = useState(s.animation), [iv, setIv] = useState(String(s.interval_minutes)), [on, setOn] = useState(!!s.enabled)
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const ivBad = !/^\d+$/.test(iv) || Number(iv) < L.min_min || Number(iv) > L.max_min
  const invalid = !msg.trim() || msg.length > L.msg || ivBad
  return (
    <Modal title={s.id ? 'Edit bubble line' : 'Add bubble line'} icon={s.id ? 'fas fa-pen' : 'fas fa-plus'} onClose={onClose} wide>
      <form className="cmd-form" onSubmit={async (e) => { e.preventDefault(); if (invalid) return; setBusy(true); setErr(await onSave({ pet_action: 'save_schedule', id: String(s.id), message: msg.trim(), animation: anim, interval_minutes: iv, enabled: on ? '1' : '0' })); setBusy(false) }}>
        <div className="fld fld-wide"><label htmlFor="pb-m">Line</label><input id="pb-m" className="input" maxLength={L.msg} data-autofocus placeholder="We have a Discord" value={msg} onChange={(e) => setMsg(e.target.value)} /><Counter n={msg.length} max={L.msg} /></div>
        <div className="fld"><label htmlFor="pb-i">Every (minutes, {L.min_min} to {L.max_min})</label><input id="pb-i" className={`input${ivBad ? ' is-bad' : ''}`} type="number" inputMode="numeric" min={L.min_min} max={L.max_min} value={iv} onChange={(e) => setIv(e.target.value)} />{ivBad && <small className="counter over">Pick {L.min_min} to {L.max_min} minutes.</small>}</div>
        <div className="fld"><label>Animation</label><Combobox label="Animation" value={anim} onChange={setAnim} options={animOpts} /></div>
        <div className="fld tm-status"><span className="lbl">Status</span><Switch on={on} label="Enabled" onChange={setOn} /><span>{on ? 'Enabled' : 'Disabled'}</span></div>
        {err && <p className="fld-wide mp-bad small" role="alert">{err}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || invalid}><i className={busy ? 'fas fa-spinner fa-spin' : s.id ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {busy ? 'Saving' : s.id ? 'Save changes' : 'Add line'}</button></div>
      </form>
    </Modal>
  )
}
