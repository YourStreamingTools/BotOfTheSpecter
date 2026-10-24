import { useEffect, useMemo, useState } from 'react'
import { hasConsent } from '../consent'
import Modal from '../components/Modal'
import Combobox, { type ComboOption } from '../components/Combobox'
import { get } from '../bot'
import { Counter, Switch, ToastBar, VarsHelp, send, type Toast } from './commands/shared'

// Port of dashboard/timed_messages.php via public/api/timed/timed.php.
const API = '/api/timed/timed.php'
const MAX = 500
type Trigger = 'timer' | 'chat_lines' | 'both' | 'scheduled'
type Msg = { id: number | string; message: string; interval_count: number | string | null; chat_line_trigger: number | string | null; scheduled_time: string | null; status: number | string; trigger_type: Trigger | null }
const TRIGGERS: ComboOption[] = [
  { value: 'timer', label: 'Timer' },
  { value: 'chat_lines', label: 'Chat lines' },
  { value: 'both', label: 'Timer and chat lines' },
  { value: 'scheduled', label: 'Scheduled time of day' },
]
type StatusF = 'all' | 'on' | 'off'
type Filter = { status: StatusF; trig: 'all' | Trigger }
// Saved in a cookie only after cookie consent; otherwise it lives in page state.
function readFilter(): Filter {
  const d: Filter = { status: 'all', trig: 'all' }
  if (!hasConsent()) return d
  try {
    const m = document.cookie.match(/(?:^|; )tm_filter=([^;]*)/)
    const v = m ? JSON.parse(decodeURIComponent(m[1])) : {}
    return { status: ['all', 'on', 'off'].includes(v.status) ? v.status : 'all', trig: ['all', 'timer', 'chat_lines', 'both', 'scheduled'].includes(v.trig) ? v.trig : 'all' }
  } catch { return d }
}
function saveFilter(f: Filter) {
  if (!hasConsent()) return
  document.cookie = `tm_filter=${encodeURIComponent(JSON.stringify(f))}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax; Secure`
}
const hasShoutout = (s: string) => /\(shoutout\.\w+\)/.test(s)
const usesTimer = (t: Trigger) => t === 'timer' || t === 'both'
const usesLines = (t: Trigger) => t === 'chat_lines' || t === 'both'

function triggerText(m: Msg) {
  const t = m.trigger_type || 'timer'
  if (t === 'chat_lines') return `Every ${m.chat_line_trigger} chat lines`
  if (t === 'both') return `Every ${m.interval_count} min or ${m.chat_line_trigger} chat lines`
  if (t === 'scheduled') return `Daily at ${m.scheduled_time ? String(m.scheduled_time).slice(0, 5) : '--:--'}`
  return `Every ${m.interval_count} min`
}

export default function TimedMessages() {
  const [msgs, setMsgs] = useState<Msg[] | null>(null)
  const [err, setErr] = useState('')
  const [tz, setTz] = useState<{ timezone: string; now: string } | null>(null)
  const [toast, setToast] = useState<Toast>(null)
  const [edit, setEdit] = useState<Msg | null>(null)
  const [del, setDel] = useState<Msg | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [f, setF] = useState<Filter>(readFilter)
  const [q, setQ] = useState('')
  function filt(n: Partial<Filter>) { const x = { ...f, ...n }; setF(x); saveFilter(x) }
  const all = msgs ?? []
  const trigOf = (m: Msg) => m.trigger_type || 'timer'
  const byStatus = all.filter((m) => f.status === 'all' || (Number(m.status) === 1) === (f.status === 'on'))
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return byStatus.filter((m) => (f.trig === 'all' || trigOf(m) === f.trig) && (!n || m.message.toLowerCase().includes(n) || String(m.id) === n.replace('#', '')))
  }, [byStatus, f.trig, q]) // eslint-disable-line react-hooks/exhaustive-deps
  const onN = all.filter((m) => Number(m.status) === 1).length
  const trigOpts: ComboOption[] = [{ value: 'all', label: `All triggers (${byStatus.length})` }, ...TRIGGERS.map((t) => ({ ...t, label: `${t.label} (${byStatus.filter((m) => trigOf(m) === t.value).length})` }))]

  async function load() {
    try {
      const r = await get<{ success: boolean; messages: Msg[] }>(`${API}?ajax_action=list`)
      if (!r.success) throw new Error()
      setMsgs([...r.messages].sort((a, b) => Number(a.id) - Number(b.id))); setErr('')
    } catch { setErr('Could not load timed messages. Try again in a moment.') }
  }
  useEffect(() => { load(); get<{ success: boolean; timezone: string; now: string }>(API).then((r) => r.success && setTz(r)).catch(() => {}) }, [])

  async function act(body: Record<string, string>) {
    try {
      const r = await send(API, body)
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || 'Done.' })
      return r.success
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'That did not save.' }); return false } finally { load() }
  }
  async function toggle(m: Msg) {
    const id = String(m.id); setBusy(id)
    try {
      const r = await send<{ success: boolean; new_status?: number; error?: string }>(API, { ajax_action: 'toggle_status', toggle_id: id, toggle_status: String(Number(m.status)) })
      if (r.success) setMsgs((l) => l && l.map((x) => (String(x.id) === id ? { ...x, status: r.new_status ?? (Number(m.status) ? 0 : 1) } : x)))
      else setToast({ kind: 'err', text: 'Could not change the status.' })
    } catch { setToast({ kind: 'err', text: 'Could not change the status.' }) } finally { setBusy(null) }
  }

  const tzText = tz ? `${tz.timezone}, now ${tz.now}` : ''
  return (
    <div className="page cmds tm">
      <div className="section-head"><h2><i className="fas fa-clock" /> Timed Messages</h2></div>
      <p className="muted">The bot posts each message on a timer, after a number of chat lines, on whichever of those comes first, or once a day at a set time. Scheduled messages only send while you are live.</p>
      <VarsHelp where="message" />
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card">
        <h3 className="card-h"><i className="fas fa-plus" /> Add a timed message</h3>
        <TimedForm tzText={tzText} onSave={act} />
      </section>
      <section className="card cmd-lib">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list" /> Your timed messages</h3></div>
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!msgs && !err && <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {msgs && !msgs.length && <p className="muted media-empty">No timed messages yet.</p>}
        {msgs && msgs.length > 0 && (
          <div className="tm-filters">
            <div className="seg tm-seg" role="group" aria-label="Status">
              {([['all', 'All', all.length], ['on', 'Enabled', onN], ['off', 'Disabled', all.length - onN]] as [StatusF, string, number][]).map(([v, l, n]) => <button key={v} type="button" aria-pressed={f.status === v} className={f.status === v ? 'on' : ''} onClick={() => filt({ status: v })}>{l} <span className="tm-n">{n}</span></button>)}
            </div>
            <div className="tm-trig"><Combobox label="Trigger type" searchable={false} options={trigOpts} value={f.trig} onChange={(v) => filt({ trig: v as Filter['trig'] })} /></div>
            <label className="media-search tm-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search messages" aria-label="Search timed messages" value={q} onChange={(e) => setQ(e.target.value)} /></label>
          </div>
        )}
        {msgs && msgs.length > 0 && shown.length !== all.length && <p className="muted small tm-count" aria-live="polite">Showing {shown.length} of {all.length}</p>}
        {msgs && msgs.length > 0 && !shown.length && <div className="media-empty tm-empty"><p className="muted">No messages match these filters.</p><button type="button" className="btn btn-quiet" onClick={() => { setQ(''); filt({ status: 'all', trig: 'all' }) }}>Clear filters</button></div>}
        {shown.length > 0 && (
          <ul className="cmd-list">
            {shown.map((m) => {
              const on = Number(m.status) === 1
              return (
                <li key={m.id} className={`cmd-row${on ? '' : ' is-off'}`}>
                  <div className="cmd-main">
                    <div className="cmd-name"><span className="pill">#{m.id}</span><span className="pill pill-accent"><i className={m.trigger_type === 'scheduled' ? 'fas fa-calendar-day' : m.trigger_type === 'chat_lines' ? 'fas fa-comments' : 'fas fa-clock'} /> {triggerText(m)}</span></div>
                    <p className="cmd-resp">{m.message}</p>
                  </div>
                  <div className="cmd-side">
                    <Switch on={on} busy={busy === String(m.id)} label={`Message #${m.id} ${on ? 'enabled' : 'disabled'}`} onChange={() => toggle(m)} />
                    <button type="button" className="icon-btn" aria-label={`Edit message #${m.id}`} title="Edit" onClick={() => setEdit(m)}><i className="fas fa-pencil" /></button>
                    <button type="button" className="icon-btn icon-err" aria-label={`Delete message #${m.id}`} title="Delete" onClick={() => setDel(m)}><i className="fas fa-trash" /></button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>
      {edit && <Modal title={`Edit message #${edit.id}`} icon="fas fa-pencil" onClose={() => setEdit(null)} wide><TimedForm edit={edit} tzText={tzText} onSave={async (b) => { const ok = await act(b); if (ok) setEdit(null); return ok }} /></Modal>}
      {del && (
        <Modal title="Delete message?" icon="fas fa-trash" onClose={() => setDel(null)}>
          <p>This permanently removes message <b>#{del.id}</b>:</p>
          <blockquote className="tm-quote">{del.message}</blockquote>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => { act({ remove_message: String(del.id) }); setDel(null) }}><i className="fas fa-trash" /> Delete message</button></div>
        </Modal>
      )}
    </div>
  )
}

function TimedForm({ edit, tzText, onSave }: { edit?: Msg; tzText: string; onSave: (b: Record<string, string>) => Promise<boolean> }) {
  const [msg, setMsg] = useState(edit?.message ?? '')
  const [trig, setTrig] = useState<Trigger>(edit?.trigger_type || 'timer')
  const [iv, setIv] = useState(String(edit?.interval_count || 5))
  const [lines, setLines] = useState(String(edit?.chat_line_trigger || 5))
  const [at, setAt] = useState(edit?.scheduled_time ? String(edit.scheduled_time).slice(0, 5) : '')
  const [on, setOn] = useState(edit ? Number(edit.status) === 1 : true)
  const [busy, setBusy] = useState(false)
  const so = hasShoutout(msg)
  const min = so ? 60 : 5
  // Same as the live page: adding a shoutout variable lifts the interval to 60.
  useEffect(() => { if (so && Number(iv) < 60) setIv('60') }, [so]) // eslint-disable-line react-hooks/exhaustive-deps
  const ivOk = /^\d+$/.test(iv) && Number(iv) >= min && Number(iv) <= 480
  const linesOk = /^\d+$/.test(lines) && Number(lines) >= 5
  const atOk = /^([01]\d|2[0-3]):[0-5]\d$/.test(at)
  const valid = msg.trim().length > 0 && msg.length <= MAX && (!usesTimer(trig) || ivOk) && (!usesLines(trig) || linesOk) && (trig !== 'scheduled' || atOk)
  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!valid) return
    setBusy(true)
    const ok = await onSave(edit
      ? { edit_message: String(edit.id), edit_message_content: msg, edit_trigger_type: trig, edit_interval: iv, edit_chat_line_trigger: lines, edit_scheduled_time: at, edit_status: on ? 'True' : 'False' }
      : { message: msg, trigger_type: trig, interval: iv, chat_line_trigger: lines, scheduled_time: at })
    if (ok && !edit) { setMsg(''); setTrig('timer'); setIv('5'); setLines('5'); setAt('') }
    setBusy(false)
  }
  const id = edit ? 'te' : 'ta'
  return (
    <form className="cmd-form" onSubmit={submit}>
      <div className="fld fld-wide"><label htmlFor={`${id}-m`}>Message</label><textarea id={`${id}-m`} className="input" rows={2} required placeholder="What the bot posts in chat" value={msg} onChange={(e) => setMsg(e.target.value)} /><Counter n={msg.length} max={MAX} /></div>
      <div className="fld"><label>Trigger</label><Combobox label="Trigger" searchable={false} options={TRIGGERS} value={trig} onChange={(v) => setTrig(v as Trigger)} /></div>
      {usesTimer(trig) && <div className="fld"><label htmlFor={`${id}-i`}>Interval (minutes, {min} to 480)</label><input id={`${id}-i`} className="input" type="number" inputMode="numeric" min={min} max={480} required value={iv} onChange={(e) => setIv(e.target.value)} />{!ivOk && <small className="counter over">Pick {min} to 480 minutes.</small>}{so && <small className="tm-warn"><i className="fas fa-triangle-exclamation" /> The shoutout variable raises the minimum to 60.</small>}</div>}
      {usesLines(trig) && <div className="fld"><label htmlFor={`${id}-l`}>Chat lines (5 or more)</label><input id={`${id}-l`} className="input" type="number" inputMode="numeric" min={5} required value={lines} onChange={(e) => setLines(e.target.value)} />{!linesOk && <small className="counter over">Enter 5 or more chat lines.</small>}</div>}
      {trig === 'scheduled' && <div className="fld"><label htmlFor={`${id}-t`}>Send at{tzText && <span className="muted"> ({tzText})</span>}</label><input id={`${id}-t`} className="input" type="time" required value={at} onChange={(e) => setAt(e.target.value)} /></div>}
      {edit && <div className="fld tm-status"><span className="lbl">Status</span><Switch on={on} label="Enabled" onChange={setOn} /><span>{on ? 'Enabled' : 'Disabled'}</span></div>}
      <div className="modal-acts fld-wide"><button className="btn" disabled={busy || !valid}><i className={edit ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {busy ? 'Saving' : edit ? 'Save changes' : 'Add message'}</button></div>
    </form>
  )
}
