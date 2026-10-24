import { useEffect, useState, type ReactNode } from 'react'
import Modal from '../../components/Modal'
import { Confirm, getJson, LoadErr, Loading, post, ToastBar, useLoad, type Ask, type Toast } from '../ui'

// Port of dashboard/admin/users.php: search, the users table (flags, beta programs,
// premium tier, dates, every action button with the old permission rules), the
// details modal with confirm-to-reveal email / API key and Export Data, and
// Act As, which now opens the beta dashboard (see admin-tails/users.php).
type U = { id: number; username: string; twitch_display_name: string; twitch_user_id: string | null; profile_image: string; is_admin: number; super_admin: number; beta_access: number; beta_programs: string; is_deceased: number; signup_date: string | null; last_login: string | null; is_restricted: boolean; has_email: boolean; email_domain: string; email_local_len: number; api_key_len: number }
type Meta = { current_admin_id: number; is_super_admin: boolean; L: Record<string, string>; title: string }
type R = { success: boolean; msg?: string; db_deleted?: boolean }
const API = '/api/users.php'
const LOGO = 'https://cdn.botofthespecter.com/logo.png'
const progs = (u: U): string[] => { try { const p = JSON.parse(u.beta_programs || '[]'); return Array.isArray(p) ? p : [] } catch { return [] } }
const sfx = (d: number) => (d >= 11 && d <= 13) ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[d % 10] || 'th'
function DateCell({ v }: { v: string | null }) {
  if (!v) return <>-</>
  const d = new Date(v.replace(' ', 'T')); if (isNaN(+d)) return <>-</>
  return <span className="adm-date"><span>{d.getDate()}{sfx(d.getDate())} {d.toLocaleString('en-US', { month: 'short' })} {d.getFullYear()}</span><span className="muted">{d.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }).replace(' ', '').toLowerCase()}</span></span>
}

export default function Users() {
  const meta = useLoad<Meta>(API)
  const [users, setUsers] = useState<U[] | null>(null), [loadErr, setLoadErr] = useState(false)
  const [tiers, setTiers] = useState<Record<string, string> | null>(null)
  const [q, setQ] = useState(() => new URLSearchParams(location.search).get('search') || '')
  const [toast, setToast] = useState<Toast | null>(null), [ask, setAsk] = useState<Ask>(null)
  const [detail, setDetail] = useState<U | null>(null), [programsFor, setProgramsFor] = useState<U | null>(null), [del, setDel] = useState<U | null>(null)
  const [actNote] = useState(() => new URLSearchParams(location.search).get('act_as') || '')
  const load = async () => {
    try { const r = await getJson<{ success: boolean; users: U[] }>(`${API}?ajax_action=list`); if (!r.success) throw new Error(); setUsers(r.users); setLoadErr(false) } catch { setLoadErr(true) }
  }
  useEffect(() => {
    void load()
    getJson<{ success: boolean; tiers: Record<string, string> }>(`${API}?ajax_action=premium`).then((r) => setTiers(r.tiers || {})).catch(() => setTiers({}))
    if (new URLSearchParams(location.search).get('act_as')) history.replaceState({}, '', location.pathname)
  }, [])
  if (meta.err) return <LoadErr err={meta.err} retry={meta.reload} />
  if (!meta.data) return <Loading />
  const { current_admin_id: me, is_super_admin: sa, L } = meta.data
  const l = (k: string) => L[k] ?? k
  const fmt = (k: string, v: Record<string, string | number>): ReactNode => {
    const parts = l(k).split(/(:[a-z]+)/g)
    return <>{parts.map((p, i) => p.startsWith(':') && p.slice(1) in v ? <b key={i}>{v[p.slice(1)]}</b> : p)}</>
  }
  const done = async (r: R, okTitle: string, okText: string, failKey: string) => {
    if (r.success) { setToast({ kind: 'ok', text: `${okTitle}: ${okText}` }); await load() } else setToast({ kind: 'err', text: r.msg || l(failKey) })
  }
  const act = (title: string, text: ReactNode, okLabel: string, body: Record<string, string | number>, okTitle: string, okText: string, failKey: string, danger = false) =>
    setAsk({ title, text, ok: okLabel, danger, run: async () => { try { await done(await post<R>(API, body), okTitle, okText, failKey) } catch (e) { setToast({ kind: 'err', text: (e as Error).message }) } } })
  const tierPill = (tid: string | null) => {
    if (tiers === null) return <span className="bp-skel adm-skel-pill" aria-hidden="true" />
    const t = tid ? tiers[tid] : null
    return t === '1000' ? <span className="pill pill-warn">{l('tier_1')}</span> : t === '2000' ? <span className="pill pill-info">{l('tier_2')}</span> : t === '3000' ? <span className="pill pill-err">{l('tier_3')}</span> : <span className="pill">{l('tier_none')}</span>
  }
  const flag = (on: boolean, cls: string, onT: string, offT: string) => on ? <span className={`pill ${cls}`} title={onT} aria-label={onT}><i className="fas fa-check" /></span> : <span className="pill" title={offT} aria-label={offT}><i className="fas fa-minus" /></span>
  const shown = (users ?? []).filter((u) => u.username.toLowerCase().includes(q.trim().toLowerCase()))
  const notes: Record<string, [string, string]> = { invalid: ['notice-error', l('act_as_invalid')], not_found: ['notice-error', l('act_as_not_found')], no_token: ['notice-warn', l('act_as_no_token')], error: ['notice-error', l('act_as_error')], started: ['notice-ok', l('act_as_started')], stopped: ['notice-info', 'Act As mode has been stopped.'] }

  const row = (u: U) => {
    const isSA = !!u.super_admin, isA = !!u.is_admin, dead = !!u.is_deceased, beta = !!u.beta_access, rest = u.is_restricted
    const canRestrict = !isSA && (!isA || sa), canDelete = u.id !== me && (sa || (!isA && !isSA)), locked = isSA && !sa
    const delTitle = u.id === me ? l('title_cannot_delete_self') : (!sa && (isA || isSA)) ? l('title_only_super_delete_admin') : dead ? l('title_memorial_cannot_delete') : l('btn_delete_user')
    const betaTitle = locked ? l('title_only_super_manage_super') : l(beta ? 'btn_remove_beta' : 'btn_give_beta')
    const restrictTitle = rest ? l('btn_unrestrict') : canRestrict ? l('btn_restrict') : l('title_cannot_restrict')
    const B = ({ t, icon, cls = 'btn-ghost', dis, on }: { t: string; icon: string; cls?: string; dis?: boolean; on: () => void }) => <button type="button" className={`btn ${cls} adm-ib`} title={t} aria-label={`${t}: ${u.username}`} disabled={dis} onClick={on}><i className={icon} /></button>
    const restrictNote = <div className="adm-left"><p><strong>{l('js_restrict_note_label')}</strong></p><p>{l('js_restrict_note_1')}</p><p>{l('js_restrict_note_2')}</p><p>{fmt('js_restrict_note_3', { unrestrict: l('btn_unrestrict') })}</p></div>
    return (
      <tr key={u.id} className={dead ? 'adm-row-memorial' : rest ? 'adm-row-restricted' : ''}>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_id')}</span>{u.id}</td>
        <td><div className="adm-user"><img src={u.profile_image || LOGO} alt="" onError={(e) => { (e.target as HTMLImageElement).src = LOGO }} /><span>{u.username}</span>
          {dead ? <span className="pill adm-memorial"><i className="fas fa-dove" /> {l('label_memorial')}</span> : rest ? <span className="pill pill-warn">{l('label_restricted')}</span> : null}</div></td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_admin')}</span>{flag(isA, 'pill-ok', l('th_admin'), l('title_not_admin'))}</td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_super_admin')}</span>{flag(isSA, 'pill-ok', l('th_super_admin'), l('title_not_super_admin'))}</td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_beta')}</span>{flag(beta, 'pill-info', l('title_beta_access'), l('title_no_beta_access'))}</td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_beta_programs')}</span>{progs(u).length ? <span className="adm-chips adm-chips-c">{progs(u).map((p) => <span key={p} className="pill pill-info">{p}</span>)}</span> : <span className="pill"><i className="fas fa-minus" /></span>}</td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_premium')}</span>{tierPill(u.twitch_user_id)}</td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_signup')}</span><DateCell v={u.signup_date} /></td>
        <td className="adm-c"><span className="adm-lbl-m">{l('th_last_login')}</span><DateCell v={u.last_login} /></td>
        <td><div className="adm-acts">
          <B t={l('btn_view_details')} icon="fas fa-eye" on={() => setDetail(u)} />
          <B t={delTitle} icon="fas fa-trash" cls="btn-danger" dis={!canDelete || dead} on={() => setDel(u)} />
          <B t={l(isA ? 'btn_remove_admin' : 'btn_give_admin')} icon="fas fa-user-shield" cls={isA ? 'btn-warn' : ''} dis={!sa || dead}
            on={() => act(l(isA ? 'js_remove_admin_title' : 'js_grant_admin_title'), fmt(isA ? 'js_remove_admin_html' : 'js_grant_admin_html', { name: u.username }), l(isA ? 'js_remove_word' : 'js_grant_word'), { admin_action: isA ? 'remove_admin' : 'grant_admin', user_id: u.id }, l('js_updated'), l(isA ? 'js_admin_access_removed' : 'js_admin_access_granted'), 'msg_update_admin_access_failed', isA)} />
          <B t={betaTitle} icon="fas fa-flask" cls={beta ? 'btn-warn' : ''} dis={dead || locked}
            on={() => act(l(beta ? 'js_remove_beta_title' : 'js_grant_beta_title'), fmt(beta ? 'js_remove_beta_html' : 'js_grant_beta_html', { name: u.username }), l(beta ? 'js_remove_word' : 'js_grant_word'), { beta_action: beta ? 'remove_beta' : 'grant_beta', user_id: u.id }, l('js_updated'), l(beta ? 'js_beta_access_removed' : 'js_beta_access_granted'), 'msg_update_beta_access_failed', beta)} />
          <B t={l('btn_manage_beta_programs')} icon="fas fa-list-check" dis={dead} on={() => setProgramsFor(u)} />
          <B t={restrictTitle} icon="fas fa-user-lock" cls={rest ? 'btn-warn' : 'btn-ghost'} dis={dead || (!rest && !canRestrict)}
            on={() => act(l(rest ? 'js_remove_restriction_title' : 'js_restrict_user_title'), <>{fmt('js_confirm_restrict_html', { action: l(rest ? 'js_action_word_unrestrict' : 'js_action_word_restrict'), name: u.username })}{!rest && restrictNote}</>, l(rest ? 'btn_unrestrict' : 'btn_restrict'), { restrict_action: rest ? 'unrestrict' : 'restrict', user_id: u.id, username: u.username, twitch_user_id: u.twitch_user_id || '' }, l('js_success'), l(rest ? 'js_restriction_removed' : 'js_user_restricted'), 'js_could_not_update_restriction', !rest)} />
          <B t={l(dead ? 'btn_remove_memorial' : 'btn_mark_memorial')} icon="fas fa-dove" cls="btn-ghost adm-memorial-btn" dis={!sa}
            on={() => act(l(dead ? 'js_remove_memorial_title' : 'js_mark_memorial_title'), fmt(dead ? 'js_remove_memorial_html' : 'js_mark_memorial_html', { name: u.username }), l(dead ? 'js_remove_word' : 'js_mark_memorial_confirm'), { deceased_action: dead ? 'unmark_deceased' : 'mark_deceased', user_id: u.id }, l(dead ? 'js_updated' : 'js_preserved'), l(dead ? 'js_memorial_status_removed' : 'js_marked_memorial'), dead ? 'msg_remove_memorial_failed' : 'msg_mark_memorial_failed', dead)} />
          {u.id !== me ? <a className="btn adm-ib" href={`${API}?act_as_user=${u.id}`} title={`${l('btn_act_as')} (beta dashboard)`} aria-label={`${l('btn_act_as')}: ${u.username}`}><i className="fas fa-user-secret" /></a>
            : <button type="button" className="btn adm-ib" disabled title={l('title_already_own_dashboard')} aria-label={l('title_already_own_dashboard')}><i className="fas fa-user-secret" /></button>}
        </div></td>
      </tr>
    )
  }

  return (
    <div className="cmds adm">
      {notes[actNote] && <div className={`notice ${notes[actNote][0]}`} role="status">{notes[actNote][1]}</div>}
      <section className="card">
        <div className="card-head">
          <h2 className="card-h"><i className="fas fa-users-cog" /> {meta.data.title}</h2>
          <div className="adm-inline">
            <input className="input" type="search" autoComplete="off" value={q} placeholder={l('search_placeholder')} aria-label={l('search_placeholder')} onChange={(e) => setQ(e.target.value)} />
            {q && <button type="button" className="btn btn-ghost adm-ib" aria-label="Clear search" onClick={() => setQ('')}><i className="fas fa-times" /></button>}
          </div>
        </div>
        <div className="bp-table adm-users"><table>
          <thead><tr><th className="adm-c">{l('th_id')}</th><th>{l('th_user')}</th><th className="adm-c">{l('th_admin')}</th><th className="adm-c">{l('th_super_admin')}</th><th className="adm-c">{l('th_beta')}</th><th className="adm-c">{l('th_beta_programs')}</th><th className="adm-c">{l('th_premium')}</th><th className="adm-c">{l('th_signup')}</th><th className="adm-c">{l('th_last_login')}</th><th className="adm-c">{l('th_actions')}</th></tr></thead>
          <tbody aria-busy={!users && !loadErr}>
            {!users && !loadErr && Array.from({ length: 8 }, (_, i) => <tr key={i} aria-hidden="true"><td colSpan={10}><span className="bp-skel bp-skel-w" /></td></tr>)}
            {loadErr && <tr><td colSpan={10} className="adm-c">{l('load_failed')}</td></tr>}
            {users && users.length === 0 && <tr><td colSpan={10} className="adm-c">{l('none_found')}</td></tr>}
            {users && shown.map(row)}
          </tbody>
        </table></div>
      </section>
      {detail && <Details u={detail} l={l} fmt={fmt} tier={tierPill(detail.twitch_user_id)} setToast={setToast} onClose={() => setDetail(null)} />}
      {programsFor && <Programs u={(users ?? []).find((x) => x.id === programsFor.id) ?? programsFor} l={l} fmt={fmt} setToast={setToast} reload={load} onClose={() => setProgramsFor(null)} />}
      {del && <DeleteFlow u={del} l={l} fmt={fmt} onClose={() => setDel(null)} onDone={async (r, withDb) => {
        if (!r.success) setToast({ kind: 'err', text: r.msg || l('msg_delete_user_failed') })
        else if (withDb && !r.db_deleted) setToast({ kind: 'err', text: `${l('js_user_deleted_title')}: ${l('js_db_delete_failed_html')} ${r.msg ? l('js_db_delete_reason').replace(':reason', r.msg) : l('js_unknown_error')}` })
        else setToast({ kind: 'ok', text: `${l('js_deleted_title')}: ${l(withDb ? 'js_deleted_user_and_db' : 'js_deleted_user_no_db')}` })
        await load()
      }} />}
      <Confirm ask={ask} onClose={() => setAsk(null)} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

type L = (k: string) => string
type F = (k: string, v: Record<string, string | number>) => ReactNode

function Reveal({ u, field, mask, l, fmt, setToast }: { u: U; field: 'email' | 'api_key'; mask: string; l: L; fmt: F; setToast: (t: Toast) => void }) {
  const [val, setVal] = useState(''), [shown, setShown] = useState(false), [asking, setAsking] = useState(false)
  const label = l(field === 'email' ? 'modal_email' : 'modal_api_key')
  const reveal = async () => {
    try { const r = await getJson<{ success: boolean; value?: string; msg?: string }>(`${API}?reveal=${u.id}&field=${field}`); if (!r.success) throw new Error(r.msg); setVal(r.value || ''); setShown(true) }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message || l('js_error') }) }
  }
  return (
    <span className="adm-reveal">
      <code>{shown ? val : mask}</code>
      {shown ? <button type="button" className="btn btn-ghost adm-ib" aria-label={`Hide ${label}`} onClick={() => setShown(false)}><i className="fas fa-eye-slash" /></button>
        : <button type="button" className="btn btn-ghost adm-ib" aria-label={`Reveal ${label}`} onClick={() => setAsking(true)}><i className="fas fa-eye" /></button>}
      {asking && <Modal title={l('js_reveal_title').replace(':label', label)} icon="fas fa-triangle-exclamation" onClose={() => setAsking(false)}>
        <p>{fmt('js_reveal_text', { label: label.replace(/:$/, '').toLowerCase() })}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setAsking(false)}>{l('js_cancel')}</button><button type="button" className="btn" data-autofocus onClick={() => { setAsking(false); void reveal() }}>{l('js_reveal_confirm')}</button></div>
      </Modal>}
    </span>
  )
}

function Details({ u, l, fmt, tier, setToast, onClose }: { u: U; l: L; fmt: F; tier: ReactNode; setToast: (t: Toast) => void; onClose: () => void }) {
  const [exp, setExp] = useState(false)
  const pretty = (s: string | null) => { if (!s) return '-'; const d = new Date(s.replace(' ', 'T')); if (isNaN(+d)) return '-'; return `${d.getDate()}${sfx(d.getDate())} ${d.toLocaleString('en-US', { month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}` }
  const tf = (on: number) => on ? <span className="pill pill-ok">{l('val_true')}</span> : <span className="pill pill-err">{l('val_false')}</span>
  const dot = '\u2022'
  const doExport = async () => {
    try { const r = await post<R>('/api/export_user_data.php', { user_id: u.id, email: '', username: u.username }); if (r.success) { setToast({ kind: 'ok', text: `${l('js_queued')}: ${l('js_export_started')}` }); onClose() } else setToast({ kind: 'err', text: r.msg || l('js_could_not_start_export') }) }
    catch { setToast({ kind: 'err', text: l('js_could_not_reach_export') }) }
  }
  return (
    <Modal title={l('modal_title')} icon="fas fa-user-secret" onClose={onClose}>
      <div className="adm-modal-scroll">
        <div className="adm-user adm-user-lg"><img src={u.profile_image || LOGO} alt={l('modal_profile_alt')} onError={(e) => { (e.target as HTMLImageElement).src = LOGO }} />
          <div><p className="adm-strong">{u.twitch_display_name}</p><p className="muted">{l('modal_twitch_id')} <span className="adm-danger">{u.twitch_user_id}</span></p></div></div>
        <table className="adm-kv adm-gap"><tbody>
          <tr><th scope="row">{l('th_admin')}</th><td>{tf(u.is_admin)}</td></tr>
          <tr><th scope="row">{l('modal_beta_access')}</th><td>{tf(u.beta_access)}</td></tr>
          <tr><th scope="row">{l('th_beta_programs')}</th><td>{progs(u).length ? <span className="adm-chips">{progs(u).map((p) => <span key={p} className="pill pill-info">{p}</span>)}</span> : <span className="pill">{l('tier_none')}</span>}</td></tr>
          <tr><th scope="row">{l('modal_premium_access')}</th><td>{tier}</td></tr>
          <tr><th scope="row">{l('modal_signup_date')}</th><td>{pretty(u.signup_date)}</td></tr>
          <tr><th scope="row">{l('th_last_login')}</th><td>{pretty(u.last_login)}</td></tr>
          <tr><th scope="row">{l('modal_email')}</th><td>{u.has_email ? <Reveal u={u} field="email" mask={dot.repeat(Math.min(u.email_local_len, 12)) + u.email_domain} l={l} fmt={fmt} setToast={setToast} /> : <span className="muted">{l('val_none')}</span>}</td></tr>
          <tr><th scope="row">{l('modal_api_key')}</th><td><Reveal u={u} field="api_key" mask={dot.repeat(Math.min(u.api_key_len, 16))} l={l} fmt={fmt} setToast={setToast} /></td></tr>
        </tbody></table>
      </div>
      <div className="modal-acts">
        <button type="button" className="btn btn-ghost" onClick={onClose}>{l('btn_close')}</button>
        <button type="button" className="btn" title={l('btn_export_user_data')} onClick={() => setExp(true)}><i className="fas fa-download" /> {l('btn_export_data')}</button>
      </div>
      {exp && <Modal title={l('js_export_title')} icon="fas fa-circle-question" onClose={() => setExp(false)}>
        <p>{fmt('js_export_html', { name: u.username, id: u.id, email: l('js_export_account_email') })}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setExp(false)}>{l('js_cancel')}</button><button type="button" className="btn" data-autofocus onClick={() => { setExp(false); void doExport() }}>{l('js_yes_export')}</button></div>
      </Modal>}
    </Modal>
  )
}

function Programs({ u, l, fmt, setToast, reload, onClose }: { u: U; l: L; fmt: F; setToast: (t: Toast) => void; reload: () => Promise<void>; onClose: () => void }) {
  const [val, setVal] = useState(''), [busy, setBusy] = useState(false), [rm, setRm] = useState('')
  const send = async (action: 'add' | 'remove', program: string) => {
    setBusy(true)
    try { const r = await post<R>(API, { beta_programs_action: action, user_id: u.id, program }); if (r.success) { setToast({ kind: 'ok', text: `${l(action === 'add' ? 'js_added' : 'js_removed')}: ${program}` }); setVal(''); await reload() } else setToast({ kind: 'err', text: r.msg || l('msg_update_beta_programs_failed') }) }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    setBusy(false)
  }
  const list = progs(u)
  return (
    <Modal title={l('js_beta_programs_title').replace(':name', u.username)} icon="fas fa-list-check" onClose={onClose}>
      <p>{l('js_current_programs_label')}</p>
      <div className="adm-chips adm-gap">{list.length ? list.map((p) => <button key={p} type="button" className="pill pill-info adm-chip-x" aria-label={`Remove ${p}`} onClick={() => setRm(p)}>{p} <i className="fas fa-times" /></button>) : <em>{l('js_none')}</em>}</div>
      <form className="adm-inline" onSubmit={(e) => { e.preventDefault(); const p = val.trim().toLowerCase(); if (p) void send('add', p) }}>
        <input className="input" value={val} placeholder={l('js_program_placeholder')} aria-label={l('js_program_placeholder')} onChange={(e) => setVal(e.target.value)} />
        <button type="submit" className="btn" disabled={busy || !val.trim()}>{l('js_add_word')}</button>
      </form>
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>{l('js_close')}</button></div>
      {rm && <Modal title={l('js_remove_program_title')} icon="fas fa-triangle-exclamation" onClose={() => setRm('')}>
        <p>{fmt('js_remove_program_html', { program: rm })}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setRm('')}>{l('js_cancel')}</button><button type="button" className="btn btn-danger" data-autofocus onClick={() => { const p = rm; setRm(''); void send('remove', p) }}>{l('js_remove_word')}</button></div>
      </Modal>}
    </Modal>
  )
}

// The old three-step delete: confirm, ask whether to drop the user's databases, final confirm.
function DeleteFlow({ u, l, fmt, onClose, onDone }: { u: U; l: L; fmt: F; onClose: () => void; onDone: (r: R, withDb: boolean) => Promise<void> }) {
  const [step, setStep] = useState(1), [withDb, setWithDb] = useState(false), [busy, setBusy] = useState(false)
  const run = async () => { setBusy(true); let r: R; try { r = await post<R>(API, { delete_user_id: u.id, delete_db: withDb ? 1 : 0 }) } catch (e) { r = { success: false, msg: (e as Error).message } } setBusy(false); onClose(); await onDone(r, withDb) }
  return (
    <Modal title={l(step === 1 ? 'js_delete_user_title' : step === 2 ? 'js_delete_db_title' : 'js_final_confirm_title')} icon="fas fa-triangle-exclamation" onClose={onClose}>
      {step === 1 && <><p>{fmt('js_delete_user_html', { name: u.username })}</p>{!!u.is_deceased && <p className="adm-memorial-warn"><strong>{l('js_memorial_delete_warning')}</strong></p>}
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>{l('js_no')}</button><button type="button" className="btn btn-danger" data-autofocus onClick={() => setStep(2)}>{l('js_yes')}</button></div></>}
      {step === 2 && <><p>{fmt('js_delete_db_html', { name: u.username })}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => { setWithDb(false); setStep(3) }}>{l('js_no')}</button><button type="button" className="btn btn-danger" onClick={() => { setWithDb(true); setStep(3) }}>{l('js_yes')}</button></div></>}
      {step === 3 && <><p>{l(withDb ? 'js_final_confirm_with_db' : 'js_final_confirm_no_db').replace(':name', u.username)}</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>{l('js_cancel')}</button><button type="button" className="btn btn-danger" disabled={busy} onClick={run}>{l('js_yes_delete')}</button></div></>}
    </Modal>
  )
}
