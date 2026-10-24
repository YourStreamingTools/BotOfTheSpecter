import { UrlReveal } from '../components/UrlReveal'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import Modal from '../components/Modal'
import { BgSwatches, ExpandButton, ExpandLayer, SNAP_LINES, SnapGuides, snapAxis, useExpandLayer } from '../components/OverlayCanvas'
import Combobox, { type ComboOption } from '../components/Combobox'
import { get } from '../bot'
import { hasConsent } from '../consent'
import { Switch, ToastBar, VarsHelp, send, type Toast } from './commands/shared'
import {
  type Alert, type Group, CATS, catLabel, SIMPLE, MODULE, FIXED, MEDIA_LEVELS, TESTABLE_PLAYBACK, SWITCH_GATED, FIXED_DEFAULTS, PLAYBACK_NOTES,
  VARS, SAMPLE, BINGO, ANIM_IN, ANIM_OUT, FONTS, WEIGHTS, LAYOUTS, PILL_POS, CANVAS, EFFECTS, INTENSITY,
  rewardIdOf, bingoOf, adPill, adOpacity, adColor, hexRgb, clamp, presetToPercent, testEvent,
} from './alertsData'

// Port of dashboard/alerts.php via public/api/alerts/{alerts,notify}.php.
const API = '/api/alerts/alerts.php'
const NOTIFY = '/api/alerts/notify.php'
type Reward = { reward_id: string; reward_title: string; reward_cost: number | string | null }
type ListResp = { success: boolean; message?: string; alerts: Record<string, Alert>; categoryRandomize: Record<string, number | boolean>; libraryImages: string[]; librarySounds: string[]; channelPointRewards: Reward[]; testMedia: { sound?: string | null; video?: string | null }; testTtsVoice: Record<string, string | null> | null }
type PageResp = { success: boolean; variantLimits: Record<string, number>; canvas: string; mediaBase: string; soundGen: { remaining: number; limit: number; active: { id: number; elapsed: number } | null; promptMax: number } }
type StatusF = 'all' | 'on' | 'off'
type Filter = { status: StatusF; group: 'all' | Group }
const opts = (l: [string, string][]): ComboOption[] => l.map(([value, label]) => ({ value, label }))
const on = (v: unknown) => String(v) === '1'
const ext = (f: string) => f.split('.').pop()!.toLowerCase()
type SoundJob = { id: number; start: number; alertId: number | null }
const JOB_KEY = 'alertsSoundJob'
const OVERLAY_ACTIONS = ['save_alert', 'set_alert_position', 'set_alert_canvas', 'toggle_alert', 'set_category_randomize', 'create_variant', 'delete_variant', 'remove_alert_media', 'set_media_levels']

function readFilter(): Filter {
  const d: Filter = { status: 'all', group: 'all' }
  if (!hasConsent()) return d
  try {
    const m = document.cookie.match(/(?:^|; )al_filter=([^;]*)/)
    const v = m ? JSON.parse(decodeURIComponent(m[1])) : {}
    return { status: ['all', 'on', 'off'].includes(v.status) ? v.status : 'all', group: ['all', 'twitch', 'integrations', 'own'].includes(v.group) ? v.group : 'all' }
  } catch { return d }
}
function saveFilter(f: Filter) {
  if (!hasConsent()) return
  document.cookie = `al_filter=${encodeURIComponent(JSON.stringify(f))}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax; Secure`
}
const loadedFonts = new Set<string>()
function loadFont(f: string) {
  if (loadedFonts.has(f)) return
  loadedFonts.add(f)
  const l = document.createElement('link')
  l.rel = 'stylesheet'
  l.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(f)}:wght@300;400;500;600;700;800&display=swap`
  document.head.appendChild(l)
}

export default function Alerts() {
  const [alerts, setAlerts] = useState<Record<string, Alert> | null>(null)
  const [rand, setRand] = useState<Record<string, boolean>>({})
  const [lib, setLib] = useState<{ images: string[]; sounds: string[] }>({ images: [], sounds: [] })
  const [rewards, setRewards] = useState<Reward[]>([])
  const [testMedia, setTestMedia] = useState<ListResp['testMedia']>({})
  const [voice, setVoice] = useState<ListResp['testTtsVoice']>(null)
  const [page, setPage] = useState<PageResp | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [dupes, setDupes] = useState(0)
  const [editId, setEditId] = useState<number | null>(null)
  const [ed, setEd] = useState({ dirty: false, saving: false })
  const [liveEn, setLiveEn] = useState<{ id: number; en: unknown } | null>(null)
  const [pending, setPending] = useState<number | null>(null)
  const api = useRef<{ save: () => Promise<boolean>; discard: () => void } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [f, setF] = useState<Filter>(readFilter)
  const [q, setQ] = useState('')
  const refreshT = useRef<number>(0)
  const [job, setJob] = useState<SoundJob | null>(null)
  const [pct, setPct] = useState(0)
  const [soundDone, setSoundDone] = useState<{ alertId: number | null; file: string; n: number } | null>(null)

  async function load() {
    try {
      const [l, p] = await Promise.all([get<ListResp>(`${API}?ajax_action=list`), get<PageResp>(API)])
      if (!l.success) throw new Error(l.message || 'Could not load your alerts.')
      setAlerts(Array.isArray(l.alerts) ? {} : l.alerts)
      setRand(Object.fromEntries(Object.entries(l.categoryRandomize && !Array.isArray(l.categoryRandomize) ? l.categoryRandomize : {}).map(([k, v]) => [k, !!Number(v)])))
      setLib({ images: l.libraryImages || [], sounds: l.librarySounds || [] })
      setRewards(l.channelPointRewards || []); setTestMedia(l.testMedia || {}); setVoice(l.testTtsVoice || null)
      if (p.success) setPage(p)
      setErr('')
    } catch (e) { setErr(e instanceof Error && e.message ? e.message : 'Could not load your alerts.') }
  }
  async function instances() {
    try { const r = await get<{ success: boolean; count: number }>(`${API}?ajax_action=overlay_instances`); setDupes(r.success && r.count > 1 ? r.count : 0) } catch { /* keep last */ }
  }
  useEffect(() => { load(); instances(); const h = setInterval(instances, 60000); return () => clearInterval(h) }, [])

  // The overlay reads configs at load, so a saved change reloads every open alerts page (batched).
  function queueRefresh() { clearTimeout(refreshT.current); refreshT.current = window.setTimeout(() => { send(NOTIFY, { event: 'OVERLAY_REFRESH' }).catch(() => {}) }, 1500) }
  async function post<T extends { success: boolean; message?: string }>(body: Record<string, string>): Promise<T> {
    const r = await send<T>(API, body, true)
    if (r.success && OVERLAY_ACTIONS.includes(body.action)) queueRefresh()
    return r
  }
  const patch = (id: number, p: Partial<Alert>) => setAlerts((m) => (m && m[id] ? { ...m, [id]: { ...m[id], ...p } } : m))

  async function randomize(cat: string, v: boolean) {
    setBusy('r' + cat); setRand((r) => ({ ...r, [cat]: v }))
    try { const r = await post({ action: 'set_category_randomize', category: cat, randomize: v ? '1' : '0' }); if (!r.success) throw new Error(r.message) }
    catch (e) { setRand((r) => ({ ...r, [cat]: !v })); setToast({ kind: 'err', text: (e as Error).message || 'Could not change randomize.' }) }
    setBusy(null)
  }
  async function addVariant(cat: string) {
    setBusy('a' + cat)
    try {
      const r = await post<{ success: boolean; message?: string; variant: Alert }>({ action: 'create_variant', category: cat })
      if (!r.success) throw new Error(r.message || 'Failed to add variant. Please try again.')
      setAlerts((m) => ({ ...(m || {}), [r.variant.id]: r.variant })); pick(r.variant.id)
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Failed to add variant. Please try again.' }) }
    setBusy(null)
  }
  async function refreshOverlay() {
    setBusy('refresh')
    try { const r = await send(NOTIFY, { event: 'OVERLAY_REFRESH' }); setToast(r.success ? { kind: 'ok', text: 'Refresh sent. The alerts overlay will reload in OBS shortly.' } : { kind: 'err', text: r.message || 'Could not reach the overlay. Is the browser source open?' }) }
    catch { setToast({ kind: 'err', text: 'Could not reach the overlay. Is the browser source open?' }) }
    setBusy(null)
  }

  // Make Your Sound: the job runs on the server, so it keeps going when the dialog or editor closes.
  const resumed = useRef(false)
  useEffect(() => {
    if (!page || resumed.current) return
    resumed.current = true
    const act = page.soundGen.active
    if (act && act.id) { setJob({ id: act.id, start: Date.now() - (act.elapsed || 0) * 1000, alertId: null }); return }
    let rem = 0
    try { rem = parseInt(localStorage.getItem(JOB_KEY) || '0', 10) || 0 } catch { /* ignore */ }
    if (!rem) return
    try { localStorage.removeItem(JOB_KEY) } catch { /* ignore */ }
    post<{ success: boolean; status?: string; filename?: string; message?: string; remaining?: number }>({ action: 'sound_job_status', id: String(rem) }).then((r) => {
      if (!r.success) return
      if (typeof r.remaining === 'number') setPage((pg) => (pg ? { ...pg, soundGen: { ...pg.soundGen, remaining: r.remaining! } } : pg))
      if (r.status === 'done' && r.filename) { const fn = r.filename; setLib((l) => ({ ...l, sounds: l.sounds.includes(fn) ? l.sounds : [...l.sounds, fn] })); setToast({ kind: 'ok', text: `Sound saved to your library: ${fn}` }) }
      else if (r.status === 'failed') setToast({ kind: 'err', text: r.message || 'Could not make your sound.' })
    }).catch(() => {})
  }, [page]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!job) return
    const tick = setInterval(() => setPct(Math.min(95, Math.round(95 * (1 - Math.exp(-(Date.now() - job.start) / 9000))))), 250)
    let stop = false, t = 0
    const end = () => { try { localStorage.removeItem(JOB_KEY) } catch { /* ignore */ } setJob(null); setPct(0) }
    const poll = async () => {
      try {
        const r = await post<{ success: boolean; status?: string; filename?: string; message?: string; remaining?: number }>({ action: 'sound_job_status', id: String(job.id) })
        if (stop) return
        if (typeof r.remaining === 'number') setPage((pg) => (pg ? { ...pg, soundGen: { ...pg.soundGen, remaining: r.remaining! } } : pg))
        if (r.success && r.status === 'generating') { t = window.setTimeout(poll, 1500); return }
        end()
        if (r.success && r.status === 'done' && r.filename) {
          const fn = r.filename
          setLib((l) => ({ ...l, sounds: l.sounds.includes(fn) ? l.sounds : [...l.sounds, fn] }))
          setSoundDone((d) => ({ alertId: job.alertId, file: fn, n: (d?.n || 0) + 1 }))
          setToast({ kind: 'ok', text: `Sound saved to your library: ${fn}` })
        } else if (r.success) setToast({ kind: 'err', text: r.message || 'Could not make your sound.' })
      } catch { if (!stop) t = window.setTimeout(poll, 3000) }
    }
    t = window.setTimeout(poll, 1500)
    return () => { stop = true; clearInterval(tick); clearTimeout(t) }
  }, [job]) // eslint-disable-line react-hooks/exhaustive-deps
  async function startSound(prompt: string, dur: string, alertId: number) {
    if (!prompt || job) return
    try {
      const r = await post<{ success: boolean; job_id?: number; message?: string; remaining?: number }>({ action: 'generate_alert_sound', prompt, duration: dur })
      if (typeof r.remaining === 'number') setPage((pg) => (pg ? { ...pg, soundGen: { ...pg.soundGen, remaining: r.remaining! } } : pg))
      if (r.job_id) {
        if (hasConsent()) { try { localStorage.setItem(JOB_KEY, String(r.job_id)) } catch { /* ignore */ } }
        // A sound already being made for this channel is followed, but not set on this alert.
        setJob({ id: r.job_id, start: Date.now(), alertId: r.success ? alertId : null })
        if (!r.success && r.message) setToast({ kind: 'ok', text: r.message })
      } else setToast({ kind: 'err', text: r.message || 'Could not make your sound.' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Could not make your sound.' }) }
  }

  // Selecting loads the variant into the canvas and option panels; unsaved edits ask first, as on live.
  function pick(id: number) { if (id === editId) return; if (ed.dirty) setPending(id); else setEditId(id) }
  useEffect(() => {
    if (!alerts || (editId != null && alerts[editId])) return
    for (const c of CATS) { const v = Object.values(alerts).filter((a) => a.alert_category === c.key).sort((x, y) => (Number(x.variant_index) || 0) - (Number(y.variant_index) || 0))[0]; if (v) { setEditId(v.id); return } }
  }, [alerts]) // eslint-disable-line react-hooks/exhaustive-deps

  function filt(p: Partial<Filter>) { const n = { ...f, ...p }; setF(n); saveFilter(n) }
  const all = useMemo(() => Object.values(alerts || {}).map((a) => liveEn && a.id === liveEn.id && a.id === editId ? { ...a, enabled: liveEn.en } as Alert : a), [alerts, liveEn, editId])
  const byCat = useMemo(() => {
    const m: Record<string, Alert[]> = {}
    for (const a of all) (m[a.alert_category] ||= []).push(a)
    for (const k in m) m[k].sort((x, y) => (Number(x.variant_index) || 0) - (Number(y.variant_index) || 0))
    return m
  }, [all])
  const inGroup = (a: Alert) => f.group === 'all' || CATS.find((c) => c.key === a.alert_category)?.group === f.group
  const needle = q.trim().toLowerCase()
  const match = (a: Alert) => inGroup(a) && (f.status === 'all' || (f.status === 'on') === on(a.enabled))
    && (!needle || [a.variant_name, a.alert_condition || '', catLabel(a.alert_category)].some((s) => s.toLowerCase().includes(needle)))
  const pool = all.filter(inGroup)
  const onN = pool.filter((a) => on(a.enabled)).length
  const hits = all.filter(match)
  const filtered = f.status !== 'all' || f.group !== 'all' || !!needle
  const limits = page?.variantLimits || {}
  const editing = editId != null && alerts ? alerts[editId] : null

  const obsEl = (
      <div className="al-obs">
        <h4 className="al-sec"><i className="fas fa-display" /> OBS browser source</h4>
        <UrlReveal label="Browser source URL" mask="https://overlay.botofthespecter.com/?code=••••••••••••" copied="Browser source URL copied." fail="Could not get the browser source URL." toast={setToast}
          fetchUrl={async () => { const r = await get<{ success: boolean; url: string }>(`${API}?beta=source_url`); if (!r.success) throw new Error(); return r.url }}>
          <button type="button" className="btn btn-quiet" disabled={busy === 'refresh'} onClick={refreshOverlay} title="Reload the live alerts browser source so it picks up the latest settings"><i className={busy === 'refresh' ? 'fas fa-spinner fa-spin' : 'fas fa-rotate'} /> Refresh overlay</button>
        </UrlReveal>
        <p className="muted small">The URL contains your channel key, so keep it out of screen shares. Saved changes reload the overlay automatically.</p>
      </div>
  )
  return (
    <div className="page cmds al">
      {dupes > 0 && <div className="notice notice-err" role="status"><i className="fas fa-triangle-exclamation" /> <span>Specter Alerts is open in {dupes} places, so every alert shows and plays once per copy. Remove the extra OBS browser source (check other scenes, hidden sources and browser tabs).</span></div>}
      <div className="al-top">
        <h2><i className="fas fa-bell" /> Alerts</h2>
        <div className={`al-top-acts${ed.dirty ? ' is-dirty' : ''}`}>
          {ed.dirty && <span className="pill pill-warn al-unsaved">Unsaved changes</span>}
          <button type="button" className="btn btn-ghost" disabled={!ed.dirty || ed.saving} onClick={() => api.current?.discard()}><i className="fas fa-rotate-left" /> Discard</button>
          <button type="button" className="btn" disabled={!ed.dirty || ed.saving} onClick={() => api.current?.save()}><i className={ed.saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {ed.saving ? 'Saving' : 'Save changes'}</button>
        </div>
      </div>
      <div className="al-shell">
      <section className="card al-p-list" aria-label="Variants">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-layer-group" /> Variants</h3><span className="muted small">{all.length} variants</span></div>
        {alerts && all.length > 0 && (
          <div className="tm-filters">
            <div className="seg tm-seg" role="group" aria-label="Status">
              {([['all', 'All', pool.length], ['on', 'On', onN], ['off', 'Off', pool.length - onN]] as [StatusF, string, number][]).map(([v, l, n]) => <button key={v} type="button" aria-pressed={f.status === v} className={f.status === v ? 'on' : ''} onClick={() => filt({ status: v })}>{l} <span className="tm-n">{n}</span></button>)}
            </div>
            <div className="tm-trig"><Combobox label="Category type" searchable={false} value={f.group} onChange={(v) => filt({ group: v as Filter['group'] })} options={[{ value: 'all', label: 'All categories' }, { value: 'twitch', label: 'Twitch events' }, { value: 'integrations', label: 'Integrations' }, { value: 'own', label: 'Own theme and playback' }]} /></div>
            <label className="media-search tm-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search variants or conditions" aria-label="Search variants" value={q} onChange={(e) => setQ(e.target.value)} /></label>
          </div>
        )}
        {alerts && filtered && hits.length > 0 && <p className="muted small tm-count" aria-live="polite">Showing {hits.length} of {all.length}</p>}
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!alerts && !err && <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {alerts && filtered && !hits.length && <div className="media-empty tm-empty"><p className="muted">No variants match these filters.</p><button type="button" className="btn btn-quiet" onClick={() => { setQ(''); filt({ status: 'all', group: 'all' }) }}>Clear filters</button></div>}
        {alerts && (!filtered || hits.length > 0) && CATS.map((c) => {
          const vs = byCat[c.key] || [], vis = filtered ? vs.filter(match) : vs
          if (filtered && !vis.length) return null
          const lim = limits[c.key], canRand = lim === undefined || lim > 1, canAdd = lim === undefined || vs.length < lim
          const nOn = vs.filter((a) => on(a.enabled)).length
          const single = lim === 1 && vs.length === 1
          return (
            <div key={c.key} className="al-cat">
              <div className={`al-cat-head${single && vs[0].id === editId ? ' is-sel' : ''}`}>
                {single ? <button type="button" className="al-pick al-pick-cat" aria-pressed={vs[0].id === editId} aria-label={`Edit ${vs[0].variant_name}`} onClick={() => pick(vs[0].id)}><span className="al-cat-icon"><i className={c.icon} /></span><span className={`al-pick-main${on(vs[0].enabled) ? '' : ' is-off'}`}><b className="al-cat-name">{c.label}</b>{vs[0].variant_name !== c.label && <span className="ku-name small">{vs[0].variant_name}</span>}{rowInfo(vs[0]).tags && <Tags a={vs[0]} />}</span></button>
                  : <><span className="al-cat-icon"><i className={c.icon} /></span><b className="al-cat-name">{c.label}</b></>}
                {vs.length > 1 && <span className={`pill ${nOn ? 'pill-ok' : ''} al-cat-n`}>{nOn} of {vs.length} on</span>}
                <span className="al-cat-acts">
                  {canRand && <label className="al-rand"><span className="small">Randomize</span><Switch on={!!rand[c.key]} label={`Randomize ${c.label}`} busy={busy === 'r' + c.key} onChange={(v) => randomize(c.key, v)} /></label>}
                  {single && !on(vs[0].enabled) && <span className="pill al-off">Off</span>}
                  {canAdd && <button type="button" className="btn btn-ghost al-add" disabled={busy === 'a' + c.key} onClick={() => addVariant(c.key)}><i className={busy === 'a' + c.key ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> {busy === 'a' + c.key ? 'Adding' : 'New variant'}</button>}
                </span>
              </div>
              {single ? null
              : !vs.length ? <p className="muted small al-none">No variants yet.</p> : (
                <ul className="cmd-list">
                  {vis.map((a) => <Row key={a.id} a={a} prio={vs.length > 1} sel={a.id === editId} onPick={pick} />)}
                </ul>
              )}
            </div>
          )
        })}
      </section>
        {editing && page ? <Editor key={editing.id} a={editing} page={page} lib={lib} setLib={setLib} rewards={rewards} testMedia={testMedia} voice={voice}
          post={post} onSaved={(p) => patch(editing.id, p)} onCanvas={(c) => setPage({ ...page, canvas: c })} obs={obsEl}
          job={job} pct={pct} soundDone={soundDone} onStartSound={(p, d) => startSound(p, d, editing.id)} apiRef={api} onState={setEd} onLive={(en) => setLiveEn({ id: editing.id, en })}
          onDeleted={() => { setAlerts((m) => { const n = { ...(m || {}) }; delete n[editing.id]; return n }); setEditId(null); setEd({ dirty: false, saving: false }); setToast({ kind: 'ok', text: 'Variant deleted.' }) }}
          toast={setToast} /> : (
          <>
            <div className="al-center">
              <section className="card al-p-canvas"><div className="al-canvas al-bg-transparent"><p className="muted al-canvas-note">{err ? 'Alerts could not load.' : alerts ? 'Select a variant to preview it here.' : 'Loading your alerts'}</p></div></section>
              <section className="card al-p-tools">{obsEl}</section>
            </div>
            <section className="card al-p-set"><p className="muted">Select a variant to edit its settings.</p></section>
          </>
        )}
      </div>
      {pending != null && <Modal title="Unsaved changes" icon="fas fa-triangle-exclamation" onClose={() => setPending(null)}>
        <p>You have unsaved changes to this variant. Save them before switching?</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setPending(null)}>Keep editing</button><button type="button" className="btn btn-ghost" onClick={() => { const n = pending; setPending(null); setEd({ dirty: false, saving: false }); setEditId(n) }}>Discard and switch</button><button type="button" className="btn" onClick={async () => { const n = pending; setPending(null); if (await api.current?.save()) setEditId(n) }}>Save and switch</button></div>
      </Modal>}
      {ed.dirty && <div className="al-mbar" role="region" aria-label="Unsaved changes"><button type="button" className="btn btn-ghost" disabled={ed.saving} onClick={() => api.current?.discard()}>Discard</button><button type="button" className="btn" disabled={ed.saving} onClick={() => api.current?.save()}><i className={ed.saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {ed.saving ? 'Saving' : 'Save changes'}</button></div>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

// One variant row. A one-variant category puts its switch on the category line instead.
function rowInfo(a: Alert) {
  const cat = a.alert_category, styled = !SIMPLE.includes(cat) && !MODULE.includes(cat), conds = styled && !FIXED.includes(cat) && cat !== 'follow'
  return { styled, conds, tags: styled && (conds || !!a.alert_image || !!a.alert_sound) }
}
function Tags({ a }: { a: Alert }) {
  const { conds } = rowInfo(a)
  return (
    <div className="al-tags">
      {conds && (a.alert_condition ? <span className="pill pill-accent ku-kind">Condition</span> : <span className="pill ku-kind">Always</span>)}
      {conds && a.alert_condition && <code className="al-cond">{a.alert_condition}</code>}
      {a.alert_image && <span className="pill ku-kind"><i className="fas fa-image" /> Image</span>}
      {a.alert_sound && <span className="pill ku-kind"><i className="fas fa-music" /> Sound</span>}
    </div>
  )
}
function Row({ a, prio, sel, onPick }: { a: Alert; prio?: boolean; sel: boolean; onPick: (id: number) => void }) {
  return (
    <li className={`cmd-row al-row${on(a.enabled) ? '' : ' is-off'}${sel ? ' is-sel' : ''}`}>
      <button type="button" className="al-pick" aria-pressed={sel} aria-label={`Edit ${a.variant_name}`} onClick={() => onPick(a.id)}>
        {prio && <span className="al-prio" title="Priority">{(Number(a.variant_index) || 0) + 1}</span>}
        <span className="cmd-main"><span className="cmd-name"><b className="ku-name">{a.variant_name}</b></span>{rowInfo(a).tags && <Tags a={a} />}</span>
        {!on(a.enabled) && <span className="pill al-off">Off</span>}
      </button>
    </li>
  )
}

type Post = <T extends { success: boolean; message?: string }>(b: Record<string, string>) => Promise<T>
type Lib = { images: string[]; sounds: string[] }
type EditorProps = {
  a: Alert; page: PageResp; lib: Lib; setLib: (f: (l: Lib) => Lib) => void
  rewards: Reward[]; testMedia: ListResp['testMedia']; voice: ListResp['testTtsVoice']; post: Post; obs: ReactNode
  apiRef: React.MutableRefObject<{ save: () => Promise<boolean>; discard: () => void } | null>; onState: (s: { dirty: boolean; saving: boolean }) => void; onLive: (en: unknown) => void; onSaved: (p: Partial<Alert>) => void
  onCanvas: (c: string) => void; job: SoundJob | null; pct: number; soundDone: { alertId: number | null; file: string; n: number } | null; onStartSound: (p: string, d: string) => Promise<void>; onDeleted: () => void; toast: (t: Toast) => void
}

function Field({ label, id, children, wide, hint }: { label: string; id?: string; children: ReactNode; wide?: boolean; hint?: ReactNode }) {
  return <div className={`fld${wide ? ' fld-wide' : ''}`}><label htmlFor={id}>{label}</label>{children}{hint && <small className="muted">{hint}</small>}</div>
}
function Range({ id, label, v, min, max, step, onChange }: { id: string; label: string; v: number; min: number; max: number; step?: number; onChange: (n: number) => void }) {
  return (
    <div className="fld al-range"><label htmlFor={id}>{label} <b className="al-val">{v}%</b></label>
      <input id={id} type="range" min={min} max={max} step={step} value={v} onChange={(e) => onChange(Number(e.target.value))} /></div>
  )
}
function Color({ id, label, v, onChange }: { id: string; label: string; v: string; onChange: (s: string) => void }) {
  const [t, setT] = useState(v)
  useEffect(() => setT(v), [v])
  return (
    <div className="fld"><label htmlFor={id}>{label}</label>
      <div className="al-color"><input type="color" aria-label={`${label} picker`} value={/^#[0-9a-f]{6}$/i.test(v) ? v : '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} />
        <input id={id} className="input" value={t} maxLength={7} onChange={(e) => { setT(e.target.value); if (/^#[0-9A-Fa-f]{6}$/.test(e.target.value)) onChange(e.target.value) }} /></div></div>
  )
}
function Check({ label, v, onChange, hint }: { label: string; v: boolean; onChange: (b: boolean) => void; hint?: string }) {
  return <div className="al-check"><span><span className="lbl">{label}</span>{hint && <small className="muted al-hint2">{hint}</small>}</span><Switch on={v} label={label} onChange={onChange} /></div>
}
const asPos = (v: unknown) => v !== null && v !== undefined && v !== ''

function Editor({ a, page, lib, setLib, rewards, testMedia, voice, post, obs, apiRef, onState, onLive, onSaved, onCanvas, job, pct, soundDone, onStartSound, onDeleted, toast }: EditorProps) {
  const cat = a.alert_category
  const isSimple = SIMPLE.includes(cat), isModule = MODULE.includes(cat), isAd = cat === 'ad_break', lock = FIXED.includes(cat)
  const init = (): Alert => ({
    ...a, alert_condition: a.alert_condition || '',
    message_template: (() => { const m = (a.message_template || '').replace(/\\n/g, '\n'); return lock && !m.trim() ? FIXED_DEFAULTS[cat] || '' : m })(),
    layout_preset: isAd ? (a.layout_preset === 'pill' ? 'pill' : 'frame') : a.layout_preset || 'above',
    screen_position: isAd ? adPill(a.screen_position) : a.screen_position, bg_opacity: isAd ? adOpacity(a.bg_opacity) : a.bg_opacity, ad_color: adColor(a.ad_color),
  })
  const [s, setS] = useState<Alert>(init)
  const sRef = useRef(s); sRef.current = s
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)
  const [ask, setAsk] = useState<null | 'discard' | 'delete'>(null)
  const [libMode, setLibMode] = useState<null | 'image' | 'sound'>(null)
  const [makeOpen, setMakeOpen] = useState(false)
  const [bg, setBg] = useState('transparent')
  const [canvas, setCanvas] = useState(page.canvas || '1920x1080')
  const [expanded, setExpanded] = useState(false)
  const [play, setPlay] = useState(0)
  const [animOut, setAnimOut] = useState(false)
  const [msg, setMsg] = useState('')
  const [testing, setTesting] = useState(false)
  const [uploading, setUploading] = useState(false)
  const set = (p: Partial<Alert>) => { setS((o) => ({ ...o, ...p })); setDirty(true); setMsg('') }
  const area = useRef<HTMLDivElement>(null), box = useRef<HTMLDivElement>(null)
  const upRef = useRef<HTMLInputElement>(null), upKind = useRef<'image' | 'sound'>('image')
  const levelT = useRef(0)
  const dragging = useRef<{ gx: number; gy: number } | null>(null)
  const [guide, setGuide] = useState<{ x: number | null; y: number | null }>({ x: null, y: null })
  const obsW = Number(canvas.split('x')[0]) || 1920
  const [cw, setCw] = useState(0)
  useEffect(() => {
    const el = area.current; if (!el) return
    const ro = new ResizeObserver(() => setCw(el.clientWidth)); ro.observe(el); setCw(el.clientWidth)
    return () => ro.disconnect()
  }, [expanded])
  // True to scale: the box is drawn at OBS pixel size, shrunk to the preview width.
  const scale = cw ? cw / obsW : 0.3
  const playNote = PLAYBACK_NOTES[cat] || ''
  const showPos = !isAd && !(isSimple && playNote && cat !== 'video_alerts')
  // Seed a box with no saved x/y from its old 9-point preset (or the category default).
  useEffect(() => {
    if (!showPos || (asPos(s.position_x) && asPos(s.position_y)) || !area.current || !box.current) return
    const cr = area.current.getBoundingClientRect(), br = box.current.getBoundingClientRect()
    if (!cr.width) return
    const p = presetToPercent(s.screen_position || ({ weather: 'left-top', deaths: 'left-bottom' } as Record<string, string>)[cat] || 'center-center', br.width / cr.width * 100, br.height / cr.height * 100)
    setS((o) => ({ ...o, position_x: +p.x.toFixed(2), position_y: +p.y.toFixed(2) }))
  }, [cw]) // eslint-disable-line react-hooks/exhaustive-deps
  function pdown(e: React.PointerEvent) { const r = box.current!.getBoundingClientRect(); dragging.current = { gx: e.clientX - r.left, gy: e.clientY - r.top }; box.current!.setPointerCapture(e.pointerId); e.preventDefault() }
  function pmove(e: React.PointerEvent) {
    if (!dragging.current || !area.current || !box.current) return
    const cr = area.current.getBoundingClientRect(), br = box.current.getBoundingClientRect()
    const w = br.width / cr.width * 100, h = br.height / cr.height * 100
    let x = (e.clientX - cr.left - dragging.current.gx) / cr.width * 100, y = (e.clientY - cr.top - dragging.current.gy) / cr.height * 100
    let gx: number | null = null, gy: number | null = null
    const snap = (pos: number, size: number, th: number) => snapAxis(pos, size, SNAP_LINES, th)
    if (!e.altKey) { const sx = snap(x, w, 7 / cr.width * 100), sy = snap(y, h, 7 / cr.height * 100); if (sx) { x = sx.pos; gx = sx.line } if (sy) { y = sy.pos; gy = sy.line } }
    x = clamp(x, 0, Math.max(0, 100 - w)); y = clamp(y, 0, Math.max(0, 100 - h))
    if (gx !== null && ![x, x + w / 2, x + w].some((v) => Math.abs(v - gx!) < 0.4)) gx = null
    if (gy !== null && ![y, y + h / 2, y + h].some((v) => Math.abs(v - gy!) < 0.4)) gy = null
    setGuide({ x: gx, y: gy }); setS((o) => ({ ...o, position_x: +x.toFixed(2), position_y: +y.toFixed(2) }))
  }
  function pup() {
    if (!dragging.current) return
    dragging.current = null; setGuide({ x: null, y: null })
    const o = sRef.current
    // Walk-ons and video alerts save their position straight away, like the switch.
    if (isSimple) post({ action: 'set_alert_position', id: String(a.id), x: String(o.position_x), y: String(o.position_y) }).then((r) => { if (r.success) onSaved({ position_x: o.position_x, position_y: o.position_y }); else toast({ kind: 'err', text: r.message || 'Could not save the position.' }) }).catch((e) => toast({ kind: 'err', text: (e as Error).message }))
    else setDirty(true)
  }
  async function changeCanvas(c: string) {
    setCanvas(c); onCanvas(c)
    try { await post({ action: 'set_alert_canvas', canvas: c }) } catch { /* the preview still changes */ }
  }

  function collect(): Record<string, string> {
    const v = (x: unknown) => (x === null || x === undefined ? '' : String(x))
    const b = (x: unknown) => (on(x) ? '1' : '0')
    const d: Record<string, string> = {
      action: 'save_alert', id: String(a.id), variant_name: v(s.variant_name), enabled: b(s.enabled), alert_condition: v(s.alert_condition),
      duration: v(s.duration), animation_in: v(s.animation_in), animation_out: v(s.animation_out), animation_in_duration: v(s.animation_in_duration), animation_out_duration: v(s.animation_out_duration),
      layout_preset: v(s.layout_preset), bg_color: v(s.bg_color), bg_opacity: v(s.bg_opacity), padding: v(s.padding), gap: v(s.gap),
      rounded_corners: b(s.rounded_corners), drop_shadow: b(s.drop_shadow), message_template: v(s.message_template),
      font_family: v(s.font_family), font_weight: v(s.font_weight), font_size: v(s.font_size), text_alignment: v(s.text_alignment) || 'center', text_vertical_alignment: v(s.text_vertical_alignment) || 'center',
      text_color: v(s.text_color), accent_color: v(s.accent_color), text_drop_shadow: b(s.text_drop_shadow), tts_enabled: b(s.tts_enabled),
      alert_image: v(s.alert_image), image_scale: v(s.image_scale), image_volume: v(s.image_volume), alert_sound: v(s.alert_sound), sound_volume: v(s.sound_volume),
      celebration_enabled: b(s.celebration_enabled), celebration_effect: v(s.celebration_effect), celebration_intensity: v(s.celebration_intensity), celebration_area: 'full',
      screen_position: v(s.screen_position), position_x: v(s.position_x), position_y: v(s.position_y),
    }
    if (isAd) d.ad_color = adColor(s.ad_color)
    return d
  }
  async function save(): Promise<boolean> {
    setSaving(true); setMsg('')
    try {
      const d = collect(), r = await post(d)
      if (!r.success) { setMsg(r.message || 'Failed to save. Please try again.'); return false }
      onSaved({ ...s, alert_condition: s.alert_condition || null, alert_image: s.alert_image || null, alert_sound: s.alert_sound || null })
      setDirty(false); toast({ kind: 'ok', text: r.message || 'Saved.' }); return true
    } catch (e) { setMsg((e as Error).message || 'Failed to save. Please try again.'); return false } finally { setSaving(false) }
  }
  apiRef.current = { save, discard: () => { if (dirty) setAsk('discard') } }
  useEffect(() => { onState({ dirty, saving }) }, [dirty, saving]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { onLive(s.enabled) }, [s.enabled]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => () => { apiRef.current = null }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    const h = (e: BeforeUnloadEvent) => { if (dirty) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', h); return () => window.removeEventListener('beforeunload', h)
  }, [dirty])
  async function simpleToggle(v: boolean) {
    setS((o) => ({ ...o, enabled: v ? 1 : 0 }))
    try { const r = await post({ action: 'toggle_alert', id: String(a.id), enabled: v ? '1' : '0' }); if (!r.success) throw new Error(r.message); onSaved({ enabled: v ? 1 : 0 }) }
    catch (e) { setS((o) => ({ ...o, enabled: v ? 0 : 1 })); toast({ kind: 'err', text: (e as Error).message || 'Could not change the alert.' }) }
  }
  // Volume and video size save shortly after the slider stops, so the overlay refresh picks them up.
  function levels(p: Partial<Alert>) {
    const n = { ...sRef.current, ...p }
    setS(n)
    clearTimeout(levelT.current)
    levelT.current = window.setTimeout(() => {
      post({ action: 'set_media_levels', id: String(a.id), video_size: String(n.video_size ?? 70), sound_volume: String(n.sound_volume ?? 50) })
        .then((r) => { if (r.success) onSaved({ video_size: n.video_size, sound_volume: n.sound_volume }); else toast({ kind: 'err', text: r.message || 'Could not save the level.' }) })
        .catch((e) => toast({ kind: 'err', text: (e as Error).message }))
    }, 500)
  }
  async function removeMedia(field: 'alert_image' | 'alert_sound') {
    try { const r = await post({ action: 'remove_alert_media', id: String(a.id), field }); if (r.success) { setS((o) => ({ ...o, [field]: null })); onSaved({ [field]: null }) } else toast({ kind: 'err', text: r.message || 'Could not remove the file.' }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
  }
  async function upload(file: File) {
    setUploading(true)
    try {
      const fd = new FormData(); fd.append('action', 'upload_alert_media'); fd.append('media_file', file)
      const res = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
      if (res.status === 401) throw new Error('Your session expired. Sign in again.')
      const r = await res.json() as { success: boolean; message?: string; filename?: string }
      if (!r.success || !r.filename) throw new Error(r.message || 'Upload failed.')
      const fn = r.filename, snd = ext(fn) === 'mp3'
      setLib((l) => (snd ? { ...l, sounds: l.sounds.includes(fn) ? l.sounds : [...l.sounds, fn] } : { ...l, images: l.images.includes(fn) ? l.images : [...l.images, fn] }))
      set(upKind.current === 'sound' ? { alert_sound: fn } : { alert_image: fn })
    } catch (e) { toast({ kind: 'err', text: (e as Error).message || 'Upload failed.' }) } finally { setUploading(false) }
  }
  async function test() {
    if (dirty) { setMsg('The live test plays your saved settings. Save your changes, then send the test.'); return }
    if (SWITCH_GATED.includes(cat) && !on(s.enabled)) { setMsg('This alert is switched off, so the overlay would ignore the test. Switch it on, then send the test.'); return }
    const t = testEvent(s, rewards, testMedia, voice)
    if ('info' in t) { setMsg(t.info); return }
    setTesting(true)
    try { const r = await send(NOTIFY, { event: t.event, test_variant: String(a.id), ...t.params }); toast(r.success ? { kind: 'ok', text: t.file ? `Test sent with ${t.file}.` : 'Test sent. Check your OBS browser source.' } : { kind: 'err', text: r.message || 'Failed to send test event.' }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message || 'Failed to send test event.' }) } finally { setTesting(false) }
  }
  const seenDone = useRef(soundDone?.n || 0)
  useEffect(() => {
    if (!soundDone || soundDone.n === seenDone.current) return
    seenDone.current = soundDone.n
    if (soundDone.alertId === a.id) { set({ alert_sound: soundDone.file }); setMakeOpen(false) }
  }, [soundDone]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!isSimple && !isModule && !isAd) loadFont(String(s.font_family || 'Roboto')) }, [s.font_family]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (!play) return; setAnimOut(false); const h = setTimeout(() => setAnimOut(true), (isAd ? 4 : Number(s.duration) || 8) * 1000); return () => clearTimeout(h) }, [play]) // eslint-disable-line react-hooks/exhaustive-deps

  const sideLayout = s.layout_preset === 'left' || s.layout_preset === 'right'
  const rid = rewardIdOf(s.alert_condition), rw = rewards.find((r) => rid && String(r.reward_id) === rid)
  const rewardOpts: ComboOption[] = [...rewards.map((r) => ({ value: String(r.reward_id), label: r.reward_title + (r.reward_cost ? ` (${r.reward_cost} pts)` : '') })), ...(rid && !rw ? [{ value: rid, label: `Unknown reward (${rid})` }] : [])]
  const vars = VARS[cat] || ['{username}']
  const anim: React.CSSProperties | undefined = !play ? undefined
    : animOut ? { animation: `al-${isAd ? 'fadeOut' : s.animation_out} ${isAd ? 1 : s.animation_out_duration}s forwards` }
    : { animation: `al-${isAd ? 'fadeIn' : s.animation_in} ${isAd ? 1 : s.animation_in_duration}s forwards` }
  const pos = { left: `${s.position_x ?? 0}%`, top: `${s.position_y ?? 0}%` }
  const vsz = Number(s.video_size) || 70
  const [ph, pv] = String(s.screen_position || 'right-top').split('-')

  const sample = cat === 'video_alerts' ? <div className="al-video"><i className="fas fa-film" /></div>
    : cat === 'walkons' ? <div className="al-walkon"><div className="al-avatar" /><div className="al-wname">PreviewUser</div></div>
    : cat === 'weather' ? <div className="al-weather"><div className="al-wh"><span>Sydney</span><span className="al-temp">22°C | 72°F</span></div><div className="al-wd"><i className="fas fa-cloud-sun" /><span>Partly cloudy</span><span>10 kph</span><span>60%</span></div></div>
    : cat === 'deaths' ? <div className="al-deaths"><div className="al-dt"><span className="al-rip" /><span>Current Deaths</span></div><div className="al-dg">Elden Ring</div><div className="al-dc">42</div></div>
    : <Card s={s} sideLayout={sideLayout} mediaBase={page.mediaBase} reward={rw} anim={anim} />
  const canvasEl = (
    <div className="al-stage">
      <div ref={area} className={`al-canvas al-bg-${bg}`}>
        {!showPos && !isAd && <p className="muted al-canvas-note">{playNote}</p>}
        {isAd && (
          <div className="al-ad-frame" data-display={String(s.layout_preset)} data-h={ph} data-v={pv} style={{ ['--ad-rgb' as string]: hexRgb(String(s.ad_color)), ['--ad-alpha' as string]: String(adOpacity(s.bg_opacity) / 100), ...anim }}>
            <div className="al-ad-pill">1:30</div>
          </div>
        )}
        {showPos && (
          <div ref={box} className="al-drag" role="img" aria-label="Alert preview, drag to position"
            style={cat === 'video_alerts' ? { ...pos, width: `${vsz}%`, height: `${vsz}%` } : { ...pos, transform: `scale(${scale})`, transformOrigin: 'top left' }}
            onPointerDown={pdown} onPointerMove={pmove} onPointerUp={pup} onPointerCancel={pup}>{sample}</div>
        )}
        <SnapGuides guide={guide} />
      </div>
    </div>
  )
  const optsEl = (
      <div className="al-prev-opts">
        <div className="al-canvas-size"><span className="fld-label">OBS canvas</span><Combobox label="OBS canvas" searchable={false} value={canvas} onChange={changeCanvas} options={opts(CANVAS)} /></div>
        <BgSwatches bg={bg} onChange={setBg} />
        {expanded && !isSimple && <button type="button" className="btn btn-ghost" disabled={isModule} onClick={() => setPlay((p) => p + 1)}><i className="fas fa-play" /> Replay</button>}
        <ExpandButton expanded={expanded} onToggle={() => setExpanded((x) => !x)} />
      </div>
  )
  const toolsEl = (
    <div className="al-tools">
      {showPos && <p className="muted small al-hint">Drag the alert to place it. Edges, centre and thirds snap; hold Alt for free placement.</p>}
      {!expanded && optsEl}
    </div>
  )
  useExpandLayer(expanded, () => setExpanded(false))
  const title = a.variant_name === catLabel(cat) ? catLabel(cat) : `${catLabel(cat)}: ${a.variant_name}`
  const expandEl = expanded && (
    <ExpandLayer label={`Expanded canvas for ${title}`} onClose={() => setExpanded(false)}
      bar={<><div className="al-p-title"><i className={CATS.find((c) => c.key === cat)?.icon} /> <b>{title}</b> <span className="muted small">{canvas.replace('x', ' x ')} at true scale</span></div>{optsEl}</>}>
      {canvasEl}
    </ExpandLayer>)

  return (
    <>
      <div className="al-center">
        <section className="card al-p-canvas" aria-label={`Preview of ${title}`}>
          <div className="al-p-title"><i className={CATS.find((c) => c.key === cat)?.icon} /> <b>{title}</b> <span className={`pill ku-kind ${on(s.enabled) ? 'pill-ok' : ''}`}>{on(s.enabled) ? 'On' : 'Off'}</span></div>
          {expanded ? <div className="al-canvas al-xv-hold"><p className="muted al-canvas-note">Open in the expanded view. Press Esc to close.</p></div> : canvasEl}
          {expandEl}
        </section>
        <section className="card al-p-tools">
          {toolsEl}
          <div className="al-ed-acts">
            {!isSimple && <button type="button" className="btn btn-ghost" disabled={isModule} title={isModule ? 'This card keeps its own animation' : undefined} onClick={() => setPlay((p) => p + 1)}><i className="fas fa-play" /> Replay preview</button>}
            <button type="button" className="btn btn-quiet" disabled={testing || (isSimple && !TESTABLE_PLAYBACK.includes(cat))} onClick={test} title={isSimple && !TESTABLE_PLAYBACK.includes(cat) ? 'Walk-ons have no live test' : 'Send a test to your OBS browser source'}><i className={testing ? 'fas fa-spinner fa-spin' : 'fas fa-bolt'} /> Trigger live test</button>
          </div>
          {msg && <div className="notice notice-err al-msg" role="status"><i className="fas fa-circle-info" /> <span>{msg}</span></div>}
          {obs}
        </section>
      </div>
      <section className="card al-p-set" aria-label="Alert settings">

          {isSimple ? (
            <>
              <Check label="Enabled" v={on(s.enabled)} onChange={simpleToggle} />
              {cat === 'walkons' && <p className="muted small">Walk-ons show however you set each viewer on the Media page (sound, sound with picture and name, or video).</p>}
              {cat === 'video_alerts' && <Range id="al-vs" label="Video size (share of the screen)" v={vsz} min={10} max={100} onChange={(n) => levels({ video_size: n })} />}
              {MEDIA_LEVELS.includes(cat) && <Range id="al-sv" label="Sound volume" v={isNaN(parseInt(String(s.sound_volume), 10)) ? 50 : Number(s.sound_volume)} min={0} max={100} onChange={(n) => levels({ sound_volume: n })} />}
              <p className="muted small">These settings save as you change them.</p>
            </>
          ) : (
            <>
              <h4 className="al-sec">General</h4>
              <div className="cmd-form">
                <div className="fld-wide"><Check label="Enabled" v={on(s.enabled)} onChange={(b) => set({ enabled: b ? 1 : 0 })} /></div>
                {cat === 'channel_points' && <div className="fld fld-wide"><span className="fld-label">Channel point reward</span><Combobox label="Channel point reward" placeholder="Select a reward" value={rid} onChange={(v) => { const r = rewards.find((x) => String(x.reward_id) === v); if (r) set({ variant_name: r.reward_title, alert_condition: `reward_id = '${String(r.reward_id).replace(/'/g, "\\'")}'` }) }} options={rewardOpts.length ? rewardOpts : [{ value: '', label: 'No rewards synced yet' }]} />
                  <small className="muted">The name and condition come from the reward. Sync more on <a href="/channel-rewards">Channel Rewards</a>.</small></div>}
                {cat === 'stream_bingo' && <div className="fld fld-wide"><span className="fld-label">Bingo event</span><Combobox label="Bingo event" searchable={false} placeholder="Select a bingo event" value={bingoOf(s.alert_condition)} onChange={(v) => { const x = BINGO.find(([k]) => k === v); if (x) set({ variant_name: x[1], alert_condition: `bingo_event = '${x[0]}'` }) }} options={opts(BINGO)} />
                  <small className="muted">This variant fires only when the chosen bingo event happens.</small></div>}
                {!isModule && cat !== 'channel_points' && cat !== 'stream_bingo' && <Field label="Variant name" id="al-name" wide><input id="al-name" className="input" maxLength={100} value={s.variant_name} onChange={(e) => set({ variant_name: e.target.value })} /></Field>}
                {!isModule && !FIXED.includes(cat) && !['follow', 'channel_points', 'stream_bingo'].includes(cat) && <Field label="Condition (advanced)" id="al-cond" wide hint="Fires only when this matches. Leave blank to always fire."><input id="al-cond" className="input" maxLength={255} placeholder="e.g. bits >= 100, months = 1, gift_count >= 5" value={s.alert_condition || ''} onChange={(e) => set({ alert_condition: e.target.value })} /></Field>}
                {isAd ? (
                  <>
                    <div className="fld fld-wide"><span className="fld-label">Ad display</span><Combobox label="Ad display" searchable={false} value={String(s.layout_preset)} onChange={(v) => set({ layout_preset: v })} options={[{ value: 'frame', label: 'Full screen frame with timer' }, { value: 'pill', label: 'Timer only' }]} /></div>
                    <div className="fld"><span className="fld-label">Timer position</span><Combobox label="Timer position" searchable={false} value={String(s.screen_position)} onChange={(v) => set({ screen_position: v })} options={opts(PILL_POS)} /></div>
                    <Range id="al-ado" label="Opacity" v={adOpacity(s.bg_opacity)} min={10} max={100} step={5} onChange={(n) => set({ bg_opacity: n })} />
                    <Color id="al-adc" label="Ad break colour" v={String(s.ad_color)} onChange={(c) => set({ ad_color: c })} />
                    <p className="muted small fld-wide">While ads play, this stays on screen and the timer counts down the time left.</p>
                  </>
                ) : <Field label="On-screen duration (seconds)" id="al-dur"><input id="al-dur" className="input" type="number" min={1} max={99} value={s.duration} onChange={(e) => set({ duration: e.target.value })} /></Field>}
                {cat === 'goals' && <p className="muted small fld-wide">Goal started and goal completed show this alert. Progress updates stay off so the card does not spam.</p>}
                {isModule && <p className="muted small fld-wide">This card keeps its own design. Drag it to place it, then add a sound, set how long it stays on screen, or add a celebration.</p>}
                {!isModule && !isAd && (
                  <>
                    <div className="fld"><span className="fld-label">In animation</span><Combobox label="In animation" searchable={false} value={s.animation_in} onChange={(v) => set({ animation_in: v })} options={opts(ANIM_IN)} /></div>
                    <Field label="In seconds" id="al-ind"><input id="al-ind" className="input" type="number" min={0.1} max={5} step={0.1} value={s.animation_in_duration} onChange={(e) => set({ animation_in_duration: e.target.value })} /></Field>
                    <div className="fld"><span className="fld-label">Out animation</span><Combobox label="Out animation" searchable={false} value={s.animation_out} onChange={(v) => set({ animation_out: v })} options={opts(ANIM_OUT)} /></div>
                    <Field label="Out seconds" id="al-outd"><input id="al-outd" className="input" type="number" min={0.1} max={5} step={0.1} value={s.animation_out_duration} onChange={(e) => set({ animation_out_duration: e.target.value })} /></Field>
                  </>
                )}
              </div>
              {!isModule && !isAd && (
                <>
                  <h4 className="al-sec">Layout</h4>
                  <div className="cmd-form">
                    <div className="fld fld-wide"><span className="fld-label">Image position</span><Combobox label="Image position" searchable={false} value={s.layout_preset} onChange={(v) => set({ layout_preset: v })} options={opts(LAYOUTS)} /></div>
                    <Color id="al-bgc" label="Background" v={s.bg_color} onChange={(c) => set({ bg_color: c })} />
                    <Field label="Opacity (%)" id="al-bgo"><input id="al-bgo" className="input" type="number" min={0} max={100} value={s.bg_opacity} onChange={(e) => set({ bg_opacity: e.target.value })} /></Field>
                    <Field label="Padding (px)" id="al-pad"><input id="al-pad" className="input" type="number" min={0} max={100} value={s.padding} onChange={(e) => set({ padding: e.target.value })} /></Field>
                    <Field label="Gap (px)" id="al-gap"><input id="al-gap" className="input" type="number" min={0} max={100} value={s.gap} onChange={(e) => set({ gap: e.target.value })} /></Field>
                    <Check label="Rounded corners" v={on(s.rounded_corners)} onChange={(x) => set({ rounded_corners: x ? 1 : 0 })} />
                    <Check label="Drop shadow" v={on(s.drop_shadow)} onChange={(x) => set({ drop_shadow: x ? 1 : 0 })} />
                  </div>
                  <h4 className="al-sec">Text and speech</h4>
                  <div className="cmd-form">
                    {!lock && (
                      <>
                        <Field label="Message template" id="al-msg" wide><textarea id="al-msg" className="input" rows={3} maxLength={1000} placeholder={'{username}\njust followed!'} value={s.message_template || ''} onChange={(e) => set({ message_template: e.target.value })} /></Field>
                        <div className="fld-wide al-vh"><VarsHelp where="message"><p className="al-vars">This alert can use {vars.map((x, i) => <span key={x}>{i ? ', ' : ''}<code>{x}</code></span>)}.</p></VarsHelp></div>
                      </>
                    )}
                    <div className="fld"><span className="fld-label">Font</span><Combobox label="Font" value={s.font_family} onChange={(v) => set({ font_family: v })} options={FONTS.map((x) => ({ value: x, label: x }))} /></div>
                    <div className="fld"><span className="fld-label">Weight</span><Combobox label="Weight" searchable={false} value={s.font_weight} onChange={(v) => set({ font_weight: v })} options={WEIGHTS.map(([v, , l]) => ({ value: v, label: l }))} /></div>
                    <Field label="Size (px)" id="al-fs"><input id="al-fs" className="input" type="number" min={8} max={120} value={s.font_size} onChange={(e) => set({ font_size: e.target.value })} /></Field>
                    <div className="fld"><span className="fld-label">Alignment</span>
                      <div className="seg al-align" role="group" aria-label="Text alignment">
                        {([['left', 'fa-align-left', 'Align left'], ['center', 'fa-align-center', 'Align center'], ['right', 'fa-align-right', 'Align right'], ['justify', 'fa-align-justify', 'Justify']] as [string, string, string][]).map(([v, ic, l]) => <button key={v} type="button" aria-label={l} title={l} aria-pressed={(s.text_alignment || 'center') === v} className={(s.text_alignment || 'center') === v ? 'on' : ''} onClick={() => set({ text_alignment: v })}><i className={`fas ${ic}`} /></button>)}
                      </div></div>
                    {sideLayout && <div className="fld"><span className="fld-label">Vertical alignment</span>
                      <div className="seg al-align" role="group" aria-label="Vertical alignment">
                        {([['top', 'fa-arrow-up', 'Align top'], ['center', 'fa-arrows-up-down', 'Align middle'], ['bottom', 'fa-arrow-down', 'Align bottom']] as [string, string, string][]).map(([v, ic, l]) => <button key={v} type="button" aria-label={l} title={l} aria-pressed={(s.text_vertical_alignment || 'center') === v} className={(s.text_vertical_alignment || 'center') === v ? 'on' : ''} onClick={() => set({ text_vertical_alignment: v })}><i className={`fas ${ic}`} /></button>)}
                      </div></div>}
                    <Color id="al-tc" label="Text colour" v={s.text_color} onChange={(c) => set({ text_color: c })} />
                    <Color id="al-ac" label="Accent colour" v={s.accent_color} onChange={(c) => set({ accent_color: c })} />
                    <Check label="Text drop shadow" v={on(s.text_drop_shadow)} onChange={(x) => set({ text_drop_shadow: x ? 1 : 0 })} />
                    <Check label="Text to speech" hint="Reads the alert aloud through the TTS overlay." v={on(s.tts_enabled)} onChange={(x) => set({ tts_enabled: x ? 1 : 0 })} />
                  </div>
                </>
              )}
              <h4 className="al-sec">Visuals and sound</h4>
              <div className="cmd-form">
                {!isModule && !isAd && (
                  <div className="fld fld-wide"><span className="fld-label">Alert image</span>
                    <MediaRow kind="image" file={s.alert_image} base={page.mediaBase} onBrowse={() => setLibMode('image')} onUpload={() => { upKind.current = 'image'; upRef.current?.click() }} onRemove={() => removeMedia('alert_image')} uploading={uploading} />
                    {s.alert_image && <div className="cmd-form al-sub">
                      <Range id="al-is" label="Image scale" v={Number(s.image_scale) || 100} min={10} max={200} onChange={(n) => set({ image_scale: n })} />
                      <Range id="al-iv" label="Image volume" v={Number(s.image_volume) || 0} min={0} max={100} onChange={(n) => set({ image_volume: n })} />
                    </div>}
                  </div>
                )}
                <div className="fld fld-wide"><span className="fld-label">Alert sound</span>
                  <MediaRow kind="sound" file={s.alert_sound} base={page.mediaBase} onBrowse={() => setLibMode('sound')} onUpload={() => { upKind.current = 'sound'; upRef.current?.click() }} onRemove={() => removeMedia('alert_sound')} uploading={uploading} onMake={() => setMakeOpen(true)} />
                  {s.alert_sound && <Range id="al-sv2" label="Sound volume" v={Number(s.sound_volume) || 0} min={0} max={100} onChange={(n) => set({ sound_volume: n })} />}
                </div>
                <input ref={upRef} type="file" hidden accept=".webm,.gif,.png,.jpg,.jpeg,.mp3" onChange={(e) => { const fl = e.target.files?.[0]; e.target.value = ''; if (fl) upload(fl) }} />
              </div>
              {!isAd && (
                <>
                  <h4 className="al-sec">Celebration</h4>
                  <div className="cmd-form">
                    <div className="fld-wide"><Check label="Particle effect" hint="Layers a full-screen effect over the alert while it plays." v={on(s.celebration_enabled)} onChange={(x) => set({ celebration_enabled: x ? 1 : 0 })} /></div>
                    {on(s.celebration_enabled) && <>
                      <div className="fld"><span className="fld-label">Effect</span><Combobox label="Effect" searchable={false} value={s.celebration_effect} onChange={(v) => set({ celebration_effect: v })} options={opts(EFFECTS)} /></div>
                      <div className="fld"><span className="fld-label">Intensity</span><Combobox label="Intensity" searchable={false} value={s.celebration_intensity} onChange={(v) => set({ celebration_intensity: v })} options={opts(INTENSITY)} /></div>
                    </>}
                  </div>
                </>
              )}
              {!lock && !isModule && <div className="al-del"><button type="button" className="btn btn-ghost al-del-btn" onClick={() => setAsk('delete')}><i className="fas fa-trash" /> Delete variant</button></div>}
            </>
          )}
      </section>
      {ask === 'discard' && <Modal title="Discard changes?" icon="fas fa-rotate-left" onClose={() => setAsk(null)}>
        <p>Your unsaved changes to this variant will be lost.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setAsk(null)}>Keep editing</button><button type="button" className="btn btn-danger" onClick={() => { setS(init()); setDirty(false); setAsk(null); setMsg('') }}>Discard</button></div>
      </Modal>}
      {ask === 'delete' && <Delete a={a} post={post} onCancel={() => setAsk(null)} onDone={onDeleted} />}
      {libMode && <Library mode={libMode} files={libMode === 'image' ? lib.images : lib.sounds} base={page.mediaBase} onClose={() => setLibMode(null)} onPick={(fl) => { set(libMode === 'image' ? { alert_image: fl } : { alert_sound: fl }); setLibMode(null) }} />}
      {makeOpen && <MakeSound gen={page.soundGen} job={job} pct={pct} onStart={onStartSound} onClose={() => setMakeOpen(false)} />}
    </>
  )
}

function Card({ s, sideLayout, mediaBase, reward, anim }: { s: Alert; sideLayout: boolean; mediaBase: string; reward?: Reward; anim?: React.CSSProperties }) {
  const hex = /^#[0-9a-f]{6}$/i.test(s.bg_color) ? s.bg_color : '#000000'
  const rgba = `rgba(${parseInt(hex.slice(1, 3), 16)},${parseInt(hex.slice(3, 5), 16)},${parseInt(hex.slice(5, 7), 16)},${(Number(s.bg_opacity) || 0) / 100})`
  const weight = WEIGHTS.find(([v]) => v === s.font_weight)?.[1] || '600'
  const sample: Record<string, string> = { ...SAMPLE, reward_title: reward?.reward_title ? String(reward.reward_title) : 'Hydrate', cost: reward?.reward_cost != null ? String(reward.reward_cost) : '500' }
  const parts = String(s.message_template || '').split(/(\{[a-z_]+\}|\n)/g)
  const img = s.alert_image ? mediaBase + s.alert_image.split('/').map(encodeURIComponent).join('/') : ''
  const imgStyle = { transform: `scale(${(Number(s.image_scale) || 100) / 100})` }
  const va = ({ top: 'flex-start', bottom: 'flex-end' } as Record<string, string>)[s.text_vertical_alignment] || 'center'
  return (
    <div className={`al-card al-l-${s.layout_preset}`} style={{ background: rgba, padding: `${s.padding}px`, gap: `${s.gap}px`, borderRadius: on(s.rounded_corners) ? 12 : 0, boxShadow: on(s.drop_shadow) ? '0 4px 20px rgba(0,0,0,0.5)' : 'none', ...anim }}>
      {img && (ext(img) === 'webm' ? <video className="al-img" src={img} autoPlay loop muted playsInline style={imgStyle} /> : <img className="al-img" src={img} alt="" style={imgStyle} />)}
      <div className="al-text" style={{ fontFamily: `"${s.font_family}", sans-serif`, fontWeight: Number(weight), fontSize: `${s.font_size}px`, color: s.text_color, textAlign: (s.text_alignment || 'center') as React.CSSProperties['textAlign'], alignSelf: sideLayout ? va : undefined, textShadow: on(s.text_drop_shadow) ? '0 2px 4px rgba(0,0,0,0.8)' : 'none' }}>
        {parts.map((p, i) => { if (p === '\n') return <br key={i} />; const m = p.match(/^\{([a-z_]+)\}$/); return m && sample[m[1]] !== undefined ? <span key={i} style={{ color: s.accent_color, fontWeight: 700 }}>{sample[m[1]]}</span> : <span key={i}>{p}</span> })}
      </div>
    </div>
  )
}

function MediaRow({ kind, file, base, onBrowse, onUpload, onRemove, onMake, uploading }: { kind: 'image' | 'sound'; file: string | null; base: string; onBrowse: () => void; onUpload: () => void; onRemove: () => void; onMake?: () => void; uploading: boolean }) {
  const url = file ? base + file.split('/').map(encodeURIComponent).join('/') : ''
  return (
    <div className="al-media">
      <div className="al-media-cur">
        {file && kind === 'image' && (ext(file) === 'webm' ? <video src={url} autoPlay loop muted playsInline className="al-thumb" /> : <img src={url} alt="" className="al-thumb" />)}
        <span className={file ? 'al-fname' : 'muted'}>{file || (kind === 'image' ? 'No image selected' : 'No sound selected')}</span>
      </div>
      {file && kind === 'sound' && <audio controls src={url} preload="none" className="al-audio" />}
      <div className="al-media-acts">
        <button type="button" className="btn btn-ghost" onClick={onBrowse}><i className="fas fa-photo-film" /> Browse library</button>
        <button type="button" className="btn btn-ghost" disabled={uploading} onClick={onUpload}><i className={uploading ? 'fas fa-spinner fa-spin' : 'fas fa-upload'} /> Upload</button>
        {onMake && <button type="button" className="btn btn-quiet" onClick={onMake}><i className="fas fa-wand-magic-sparkles" /> Make Your Sound</button>}
        {file && <button type="button" className="btn btn-ghost" onClick={onRemove}><i className="fas fa-xmark" /> Remove</button>}
      </div>
    </div>
  )
}

function Delete({ a, post, onCancel, onDone }: { a: Alert; post: Post; onCancel: () => void; onDone: () => void }) {
  const [busy, setBusy] = useState(false), [m, setM] = useState('')
  async function go() {
    setBusy(true)
    try { const r = await post({ action: 'delete_variant', id: String(a.id) }); if (r.success) onDone(); else setM(r.message || 'Failed to delete variant. Please try again.') }
    catch (e) { setM((e as Error).message || 'Failed to delete variant. Please try again.') } finally { setBusy(false) }
  }
  return (
    <Modal title={`Delete "${a.variant_name}"?`} icon="fas fa-trash" onClose={onCancel}>
      <p>This variant's settings are removed for good. Its media stays in your library.</p>
      {m && <p className="counter over" role="status">{m}</p>}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={onCancel}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy} onClick={go}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Delete variant</button></div>
    </Modal>
  )
}

function Library({ mode, files, base, onClose, onPick }: { mode: 'image' | 'sound'; files: string[]; base: string; onClose: () => void; onPick: (f: string) => void }) {
  const [q, setQ] = useState(''), [folder, setFolder] = useState('')
  const folderOf = (f: string) => (f.includes('/') ? f.slice(0, f.lastIndexOf('/')) : '')
  const counts: Record<string, number> = {}; let root = 0
  for (const fl of files) { const d = folderOf(fl); if (d) counts[d] = (counts[d] || 0) + 1; else root++ }
  const names = Object.keys(counts).sort((x, y) => x.toLowerCase().localeCompare(y.toLowerCase()))
  const list = files.filter((fl) => { const d = folderOf(fl); if (folder === '/' && d) return false; if (folder && folder !== '/' && d !== folder) return false; return !q || fl.toLowerCase().includes(q.toLowerCase()) })
  return (
    <Modal title={mode === 'image' ? 'Choose an alert image' : 'Choose an alert sound'} icon="fas fa-photo-film" onClose={onClose} wide>
      {files.length > 0 && <div className="tm-filters">
        {names.length > 0 && <div className="tm-trig"><Combobox label="Folder" searchable={false} value={folder} onChange={setFolder} options={[{ value: '', label: `All folders (${files.length})` }, ...(root ? [{ value: '/', label: `Not in a folder (${root})` }] : []), ...names.map((n) => ({ value: n, label: `${n} (${counts[n]})` }))]} /></div>}
        <label className="media-search tm-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search files" aria-label="Search files" value={q} onChange={(e) => setQ(e.target.value)} /></label>
      </div>}
      {!files.length ? <p className="muted media-empty">No files of this type in your library yet. Upload one in the editor or on the <a href="/media">Media</a> page.</p>
        : !list.length ? <p className="muted media-empty">No files match your search in this folder.</p> : (
          <div className="al-lib">
            {list.map((fl) => { const url = base + fl.split('/').map(encodeURIComponent).join('/'); const d = folderOf(fl); return (
              <button key={fl} type="button" className="al-lib-item" title={fl} onClick={() => onPick(fl)}>
                <span className="al-lib-thumb">{mode === 'sound' ? <i className="fas fa-music" /> : ext(fl) === 'webm' ? <video src={url} muted autoPlay loop playsInline /> : <img src={url} alt="" loading="lazy" />}</span>
                <span className="al-lib-name">{fl.slice(fl.lastIndexOf('/') + 1)}</span>
                {d && <span className="muted small"><i className="fas fa-folder" /> {d}</span>}
              </button>) })}
          </div>
        )}
    </Modal>
  )
}

function MakeSound({ gen, job, pct, onStart, onClose }: { gen: PageResp['soundGen']; job: SoundJob | null; pct: number; onStart: (prompt: string, dur: string) => Promise<void>; onClose: () => void }) {
  const [prompt, setPrompt] = useState(''), [dur, setDur] = useState('auto'), [starting, setStarting] = useState(false)
  const max = gen.promptMax || 300
  return (
    <Modal title="Make Your Sound" icon="fas fa-wand-magic-sparkles" onClose={onClose}>
      <p className="muted">Describe the sound and we will make it, save it to your media library and set it on this alert. Save changes afterwards to keep it there.</p>
      <div className="fld"><label htmlFor="al-mp">Describe your sound</label><textarea id="al-mp" className="input" rows={3} maxLength={max} disabled={!!job} placeholder="e.g. Retro arcade coin pickup with a bright sparkle" value={prompt} onChange={(e) => setPrompt(e.target.value)} /><small className="counter">{prompt.length}/{max} characters</small></div>
      <div className="fld"><span className="fld-label">Length</span><Combobox label="Length" searchable={false} value={dur} onChange={setDur} options={[{ value: 'auto', label: 'Automatic' }, ...[1, 2, 3, 5, 8, 10, 15, 20, 30].map((n) => ({ value: String(n), label: `${n} seconds` }))]} /></div>
      {job && <><div className="al-prog" role="progressbar" aria-label="Making your sound" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${pct}%` }} /></div>
        <p className="muted small">Making your sound, {pct}%. You can close this; it is still saved to your library.</p></>}
      <div className="modal-acts al-make-acts"><span className="muted small">{gen.remaining} left in the next 24 hours</span>
        <button type="button" className="btn" disabled={!!job || starting || !prompt.trim() || gen.remaining <= 0} onClick={async () => { setStarting(true); await onStart(prompt.trim(), dur); setStarting(false) }}><i className={job || starting ? 'fas fa-spinner fa-spin' : 'fas fa-wand-magic-sparkles'} /> {job ? 'Making your sound' : 'Make sound'}</button></div>
    </Modal>
  )
}
