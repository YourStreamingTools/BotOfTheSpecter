import { useEffect, useState } from 'react'
import { get } from '../../bot'
import Modal from '../../components/Modal'
import { send } from '../commands/shared'
import { API, UserCard, SkelCards } from './shared'

// Port of dashboard/vips.php: "Your VIPs:" card with Manage VIPs and the
// search box in the header, the status message, the VIP card grid (VIP badge)
// or the empty state, and the Add or Remove modal (Add VIP / Remove VIP).
// The POST answers with the fresh list, like the live page.
// Endpoint: public/api/community/vips.php.
const URL_ = `${API}/vips.php`
type V = { user_id: string; user_name: string; profile_image_url: string }

export default function Vips() {
  const [list, setList] = useState<V[] | null>(null), [err, setErr] = useState(false), [q, setQ] = useState(''), [open, setOpen] = useState(false)
  const [name, setName] = useState(''), [busy, setBusy] = useState(''), [status, setStatus] = useState<{ ok: boolean; text: string } | null>(null)
  useEffect(() => { get<{ success: boolean; vips: V[] }>(URL_, 30000).then((r) => { if (!r.success) throw new Error(); setList(r.vips) }).catch((x) => { if ((x as Error).message !== 'http_401') setErr(true) }) }, [])
  const user = name.trim().replace(/^@/, ''), valid = /^[A-Za-z0-9_]{1,25}$/.test(user)
  async function act(action: 'add' | 'remove') {
    if (!valid) return
    setBusy(action)
    try {
      const r = await send<{ success: boolean; message?: string; error?: string; vips?: V[] }>(URL_, { 'vip-username': user, action }, true)
      setStatus({ ok: !!r.success, text: r.message || r.error || 'An error occurred. Please try again.' })
      if (r.success) { if (r.vips) setList(r.vips); setName(''); setOpen(false) }
    } catch { setStatus({ ok: false, text: 'An error occurred. Please try again.' }) } finally { setBusy('') }
  }
  const t = q.toLowerCase(), shown = (list ?? []).filter((v) => v.user_name.toLowerCase().includes(t))
  return (
    <div className="page cmds cm">
      <section className="card" aria-label="VIPs">
        <div className="card-title-row">
          <h3 className="card-h"><i className="fas fa-star" /> Your VIPs:</h3>
          <div className="cm-head-tools">
            <button type="button" className="btn cm-btn" onClick={() => setOpen(true)}><i className="fas fa-user-cog" /> Manage VIPs</button>
            <label className="media-search cm-search"><i className="fas fa-search" /><input className="input" type="search" autoComplete="off" aria-label="Search for VIPs" placeholder="Search for VIPs..." value={q} onChange={(e) => setQ(e.target.value)} /></label>
          </div>
        </div>
        {status && <div className={`notice ${status.ok ? 'notice-info' : 'notice-err'}`} role="status"><i className="fas fa-info-circle" /> <span>{status.text}</span></div>}
        {err ? <p className="muted">Something went wrong loading your VIPs. Refresh to try again.</p>
          : list && list.length === 0 ? <div className="cm-empty"><i className="fas fa-star fa-3x" /><p>No VIPs found</p></div>
          : <div className="cm-grid" aria-busy={!list || !!busy}>
            {!list || busy ? <SkelCards /> : shown.map((v) => <UserCard key={v.user_id} img={v.profile_image_url} name={v.user_name}>
              <span className="cm-name">{v.user_name}</span><span><span className="pill pill-accent"><i className="fas fa-star" /> VIP</span></span>
            </UserCard>)}
          </div>}
      </section>
      {open && <Modal title="Add or Remove a user from your VIP list:" icon="fas fa-user-plus" onClose={() => setOpen(false)}>
        <form className="cr-form" onSubmit={(e) => { e.preventDefault() }}>
          <label className="fld"><span className="fld-label">Username:</span>
            <input className="input cm-in" data-autofocus required autoComplete="off" placeholder="Enter username" value={name} aria-invalid={!!user && !valid} onChange={(e) => setName(e.target.value)} /></label>
          {!!user && !valid && <p className="mp-bad small">Twitch usernames are 1 to 25 letters, numbers or underscores.</p>}
          <div className="modal-acts cm-start">
            <button type="button" className="btn btn-ok cm-btn" disabled={!valid || !!busy} onClick={() => act('add')}><i className={busy === 'add' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add VIP</button>
            <button type="button" className="btn btn-danger cm-btn" disabled={!valid || !!busy} onClick={() => act('remove')}><i className={busy === 'remove' ? 'fas fa-spinner fa-spin' : 'fas fa-minus'} /> Remove VIP</button>
          </div>
        </form>
      </Modal>}
    </div>
  )
}
