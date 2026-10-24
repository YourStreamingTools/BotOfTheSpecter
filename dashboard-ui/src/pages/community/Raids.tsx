import { useEffect, useState } from 'react'
import { get } from '../../bot'
import Modal from '../../components/Modal'
import { API, SkelRows } from './shared'

// Port of dashboard/raids.php: Raids card, Recent Raids - Received beside
// Latest Raid - Sent (Show Last 5 opens the last five sent raids), Top Raiders
// and Overall Average Viewers. Endpoint: public/api/community/raids.php (read only).
type R = { raider_name: string; viewers: string | number; created_at: string }
type T = { raider_name: string; raids: string | number; avg_viewers: string }
type Data = { success: boolean; recent_received: R[]; recent_sent: R[]; latest_sent: R | null; top_raiders: T[]; avg_viewers: string | null }
const FAIL = 'Could not load'

function Table({ first, rows, busy, n = 1 }: { first: string; rows: R[]; busy?: boolean; n?: number }) {
  return <div className="bp-table cm-table"><table>
    <thead><tr><th>{first}</th><th>Viewers</th><th>Date / Time</th></tr></thead>
    <tbody aria-busy={busy}>{busy ? <SkelRows cols={3} n={n} /> : rows.map((r, k) => <tr key={k}><td data-l={first}>{r.raider_name}</td><td data-l="Viewers">{r.viewers}</td><td data-l="Date / Time">{r.created_at}</td></tr>)}</tbody>
  </table></div>
}

export default function Raids() {
  const [d, setD] = useState<Data | null>(null), [err, setErr] = useState(false), [open, setOpen] = useState(false)
  useEffect(() => { get<Data>(`${API}/raids.php?ajax_action=list`).then((r) => { if (!r.success) throw new Error(); setD(r) }).catch((x) => { if ((x as Error).message !== 'http_401') setErr(true) }) }, [])
  const sent = d?.recent_sent ?? []
  return (
    <div className="page cmds cm">
      <section className="card" aria-label="Raids">
        <h3 className="card-h"><i className="fas fa-bullhorn" /> Raids</h3>
        <div className="ss-layout">
          <div>
            <h4 className="ss-h">Recent Raids - Received</h4>
            {err ? <p className="muted ss-empty">{FAIL}</p> : d && d.recent_received.length === 0 ? <p className="muted ss-empty">No received raid data available yet.</p> : <Table first="Raider" rows={d?.recent_received ?? []} busy={!d} n={6} />}
          </div>
          <div>
            <div className="cm-sec-head"><h4 className="ss-h">Latest Raid - Sent</h4><button type="button" className="btn btn-quiet cm-btn" disabled={!sent.length} onClick={() => setOpen(true)}>Show Last 5</button></div>
            {err ? <p className="muted">{FAIL}</p> : d && !d.latest_sent ? <p className="muted">No sent raid data available yet.</p> : <Table first="Target" rows={d?.latest_sent ? [d.latest_sent] : []} busy={!d} />}
            <hr className="ss-hr" />
            <h4 className="ss-h">Top Raiders</h4>
            <div aria-busy={!d && !err}>{err ? <p className="muted">{FAIL}</p> : !d ? <div className="ss-skel" aria-hidden="true">{[80, 70, 90, 60, 80].map((w, k) => <span key={k} className="bp-skel" style={{ width: `${w}%` }} />)}</div>
              : d.top_raiders.length === 0 ? <p className="muted">No data yet.</p>
              : <ul className="ss-list">{d.top_raiders.map((t, k) => <li key={k}><b>{t.raider_name}</b> - {t.raids} raids, Avg: {t.avg_viewers} viewers</li>)}</ul>}</div>
            <hr className="ss-hr" />
            <h4 className="ss-h">Overall Average Viewers</h4>
            <p className="cm-avg" aria-busy={!d && !err}>{err ? FAIL : !d ? <span className="skel w-40" /> : d.avg_viewers === null || d.avg_viewers === '' ? 'N/A' : `${d.avg_viewers} viewers`}</p>
          </div>
        </div>
      </section>
      {open && <Modal title="Last 5 Sent Raids" wide onClose={() => setOpen(false)}>{sent.length ? <Table first="Target" rows={sent} /> : <p className="muted">No sent raid data available yet.</p>}</Modal>}
    </div>
  )
}
