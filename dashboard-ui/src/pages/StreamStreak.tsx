import { useEffect, useState } from 'react'
import { get } from '../bot'

// Port of dashboard/stream_streak.php: milestone notice, then the Stream Watch
// Streaks card with Recent Milestones (Viewer, Current, Best, Total, Last
// Milestone) beside All-Time Highest Streaks and Milestone Breakdown.
// Endpoint: public/api/stream-streak (read only). Loads once; no timers.
const API = '/api/stream-streak/stream-streak.php?ajax_action=list'
type Recent = { user_name: string; streak_value: string; highest_streak: string; total_streams_watched: string; updated_at: string }
type Top = { user_name: string; highest_streak: string; total_streams_watched: string }
type Mile = { streak_value: string; user_count: string }
type Data = { success: boolean; recent_streaks: Recent[]; top_streakers: Top[]; milestone_breakdown: Mile[] }
const i = (v: unknown) => { const x = parseInt(String(v), 10); return isNaN(x) ? 0 : x }

export default function StreamStreak() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(false)
  useEffect(() => { get<Data>(API).then((r) => { if (!r.success) throw new Error(); setD(r) }).catch(() => setErr(true)) }, [])
  const skel = (n: number) => <div className="ss-skel" aria-hidden="true">{Array.from({ length: n }, (_, k) => <span key={k} className="bp-skel" style={{ width: `${50 + ((k * 17) % 40)}%` }} />)}</div>
  const fail = <p className="muted">Something went wrong loading this. Refresh to try again.</p>
  return (
    <div className="page cmds mp ss">
      <div className="pg-head"><h2><i className="fas fa-fire" /> Stream Watch Streaks</h2></div>
      <div className="notice notice-info"><i className="fas fa-info-circle" /> <span>Milestones are recorded automatically when viewers hit consecutive stream watch streaks (for example 3, 7, 10 or 50 streams in a row).</span></div>
      <section className="card" aria-label="Stream Watch Streaks">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-fire" /> Stream Watch Streaks</h3></div>
        <div className="ss-layout">
          <div>
            <h4 className="ss-h">Recent Milestones</h4>
            {err ? fail : d && d.recent_streaks.length === 0 ? <p className="muted ss-empty">No stream watch streak data available yet.</p>
              : <div className="bp-table ss-table"><table>
                <thead><tr><th>Viewer</th><th>Current Streak</th><th>Best Streak</th><th>Total Watched</th><th>Last Milestone</th></tr></thead>
                <tbody aria-busy={!d}>{!d ? Array.from({ length: 5 }, (_, k) => <tr key={k} aria-hidden="true">{[60, 40, 40, 40, 70].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
                  : d.recent_streaks.map((r, k) => { const c = i(r.streak_value)
                    return <tr key={k}><td data-l="Viewer" className="bp-user">{r.user_name}</td><td data-l="Current" className="bp-pts">{c} streams</td><td data-l="Best">{Math.max(i(r.highest_streak), c)} streams</td><td data-l="Total">{Math.max(i(r.total_streams_watched), c)} streams</td><td data-l="Last Milestone" className="small">{r.updated_at}</td></tr> })}</tbody></table></div>}
          </div>
          <div className="ss-side">
            <h4 className="ss-h">All-Time Highest Streaks</h4>
            <div aria-busy={!d && !err}>{err ? fail : !d ? skel(4) : d.top_streakers.length === 0 ? <p className="muted">No data yet.</p>
              : <ul className="ss-list">{d.top_streakers.map((t, k) => <li key={k}><b>{t.user_name}</b> <span className="muted">best:</span> {t.highest_streak} streams <span className="muted">· total:</span> {t.total_streams_watched} streams</li>)}</ul>}</div>
            <hr className="ss-hr" />
            <h4 className="ss-h">Milestone Breakdown</h4>
            <div aria-busy={!d && !err}>{err ? fail : !d ? skel(4) : d.milestone_breakdown.length === 0 ? <p className="muted">No data yet.</p>
              : <ul className="ss-list">{d.milestone_breakdown.map((m, k) => { const c = i(m.user_count); return <li key={k}><b>{m.streak_value} streams</b> - {c} {c === 1 ? 'viewer' : 'viewers'}</li> })}</ul>}</div>
          </div>
        </div>
      </section>
    </div>
  )
}
