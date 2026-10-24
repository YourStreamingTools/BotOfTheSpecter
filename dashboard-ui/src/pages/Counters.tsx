import { useEffect, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/counters.php: Counters & Management card with View Data /
// Edit Data tabs. View has the 14 data buttons and the titled table; Edit has
// the 8 edit tabs, each with its Edit and Remove cards (Deaths shows the total,
// Quotes has Add / Edit / Remove). Endpoint: public/api/counters. No timers.
const API = '/api/counters/counters.php'
type Row = Record<string, string | number | string[] | null>
type Data = { success: boolean; error?: string; totalDeaths: number } & Record<string, Row[]>
type VT = { key: string; label: string; title: string; cols: string[]; cells: (r: Row) => React.ReactNode[] }
const n = (v: unknown) => Number(v ?? 0)
const UNITS: [string, number][] = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]]
function watch(s: unknown) {
  let x = n(s); if (!x) return <span className="mp-bad">Not Recorded</span>
  const parts: string[] = []
  for (const [u, d] of UNITS) { const q = Math.floor(x / d); if (q > 0) { parts.push(`${q} ${u}${q > 1 ? 's' : ''}`); x -= q * d } }
  return <span className="ct-val">{parts.join(', ') || 'Less than a minute'}</span>
}
const V = (v: unknown) => <span className="ct-val">{String(v ?? '')}</span>
function Options({ items }: { items: string[] }) {
  const [open, setOpen] = useState(false)
  if (!items.length) return <span className="muted">No options saved</span>
  if (items.length <= 5) return <>{items.map((o, i) => <div key={i}>{o}</div>)}</>
  return <div><button type="button" className="ct-more" aria-expanded={open} onClick={() => setOpen(!open)}>{items.slice(0, 3).join(', ')}, ... <span className="muted">({open ? 'hide list' : 'view all'} {items.length})</span></button>{open && <div className="ct-all">{items.map((o, i) => <div key={i}>{o}</div>)}</div>}</div>
}
const VIEWS: VT[] = [
  { key: 'lurkers', label: 'Lurkers', title: 'Lurkers', cols: ['Username', 'Time'], cells: (r) => [r.username, V(r.lurk_duration)] },
  { key: 'typos', label: 'User Typos', title: 'User Typos', cols: ['Username', 'Typo Count'], cells: (r) => [r.username, V(r.typo_count)] },
  { key: 'deaths', label: 'Deaths Overview', title: 'Deaths Overview', cols: ['Game', 'Count'], cells: (r) => [r.game_name, V(r.death_count)] },
  { key: 'hugs', label: 'Hug Counts', title: 'Hug Counts', cols: ['Username', 'Count'], cells: (r) => [r.username, V(r.hug_count)] },
  { key: 'kisses', label: 'Kiss Counts', title: 'Kiss Counts', cols: ['Username', 'Count'], cells: (r) => [r.username, V(r.kiss_count)] },
  { key: 'highfives', label: 'High-Five Counts', title: 'High-Five Counts', cols: ['Username', 'Count'], cells: (r) => [r.username, V(r.highfive_count)] },
  { key: 'customCounts', label: 'Custom Counts', title: 'Custom Counts', cols: ['Command', 'Used'], cells: (r) => [r.command, V(r.count)] },
  { key: 'userCounts', label: 'User Counts', title: 'User Counts', cols: ['Username', 'Command', 'Count'], cells: (r) => [r.user, V(r.command), V(r.count)] },
  { key: 'rewardCounts', label: 'Reward Counts', title: 'Reward Counts', cols: ['Reward Name', 'Username', 'Count'], cells: (r) => [r.reward_title, r.user, V(r.count)] },
  { key: 'rewardStreaks', label: 'Reward Streaks', title: 'Reward Streaks', cols: ['Reward', 'Username', 'Streak'], cells: (r) => [r.reward_title, r.current_user, V(r.streak)] },
  { key: 'rewardUsage', label: 'Reward Usage', title: 'Reward Usage', cols: ['Reward Name', 'Usage Count'], cells: (r) => [r.reward_title, V(r.usage_count)] },
  { key: 'watchTime', label: 'Watch Time', title: 'Watch Time', cols: ['Username', 'Online Watch Time', 'Offline Watch Time'], cells: (r) => [r.username, watch(r.total_watch_time_live), watch(r.total_watch_time_offline)] },
  { key: 'quotes', label: 'Quotes', title: 'Quotes', cols: ['ID', 'What was said'], cells: (r) => [r.id, V(r.quote)] },
  { key: 'manyOptions', label: 'Random Pick Lists', title: 'Random Pick Lists', cols: ['Command', 'Items', 'Total'], cells: (r) => [`!${r.command}`, <Options items={(r.items as string[]) || []} />, V(r.items_count)] },
]
// Edit tabs: list key, the live form field names, labels.
type ET = { key: string; label: string; list: string; name: string; count: string; pick: string; what: string; noun: string; sel: string; f: string; c: string; r: string }
const EDITS: ET[] = [
  { key: 'typos', label: 'User Typos', list: 'typos', name: 'username', count: 'typo_count', pick: 'Username', what: 'User Typos', noun: 'typo', sel: 'Select a user', f: 'typo-username', c: 'typo_count', r: 'typo-username-remove' },
  { key: 'customCounts', label: 'Custom Counts', list: 'customCounts', name: 'command', count: 'count', pick: 'Command', what: 'Custom Counter', noun: 'command', sel: 'Select a command', f: 'command', c: 'command_count', r: 'command-remove' },
  { key: 'deaths', label: 'Deaths', list: 'deaths', name: 'game_name', count: 'death_count', pick: 'Game', what: 'Game Deaths', noun: 'death', sel: 'Select a game', f: 'death-game', c: 'death_count', r: 'death-game-remove' },
  { key: 'hugs', label: 'Hug Counts', list: 'hugs', name: 'username', count: 'hug_count', pick: 'Username', what: 'User Hugs', noun: 'hug', sel: 'Select a user', f: 'hug-username', c: 'hug_count', r: 'hug-username-remove' },
  { key: 'kisses', label: 'Kiss Counts', list: 'kisses', name: 'username', count: 'kiss_count', pick: 'Username', what: 'User Kisses', noun: 'kiss', sel: 'Select a user', f: 'kiss-username', c: 'kiss_count', r: 'kiss-username-remove' },
  { key: 'highfives', label: 'High-Five Counts', list: 'highfives', name: 'username', count: 'highfive_count', pick: 'Username', what: 'User High-Fives', noun: 'high-five', sel: 'Select a user', f: 'highfive-username', c: 'highfive_count', r: 'highfive-username-remove' },
]
const cOk = (v: string) => /^\d{1,9}$/.test(v)
const qLabel = (q: Row) => { const s = String(q.quote ?? ''); return `#${q.id}: ${s.length > 60 ? s.slice(0, 60) + '...' : s}` }

export default function Counters() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(''), [mode, setMode] = useState<'view' | 'edit'>('view'), [view, setView] = useState('lurkers'), [tab, setTab] = useState('typos')
  const [f, setF] = useState<Record<string, string>>({}), [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null)
  const [confirm, setConfirm] = useState<{ text: string; body: Record<string, string> } | null>(null)
  const load = () => get<Data>(`${API}?ajax_action=list`, 30000).then((r) => { if (!r.success) throw new Error(r.error); setD(r); setErr('') }).catch((x) => setErr((x as Error).message || 'Could not load counters.'))
  useEffect(() => { load() }, [])
  const set = (p: Record<string, string>) => setF({ ...f, ...p })
  async function act(k: string, body: Record<string, string>, reset: string[]) {
    setBusy(k)
    try { const r = await send<{ success: boolean; error?: string; message?: string }>(API, body); if (!r.success) throw new Error(r.error || 'Something went wrong. Please try again.'); setToast({ kind: 'ok', text: r.message || 'Saved.' }); setF((o) => { const c = { ...o }; reset.forEach((x) => delete c[x]); return c }); await load(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  const vt = VIEWS.find((v) => v.key === view)!
  let rows: Row[] = d ? (d[view] as Row[]) || [] : []
  if (view === 'watchTime') rows = [...rows].sort((a, b) => n(b.total_watch_time_live) - n(a.total_watch_time_live) || n(b.total_watch_time_offline) - n(a.total_watch_time_offline))
  const pickCard = (e: ET) => { const list = (d?.[e.list] as Row[]) || [], opts = list.map((r) => ({ value: String(r[e.name]), label: String(r[e.name]) })), cur = list.find((r) => String(r[e.name]) === f[e.f])
    return <div className="ct-two">
      <section className="card bg-inner"><h4 className="card-h">Edit {e.what}</h4>
        <form className="cr-form" onSubmit={(x) => { x.preventDefault(); act('u' + e.key, { action: 'update', [e.f]: f[e.f], [e.c]: f[e.c] }, [e.f, e.c]) }}>
          <div className="fld"><span className="fld-label">{e.pick}</span><Combobox label={e.pick} value={f[e.f] ?? ''} options={opts} onChange={(v) => { const r = list.find((x) => String(x[e.name]) === v); set({ [e.f]: v, [e.c]: r ? String(r[e.count] ?? '') : '' }) }} /></div>
          <label className="fld"><span className="fld-label">New {e.noun} count {cur && <span className="muted small">now {String(cur[e.count])}</span>}</span><input className="input" type="number" inputMode="numeric" min={0} value={f[e.c] ?? ''} aria-invalid={!!f[e.c] && !cOk(f[e.c])} onChange={(x) => set({ [e.c]: x.target.value })} /></label>
          <div><button type="submit" className="btn" disabled={!f[e.f] || !cOk(f[e.c] ?? '') || busy === 'u' + e.key}><i className={busy === 'u' + e.key ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Update {e.noun} count</button></div>
        </form></section>
      <section className="card bg-inner"><h4 className="card-h">Remove {e.what}</h4>
        <div className="cr-form"><div className="fld"><span className="fld-label">{e.pick}</span><Combobox label={e.pick} value={f[e.r] ?? ''} options={opts} onChange={(v) => set({ [e.r]: v })} /></div>
          <div><button type="button" className="btn btn-danger" disabled={!f[e.r]} onClick={() => setConfirm({ text: `Remove the ${e.noun} record for ${f[e.r]}?`, body: { action: 'remove', [e.r]: f[e.r] } })}><i className="fas fa-trash" /> Remove {e.noun}</button></div></div></section>
    </div> }
  const uc = (d?.userCounts as Row[]) || [], ucCmds = [...new Set(uc.map((r) => String(r.command)))].map((v) => ({ value: v, label: v }))
  const ucUsers = (cmd: string) => uc.filter((r) => String(r.command) === cmd).map((r) => ({ value: String(r.user), label: String(r.user) }))
  const quotes = (d?.quotes as Row[]) || [], qOpts = quotes.map((q) => ({ value: String(q.id), label: qLabel(q) }))
  const qBad = (s: string) => !s.trim() || s.length > 1000
  return (
    <div className="page cmds mp ct">
      <div className="pg-head"><h2><i className="fas fa-stopwatch" /> Counters &amp; Management</h2></div>
      <section className="card" aria-label="Counters">
        <div className="seg mp-tabs" role="tablist" aria-label="Mode">
          <button role="tab" aria-selected={mode === 'view'} className={mode === 'view' ? 'on' : ''} onClick={() => setMode('view')}><i className="fas fa-eye" /> View Data</button>
          <button role="tab" aria-selected={mode === 'edit'} className={mode === 'edit' ? 'on' : ''} onClick={() => setMode('edit')}><i className="fas fa-edit" /> Edit Data</button>
        </div>
        {err && <p className="mp-bad">{err}</p>}
        {mode === 'view' ? <>
          <div className="ct-btns" role="group" aria-label="Data">{VIEWS.map((v) => <button key={v.key} type="button" className={`btn ${view === v.key ? '' : 'btn-ghost'}`} aria-pressed={view === v.key} onClick={() => setView(v.key)}>{v.label}</button>)}</div>
          <h3 className="ct-title">{vt.title}</h3>
          <div className="bp-table ct-table"><table>
            <thead><tr>{vt.cols.map((c) => <th key={c}>{c}</th>)}</tr></thead>
            <tbody aria-busy={!d && !err}>{!d ? !err && Array.from({ length: 5 }, (_, i) => <tr key={i} aria-hidden="true">{vt.cols.map((_, j) => <td key={j}><span className="bp-skel" /></td>)}</tr>)
              : rows.length === 0 ? <tr><td colSpan={vt.cols.length} className="muted bp-empty">Nothing recorded yet.</td></tr>
              : rows.map((r, i) => <tr key={i}>{vt.cells(r).map((c, j) => <td key={j} data-l={vt.cols[j]}>{c as React.ReactNode}</td>)}</tr>)}</tbody></table></div>
        </> : <>
          <div className="ct-btns" role="group" aria-label="Edit">{[...EDITS.map((e) => [e.key, e.label]), ['userCounts', 'User Counts'], ['quotes', 'Quotes']].map(([k, l]) => <button key={k} type="button" className={`btn ${tab === k ? '' : 'btn-ghost'}`} aria-pressed={tab === k} onClick={() => setTab(k)}>{l}</button>)}</div>
          {!d ? <p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p> : <>
            {tab === 'deaths' && <div className="notice notice-info ct-total"><span><b>Total Deaths:</b> <span className="ct-deaths">{d.totalDeaths}</span></span></div>}
            {EDITS.filter((e) => e.key === tab).map((e) => <div key={e.key}>{pickCard(e)}</div>)}
            {tab === 'userCounts' && <div className="ct-two">
              <section className="card bg-inner"><h4 className="card-h">Edit User Counts</h4>
                <form className="cr-form" onSubmit={(x) => { x.preventDefault(); act('uuc', { action: 'update', 'usercount-command': f.ucc, 'usercount-user': f.ucu, usercount_count: f.ucn }, ['ucc', 'ucu', 'ucn']) }}>
                  <div className="fld"><span className="fld-label">Command</span><Combobox label="Command" value={f.ucc ?? ''} options={ucCmds} onChange={(v) => set({ ucc: v, ucu: '', ucn: '' })} /></div>
                  <div className="fld"><span className="fld-label">Username</span><Combobox label="Username" value={f.ucu ?? ''} options={ucUsers(f.ucc ?? '')} onChange={(v) => { const r = uc.find((x) => String(x.command) === f.ucc && String(x.user) === v); set({ ucu: v, ucn: r ? String(r.count) : '' }) }} /></div>
                  <label className="fld"><span className="fld-label">New Count</span><input className="input" type="number" inputMode="numeric" min={0} value={f.ucn ?? ''} onChange={(x) => set({ ucn: x.target.value })} /></label>
                  <div><button type="submit" className="btn" disabled={!f.ucc || !f.ucu || !cOk(f.ucn ?? '') || busy === 'uuc'}><i className={busy === 'uuc' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Update</button></div>
                </form></section>
              <section className="card bg-inner"><h4 className="card-h">Remove User Count</h4>
                <div className="cr-form">
                  <div className="fld"><span className="fld-label">Command</span><Combobox label="Command" value={f.ucrc ?? ''} options={ucCmds} onChange={(v) => set({ ucrc: v, ucru: '' })} /></div>
                  <div className="fld"><span className="fld-label">Username</span><Combobox label="Username" value={f.ucru ?? ''} options={ucUsers(f.ucrc ?? '')} onChange={(v) => set({ ucru: v })} /></div>
                  <div><button type="button" className="btn btn-danger" disabled={!f.ucrc || !f.ucru} onClick={() => setConfirm({ text: `Remove the ${f.ucrc} count for ${f.ucru}?`, body: { action: 'remove', 'usercount-command-remove': f.ucrc, 'usercount-user-remove': f.ucru } })}><i className="fas fa-trash" /> Remove</button></div></div></section>
            </div>}
            {tab === 'quotes' && <>
              <section className="card bg-inner ct-add"><h4 className="card-h">Add Quote</h4>
                <form className="cr-form" onSubmit={(x) => { x.preventDefault(); act('qa', { action: 'add_quote', quote_text: (f.qa ?? '').trim() }, ['qa']) }}>
                  <label className="fld"><span className="fld-label">Quote Text</span><textarea className="input" rows={3} maxLength={1000} placeholder="Enter the quote text..." value={f.qa ?? ''} onChange={(x) => set({ qa: x.target.value.replace(/[\r\n]/g, ' ') })} /></label>
                  <div><button type="submit" className="btn btn-ok" disabled={qBad(f.qa ?? '') || busy === 'qa'}><i className={busy === 'qa' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add Quote</button></div>
                </form></section>
              <div className="ct-two">
                <section className="card bg-inner"><h4 className="card-h">Edit Quote</h4>
                  <form className="cr-form" onSubmit={(x) => { x.preventDefault(); act('qe', { action: 'update_quote', quote_id: f.qe, quote_text: (f.qt ?? '').trim() }, ['qe', 'qt']) }}>
                    <div className="fld"><span className="fld-label">Select Quote</span><Combobox label="Select Quote" value={f.qe ?? ''} options={qOpts} onChange={(v) => set({ qe: v, qt: String(quotes.find((q) => String(q.id) === v)?.quote ?? '') })} /></div>
                    <label className="fld"><span className="fld-label">Quote Text</span><textarea className="input" rows={3} maxLength={1000} value={f.qt ?? ''} onChange={(x) => set({ qt: x.target.value.replace(/[\r\n]/g, ' ') })} /></label>
                    <div><button type="submit" className="btn" disabled={!f.qe || qBad(f.qt ?? '') || busy === 'qe'}><i className={busy === 'qe' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Update Quote</button></div>
                  </form></section>
                <section className="card bg-inner"><h4 className="card-h">Remove Quote</h4>
                  <div className="cr-form"><div className="fld"><span className="fld-label">Select Quote</span><Combobox label="Select Quote" value={f.qr ?? ''} options={qOpts} onChange={(v) => set({ qr: v })} /></div>
                    <div><button type="button" className="btn btn-danger" disabled={!f.qr} onClick={() => setConfirm({ text: `Remove quote #${f.qr}?`, body: { action: 'remove_quote', quote_id: f.qr } })}><i className="fas fa-trash" /> Remove Quote</button></div></div></section>
              </div>
            </>}
          </>}
        </>}
      </section>
      {confirm && <Modal title="Are you sure?" icon="fas fa-triangle-exclamation" onClose={() => setConfirm(null)}>
        <p>{confirm.text} This can't be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setConfirm(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'rm'} onClick={() => { setConfirm(null); act('rm', confirm.body, ['qr', 'ucrc', 'ucru', ...EDITS.map((e) => e.r)]) }}><i className="fas fa-trash" /> Yes, remove it</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
