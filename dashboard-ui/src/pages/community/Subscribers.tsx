import { useEffect, useMemo, useState } from 'react'
import { get } from '../../bot'
import Pager from '../../components/Pager'
import { API, UserCard, SkelCards } from './shared'

// Port of dashboard/subscribers.php: "Your Subscribers:" card. Affiliate /
// Partner check notice, error notice, search (shown past 50 subscribers,
// filters the page shown), 50 per page sorted Tier 3, 2, 1 within the page,
// tier badge (bronze / silver / gold, "Gifted by" on hover) and pagination.
// Endpoint: public/api/community/subscribers.php (read only).
type S = { user_id: string; user_name: string; tier: string; is_gift: boolean; gifter_name: string; profile_image: string }
type Data = { success: boolean; no_access: boolean; is_owner: boolean; channel: string; error: string | null; subscribers: S[] }
const PER = 50
const TIER: Record<string, [string, string]> = { '3000': ['3', 'cm-t3'], '2000': ['2', 'cm-t2'], '1000': ['1', 'cm-t1'] }
const rank = (t: string) => ['3000', '2000', '1000'].indexOf(t)

export default function Subscribers() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(false), [page, setPage] = useState(1), [q, setQ] = useState('')
  useEffect(() => { get<Data>(`${API}/subscribers.php`, 60000).then((r) => { if (!r.success) throw new Error(); setD(r) }).catch((x) => { if ((x as Error).message !== 'http_401') setErr(true) }) }, [])
  const all = d?.subscribers ?? [], pages = Math.max(1, Math.ceil(all.length / PER))
  const shown = useMemo(() => {
    const cur = all.slice((page - 1) * PER, page * PER).sort((a, b) => rank(a.tier) - rank(b.tier))
    const t = q.toLowerCase()
    return t ? cur.filter((s) => s.user_name.toLowerCase().includes(t)) : cur
  }, [all, page, q])
  const ok = d && !d.no_access && !d.error
  return (
    <div className="page cmds cm">
      <section className="card" aria-label="Subscribers">
        <h3 className="card-h"><i className="fas fa-star" /> Your Subscribers:</h3>
        {err ? <div className="notice notice-err"><i className="fas fa-exclamation-circle" /> <span>Unable to fetch subscribers right now. Please try again later.</span></div>
          : d?.no_access ? <div className="notice notice-info"><i className="fas fa-info-circle" /> <span>{d.is_owner ? 'You are' : `${d.channel} is`} currently a normal broadcaster on Twitch and subscriber data is only available for Affiliate or Partner channels.</span></div>
          : d?.error ? <div className="notice notice-err"><i className="fas fa-exclamation-circle" /> <span>{d.error}</span></div>
          : <>
            {ok && all.length > PER && <label className="media-search cm-search"><i className="fas fa-search" /><input className="input" type="search" autoComplete="off" aria-label="Search for Subscribers" placeholder="Search for Subscribers..." value={q} onChange={(e) => setQ(e.target.value)} /></label>}
            {ok && all.length === 0 && <p className="muted">No subscribers yet.</p>}
            <div className="cm-grid" aria-busy={!d}>
              {!d ? <SkelCards /> : shown.map((s) => { const [n, c] = TIER[s.tier] ?? ['Unknown', 'cm-t0']
                return <UserCard key={s.user_id} img={s.profile_image} name={s.user_name}>
                  <span className={`cm-tier ${c}`} title={s.is_gift && s.gifter_name ? `Gifted by ${s.gifter_name}` : undefined}>Tier {n}</span>
                  <span className="cm-name">{s.user_name}</span>
                </UserCard> })}
            </div>
            <Pager page={page} pages={pages} label="Subscriber pages" onPage={(p) => { setPage(p); window.scrollTo({ top: 0 }) }} />
          </>}
      </section>
    </div>
  )
}
