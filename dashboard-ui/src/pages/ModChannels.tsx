import { useEffect, useState } from 'react'
import { get } from '../bot'

// Port of dashboard/mod_channels.php: heading, search (shown past 9 channels),
// empty / error notices and the channel card grid with Act As This Channel.
// Endpoint: public/api/mod-channels (read only). Act As goes through
// public/api/mod_act_as.php on the beta session.
const API = '/api/mod-channels/mod-channels.php?ajax_action=list'
type Ch = { id: number; twitch_user_id: string; twitch_display_name: string; profile_image: string; username: string }
const safeImg = (u: string) => (/^https:\/\//i.test(u) ? u : '')

export default function ModChannels() {
  const [list, setList] = useState<Ch[] | null>(null), [err, setErr] = useState(false), [q, setQ] = useState('')
  useEffect(() => { get<{ success: boolean; channels: Ch[] }>(API).then((r) => { if (!r.success) throw new Error(); setList(r.channels) }).catch(() => setErr(true)) }, [])
  const act = new URLSearchParams(location.search).get('act_as')
  const term = q.trim().toLowerCase()
  const shown = (list || []).filter((c) => !term || `${c.twitch_display_name} ${c.username}`.toLowerCase().includes(term))
  return (
    <div className="page cmds mp mc">
      <div className="pg-head"><h2><i className="fas fa-user-shield" /> Mod Channels</h2><p className="muted">Channels you can moderate for</p></div>
      {act === 'stopped' && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span>Moderator Act As mode has been stopped.</span></div>}
      {act === 'denied' && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>You do not have permission to Act As that channel.</span></div>}
      {act === 'not_found' && <div className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>The selected channel could not be found.</span></div>}
      {list && list.length > 9 && <label className="fld mc-search"><span className="fld-label">Search channels</span><input className="input" type="search" autoComplete="off" placeholder="Type streamer name or username" value={q} onChange={(e) => setQ(e.target.value)} /></label>}
      {err ? <div className="notice notice-err"><i className="fas fa-exclamation-circle" /> <span>Something went wrong loading your channels. Refresh to try again.</span></div>
        : list && list.length === 0 ? <div className="notice notice-info"><i className="fas fa-info-circle" /> <span>No channels to mod. If you think this is wrong, ask your broadcaster to add you to their allow list.</span></div>
        : <div className="mc-grid" aria-busy={!list}>
          {!list ? Array.from({ length: 6 }, (_, i) => <section key={i} className="card mc-card" aria-hidden="true"><div className="mc-who"><span className="mc-av bp-skel" /><div className="mc-names"><span className="bp-skel bp-skel-w" /><span className="bp-skel" /></div></div><span className="bp-skel bp-skel-w" /></section>)
            : shown.length === 0 ? <p className="muted">No channels match "{q}".</p>
            : shown.map((c) => <section key={c.id} className="card mc-card">
              <div className="mc-who">{safeImg(c.profile_image) ? <img className="mc-av" src={safeImg(c.profile_image)} alt="" width={64} height={64} loading="lazy" /> : <span className="mc-av" />}
                <div className="mc-names"><p className="mc-name" title={c.twitch_display_name}>{c.twitch_display_name}</p><p className="muted small">@{c.username}</p></div></div>
              <a className="btn mc-act" href={`/api/mod_act_as.php?user_id=${encodeURIComponent(c.twitch_user_id)}`}><i className="fas fa-user-secret" /> Act As This Channel</a>
            </section>)}
        </div>}
    </div>
  )
}
