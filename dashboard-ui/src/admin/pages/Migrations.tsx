import { useState } from 'react'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Res, type Toast } from '../ui'

// Port of dashboard/admin/migrations.php: one card per managed database with counts, Adopt baseline,
// Apply all pending, and a table of applied (Roll back on the last one) and pending migrations with
// Review SQL. Every action asks first here (the old page only asked for destructive apply and rollback).
type Db = { db: string; label: string; error: string | null; missing: number
  applied: { id: string; description: string; applied_at: string; applied_by: string; drift: boolean }[]
  pending: { id: string; description: string; destructive: boolean; procedural: boolean; sql: string }[] }
const API = '/api/migrations.php'
const fmt = (s: string, ...v: (string | number)[]) => { let i = 0; return s.replace(/%[sd]/g, () => String(v[i++] ?? '')) }

export default function Migrations() {
  const { data, err, reload } = useLoad<{ dbs: Db[]; L: Record<string, string> }>(API)
  const [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string, ...v: (string | number)[]) => fmt(L[k] ?? k, ...v)
  const act = (title: string, ok: string, text: string, body: Record<string, string>, danger = true) => setAsk({ title, ok, danger, text, run: async () => {
    try { const r = await post<Res>(API, body); setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? '' : l('js_error_title')) }); if (r.success) await reload() }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
  } })
  return (
    <div className="cmds adm">
      <section className="card"><h2 className="card-h"><i className="fas fa-database" /> {l('page_title')}</h2><p className="muted">{l('intro')}</p></section>
      {data.dbs.map((d) => (
        <section key={d.db} className="card">
          <h2 className="card-h">{d.label} <span className="muted">({d.db})</span></h2>
          {d.error ? <div className="notice notice-error">{d.error}</div> : <>
            <p className="adm-gap">{l('counts', d.applied.length, d.pending.length)} {d.missing > 0 && <span className="adm-amber">{l('missing_warn', d.missing)}</span>}</p>
            <div className="adm-inline adm-gap">
              {d.applied.length === 0 && d.pending.length > 0 && <button type="button" className="btn btn-ghost" onClick={() => act(l('adopt_baseline'), l('js_confirm_btn'), `Mark all ${d.pending.length} pending migration(s) on ${d.db} as already applied, without running them?`, { action: 'adopt_baseline', db: d.db })}><i className="fas fa-check-double" /> {l('adopt_baseline')}</button>}
              {d.pending.length > 0 && <button type="button" className="btn" onClick={() => act(l('apply_all'), l('apply_all'), `Apply ${d.pending.filter((m) => !m.destructive).length} pending migration(s) to ${d.db}? Destructive ones are skipped, as before.`, { action: 'apply_all', db: d.db })}><i className="fas fa-forward" /> {l('apply_all')}</button>}
            </div>
            <div className="bp-table adm-left"><table>
              <thead><tr><th>{l('th_status')}</th><th>{l('th_id')}</th><th>{l('th_desc')}</th><th>{l('th_actions')}</th></tr></thead>
              <tbody>
                {d.applied.map((m, i) => (
                  <tr key={'a' + m.id}><td><span className="pill pill-ok">{l('status_applied')}</span>{m.drift && <> <span className="pill pill-warn">{l('status_drift')}</span></>}</td>
                    <td><code>{m.id}</code></td><td>{m.description}<br /><small className="muted">{m.applied_at} {'\u00b7'} {m.applied_by}</small></td>
                    <td>{i === d.applied.length - 1 && <button type="button" className="btn btn-warn" onClick={() => act(l('js_rollback_confirm_title'), l('rollback'), l('js_rollback_confirm_text', m.id), { action: 'rollback', db: d.db, id: m.id })}>{l('rollback')}</button>}</td></tr>))}
                {d.pending.map((m) => (
                  <tr key={'p' + m.id}><td><span className="pill pill-info">{l('status_pending')}</span>{m.destructive && <> <span className="pill pill-err">{l('status_destructive')}</span></>}{m.procedural && <> <span className="pill">{l('status_procedural')}</span></>}</td>
                    <td><code>{m.id}</code></td>
                    <td>{m.description}<details className="adm-review"><summary>{l('review_sql')}</summary><pre className="adm-log adm-out">{m.sql}</pre></details></td>
                    <td><button type="button" className="btn btn-ok" onClick={() => m.destructive
                      ? act(l('js_apply_confirm_title'), l('js_confirm_btn'), l('js_destructive_text', m.id), { action: 'apply', db: d.db, id: m.id, confirm_destructive: '1' })
                      : act(l('apply'), l('apply'), `Apply migration ${m.id} to ${d.db}?`, { action: 'apply', db: d.db, id: m.id, confirm_destructive: '' }, false)}>{l('apply')}</button></td></tr>))}
              </tbody>
            </table></div></>}
        </section>))}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
