import { useState } from 'react'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/known_bots.php: intro card, Add a Bot form, Known Bots table.
type Bot = { id: number; bot_login: string; is_active: number; added_by: string | null; notes: string | null }
const API = '/api/known_bots.php'

export default function KnownBots() {
  const { data, err, reload, setData } = useLoad<{ bots: Bot[]; username: string }>(API)
  const [login, setLogin] = useState(''), [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(0), [adding, setAdding] = useState(false)
  const [toast, setToast] = useState<Toast>(null), [ask, setAsk] = useState<Ask>(null)

  const add = async (e: React.FormEvent) => {
    e.preventDefault(); setAdding(true)
    try {
      const r = await post(API, { add_bot: 1, bot_login: login, notes })
      if (!r.success) { setToast({ kind: 'err', text: r.message || 'Failed to add the bot. Please try again.' }); return }
      setLogin(''); setNotes(''); setToast({ kind: 'ok', text: r.message || 'Bot added.' }); await reload()
    } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setAdding(false) }
  }
  const toggle = async (b: Bot) => {
    const on = b.is_active ? 0 : 1; setBusy(b.id)
    try {
      const r = await post(API, { toggle_bot: 1, id: b.id, is_active: on })
      if (r.success) setData((d) => ({ ...d, bots: d.bots.map((x) => (x.id === b.id ? { ...x, is_active: on } : x)) }))
      else setToast({ kind: 'err', text: r.message || 'Failed to update the bot. Please try again.' })
    } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) } finally { setBusy(0) }
  }
  const del = (b: Bot) => setAsk({
    title: 'Remove bot?', text: <p>Remove "{b.bot_login}" from the known-bots list?</p>, ok: 'Remove', danger: true,
    run: async () => {
      try {
        const r = await post(API, { delete_bot: 1, id: b.id })
        if (r.success) { setData((d) => ({ ...d, bots: d.bots.filter((x) => x.id !== b.id) })); setToast({ kind: 'ok', text: r.message || 'Bot removed.' }) }
        else setToast({ kind: 'err', text: r.message || 'Failed to remove the bot. Please try again.' })
      } catch (x) { setToast({ kind: 'err', text: (x as Error).message }) }
    },
  })

  return (
    <div className="cmds adm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      <section className="card">
        <h2 className="card-h"><i className="fas fa-robot" /> Known Bots</h2>
        <p className="muted">Global list of Twitch accounts treated as bots. These accounts are hidden from the dashboard leaderboards (watch time, chat, streaks and interactions) for every channel.</p>
      </section>
      <section className="card">
        <h2 className="card-h">Add a Bot</h2>
        <form className="adm-form" onSubmit={add}>
          <label className="fld"><span className="fld-label">Twitch Login</span>
            <input className="input" required maxLength={26} placeholder="e.g. nightbot" value={login} onChange={(e) => setLogin(e.target.value)} />
            <small className="muted">Lowercase Twitch login: letters, numbers and underscores (max 25 characters).</small></label>
          <label className="fld"><span className="fld-label">Notes (optional)</span>
            <input className="input" placeholder="Why this account is excluded" value={notes} onChange={(e) => setNotes(e.target.value)} /></label>
          <div><button type="submit" className="btn" disabled={adding}><i className="fas fa-plus" /> Add Bot</button></div>
        </form>
      </section>
      <section className="card">
        <h2 className="card-h">Known Bots {data && <span className="pill">{data.bots.length}</span>}</h2>
        {err && <LoadErr err={err} retry={reload} />}
        {!data && !err && <Loading />}
        {data && (data.bots.length === 0 ? <div className="notice notice-info">No bots configured yet.</div> : (
          <div className="bp-table"><table>
            <thead><tr><th>Login</th><th>Status</th><th>Added By</th><th>Actions</th></tr></thead>
            <tbody>{data.bots.map((b) => (
              <tr key={b.id}>
                <td><strong>{b.bot_login}</strong>{b.notes && <div className="muted adm-sub">{b.notes}</div>}</td>
                <td><span className={`pill ${b.is_active ? 'pill-ok' : 'pill-off'}`}>{b.is_active ? 'Active' : 'Disabled'}</span></td>
                <td className="adm-hide-m">{b.added_by ?? ''}</td>
                <td><div className="bp-acts">
                  <button type="button" className="btn btn-ghost" disabled={busy === b.id} onClick={() => toggle(b)}><i className={`fas ${b.is_active ? 'fa-toggle-off' : 'fa-toggle-on'}`} /> {b.is_active ? 'Disable' : 'Enable'}</button>
                  <button type="button" className="btn btn-danger" onClick={() => del(b)}><i className="fas fa-trash" /> Delete</button>
                </div></td>
              </tr>))}</tbody>
          </table></div>
        ))}
      </section>
    </div>
  )
}
