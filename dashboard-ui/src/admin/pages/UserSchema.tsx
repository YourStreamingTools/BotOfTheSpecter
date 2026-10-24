import { useRef, useState } from 'react'
import { Confirm, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/user_schema.php: user search with Check / Apply / Scan all / Fix all,
// the result card (pending changes with SQL, skipped columns, apply log) and the fleet table.
type Change = { kind: string; table?: string; column?: string; message?: string; sql?: string; destructive?: boolean }
type Insp = { success?: boolean; ok?: boolean; message?: string; db_exists?: boolean; current?: boolean; pending?: Change[]; skipped?: { message: string }[]; existing_tables?: number; expected_tables?: number; apply_errors?: string[] }
type Log = { level: string; message: string }
const API = '/api/user_schema.php'
const fmt = (s: string, m: Record<string, string | number>) => s.replace(/%([sdte])/g, (_, k) => String(m[k] ?? ''))

export default function UserSchema() {
  const { data, err, reload } = useLoad<{ users: { id: number; username: string }[]; L: Record<string, string> }>(API)
  const [q, setQ] = useState(''), [sel, setSel] = useState(''), [open, setOpen] = useState(false)
  const [res, setRes] = useState<{ user: string; d: Insp; logs: Log[] } | null>(null), [busy, setBusy] = useState(''), [progress, setProgress] = useState('')
  const [fleet, setFleet] = useState<Record<string, Insp | null> | null>(null), [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  const resRef = useRef<HTMLElement>(null)
  if (err) return <LoadErr err={err} retry={reload} />
  if (!data) return <Loading />
  const L = data.L, l = (k: string) => L[k] ?? k
  const kinds: Record<string, string> = Object.fromEntries(Object.keys(L).filter((k) => k.startsWith('kind_')).map((k) => [k.slice(5), L[k]]))
  const status = (d?: Insp | null) => !d ? '\u2026' : (d.success === false || d.ok === false) ? l('status_failed') : !d.db_exists ? l('status_missing_db') : d.current ? l('status_current') : l('status_pending')
  const ok = (d?: Insp | null) => !!d && d.success !== false && d.ok !== false
  const matches = data.users.filter((u) => !q || u.username.toLowerCase().includes(q.toLowerCase())).slice(0, 40)
  const check = async (u: string, silent: boolean) => {
    let d: Insp
    try { d = await post<Insp>(API, { action: 'check', username: u }) } catch (e) { d = { success: false, message: (e as Error).message } }
    if (!silent) { if (d.success === false) setToast({ kind: 'err', text: d.message || l('status_failed') }); else setRes({ user: u, d, logs: [] }) }
    setFleet((f) => f ? { ...f, [u]: d } : f); return d
  }
  const pick = () => { const u = sel || q.trim(); if (!u) { setToast({ kind: 'err', text: l('err_pick_user') }); return '' } return u }
  const doCheck = async () => { const u = pick(); if (!u) return; setBusy('check'); await check(u, false); setBusy('') }
  const doApply = () => {
    const u = pick(); if (!u || !res) return
    const destructive = (res.d.pending || []).some((c) => c.destructive)
    setAsk({ title: l('js_apply_confirm_title'), ok: l('js_confirm_btn'), danger: destructive, text: fmt(l(destructive ? 'js_apply_destructive_text' : 'js_apply_confirm_text'), { s: u }), run: async () => {
      setBusy('apply')
      try { const r = await post<{ success: boolean; message?: string; logs?: Log[]; inspect?: Insp }>(API, { action: 'apply', username: u }); if (!r.success) setToast({ kind: 'err', text: r.message || l('status_failed') }); else { setRes({ user: u, d: r.inspect || {}, logs: r.logs || [] }); setFleet((f) => f ? { ...f, [u]: r.inspect || {} } : f) } }
      catch (e) { setToast({ kind: 'err', text: (e as Error).message }) } finally { setBusy('') }
    } })
  }
  const scan = async () => {
    setBusy('scan'); const f: Record<string, Insp | null> = {}; data.users.forEach((u) => { f[u.username] = null }); setFleet(f)
    let done = 0; const queue = data.users.map((u) => u.username)
    await Promise.all([0, 1, 2].map(async () => { while (queue.length) { await check(queue.shift()!, true); done++; setProgress(fmt(l('scan_progress'), { d: done, t: data.users.length })) } }))
    setBusy('')
  }
  const fixAll = () => {
    const targets = fleet ? data.users.filter((u) => { const d = fleet[u.username]; return !d || !ok(d) || !d.current }) : data.users
    if (!targets.length) { setToast({ kind: 'ok', text: l('js_fix_all_none') }); return }
    const destructive = !!fleet && targets.some((u) => (fleet[u.username]?.pending || []).some((c) => c.destructive))
    setAsk({ title: l('js_fix_all_title'), ok: l('js_confirm_btn'), danger: true, text: fmt(l(destructive ? 'js_fix_all_destructive' : 'js_fix_all_text'), { d: targets.length }), run: async () => {
      setBusy('fix'); if (!fleet) { const f: Record<string, Insp | null> = {}; data.users.forEach((u) => { f[u.username] = null }); setFleet(f) }
      let done = 0, failed = 0; const queue = targets.map((u) => u.username)
      await Promise.all([0, 1].map(async () => { while (queue.length) {
        const u = queue.shift()!; let d: Insp
        try { const r = await post<{ success: boolean; message?: string; logs?: Log[]; inspect?: Insp }>(API, { action: 'apply', username: u })
          if (!r.success) d = { success: false, ok: false, apply_errors: [r.message || l('status_failed')] }
          else { const errs = (r.logs || []).filter((x) => x.level === 'error').map((x) => x.message); d = { success: true, apply_errors: errs, ...(r.inspect || {}) }; if (sel === u) setRes({ user: u, d, logs: r.logs || [] }) }
        } catch (e) { d = { success: false, ok: false, apply_errors: [(e as Error).message] } }
        if (!ok(d) || (d.apply_errors || []).length || !d.current) failed++
        setFleet((f) => ({ ...(f || {}), [u]: d })); done++; setProgress(fmt(l('fix_progress'), { d: done, t: targets.length }))
      } }))
      setBusy(''); setToast({ kind: failed ? 'err' : 'ok', text: fmt(l('js_fix_all_done'), { d: done, e: failed }) })
    } })
  }
  const openUser = async (u: string) => { setSel(u); setQ(u); await check(u, false); resRef.current?.scrollIntoView({ behavior: 'smooth' }) }
  const d = res?.d, pend = d?.pending || []

  return (
    <div className="cmds adm">
      <section className="card">
        <h2 className="card-h"><i className="fas fa-database" /> {l('page_title')}</h2>
        <p className="muted adm-gap">{l('intro')}</p>
        <label className="fld adm-rel"><span className="fld-label">{l('user_label')}</span>
          <span className="adm-inline"><input className="input" type="search" autoComplete="off" value={q} placeholder={l('search_placeholder')} onFocus={() => setOpen(true)}
            onChange={(e) => { setQ(e.target.value); setSel(data.users.some((u) => u.username === e.target.value) ? e.target.value : ''); setRes(null); setOpen(true) }} /></span>
          {open && matches.length > 0 && !(matches.length === 1 && matches[0].username === q) && <ul className="adm-pick">{matches.map((u) => <li key={u.id}><button type="button" className={u.username === sel ? 'is-sel' : ''} onClick={() => { setSel(u.username); setQ(u.username); setRes(null); setOpen(false) }}>{u.username}</button></li>)}</ul>}
        </label>
        <div className="adm-inline">
          <button type="button" className="btn" disabled={!!busy} onClick={doCheck}><i className="fas fa-stethoscope" /> {l('check')}</button>
          <button type="button" className="btn btn-ok" disabled={!!busy || !res || !!res.d.current} onClick={doApply}><i className="fas fa-play" /> {l('apply')}</button>
          <button type="button" className="btn btn-ghost" disabled={!!busy} onClick={scan}><i className="fas fa-list-check" /> {l('scan_all')}</button>
          <button type="button" className="btn btn-warn" disabled={!!busy} onClick={fixAll}><i className="fas fa-wrench" /> {l('fix_all')}</button>
        </div>
      </section>
      {res && d && <section className="card" ref={resRef}>
        <h2 className="card-h">{fmt(l('result_title'), { s: res.user })}</h2>
        <p className="adm-gap">{d.current ? l('no_pending') : fmt(l('summary'), { d: pend.length, s: d.existing_tables || 0, t: d.expected_tables || 0 })}</p>
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_kind')}</th><th>{l('th_target')}</th><th>{l('th_change')}</th></tr></thead>
          <tbody>{pend.length === 0 ? <tr><td colSpan={3}>{l('no_pending')}</td></tr> : pend.flatMap((c, i) => [
            <tr key={i}><td><span className={`pill ${c.destructive ? 'pill-err' : 'pill-info'}`}>{kinds[c.kind] || c.kind}</span></td><td><code>{[c.table, c.column].filter(Boolean).join('.') || '-'}</code></td><td>{c.message}</td></tr>,
            ...(c.sql ? [<tr key={i + 's'}><td colSpan={3}><pre className="adm-log adm-out">{c.sql}</pre></td></tr>] : [])])}</tbody>
        </table></div>
        {(d.skipped || []).length > 0 && <details className="adm-review"><summary>{fmt(l('skipped'), { d: d.skipped!.length })}</summary><ul>{d.skipped!.map((s, i) => <li key={i}>{s.message}</li>)}</ul></details>}
        {res.logs.length > 0 && <details className="adm-review" open><summary>{l('apply_log')}</summary><pre className="adm-log adm-out">{res.logs.map((e) => `[${e.level === 'error' ? 'ERROR' : 'LOG'}] ${e.message || ''}`).join('\n')}</pre></details>}
      </section>}
      <section className="card">
        <div className="card-head"><h2 className="card-h">{l('fleet_title')}</h2><span className="muted" aria-live="polite">{progress}</span></div>
        <div className="bp-table adm-left"><table>
          <thead><tr><th>{l('th_user')}</th><th>{l('th_status')}</th><th>{l('th_pending')}</th><th>{l('th_actions')}</th></tr></thead>
          <tbody>{fleet && data.users.map((u) => { const f = fleet[u.username]; return (
            <tr key={u.id}><td><code>{u.username}</code>{(f?.apply_errors || []).length > 0 && <div className="adm-err">{f!.apply_errors!.join(' / ')}</div>}</td>
              <td><span className={`pill ${ok(f) && f!.current ? 'pill-ok' : f && !ok(f) ? 'pill-err' : 'pill-warn'}`}>{status(f)}</span></td>
              <td>{f && ok(f) ? (f.pending || []).length : '-'}</td>
              <td>{f && <button type="button" className="btn btn-ghost" onClick={() => openUser(u.username)}>{l('open')}</button>}</td></tr>) })}</tbody>
        </table></div>
      </section>
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      {toast && <ToastBar t={toast} onClose={() => setToast(null)} />}
    </div>
  )
}
