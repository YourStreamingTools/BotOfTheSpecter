import { useEffect, useRef, useState } from 'react'
import { get } from '../../bot'
import { send, ToastBar, type Toast } from '../commands/shared'
import { Confirm, notOk } from './shared'

// Port of dashboard/schedule.php: Schedule Settings (vacation and add
// segment), Stream Summary, the day columns with an edit form per stream,
// Load More, the vacation card and the empty state.
// Endpoint: public/api/schedule/schedule.php.
const API = '/api/schedule/schedule.php'
type Seg = { id: string; title: string; category_name: string; category_id: string; is_recurring: boolean; is_canceled: boolean; start_local: string; end_local: string; start_date_text: string; start_time_text: string; end_time_text: string; end_date_text: string; duration_human: string }
type Day = { day_key: string; label: string; initially_visible: boolean; segments: Seg[] }
type Vac = { start_local: string; end_local: string; start_display: string; end_display: string } | null
type View = { days: Day[]; summary: { vacation: boolean }; load_more_day_batch_size: number; vacation: Vac }
type Res = { success: boolean; message?: string | null; error?: string | null }
type Cat = { id: string; name: string }
const MIN = 30, MAX = 1380
const HELP = 'Duration must be between 30 minutes and 23 hours (1380 minutes).'
const mins = (a: string, b: string) => { if (!a || !b) return null; const s = new Date(a).getTime(), e = new Date(b).getTime(); return isNaN(s) || isNaN(e) ? null : Math.floor((e - s) / 60000) }
const human = (m: number | null) => { if (m === null || m <= 0) return '-'; const h = Math.floor(m / 60), r = m % 60; return h && r ? `${h}h ${r}m` : h ? `${h}h` : `${r}m` }
const durHelp = (m: number | null, base: string) => m === null || (m >= MIN && m <= MAX) ? base : m < MIN ? 'Duration is too short. Minimum is 30 minutes.' : 'Duration is too long. Maximum is 23 hours (1380 minutes).'
const okDur = (m: number | null) => m !== null && m >= MIN && m <= MAX

// 10 cards per page on desktop (two rows of 5), 5 on mobile.
const MOBILE = '(max-width: 768px)'
function usePer() {
  const [m, setM] = useState(() => window.matchMedia(MOBILE).matches)
  useEffect(() => { const q = window.matchMedia(MOBILE), f = () => setM(q.matches); q.addEventListener('change', f); return () => q.removeEventListener('change', f) }, [])
  return m ? 5 : 10
}

// Category box: 1s after typing, an all-digits value is looked up by ID, anything else is searched.
function CatSearch({ text, setText, setId, placeholder, toast }: { text: string; setText: (s: string) => void; setId: (s: string) => void; placeholder: string; toast: (t: Toast) => void }) {
  const [items, setItems] = useState<Cat[] | null>(null), wrap = useRef<HTMLDivElement>(null), typed = useRef(false)
  useEffect(() => {
    if (!typed.current) return
    const q = text.trim()
    if (!q) { setItems(null); return }
    let live = true
    const h = window.setTimeout(async () => {
      if (/^\d+$/.test(q)) {
        const g = await get<Cat | { error: string }>(`${API}?ajax=get_game_name&id=${q}`).catch(() => null)
        if (live && g && 'id' in g && g.id) { setId(String(g.id)); toast({ kind: 'ok', text: `Category resolved: ${g.name}` }); setItems([g]); return }
      }
      const r = await get<Cat[] | { error: string }>(`${API}?ajax=search_categories&q=${encodeURIComponent(q)}`).catch(() => [])
      if (live) setItems(Array.isArray(r) && r.length ? r : null)
    }, 1000)
    return () => { live = false; clearTimeout(h) }
  }, [text]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const off = (e: MouseEvent) => { if (wrap.current && !wrap.current.contains(e.target as Node)) setItems(null) }; document.addEventListener('mousedown', off); return () => document.removeEventListener('mousedown', off) }, [])
  return <div className="tg-search" ref={wrap}>
    <input className="input cm-in" autoComplete="off" placeholder={placeholder} aria-label="Category" value={text}
      onChange={(e) => { typed.current = true; setText(e.target.value) }}
      onBlur={(e) => { if (e.target.value.trim() === '') { setId(''); } }}
      onKeyDown={(e) => { if (e.key === 'Escape') setItems(null) }} />
    {items && <div className="tg-results" role="listbox">{items.map((c) => <button key={c.id} type="button" role="option" aria-selected={false} className="tg-result sc-result"
      onClick={() => { typed.current = false; setItems(null); setId(String(c.id)); setText(c.name); toast({ kind: 'ok', text: `Category set: ${c.name} (id:${c.id})` }) }}><b>{c.name}</b><span className="muted">id:{c.id}</span></button>)}</div>}
  </div>
}

function SegCard({ s, first, label, busy, onSave, onCancel, onDelete, toast }: { s: Seg; first: boolean; label: string; busy: boolean; onSave: (b: Record<string, string>) => void; onCancel: (s: Seg, start: string) => void; onDelete: (s: Seg) => void; toast: (t: Toast) => void }) {
  const [start, setStart] = useState(s.start_local), [end, setEnd] = useState(s.end_local)
  const [cat, setCat] = useState(s.category_name), [catId, setCatId] = useState(s.category_id), [title, setTitle] = useState(s.title)
  const m = mins(start, end)
  function update() {
    if (cat.trim() !== '' && catId.trim() === '') { toast({ kind: 'err', text: 'Please select a valid category from suggestions or clear the category field.' }); return }
    if (!okDur(m)) { toast({ kind: 'err', text: 'Please set an end time that gives a duration between 30 minutes and 23 hours.' }); return }
    onSave({ action: 'update_segment', segment_id: s.id, segment_start: start, segment_end: end, segment_category_id: cat.trim() === '' ? '' : catId, segment_title: title })
  }
  return <div className="sc-col">
    <h4 className="sc-day" aria-hidden={!first}>{first ? label : '\u00a0'}</h4>
    <div className={`card sc-card${s.is_canceled ? ' sc-canceled' : ''}`}>
      <div className="sc-card-h"><b>{s.title || 'Untitled'}</b><span className="sc-tags">{s.is_recurring && <span className="pill pill-info">Recurring</span>}{s.is_canceled && <span className="pill pill-err">Canceled</span>}</span></div>
      <div className="sc-body">
        <p><b>Start Date:</b> {s.start_date_text}</p>
        <p><b>Start Time:</b> {s.start_time_text}</p>
        <p><b>End Time:</b> {s.end_time_text}</p>
        {s.end_date_text && <p><b>End Date:</b> {s.end_date_text}</p>}
        <p><b>Duration</b><br />{s.duration_human}</p>
        <p><b>Category</b><br />{s.category_name || <em>Not specified</em>}</p>
      </div>
      <div className="sc-edit">
        <div className="sc-row"><input className="input cm-in" type="datetime-local" aria-label="Start" value={start} onChange={(e) => setStart(e.target.value)} /><input className={`input cm-in${m !== null && !okDur(m) ? ' input-err' : ''}`} type="datetime-local" aria-label="End" value={end} onChange={(e) => setEnd(e.target.value)} /></div>
        <span className="pill pill-off sc-dur">{m === null ? 'Duration: -' : `Duration: ${human(m)}`}</span>
        <CatSearch text={cat} setText={setCat} setId={setCatId} placeholder="Search category..." toast={toast} />
        <input className="input cm-in" type="text" maxLength={140} placeholder="Title" aria-label="Title" value={title} onChange={(e) => setTitle(e.target.value)} />
        <div className="sc-btns">
          <button type="button" className="btn cm-btn" disabled={busy || m === null || !okDur(m)} onClick={update}>Update</button>
          <button type="button" className={`btn ${s.is_canceled ? 'btn-warn' : 'btn-danger'} cm-btn`} disabled={busy} onClick={() => onCancel(s, start)}>{s.is_canceled ? 'Uncancel' : 'Cancel Stream'}</button>
          <button type="button" className="btn btn-danger cm-btn" disabled={busy} onClick={() => onDelete(s)}>Delete</button>
        </div>
        <small className="muted">{durHelp(m, HELP)}</small>
        <small className="muted">"Cancel Stream" only cancels this segment. For multiple streams in a row, use "Vacation / Off dates" above.</small>
      </div>
    </div>
  </div>
}

export default function Schedule() {
  const [v, setV] = useState<View | null>(null), [tz, setTz] = useState(''), [loadErr, setLoadErr] = useState('')
  const per = usePer(), [shown, setShown] = useState(0), [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null)
  const [error, setError] = useState(''), [ok, setOk] = useState('')
  const [vs, setVs] = useState(''), [ve, setVe] = useState('')
  const [cs, setCs] = useState(''), [ce, setCe] = useState(''), [cat, setCat] = useState(''), [catId, setCatId] = useState(''), [title, setTitle] = useState(''), [rec, setRec] = useState(false)
  const [del, setDel] = useState<Seg | null>(null), [far, setFar] = useState(false)
  const [rev, setRev] = useState(0)
  const load = () => get<Res & { view?: View; timezone?: string; success_message?: string | null }>(`${API}?ajax_action=list`, 30000).then((r) => {
    if (!r.success || !r.view) { setLoadErr(r.error || 'Could not load your Twitch schedule. Refresh to try again.'); return }
    setLoadErr(''); setV(r.view); setTz(r.timezone || ''); setRev((n) => n + 1)
    if (r.success_message) setOk(r.success_message)
    setVs(r.view.vacation?.start_local ?? ''); setVe(r.view.vacation?.end_local ?? '')
  }).catch((e) => { if (notOk(e)) setLoadErr('Could not load your Twitch schedule. Refresh to try again.') })
  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function post(key: string, body: Record<string, string>) {
    setBusy(key); setError(''); setOk('')
    try {
      const r = await send<Res>(API, body)
      if (r.success) { setOk(r.message || 'Schedule settings updated successfully.'); await load(); return true }
      setError(r.error || 'That did not work. Please try again.'); return false
    } catch (e) { setError((e as Error).message); return false }
    finally { setBusy(''); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  }
  async function create() {
    const m = mins(cs, ce)
    if (cat.trim() !== '' && catId.trim() === '') { setToast({ kind: 'err', text: 'Please select a valid category from the suggestions or clear the category field.' }); return }
    if (!okDur(m)) { setToast({ kind: 'err', text: 'Please set an end time that gives a duration between 30 minutes and 23 hours.' }); return }
    if (await post('create', { action: 'create_segment', segment_start: cs, segment_end: ce, segment_category_id: cat.trim() === '' ? '' : catId, segment_title: title, ...(rec ? { segment_recurring: '1' } : {}) })) { setCs(''); setCe(''); setCat(''); setCatId(''); setTitle(''); setRec(false) }
  }
  async function cancel(s: Seg, startValue: string) {
    const wants = !s.is_canceled
    if (s.is_recurring && wants) {
      const t = new Date(startValue), now = new Date()
      const days = isNaN(t.getTime()) ? -1 : Math.floor((new Date(t.getFullYear(), t.getMonth(), t.getDate()).getTime() - new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()) / 86400000)
      if (days >= 7) { setFar(true); return }
    }
    setBusy(s.id)
    try {
      const r = await send<{ ok: boolean; is_canceled?: boolean; message?: string; error?: string }>(`${API}?ajax=segment_action`, { action: 'cancel_segment', segment_id: s.id, cancel_state: wants ? '1' : '0' }, true)
      if (!r.ok) { setToast({ kind: 'err', text: r.error || 'Unable to update stream cancel state right now.' }); return }
      const now = r.is_canceled ?? wants
      setV((x) => x && { ...x, days: x.days.map((d) => ({ ...d, segments: d.segments.map((g) => g.id === s.id ? { ...g, is_canceled: now } : g) })) })
      setToast({ kind: 'ok', text: r.message || (now ? 'Segment canceled.' : 'Segment uncanceled.') })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Unable to update stream cancel state right now.' }) }
    finally { setBusy('') }
  }
  const cm = mins(cs, ce)
  const days = v?.days ?? [], all = days.flatMap((d) => d.segments.map((s, i) => ({ s, first: i === 0, label: d.label })))
  const lim = shown || per, vis = all.slice(0, lim), segs = vis.map((x) => x.s), nextN = Math.min(per, all.length - lim)
  const tile = (label: string, val: React.ReactNode, cls = '') => <div className={`card stat cm-stat ${cls}`}><div>{!v ? <span className="skel w-55" /> : <b>{val}</b>}<span>{label}</span></div></div>
  return (
    <div className="page cmds cm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <div className="pg-head"><h2><i className="fas fa-calendar-days" /> Twitch Schedule</h2><p className="muted">Your official Twitch schedule.</p></div>
      {error && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span><b>Notice:</b> {error}</span></div>}
      {ok && <div className="notice notice-ok" role="status"><i className="fas fa-check" /> <span><b>Success:</b> {ok}</span></div>}
      {loadErr && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span><b>Notice:</b> {loadErr}</span></div>}
      <section className="card" aria-label="Schedule Settings">
        <h3 className="card-h"><i className="fas fa-calendar-alt" /> Schedule Settings</h3>
        <h4 className="ss-h">Vacation / Off dates</h4>
        <div className="sc-row">
          <input className="input cm-in" type="datetime-local" aria-label="Vacation start" value={vs} onChange={(e) => setVs(e.target.value)} />
          <input className="input cm-in" type="datetime-local" aria-label="Vacation end" value={ve} onChange={(e) => setVe(e.target.value)} />
        </div>
        <div className="sc-btns">
          <button type="button" className="btn cm-btn" disabled={!!busy} onClick={() => post('vac', { action: 'save', vacation_start: vs, vacation_end: ve })}>Start Vacation</button>
          {v?.vacation && <button type="button" className="btn btn-danger cm-btn" disabled={!!busy} onClick={() => post('vac', { action: 'clear' })}>Cancel Vacation</button>}
        </div>
        <small className="muted">Times are shown in your profile timezone ({tz || '...'}). If this is wrong, update your timezone in your profile settings.</small>
        <hr className="ss-hr" />
        <h4 className="ss-h">Add schedule segment</h4>
        <div className="sc-row">
          <input className="input cm-in" type="datetime-local" aria-label="Start (local)" value={cs} onChange={(e) => setCs(e.target.value)} />
          <input className={`input cm-in${cm !== null && !okDur(cm) ? ' input-err' : ''}`} type="datetime-local" aria-label="End (local)" value={ce} onChange={(e) => setCe(e.target.value)} />
        </div>
        <span className="pill pill-off sc-dur">{cm === null ? 'Duration: -' : `Duration: ${human(cm)}`}</span>
        <div className="sc-create">
          <div className="cm-grow"><CatSearch text={cat} setText={setCat} setId={setCatId} placeholder="Search category (name or id) - type to search" toast={setToast} /></div>
          <input className="input cm-in cm-grow" type="text" maxLength={140} placeholder="Title (optional)" aria-label="Title (optional)" value={title} onChange={(e) => setTitle(e.target.value)} />
          <label className="sc-check"><input type="checkbox" checked={rec} onChange={(e) => setRec(e.target.checked)} /> Recurring</label>
          <button type="button" className="btn cm-btn" disabled={!!busy || !okDur(cm)} onClick={create}>Create</button>
        </div>
        <small className="muted">{cm !== null && !okDur(cm) ? durHelp(cm, HELP) : 'Duration must be between 30 minutes and 23 hours (1380 minutes). Non-recurring segments may be restricted to partners/affiliates.'}</small>
      </section>
      {(!v || days.length > 0) && !loadErr && <section className="card" aria-label="Stream Summary">
        <h3 className="card-h"><i className="fas fa-chart-simple" /> Stream Summary</h3>
        <div className="stat-grid small">
          {tile('Streams', segs.length)}
          {tile('Recurring', segs.filter((s) => s.is_recurring).length, 'sc-info')}
          {tile('Canceled', segs.filter((s) => s.is_canceled).length, 'sc-danger')}
          {tile('Vacation', v?.summary.vacation ? <span className="pill pill-warn">Active</span> : <span className="pill pill-ok">Off</span>)}
        </div>
      </section>}
      {!v && !loadErr ? <div className="sc-cols" aria-busy="true">{[0, 1, 2].map((k) => <div key={k} className="sc-col"><div className="card sc-card"><div className="adm-log-skel">{[60, 80, 70, 50, 90].map((w, j) => <span key={j} className="bp-skel" style={{ width: `${w}%` }} />)}</div></div></div>)}</div>
        : v && days.length > 0 ? <>
          <div className="sc-cols">{vis.map(({ s, first, label }) => <SegCard key={`${rev}-${s.id}`} s={s} first={first} label={label} busy={!!busy} toast={setToast}
            onSave={(b) => post(s.id, b)} onCancel={cancel} onDelete={setDel} />)}</div>
          {lim < all.length && <div className="sc-more"><button type="button" className="btn btn-ghost cm-btn" onClick={() => setShown(lim + per)}>{nextN === 1 ? 'Load 1 More' : `Load ${nextN} More`}</button></div>}
        </> : v && <section className="card cm-empty"><i className="fas fa-calendar-xmark fa-2x" /><p>No scheduled segments</p><p className="muted small">You don't have any scheduled stream segments on Twitch. Use the Twitch Creator Dashboard to add schedule entries.</p></section>}
      {v?.vacation && <section className="card" aria-label="Vacation"><p><b>Vacation / Off dates:</b> From {v.vacation.start_display} to {v.vacation.end_display}</p></section>}
      {far && <Confirm title="Cannot cancel this recurring stream yet" yes="OK" danger={false} only onYes={() => setFar(false)} onClose={() => setFar(false)}><p>Sorry, you can't cancel a recurring stream this far in the future. Twitch only allows recurring stream canceling within a 7-day window including today.</p></Confirm>}
      {del && <Confirm title={del.is_recurring ? 'Delete recurring stream?' : 'Delete this stream?'} yes="Yes, delete it" busy={busy === del.id} onClose={() => setDel(null)}
        onYes={async () => { const s = del; await post(s.id, { action: 'delete_segment', segment_id: s.id }); setDel(null) }}>
        <p>{del.is_recurring ? 'Deleting a recurring stream will remove all future events as well. If you only need to remove this single one, please consider marking it as canceled instead.' : 'This cannot be undone.'}</p>
      </Confirm>}
    </div>
  )
}
