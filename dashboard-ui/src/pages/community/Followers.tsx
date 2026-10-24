import { useCallback, useEffect, useState } from 'react'
import { get } from '../../bot'
import { useVisibleInterval } from '../../useVisibleInterval'
import { API, UserCard, SkelCards } from './shared'

// Port of dashboard/followers.php: "Twitch Data - Followers" card with the
// Follower Growth chart, the five metric tiles and the follower card grid
// (name, followed date, time). Reloads every 5 minutes like the live page.
// Endpoint: public/api/community/followers.php?load=followers.
type F = { user_id: string; user_name: string; followed_at: string; profile_image_url: string | null }
type Pt = { x: string; y: number }
type Data = { status: string; message?: string; data: F[]; metrics: Record<'total' | 'today' | 'week' | 'month' | 'year', number>; chartData: Pt[] }
const TILES: [keyof Data['metrics'], string][] = [['total', 'Total Followers'], ['today', 'New Followers Today'], ['week', 'New Followers This Week'], ['month', 'New Followers This Month'], ['year', 'New Followers This Year']]
const ord = (n: number) => { const s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]) }
const dt = (s: string) => new Date(s.replace(' ', 'T'))
const fmtDate = (s: string) => { const d = dt(s); return `${ord(d.getDate())} ${d.toLocaleString('default', { month: 'long' })} ${d.getFullYear()}` }

function Chart({ pts }: { pts: Pt[] }) {
  const W = 800, H = 240, L = 48, R = 16, T = 12, B = 28
  if (!pts.length) return <svg className="cm-chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Follower Growth: no data" />
  const xs = pts.map((p) => new Date(p.x).getTime()), x0 = Math.min(...xs), x1 = Math.max(...xs), span = x1 - x0 || 1
  const ymax = Math.max(1, ...pts.map((p) => p.y)), step = Math.pow(10, Math.floor(Math.log10(ymax))), top = Math.ceil(ymax / step) * step
  const X = (t: number) => L + ((t - x0) / span) * (W - L - R), Y = (v: number) => H - B - (v / top) * (H - T - B)
  const line = pts.map((p, i) => `${i ? 'L' : 'M'}${X(xs[i]).toFixed(1)},${Y(p.y).toFixed(1)}`).join(' ')
  const ticksY = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(top * f))
  const n = Math.min(6, pts.length), ticksX = Array.from({ length: n }, (_, i) => x0 + (span * i) / Math.max(1, n - 1))
  return (
    <svg className="cm-chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Follower Growth: ${pts[pts.length - 1].y} followers`}>
      {ticksY.map((v) => <g key={v}><line x1={L} x2={W - R} y1={Y(v)} y2={Y(v)} className="cm-grid" /><text x={L - 6} y={Y(v) + 4} textAnchor="end" className="cm-tick">{v}</text></g>)}
      {ticksX.map((t, i) => <text key={i} x={X(t)} y={H - 8} textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'} className="cm-tick">{new Date(t).toLocaleString('default', { month: 'short', year: 'numeric' })}</text>)}
      <path d={`${line} L${X(x1).toFixed(1)},${Y(0)} L${X(x0).toFixed(1)},${Y(0)} Z`} className="cm-area" />
      <path d={line} className="cm-line" />
      {pts.length <= 60 && pts.map((p, i) => <circle key={i} cx={X(xs[i])} cy={Y(p.y)} r={3} className="cm-dot"><title>{`${p.x}: ${p.y}`}</title></circle>)}
    </svg>
  )
}

export default function Followers() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(''), [busy, setBusy] = useState(true)
  const load = useCallback(() => {
    setBusy(true)
    get<Data>(`${API}/followers.php?load=followers`, 120000).then((r) => {
      if (r.status !== 'success') { setErr('Failed to load followers.' + (r.message ? ` (${r.message})` : '')); return }
      setD(r); setErr('')
    }).catch((x) => { if ((x as Error).message !== 'http_401') setErr('Failed to load followers. Refresh to try again.') }).finally(() => setBusy(false))
  }, [])
  useEffect(load, [load])
  useVisibleInterval(load, 300000)
  const first = busy && !d
  return (
    <div className="page cmds cm">
      <section className="card" aria-label="Followers">
        <h3 className="card-h"><i className="fas fa-users" /> Twitch Data - Followers</h3>
        <div className="cm-chart-host" aria-busy={first}>
          <p className="cm-chart-label"><span className="cm-key" /> Follower Growth</p>
          {first ? <span className="bp-skel cm-chart-skel" aria-hidden="true" /> : <Chart pts={err ? [] : d?.chartData ?? []} />}
        </div>
        <div className="stat-grid small" aria-busy={first}>
          {TILES.map(([k, l]) => <div key={k} className="card stat cm-stat"><div>{first ? <span className="skel w-55" /> : <b>{err ? 0 : d?.metrics[k] ?? 0}</b>}<span>{l}</span></div></div>)}
        </div>
        {err && <p className="muted" role="status">{err}</p>}
        {!err && d && d.data.length === 0 && <p className="muted">No followers found.</p>}
        <div className="cm-grid" aria-busy={first}>
          {first ? <SkelCards /> : !err && d?.data.map((f) => <UserCard key={f.user_id} img={f.profile_image_url} name={f.user_name}>
            <strong className="cm-name">{f.user_name}</strong><span className="muted small">{fmtDate(f.followed_at)}</span><span className="muted small">{dt(f.followed_at).toLocaleTimeString()}</span>
          </UserCard>)}
        </div>
      </section>
    </div>
  )
}
