import { useEffect, useRef, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/raffles.php: Create / Edit Raffle card (name, prize, winners,
// weighted with the four multipliers, exclusions, followers with minimum follow
// time) and the Active Raffles table (ID, Name, Prize, # Winners, Status, Weights,
// Exclusions, Winner(s), Action with Start / Edit / Draw / Delete).
// Endpoint: public/api/raffles. No timers.
const API = '/api/raffles/raffles.php'
type Raffle = { id: number; name: string; prize: string; number_of_winners: number; status: string; is_weighted: number; weight_sub_t1: string; weight_sub_t2: string; weight_sub_t3: string; weight_vip: string; exclude_mods: number; subscribers_only: number; followers_only: number; followers_min_enabled: number; followers_min_value: number; followers_min_unit: string; winners: string[] }
type Res = { success: boolean; error?: string; message?: string }
type F = { name: string; prize: string; n: string; weighted: boolean; t1: string; t2: string; t3: string; vip: string; mods: boolean; subs: boolean; fol: boolean; fmin: boolean; fval: string; funit: string }
const blank: F = { name: '', prize: '', n: '1', weighted: false, t1: '2.00', t2: '3.00', t3: '4.00', vip: '1.50', mods: false, subs: false, fol: false, fmin: false, fval: '0', funit: 'days' }
const UNITS = [['days', 'Days'], ['weeks', 'Weeks'], ['months', 'Months'], ['years', 'Years']].map(([value, label]) => ({ value, label }))
const wOk = (v: string) => /^\d{1,3}(\.\d{1,2})?$/.test(v.trim()) && +v >= 1 && +v <= 100
const fromRaffle = (r: Raffle): F => ({ name: r.name, prize: r.prize || '', n: String(r.number_of_winners), weighted: +r.is_weighted === 1, t1: String(r.weight_sub_t1), t2: String(r.weight_sub_t2), t3: String(r.weight_sub_t3), vip: String(r.weight_vip), mods: +r.exclude_mods === 1, subs: +r.subscribers_only === 1, fol: +r.followers_only === 1, fmin: +r.followers_min_enabled === 1, fval: String(r.followers_min_value ?? 0), funit: r.followers_min_unit || 'days' })
function excl(r: Raffle) {
  const x: string[] = []
  if (+r.exclude_mods) x.push('Mods excluded')
  if (+r.subscribers_only) x.push('Subs only')
  if (+r.followers_only) x.push('Followers only' + (+r.followers_min_enabled === 1 && +r.followers_min_value > 0 ? ` (${r.followers_min_value} ${r.followers_min_unit} min)` : ''))
  return x
}

export default function Raffles() {
  const [list, setList] = useState<Raffle[] | null>(null), [listErr, setListErr] = useState('')
  const [f, setF] = useState<F>(blank), [editId, setEditId] = useState<number | null>(null)
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null), [touched, setTouched] = useState(false)
  const [del, setDel] = useState<Raffle | null>(null), [draw, setDraw] = useState<Raffle | null>(null)
  const formRef = useRef<HTMLElement>(null)
  const load = () => get<{ success: boolean; raffles: Raffle[]; error?: string }>(`${API}?ajax_action=list`).then((r) => { if (!r.success) throw new Error(r.error); setList(r.raffles); setListErr('') }).catch((x) => setListErr((x as Error).message || 'Could not load raffles.'))
  useEffect(() => { load() }, [])
  const set = (p: Partial<F>) => { setF({ ...f, ...p }); setTouched(true) }
  const bad = !f.name.trim() ? 'Add a raffle name.' : f.name.trim().length > 255 ? 'Raffle name must be 255 characters or fewer.' : !f.prize.trim() ? 'Add a prize description.' : f.prize.length > 1000 ? 'Prize must be 1000 characters or fewer.'
    : !(/^\d+$/.test(f.n) && +f.n >= 1 && +f.n <= 100) ? 'Number of winners must be 1 to 100.'
    : f.weighted && ![f.t1, f.t2, f.t3, f.vip].every(wOk) ? 'Weights must be 1 to 100, up to two decimals.'
    : f.fol && f.fmin && !(/^\d+$/.test(f.fval) && +f.fval >= 1 && +f.fval <= 1000) ? 'Minimum follow time must be 1 to 1000.' : ''
  async function act(k: string, body: Record<string, string>) {
    setBusy(k)
    try { const r = await send<Res>(API, body); if (!r.success) throw new Error(r.error || 'Something went wrong. Please try again.'); setToast({ kind: 'ok', text: r.message || 'Saved.' }); await load(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault(); setTouched(true); if (bad) return
    const on = (b: boolean, k: string) => (b ? { [k]: '1' } : {})
    const body: Record<string, string> = { action: editId ? 'edit' : 'create', ...(editId ? { raffle_id: String(editId) } : {}), name: f.name.trim(), prize: f.prize.trim(), number_of_winners: f.n,
      ...on(f.weighted, 'weighted'), ...(f.weighted ? { weight_sub_t1: f.t1, weight_sub_t2: f.t2, weight_sub_t3: f.t3, weight_vip: f.vip } : {}), ...on(f.mods, 'exclude_mods'), ...on(f.subs, 'subscribers_only'), ...on(f.fol, 'followers_only'),
      ...on(f.fol && f.fmin, 'followers_min_enabled'), ...(f.fol && f.fmin ? { followers_min_value: f.fval, followers_min_unit: f.funit } : {}) }
    if (await act('save', body)) { setF(blank); setEditId(null); setTouched(false) }
  }
  const startEdit = (r: Raffle) => { setF(fromRaffle(r)); setEditId(r.id); setTouched(false); formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
  const cancelEdit = () => { setF(blank); setEditId(null); setTouched(false) }
  const chk = (k: keyof F, label: string) => <label className="cr-chk"><input type="checkbox" checked={f[k] as boolean} onChange={(e) => set({ [k]: e.target.checked, ...(k === 'fol' && !e.target.checked ? { fmin: false } : {}) })} /> <span>{label}</span></label>
  return (
    <div className="page cmds mp rf">
      <div className="pg-head"><h2><i className="fas fa-ticket-alt" /> Raffles</h2></div>
      <section className="card" ref={formRef} aria-label={editId ? 'Edit Raffle' : 'Create New Raffle'}>
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-ticket-alt" /> {editId ? 'Edit Raffle' : 'Create New Raffle'}{editId && <span className="pill pill-warn">Editing #{editId}</span>}</h3></div>
        <form className="cr-form" onSubmit={submit}>
          <label className="fld"><span className="fld-label">Raffle Name</span><input className="input" maxLength={255} value={f.name} onChange={(e) => set({ name: e.target.value.replace(/[\r\n]/g, '') })} /></label>
          <label className="fld"><span className="fld-label">Prize Description</span><textarea className="input" rows={2} maxLength={1000} placeholder="What are they winning?" value={f.prize} onChange={(e) => set({ prize: e.target.value })} /></label>
          <label className="fld rf-num"><span className="fld-label">Number of Winners</span><input className="input" type="number" inputMode="numeric" min={1} max={100} value={f.n} onChange={(e) => set({ n: e.target.value })} /></label>
          {chk('weighted', 'Enable Weighted Raffle (subscribers and VIPs get enhanced odds)')}
          {f.weighted && <div className="rf-sub">
            <span className="fld-label rf-subh">Weight Multipliers</span>
            <div className="rf-weights">{([['t1', 'Tier 1 Subscriber'], ['t2', 'Tier 2 Subscriber'], ['t3', 'Tier 3 Subscriber'], ['vip', 'VIP']] as [keyof F, string][]).map(([k, l]) =>
              <label key={k} className="fld"><span className="fld-label">{l}</span><input className="input" type="number" step="0.01" min={1} max={100} value={f[k] as string} aria-invalid={!wOk(f[k] as string)} onChange={(e) => set({ [k]: e.target.value })} /></label>)}</div></div>}
          {chk('mods', 'Exclude Moderators from winning')}
          {chk('subs', 'Only Subscribers Can Enter')}
          {chk('fol', 'Only Followers Can Enter')}
          {f.fol && <div className="rf-sub rf-sub-ok">
            {chk('fmin', 'Require Minimum Follow Time')}
            {f.fmin && <div className="fld"><span className="fld-label">Minimum Follow Time</span><div className="rf-follow">
              <input className="input" type="number" inputMode="numeric" min={1} max={1000} aria-label="Minimum follow time" value={f.fval} onChange={(e) => set({ fval: e.target.value })} />
              <Combobox label="Unit" searchable={false} value={f.funit} onChange={(v) => set({ funit: v })} options={UNITS} /></div></div>}
          </div>}
          {touched && bad && <p className="mp-bad small" role="alert">{bad}</p>}
          <div className="rf-acts">
            <button className="btn" type="submit" disabled={busy === 'save' || (touched && !!bad)}><i className={busy === 'save' ? 'fas fa-spinner fa-spin' : 'fas fa-ticket-alt'} /> {editId ? 'Update Raffle' : 'Create Raffle'}</button>
            {editId && <button type="button" className="btn btn-ghost" onClick={cancelEdit}><i className="fas fa-times" /> Cancel Edit</button>}
          </div>
        </form>
      </section>
      <section className="card" aria-label="Active Raffles">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list" /> Active Raffles</h3></div>
        <div className="bp-table rf-table"><table>
          <thead><tr><th>ID</th><th>Name</th><th>Prize</th><th># Winners</th><th>Status</th><th>Weights</th><th>Exclusions</th><th>Winner(s)</th><th>Action</th></tr></thead>
          <tbody aria-busy={!list && !listErr}>{listErr ? <tr><td colSpan={9} className="mp-bad bp-empty">{listErr}</td></tr>
            : !list ? Array.from({ length: 4 }, (_, i) => <tr key={i} aria-hidden="true">{[30, 70, 60, 30, 40, 40, 50, 50, 70].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
            : list.length === 0 ? <tr><td colSpan={9} className="muted bp-empty">No raffles yet. Create one above.</td></tr>
            : list.map((r) => { const x = excl(r), w = +r.is_weighted
              return <tr key={r.id}>
                <td data-l="ID" className="bp-pts">{r.id}</td>
                <td data-l="Name" className="cr-name">{r.name}</td>
                <td data-l="Prize" className="rf-prize">{r.prize}</td>
                <td data-l="# Winners" className="bp-pts">{r.number_of_winners}</td>
                <td data-l="Status"><span className={`pill ${r.status === 'running' ? 'pill-ok' : r.status === 'scheduled' ? 'pill-warn' : 'pill-off'}`}>{r.status}</span></td>
                <td data-l="Weights">{w ? <span className="pill" title={`T1: ${r.weight_sub_t1}x | T2: ${r.weight_sub_t2}x | T3: ${r.weight_sub_t3}x | VIP: ${r.weight_vip}x`}><i className="fas fa-check" /> Weighted</span> : <span className="muted">No</span>}</td>
                <td data-l="Exclusions">{x.length ? x.join(', ') : <span className="muted">None</span>}</td>
                <td data-l="Winner(s)" className="rf-win">{r.winners?.length ? r.winners.join(', ') : <span className="muted">-</span>}</td>
                <td data-l="Action"><div className="ps-acts rf-row">
                  {r.status === 'scheduled' && <><button type="button" className="btn btn-ok" disabled={busy === 's' + r.id} onClick={() => act('s' + r.id, { action: 'start', raffle_id: String(r.id) })}><i className={busy === 's' + r.id ? 'fas fa-spinner fa-spin' : 'fas fa-play'} /> Start</button>
                    <button type="button" className="btn btn-ghost" onClick={() => startEdit(r)}><i className="fas fa-edit" /> Edit</button></>}
                  {r.status === 'running' && <button type="button" className="btn btn-ghost cr-warn" onClick={() => setDraw(r)}><i className="fas fa-star" /> Draw</button>}
                  <button type="button" className="btn btn-danger" onClick={() => setDel(r)}><i className="fas fa-trash" /> Delete</button>
                </div></td></tr> })}</tbody></table></div>
      </section>
      {draw && <Modal title="Draw winners" icon="fas fa-star" onClose={() => setDraw(null)}>
        <p>Draw {draw.number_of_winners} winner{+draw.number_of_winners === 1 ? '' : 's'} for <b>{draw.name}</b> now? The raffle ends and the winners are announced on stream.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDraw(null)}>Cancel</button><button type="button" className="btn" disabled={busy === 'draw'} onClick={() => { setDraw(null); act('draw', { action: 'draw', raffle_id: String(draw.id) }) }}><i className={busy === 'draw' ? 'fas fa-spinner fa-spin' : 'fas fa-star'} /> Draw</button></div>
      </Modal>}
      {del && <Modal title="Delete raffle" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p>Delete <b>{del.name}</b>? This also removes its entries and winners. This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { const id = del.id; setDel(null); act('del', { action: 'delete', raffle_id: String(id) }).then((ok) => { if (ok && editId === id) cancelEdit() }) }}><i className="fas fa-trash" /> Delete</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
