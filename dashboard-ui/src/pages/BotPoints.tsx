import { useEffect, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { useVisibleInterval } from '../useVisibleInterval'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/bot_points.php: one card (title + Settings button), the
// "last updated" line and the Username / Points / Actions table with an inline
// points box, Update and Remove; the Settings modal. The list refreshes every 30 s
// while the tab is visible (one short request). Endpoint: public/api/bot-points.
const API = '/api/bot-points/bot-points.php'
type Row = { user_name: string; points: number | string }
type Settings = { point_name: string; point_amount_chat: number; point_amount_follower: number; point_amount_subscriber: number; point_amount_cheer: number; point_amount_raid: number; subscriber_multiplier: string; excluded_users: string }
type Res = { success: boolean; error?: string; status?: string; settings?: Settings; known_bots?: string[] }
const MULT = [{ value: '0', label: 'None' }, ...Array.from({ length: 9 }, (_, i) => ({ value: String(i + 2), label: `${i + 2}x` }))]
const AMOUNTS: [keyof Settings, string][] = [['point_amount_chat', 'Earned per Chat Message'], ['point_amount_follower', 'Earned for Following'], ['point_amount_subscriber', 'Earned for Subscribing'], ['point_amount_cheer', 'Earned Per Cheer'], ['point_amount_raid', 'Earned Per Raid Viewer']]

function SettingsModal({ s, onClose, onSaved }: { s: Settings; bots?: string[]; onClose: () => void; onSaved: (s: Settings) => void }) {
  const [f, setF] = useState<Record<string, string>>(Object.fromEntries(Object.entries(s).map(([k, v]) => [k, String(v)])))
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const name = f.point_name.trim() || 'Points'
  const bad = !f.point_name.trim() || f.point_name.length > 50 || /[<>]/.test(f.point_name) ? 'Points name must be 1 to 50 characters, without < or >.'
    : AMOUNTS.some(([k]) => !/^\d+$/.test(f[k]) || +f[k] > 100000) ? 'Amounts must be whole numbers from 0 to 100000.'
    : !/^[A-Za-z0-9_, ]*$/.test(f.excluded_users) ? 'Excluded users must be Twitch usernames separated by commas.' : ''
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (bad) return
    setBusy(true); setErr('')
    try { const r = await send<Res>(API, { update_settings: '1', ...f }); if (!r.success || !r.settings) throw new Error(r.error || 'Could not save the settings.'); onSaved(r.settings) }
    catch (x) { setErr((x as Error).message) }
    setBusy(false)
  }
  return (
    <Modal title="Points System Settings" icon="fas fa-cog" onClose={onClose} wide>
      <form className="bp-form" onSubmit={save}>
        <label className="fld fld-wide"><span className="fld-label">Points Name</span><input className="input" maxLength={50} value={f.point_name} onChange={(e) => setF({ ...f, point_name: e.target.value })} data-autofocus /></label>
        {AMOUNTS.map(([k, l]) => <label key={k} className="fld"><span className="fld-label">{name} {l}</span><input className="input" type="number" inputMode="numeric" min={0} max={100000} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>)}
        <div className="fld"><span className="fld-label">Subscriber Multiplier</span><Combobox label="Subscriber Multiplier" searchable={false} value={f.subscriber_multiplier} onChange={(v) => setF({ ...f, subscriber_multiplier: v })} options={MULT} /></div>
        <label className="fld fld-wide"><span className="fld-label">Excluded Users (comma-separated)</span><input className="input" maxLength={1000} value={f.excluded_users} onChange={(e) => setF({ ...f, excluded_users: e.target.value })} /><span className="muted small">You, this bot and known Twitch bots are always excluded.</span></label>
        {(bad || err) && <p className="mp-bad small fld-wide">{err || bad}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || !!bad}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Update Settings</button></div>
      </form>
    </Modal>
  )
}

export default function BotPoints() {
  const [rows, setRows] = useState<Row[] | null>(null), [s, setS] = useState<Settings | null>(null), [bots, setBots] = useState<string[]>([])
  const [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null)
  const [edit, setEdit] = useState<Record<string, string>>({}), [busy, setBusy] = useState('')
  const [ago, setAgo] = useState(0), [open, setOpen] = useState(false), [del, setDel] = useState<Row | null>(null)
  const load = () => get<{ success: boolean; points: Row[]; known_bots?: string[] }>(`${API}?ajax_action=list`).then((r) => { if (!r.success) throw new Error(); setRows(r.points); if (r.known_bots) setBots(r.known_bots); setAgo(0) }).catch(() => { if (!rows) setErr('The points list could not load. Refresh to try again.') })
  useEffect(() => { get<Res>(API).then((r) => { if (!r.success || !r.settings) throw new Error(); setS(r.settings); if (r.known_bots) setBots(r.known_bots) }).catch(() => setErr('The Points System page could not load. Refresh to try again.')); load() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  useVisibleInterval(load, 30000)
  useVisibleInterval(() => setAgo((a) => a + 1), 1000)
  async function act(k: string, body: Record<string, string>, ok: string) {
    setBusy(k)
    try { const r = await send<Res>(API, body); if (!r.success) throw new Error(r.error || 'Could not save that change.'); setToast({ kind: 'ok', text: ok }); await load(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  if (err) return <div className="page cmds mp bp"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  const pn = s?.point_name || 'Points'
  const manual = new Set((s?.excluded_users || '').split(',').map((u) => u.trim().toLowerCase()).filter(Boolean)), botSet = new Set(bots)
  const tag = (u: string) => botSet.has(u.toLowerCase()) ? 'Known bot' : manual.has(u.toLowerCase()) ? 'Excluded' : ''
  return (
    <div className="page cmds mp bp">
      <section className="card" aria-label="Bot Points Management">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-coins" /> Bot Points Management</h3>
          <button type="button" className="btn" disabled={!s} onClick={() => setOpen(true)}><i className="fas fa-cog" /> Settings</button></div>
        <p className="muted small bp-upd" aria-live="off">Data last updated: {ago} seconds ago.</p>
        <div className="bp-table">
          <table>
            <thead><tr><th>Username</th><th>{pn !== 'Points' ? `${pn} Points` : 'Points'}</th><th>Actions</th></tr></thead>
            <tbody aria-busy={!rows}>
              {!rows ? Array.from({ length: 5 }, (_, i) => <tr key={i} aria-hidden="true"><td><span className="bp-skel" /></td><td><span className="bp-skel" /></td><td><span className="bp-skel bp-skel-w" /></td></tr>)
                : rows.length === 0 ? <tr><td colSpan={3} className="muted bp-empty">No one has earned {pn.toLowerCase()} yet.</td></tr>
                : rows.map((r) => { const v = edit[r.user_name] ?? String(r.points), ok = /^\d{1,10}$/.test(v) && +v <= 2147483647
                  return <tr key={r.user_name}><td className="bp-user">{r.user_name}{tag(r.user_name) && <> <span className="pill" title={`Earns no ${pn.toLowerCase()}`}>{tag(r.user_name)}</span></>}</td><td className="bp-pts">{Number(r.points).toLocaleString()}</td>
                    <td><div className="bp-acts">
                      <input className="input bp-in" type="number" inputMode="numeric" min={0} aria-label={`${pn} for ${r.user_name}`} value={v} aria-invalid={!ok} onChange={(e) => setEdit({ ...edit, [r.user_name]: e.target.value })} />
                      <button type="button" className="btn" disabled={!ok || v === String(r.points) || busy === 'u' + r.user_name} onClick={async () => { if (await act('u' + r.user_name, { update_points: '1', user_name: r.user_name, points: v }, 'User points updated successfully!')) setEdit((o) => { const n = { ...o }; delete n[r.user_name]; return n }) }}>Update</button>
                      <button type="button" className="btn btn-danger" onClick={() => setDel(r)}>Remove</button>
                    </div></td></tr> })}
            </tbody>
          </table>
        </div>
      </section>
      {open && s && <SettingsModal s={s} bots={bots} onClose={() => setOpen(false)} onSaved={(n) => { setS(n); setOpen(false); setToast({ kind: 'ok', text: 'Settings updated successfully!' }) }} />}
      {del && <Modal title="Remove user" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p>Remove <b>{del.user_name}</b> and their {Number(del.points).toLocaleString()} {pn.toLowerCase()} from the list? This can't be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { setDel(null); act('del', { remove_user: '1', user_name: del.user_name }, 'User removed successfully!') }}><i className="fas fa-trash" /> Remove</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
