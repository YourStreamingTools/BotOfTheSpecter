import { useEffect, useState } from 'react'
import { get } from '../../bot'
import { ToastBar, send, type Toast } from '../commands/shared'
import { API, Avatar, SkelRows } from './shared'

// Port of dashboard/mods.php: "Your Moderators:" card with the dashboard access
// and security notice, then the Moderator Name / Specter Dashboard Registration
// / Specter Moderator Access table. BotOfTheSpecter always has access; stale
// access rows show "No longer mod". Add / Remove Access post the live page's
// moderator_id + action. Disabled while acting as a channel (non-admin).
// Endpoint: public/api/community/mods.php.
const URL_ = `${API}/mods.php`
type M = { user_id: string; user_name: string; is_stale_access: boolean; is_registered: boolean; has_access: boolean; is_specter: boolean; profile_image: string }
type Data = { success: boolean; moderators: M[]; disable_mod_actions: boolean }

export default function Mods() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(false), [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null)
  useEffect(() => { get<Data>(`${URL_}?ajax_action=list`, 30000).then((r) => { if (!r.success) throw new Error(); setD(r) }).catch((x) => { if ((x as Error).message !== 'http_401') setErr(true) }) }, [])
  async function act(m: M) {
    const action = m.has_access ? 'remove' : 'add'
    if (d?.disable_mod_actions) { setToast({ kind: 'err', text: 'Managing dashboard access is disabled while acting as another channel.' }); return }
    setBusy(m.user_id)
    try {
      const r = await send<{ status: string; action?: string; message?: string; error?: string }>(URL_, { moderator_id: m.user_id, action })
      if (r.status !== 'ok') throw new Error(r.message || r.error || 'Failed to update access')
      setD((o) => o && { ...o, moderators: o.moderators.map((x) => x.user_id === m.user_id ? { ...x, has_access: r.action === 'add' } : x) })
      setToast({ kind: 'ok', text: 'Access updated successfully' })
    } catch (x) { setToast({ kind: 'err', text: (x as Error).message || 'Failed to update access' }) } finally { setBusy('') }
  }
  const dis = !!d?.disable_mod_actions
  return (
    <div className="page cmds cm">
      <section className="card" aria-label="Moderators">
        <h3 className="card-h"><i className="fas fa-user-shield" /> Your Moderators:</h3>
        <div className="notice notice-info cm-access"><i className="fas fa-user-shield" /><div>
          <p><strong>Moderator Dashboard Access</strong></p>
          <p>Grant your trusted Twitch moderators access to your Specter dashboard. This allows them to manage bot settings, view analytics, and perform administrative tasks without needing to use chat commands or access your personal accounts.</p>
          <hr />
          <p><strong>Important: Only grant access to moderators you trust completely, as they will have significant control over your bot's configuration and settings.</strong></p>
        </div></div>
        <div className="bp-table cm-table"><table>
          <thead><tr><th>Moderator Name</th><th>Specter Dashboard Registration</th><th>Specter Moderator Access</th></tr></thead>
          <tbody aria-busy={!d && !err}>
            {err ? <tr><td colSpan={3}>Something went wrong loading your moderators. Refresh to try again.</td></tr>
              : !d ? <SkelRows cols={3} />
              : d.moderators.map((m) => <tr key={m.user_id}>
                <td data-l="Moderator"><span className="cm-who"><Avatar src={m.profile_image} name={m.user_name} size={32} /><span className="cm-name">{m.user_name}</span>{m.is_stale_access && <span className="pill pill-warn">No longer mod</span>}</span></td>
                <td data-l="Registered">{m.is_registered ? <span className="cm-yes">Yes</span> : <span className="cm-no">No</span>}</td>
                <td data-l="Access">{m.is_specter ? <button type="button" className="btn btn-ok cm-btn" disabled>Always Has Access</button>
                  : <button type="button" className={`btn cm-btn${m.has_access ? ' btn-danger' : ''}`} disabled={dis || busy === m.user_id} title={dis ? 'Disabled while acting as another channel' : undefined} onClick={() => act(m)}>
                    {busy === m.user_id && <i className="fas fa-spinner fa-spin" />} {m.has_access ? 'Remove Access' : 'Add Access'}</button>}</td>
              </tr>)}
          </tbody></table></div>
      </section>
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
