import { useEffect, useState } from 'react'
import { get } from '../../bot'
import Modal from '../../components/Modal'
import { ToastBar, send, type Toast } from '../commands/shared'
import { API, SkelRows } from './shared'

// Port of dashboard/birthdays.php: Birthdays heading, the three tiles, the
// Chat commands card and the Birthday list (filter, User / Birthday / Next
// birthday / Added by / Actions, remove one with a confirm).
// Endpoint: public/api/community/birthdays.php.
const URL_ = `${API}/birthdays.php`
type Bd = { id: number; user_name: string; date_display: string; days_until: number; added_by: string }
type Data = { success: boolean; message?: string | null; available: boolean; today_count: number; upcoming_count: number; birthdays: Bd[] }

export default function Birthdays() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(false), [q, setQ] = useState(''), [del, setDel] = useState<Bd | null>(null), [busy, setBusy] = useState(false), [toast, setToast] = useState<Toast>(null)
  const load = () => get<Data>(URL_, 20000).then((r) => { if (!r.success) throw new Error(); setD(r); setErr(false) }).catch((x) => { if ((x as Error).message !== 'http_401') setErr(true) })
  useEffect(() => { load() }, [])
  async function remove(b: Bd) {
    setDel(null)
    setBusy(true)
    try {
      const r = await send<Data & { error?: string }>(URL_, { action: 'delete_birthday', birthday_id: String(b.id) })
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || r.error || 'Could not remove the birthday. Please try again.' })
      if (r.birthdays) setD(r)
    } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setBusy(false) }
  }
  const av = d?.available !== false && !err, term = q.trim().toLowerCase().replace(/^@/, '')
  const rows = (d?.birthdays ?? []).filter((b) => !term || b.user_name.toLowerCase().includes(term))
  const tile = (lab: string, v: number | undefined) => <div key={lab} className="card stat cm-stat"><div>{!d && !err ? <span className="skel w-55" /> : <b>{av ? v : '-'}</b>}<span>{lab}</span></div></div>
  const next = (n: number) => n === 0 ? <span className="pill pill-ok"><i className="fas fa-cake-candles" /> Today</span> : n === 1 ? <span className="pill pill-warn">Tomorrow</span>
    : n <= 30 ? <span className="pill pill-info">In {n} days</span> : <span className="muted">In {n} days</span>
  return (
    <div className="page cmds cm">
      <div className="pg-head"><h2><i className="fas fa-cake-candles" /> Birthdays</h2></div>
      <div className="stat-grid small" aria-busy={!d && !err}>{tile('Saved birthdays', d?.birthdays.length)}{tile('Birthdays today', d?.today_count)}{tile('Next 30 days', d?.upcoming_count)}</div>
      <section className="card" aria-label="Chat commands">
        <h3 className="card-h"><i className="fas fa-terminal" /> Chat commands</h3>
        <ul className="cm-list">
          <li><code>!addbirthday @username 23/8</code> <span className="muted">Mods and the broadcaster: save or replace a viewer's birthday.</span></li>
          <li><code>!mybirthday</code> / <code>!mybirthday 23/8</code> <span className="muted">Viewers: show your saved birthday, or save one if you don't have one yet.</span></li>
          <li><code>!updatebirthday 23/8</code> <span className="muted">Viewers: change your saved birthday.</span></li>
        </ul>
        <p className="muted">Dates use the day/month format (23/8 is 23 August). Birthdays are checked against your channel timezone. The wish is its own chat message, so it is sent even when welcome messages are turned off.</p>
      </section>
      <section className="card" aria-label="Birthday list">
        <h3 className="card-h"><i className="fas fa-cake-candles" /> Birthday list</h3>
        {err ? <p className="muted">Something went wrong loading birthdays. Refresh to try again.</p>
          : d && !d.available ? <p className="muted">The birthdays table is not available yet. Open any dashboard page once so schema migrations can run, then refresh this page.</p>
          : d && d.birthdays.length === 0 ? <p className="muted cm-none">No birthdays saved yet.</p>
          : <>
            {d && <label className="media-search cm-search"><i className="fas fa-search" /><input className="input" type="search" autoComplete="off" aria-label="Filter by username" placeholder="Filter by username..." value={q} onChange={(e) => setQ(e.target.value)} /></label>}
            <div className="bp-table cm-table"><table>
              <thead><tr><th>User</th><th>Birthday</th><th>Next birthday</th><th>Added by</th><th className="cm-c">Actions</th></tr></thead>
              <tbody aria-busy={!d}>{!d ? <SkelRows cols={5} /> : rows.map((b) => <tr key={b.id}>
                <td data-l="User"><strong>{b.user_name}</strong></td><td data-l="Birthday">{b.date_display}</td><td data-l="Next">{next(b.days_until)}</td><td data-l="Added by">{b.added_by}</td>
                <td className="cm-c"><button type="button" className="btn btn-danger cm-icon" title="Remove this birthday" aria-label={`Remove the birthday for ${b.user_name}`} onClick={() => setDel(b)}><i className="fas fa-trash" /></button></td></tr>)}</tbody></table></div>
            {d && rows.length === 0 && <p className="muted cm-none">No birthdays match that filter.</p>}
          </>}
      </section>
      {del && <Modal title="Remove this birthday?" icon="fas fa-triangle-exclamation" onClose={() => setDel(null)}>
        <p>Remove the birthday for <strong>{del.user_name}</strong>? This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy} onClick={() => remove(del)}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Remove</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
