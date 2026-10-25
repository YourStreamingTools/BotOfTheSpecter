import { useEffect, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { ToastBar, type Toast } from './commands/shared'

// Port of dashboard/polls.php: Start a Twitch Poll card (question, 2 to 5 choices,
// duration, optional Channel Points voting), the Live Poll card with End Poll Now,
// and the Poll History table from poll_results. Endpoint: public/api/polls.
const API = '/api/polls/polls.php'
type Choice = { title: string; votes: number }
type Active = { id: string; title: string; choices: Choice[]; started_at: string; ends_at: string; channel_points_voting_enabled: boolean }
type Poll = { id: string; title: string; choices: Choice[]; channel_points_used: number; started_at: string | null; ended_at: string | null }
type ListRes = { success: boolean; error?: string; active: Active | null; polls: Poll[]; twitch_error: string | null }
type Res = { success: boolean; error?: string; message?: string }
const DURATIONS = [15, 30, 60, 120, 180, 300, 600, 900, 1800].map((d) => ({ value: String(d), label: d < 60 ? `${d} seconds` : d === 60 ? '1 minute' : `${d / 60} minutes` }))
const ctrl = /[\x00-\x1F\x7F]/
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '-')

async function postForm(body: URLSearchParams): Promise<Res> {
  const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
  if (r.status === 401) throw new Error('Your session expired. Sign in again.')
  return (await r.json()) as Res
}

function Results({ choices }: { choices: Choice[] }) {
  const total = choices.reduce((s, c) => s + c.votes, 0)
  return <div className="pl-res">{choices.map((c, i) => { const pct = total ? Math.round((c.votes * 100) / total) : 0
    return <div key={i} className="pl-row"><span className="pl-name">{c.title}</span><span className="muted pl-votes">{c.votes} votes ({pct}%)</span><span className="pl-bar" style={{ width: `${pct}%` }} aria-hidden="true" /></div> })}</div>
}

export default function Polls() {
  const [data, setData] = useState<ListRes | null>(null), [listErr, setListErr] = useState('')
  const [title, setTitle] = useState(''), [choices, setChoices] = useState(['', '']), [dur, setDur] = useState('120')
  const [cp, setCp] = useState(false), [cpCost, setCpCost] = useState('100')
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null), [touched, setTouched] = useState(false), [ending, setEnding] = useState<Active | null>(null)
  const load = () => get<ListRes>(`${API}?ajax_action=list`).then((r) => { if (!r.success) throw new Error(r.error); setData(r); setListErr('') }).catch((x) => setListErr((x as Error).message || 'Could not load polls.'))
  useEffect(() => { load() }, [])
  const filled = choices.map((c) => c.trim()).filter(Boolean)
  const bad = !title.trim() || title.trim().length > 60 || ctrl.test(title) ? 'Enter a question of 1 to 60 characters.'
    : filled.length < 2 || filled.some((c) => c.length > 25 || ctrl.test(c)) ? 'Add 2 to 5 choices, each 1 to 25 characters.'
    : cp && !(/^\d{1,7}$/.test(cpCost) && +cpCost >= 1 && +cpCost <= 1000000) ? 'Channel Points per vote must be 1 to 1,000,000.' : ''
  async function act(k: string, body: URLSearchParams) {
    setBusy(k)
    try { const r = await postForm(body); if (!r.success) throw new Error(r.error || 'Something went wrong. Please try again.'); setToast({ kind: 'ok', text: r.message || 'Done.' }); await load(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault(); setTouched(true); if (bad) return
    const b = new URLSearchParams({ action: 'create', title: title.trim(), duration: dur })
    filled.forEach((c) => b.append('choices[]', c))
    if (cp) { b.set('channel_points', '1'); b.set('channel_points_per_vote', cpCost) }
    if (await act('start', b)) { setTitle(''); setChoices(['', '']); setTouched(false) }
  }
  const setChoice = (i: number, v: string) => { setChoices(choices.map((c, j) => (j === i ? v : c))); setTouched(true) }
  const active = data?.active ?? null
  return (
    <div className="page cmds mp rf pl">
      <div className="pg-head"><h2><i className="fas fa-poll" /> Twitch Polls</h2></div>
      <section className="card" aria-label="Start a Twitch Poll">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-poll" /> Start a Twitch Poll</h3></div>
        <p className="muted">Specter starts the poll on your Twitch channel and saves the results below. While the bot is running it also announces the poll in chat.</p>
        <form className="cr-form" onSubmit={submit}>
          <label className="fld"><span className="fld-label">Question</span><input className="input" maxLength={60} value={title} onChange={(e) => { setTitle(e.target.value.replace(/[\r\n]/g, '')); setTouched(true) }} /></label>
          <div className="fld"><span className="fld-label">Choices (2 to 5)</span>
            <div className="pl-choices">{choices.map((c, i) => <div key={i} className="pl-choice">
              <input className="input" maxLength={25} placeholder={`Choice ${i + 1}`} aria-label={`Choice ${i + 1}`} value={c} onChange={(e) => setChoice(i, e.target.value.replace(/[\r\n]/g, ''))} />
              <button type="button" className="btn btn-ghost" aria-label={`Remove choice ${i + 1}`} disabled={choices.length <= 2} onClick={() => setChoices(choices.filter((_, j) => j !== i))}><i className="fas fa-times" /></button></div>)}</div>
            <div><button type="button" className="btn btn-ghost" disabled={choices.length >= 5} onClick={() => setChoices([...choices, ''])}><i className="fas fa-plus" /> Add choice</button></div></div>
          <div className="fld rf-num"><span className="fld-label">Duration</span><Combobox label="Duration" searchable={false} value={dur} onChange={setDur} options={DURATIONS} /></div>
          <label className="cr-chk"><input type="checkbox" checked={cp} onChange={(e) => { setCp(e.target.checked); setTouched(true) }} /> <span>Allow extra votes with Channel Points</span></label>
          {cp && <div className="rf-sub"><label className="fld rf-num"><span className="fld-label">Channel Points per extra vote</span><input className="input" type="number" inputMode="numeric" min={1} max={1000000} value={cpCost} onChange={(e) => { setCpCost(e.target.value); setTouched(true) }} /></label></div>}
          {touched && bad && <p className="mp-bad small" role="alert">{bad}</p>}
          <div className="rf-acts"><button className="btn" type="submit" disabled={busy === 'start' || (touched && !!bad)}><i className={busy === 'start' ? 'fas fa-spinner fa-spin' : 'fas fa-play'} /> Start Poll</button></div>
        </form>
      </section>
      <section className="card" aria-label="Live Poll" aria-busy={!data && !listErr}>
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-broadcast-tower" /> Live Poll{active && <span className="pill pill-live"><span className="dot" /> Live</span>}</h3></div>
        {listErr ? <p className="mp-bad">{listErr}</p>
          : !data ? <span className="bp-skel" style={{ width: '60%' }} />
          : data.twitch_error ? <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{data.twitch_error}</span></div>
          : !active ? <p className="muted">No poll is running right now.</p>
          : <><p><b>{active.title}</b></p><Results choices={active.choices} /><p className="muted small">Ends at {when(active.ends_at)}</p>
            <div className="rf-acts"><button type="button" className="btn btn-danger" disabled={busy === 'end'} onClick={() => setEnding(active)}><i className={busy === 'end' ? 'fas fa-spinner fa-spin' : 'fas fa-stop'} /> End Poll Now</button></div></>}
      </section>
      <section className="card" aria-label="Poll History">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-history" /> Poll History</h3></div>
        <div className="bp-table"><table>
          <thead><tr><th>Poll</th><th>Results</th><th>Channel Points</th><th>Started</th><th>Ended</th></tr></thead>
          <tbody aria-busy={!data && !listErr}>{listErr ? <tr><td colSpan={5} className="mp-bad bp-empty">{listErr}</td></tr>
            : !data ? Array.from({ length: 3 }, (_, i) => <tr key={i} aria-hidden="true">{[70, 80, 30, 50, 50].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
            : data.polls.length === 0 ? <tr><td colSpan={5} className="muted bp-empty">No polls recorded yet.</td></tr>
            : data.polls.map((p) => <tr key={p.id}>
              <td data-l="Poll" className="cr-name">{p.title}</td>
              <td data-l="Results" className="pl-cell"><Results choices={p.choices} /></td>
              <td data-l="Channel Points" className="bp-pts">{p.channel_points_used}</td>
              <td data-l="Started">{when(p.started_at)}</td>
              <td data-l="Ended">{p.ended_at ? when(p.ended_at) : p.id === active?.id ? <span className="pill pill-live"><span className="dot" /> Live</span> : <span className="muted">-</span>}</td>
            </tr>)}</tbody></table></div>
      </section>
      {ending && <Modal title="End poll" icon="fas fa-stop" onClose={() => setEnding(null)}>
        <p>End <b>{ending.title}</b> now? The current results become final.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setEnding(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => { const id = ending.id; setEnding(null); act('end', new URLSearchParams({ action: 'end', poll_id: id })) }}><i className="fas fa-stop" /> End Poll</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
