import { useEffect, useRef, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { UrlReveal } from '../components/UrlReveal'
import { get } from '../bot'
import { Counter, Switch, ToastBar, send, type Toast } from './commands/shared'


// Port of dashboard/working-or-study.php. Everything goes through
// public/api/working-or-study/working-or-study.php (scripts/sync-live-api.sh).
// The live page keeps two sockets open in the browser; here every action is one
// request and the server sends the overlay events. The only network timer is a
// timer status check every 60s, only while the timer runs and the tab is shown.
const API = '/api/working-or-study/working-or-study.php'
type Settings = { focus_minutes: number; micro_break_minutes: number; recharge_break_minutes: number; cycle_count: number; show_cycle_badge: number; auto_cycle_enabled: number; theme: string; list_view_mode: string }
type TaskSettings = { require_approval: number; default_reward_points: number; allow_user_tasks: number; task_visible_overlay: number }
type Page = { success: boolean; has_key: boolean; settings: Settings; task_settings: TaskSettings; commands: Record<string, number> }
type STask = { id: number; title: string; description: string; category: string; status: string; reward_points: number; project: string | null; backlog_position: number | null }
type UTask = STask & { user_name: string; approval_status: string }
type Timer = { phase?: string; remainingSeconds?: number; timerRunning?: boolean; timerPaused?: boolean; phaseLabel?: string; phaseStatus?: string }
type Stats = { totalSessions?: number; sessionsCompleted?: number; totalTimeLogged?: number; phaseCounts?: { focus?: number; micro?: number; recharge?: number } }
type Status = { success: boolean; overlay: boolean; state: string | null; timer: Timer | null; stats: Stats | null; error?: string }
type Opt = { value: string; label: string }
const THEMES: Opt[] = [{ value: 'dark', label: 'Dark (default)' }, { value: 'peachy', label: 'Peachy, warm coral' }, { value: 'ocean', label: 'Ocean, cool blue' }, { value: 'forest', label: 'Forest, green and earth' }, { value: 'midnight', label: 'Midnight, deep purple' }, { value: 'pride', label: 'Pride, rainbow' }]
const VIEWS: Opt[] = [{ value: 'split', label: 'Split, separate panels' }, { value: 'unified', label: 'Unified, one merged list' }]
const COMMANDS_OLD: [string, string][] = [['task', 'Set your current task.'], ['done', 'Complete your task, and earn points if rewards are on.'], ['rename', 'Rename your current task.'], ['remove', 'Remove your current task.'], ['mytasks', 'Show your active task and backlog titles.'], ['now', 'Set your active task now, or promote a backlog item.'], ['later', 'Queue a task at the end of your backlog.'], ['soon', 'Queue a task at the front of your backlog.'], ['backlog', 'Show your queued backlog.'], ['project', 'Switch your active project.'], ['projects', 'List your projects.'], ['personaltimer', 'Personal timers: !timer <minutes> <title>, focus tasks and work/break cycles. Aliases !focus, !ptimer, !mytimer.'], ['checktimer', 'Check time left on your personal timer. Alias !ctimer.'], ['timerhelp', 'Show all personal timer modes and aliases. Alias !thelp.'], ['studytimer', 'Control the main timer status, auto-pilot and durations. Streamer and mods only.'], ['studyhelp', 'Show the quick-start guide for these commands.']]
const STATUS_PILL: Record<string, string> = { active: 'pill-ok', completed: 'pill-info', hidden: 'pill-off', pending: 'pill-warn', rejected: 'pill-err' }
const APPROVAL: Record<string, [string, string]> = { auto: ['pill-off', 'Auto'], pending_approval: ['pill-warn', 'Needs approval'], approved: ['pill-ok', 'Approved'], rejected: ['pill-err', 'Rejected'] }
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)
const mmss = (s: number) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
const hm = (s: number) => { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return h ? `${h}h ${m}m` : `${m}m` }
const post = async <T extends { success: boolean; error?: string }>(body: Record<string, string>) => {
  const r = await send<T>(API, body)
  if (!r.success) throw new Error(r.error || 'Could not save. Try again.')
  return r
}

// Old page wording, word for word (dashboard/lang/en.php).
const COMMANDS: [string, string][] = [['task', 'set your current task'], ['done', 'complete your task (and earn points if rewards are on)'], ['rename', 'rename your current task'], ['remove', 'remove your current task'], ['mytasks', 'show your active task and backlog titles (fits one chat message)'], ['now', 'set your active task now (or promote a backlog item / skip)'], ['later', 'queue a task at the end of your backlog'], ['soon', 'queue a task at the front of your backlog'], ['backlog', 'show your queued backlog'], ['project', 'switch your active project (scopes your tasks to it)'], ['projects', 'list your projects'], ['personaltimer', 'general: !timer <minutes> <title>; focus task: !timer <minutes> "title" focus; cycles: !timer <work>/<break>/<cycles> (aliases: !focus, !ptimer, !mytimer)'], ['checktimer', 'check time left on your general or focus/break timer (alias: !ctimer)'], ['timerhelp', 'show all personal timer modes and aliases (alias: !thelp)'], ['studytimer', 'control the main group timer status, auto-pilot, and durations (streamer/mod only)'], ['studyhelp', 'show the quick-start guide for working & study commands']]
void COMMANDS_OLD
const PHASES: Record<string, string> = { focus: 'Focus Sprint', micro: 'Micro Break', recharge: 'Recharge Stretch' }
const urlOf = async (kind: string) => { const r = await get<{ success: boolean; url?: string; error?: string }>(`${API}?beta=url&kind=${kind}`); if (!r.success || !r.url) throw new Error(r.error || 'Could not get the overlay URL.'); return r.url }
async function copyLink(kind: string, what: string, toast: (t: Toast) => void) {
  try { await navigator.clipboard.writeText(await urlOf(kind)); toast({ kind: 'ok', text: `${what} copied.` }) }
  catch (e) { toast({ kind: 'err', text: (e as Error).message || 'Could not copy the link.' }) }
}

export default function WorkingStudy() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  useEffect(() => { get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r) }).catch(() => setErr('The Working & Study page could not load. Refresh to try again.')) }, [])
  if (err) return <div className="page cmds mp ws"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp ws"><section className="card"><p className="muted">Loading</p></section></div>
  return (
    <div className="page cmds mp ws">
      <OverlayControl page={page} setPage={setPage} toast={setToast} />
      <TaskManager page={page} setPage={setPage} toast={setToast} />
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

// [key, label, icon, help, max]
const DUR: [keyof Settings, string, string, string, number][] = [
  ['focus_minutes', 'Focus sprint duration', 'fas fa-fire', 'How long to focus before a break', 600],
  ['micro_break_minutes', 'Micro break duration', 'fas fa-mug-hot', 'Quick break duration', 120],
  ['recharge_break_minutes', 'Recharge break duration', 'fas fa-leaf', 'Longer break for recharging', 240],
  ['cycle_count', 'Focus cycles per session', 'fas fa-repeat', 'How many focus sprints make up one planned session.', 50]]

function OverlayControl({ page, setPage, toast }: { page: Page; setPage: (p: Page) => void; toast: (t: Toast) => void }) {
  const s = page.settings
  const [raw, setRaw] = useState<Record<string, string>>(() => Object.fromEntries(DUR.map(([k]) => [k, String(s[k])])))
  const [st, setSt] = useState<Status | null>(null)
  const [busy, setBusy] = useState('')
  const [left, setLeft] = useState(0)
  const [reset, setReset] = useState(false)
  const busyRef = useRef(false)
  const bad = (k: string) => { const max = DUR.find(([n]) => n === k)![4], v = raw[k]; return !/^\d+$/.test(v) || Number(v) < 1 || Number(v) > max ? `Enter 1 to ${max}.` : '' }
  const apply = (r: Status) => { setSt(r); setLeft(Math.max(0, Math.round(Number(r.timer?.remainingSeconds) || 0))) }
  const known = !!st?.overlay
  const state = known ? (st!.state || (st!.timer?.timerRunning ? 'running' : st!.timer?.timerPaused ? 'paused' : 'stopped')) : ''
  const check = async () => {
    if (busyRef.current) return
    busyRef.current = true
    try { apply(await post<Status>({ action: 'timer_status' })) } catch { /* keep the last state */ }
    busyRef.current = false
  }
  useEffect(() => { check() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  // Counts down locally; one short status check every 60s while running and shown.
  useEffect(() => {
    if (state !== 'running') return
    const tick = window.setInterval(() => setLeft((n) => Math.max(0, n - 1)), 1000)
    const poll = window.setInterval(() => { if (!document.hidden) check() }, 60000)
    return () => { clearInterval(tick); clearInterval(poll) }
  }, [state]) // eslint-disable-line react-hooks/exhaustive-deps
  // Like the old page: every change saves (and the server tells the overlay).
  async function save(next: Settings, quiet = false) {
    try { await post({ action: 'save_settings', ...Object.fromEntries(Object.entries(next).map(([k, v]) => [k, String(v)])) }); setPage({ ...page, settings: next }); if (!quiet) toast({ kind: 'ok', text: 'Overlay settings saved.' }); return true }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }); return false }
  }
  const fromRaw = (): Settings | null => DUR.some(([k]) => bad(k)) ? null : ({ ...page.settings, ...Object.fromEntries(DUR.map(([k]) => [k, Number(raw[k])])) } as Settings)
  async function blurSave(k: keyof Settings) { if (bad(k) || Number(raw[k]) === page.settings[k]) return; const n = fromRaw(); if (n) await save(n, true) }
  async function act(k: string, body: Record<string, string>, ok: string) {
    if (busyRef.current) return
    busyRef.current = true; setBusy(k)
    try { const r = await post<Status>(body); apply(r); toast({ kind: 'ok', text: r.overlay ? ok : `${ok} The overlay did not answer, so check it is open in OBS.` }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    busyRef.current = false; setBusy('')
  }
  async function update() { const n = fromRaw(); if (!n) { toast({ kind: 'err', text: 'Fix the highlighted duration first.' }); return } setBusy('upd'); if (await save(n, true)) toast({ kind: 'ok', text: 'Overlay updated.' }); setBusy('') }
  async function resetAll() {
    setReset(false)
    const n = { ...page.settings, focus_minutes: 60, micro_break_minutes: 5, recharge_break_minutes: 30 }
    if (!(await save(n, true))) return
    setRaw({ ...raw, focus_minutes: '60', micro_break_minutes: '5', recharge_break_minutes: '30' })
    act('reset_all', { action: 'timer_command', command: 'reset_all' }, 'Overlay reset. Durations are back to 60, 5 and 30 minutes.')
  }
  const dis = !!busy || !page.has_key
  const big = (k: string, cls: string, icon: string, label: string, onClick: () => void) =>
    <button type="button" className={`btn ws-big ${cls}`} disabled={dis} onClick={onClick}><i className={busy === k ? 'fas fa-spinner fa-spin' : icon} /> {label}</button>
  const phase = (st?.timer?.phase && PHASES[st.timer.phase]) ? st.timer.phase : 'focus'
  const stx = st?.stats, pc = stx?.phaseCounts ?? {}
  const tiles: [string, string, string, string][] = [
    ['Focus Sprints', 'fas fa-fire', 'ws-c-focus', String(Number(pc.focus) || 0)],
    ['Micro Breaks', 'fas fa-mug-hot', 'ws-c-micro', String(Number(pc.micro) || 0)],
    ['Recharge Breaks', 'fas fa-leaf', 'ws-c-recharge', String(Number(pc.recharge) || 0)],
    ['Total Sessions', 'fas fa-hourglass-half', 'ws-c-focus', String(Number(stx?.totalSessions ?? stx?.sessionsCompleted) || 0)],
    ['Total Focus Time', 'fas fa-clock', 'ws-c-micro', hm(Number(stx?.totalTimeLogged) || 0)]]
  return (
    <section className="card ws-card" aria-label="Working / Study Overlay Control">
      <div className="card-title-row"><h3 className="card-h">Working / Study Overlay Control</h3>
        <button type="button" className="btn" disabled={!page.has_key} onClick={() => copyLink('timer', 'Timer overlay link', toast)}><i className="fas fa-copy" /> Copy Timer Overlay Link</button></div>
      <div className="ws-url"><UrlReveal mask="https://overlay.botofthespecter.com/working-or-study.php?code=••••••••••••&timer" disabled={!page.has_key} toast={toast} label="Timer overlay URL" fetchUrl={() => urlOf('timer')} /></div>
      <div className="ws-cols">
        <div className="ws-col">
          <h4 className="ws-h">Duration Settings</h4>
          {DUR.map(([k, l, icon, help, max]) => { const m = bad(k); return (
            <div key={k} className="ws-fld"><label htmlFor={`ws-${k}`} className="ws-lbl"><i className={icon} /> {l}</label>
              <span className={`mp-numbox ws-num${m ? ' is-bad' : ''}`}><input id={`ws-${k}`} type="number" inputMode="numeric" min={1} max={max} aria-invalid={!!m} aria-describedby={`ws-${k}-h`} value={raw[k]} onChange={(e) => setRaw({ ...raw, [k]: e.target.value })} onBlur={() => blurSave(k)} />{k !== 'cycle_count' && <span className="mp-unit">min</span>}</span>
              <span id={`ws-${k}-h`} className={`small ${m ? 'mp-bad' : 'muted'}`}>{m || help}</span></div>) })}
          <div className="ws-fld"><div className="ws-check"><Switch on={!!s.show_cycle_badge} label="Show cycle badge on overlay" onChange={(v) => save({ ...s, show_cycle_badge: v ? 1 : 0 })} /><span>Show cycle badge on overlay</span></div>
            <span className="small muted">Displays an "N / M" badge next to the timer phase, advancing each time a focus sprint completes.</span></div>
          <div className="ws-fld"><div className="ws-check"><Switch on={!!s.auto_cycle_enabled} label="Auto-Pilot (Auto phase transition)" onChange={(v) => save({ ...s, auto_cycle_enabled: v ? 1 : 0 })} /><span>Auto-Pilot (Auto phase transition)</span></div></div>
          <div className="ws-fld"><button type="button" className="btn btn-ghost ws-fit" disabled={dis} onClick={async () => { setBusy('cyc'); try { await post({ action: 'reset_cycle' }); toast({ kind: 'ok', text: 'Cycle counter reset.' }) } catch (e) { toast({ kind: 'err', text: (e as Error).message }) } setBusy('') }}><i className={busy === 'cyc' ? 'fas fa-spinner fa-spin' : 'fas fa-arrow-rotate-left'} /> Reset Cycle Counter</button></div>
          <div className="ws-fld"><span className="ws-lbl"><i className="fas fa-palette" /> Overlay theme</span><Combobox label="Overlay theme" searchable={false} value={s.theme} onChange={(v) => save({ ...s, theme: v })} options={THEMES} />
            <span className="small muted">Pick the visual style for the working/study overlay. Changes apply to the overlay live, no reload needed.</span></div>
          <div className="ws-fld"><span className="ws-lbl"><i className="fas fa-table-columns" /> Task list view</span><Combobox label="Task list view" searchable={false} value={s.list_view_mode} onChange={(v) => save({ ...s, list_view_mode: v })} options={VIEWS} />
            <span className="small muted">Split shows separate streamer and viewer panels; Unified merges them into one "username: task" list. Changes apply to the overlay live, no reload needed.</span></div>
          <p className="small muted">Adjust durations here. Use Overlay Control to apply or reset the overlay.</p>
        </div>
        <div className="ws-col">
          <h4 className="ws-h"><i className="fas fa-bolt" /> Phase Controls</h4>
          {big('focus', 'ws-b-focus', 'fas fa-hourglass-start', 'Start Focus Sprint', () => act('focus', { action: 'timer_phase', phase: 'focus' }, 'Focus sprint started.'))}
          {big('micro', 'ws-b-micro', 'fas fa-mug-hot', 'Start Micro Break', () => act('micro', { action: 'timer_phase', phase: 'micro' }, 'Micro break started.'))}
          {big('recharge', 'ws-b-recharge', 'fas fa-leaf', 'Start Recharge Stretch', () => act('recharge', { action: 'timer_phase', phase: 'recharge' }, 'Recharge stretch started.'))}
          <h4 className="ws-h"><i className="fas fa-sliders" /> Overlay Control</h4>
          {big('upd', 'ws-b-accent', 'fas fa-cloud-arrow-up', 'Update Overlay', update)}
          {big('reset_all', 'btn-ghost ws-b-outline', 'fas fa-rotate-left', 'Reset Overlay', () => setReset(true))}
        </div>
        <div className="ws-col">
          <h4 className="ws-h"><i className="fas fa-play" /> Timer Controls</h4>
          {/* Only the buttons that fit the timer: running = pause, paused = resume, otherwise start (overlay closed counts as not running). */}
          {state !== 'running' && state !== 'paused' && big('start', 'ws-b-accent', 'fas fa-play', 'Start', () => act('start', { action: 'timer_command', command: 'start' }, 'Timer started.'))}
          {state === 'running' && big('pause', 'ws-b-recharge', 'fas fa-pause', 'Pause', () => act('pause', { action: 'timer_command', command: 'pause' }, 'Timer paused.'))}
          {state === 'paused' && big('resume', 'ws-b-green', 'fas fa-rotate-right', 'Resume', () => act('resume', { action: 'timer_command', command: 'resume' }, 'Timer resumed.'))}
          {big('reset', 'ws-b-micro', 'fas fa-arrows-rotate', 'Reset', () => act('reset', { action: 'timer_command', command: 'reset' }, 'Timer reset.'))}
          {(state === 'running' || state === 'paused') && big('stop', 'ws-b-focus', 'fas fa-stop', 'Stop', () => act('stop', { action: 'timer_command', command: 'stop' }, 'Timer stopped.'))}
        </div>
      </div>
      <div className="ws-h-row"><h4 className="ws-h"><i className="fas fa-hourglass" /> Live Timer</h4>
        <button type="button" className="btn btn-ghost mp-x" aria-label="Check the overlay again" disabled={!!busy} onClick={check}><i className="fas fa-rotate" /></button></div>
      <div className="ws-panel ws-live" aria-live="polite">
        <p className="muted">{known ? `${st!.timer?.phaseLabel || PHASES[phase]} · ${st!.timer?.phaseStatus || 'Waiting to start'}` : `${PHASES.focus} · ${st ? 'Overlay not open in OBS' : 'Checking the overlay'}`}</p>
        <b className={`ws-clock ws-c-${phase}`}>{mmss(known ? left : 0)}</b>
        <p className="small muted">{state === 'running' ? 'Running' : state === 'paused' ? 'Paused' : 'Not Running'}</p>
      </div>
      <h4 className="ws-h"><i className="fas fa-chart-line" /> Live Session Stats</h4>
      <div className="ws-panel ws-tiles">
        {tiles.map(([l, icon, c, v]) => <div key={l} className="ws-tile"><span className="small muted"><i className={icon} /> {l}</span><b className={c}>{v}</b></div>)}
      </div>
      {reset && <Modal title="Reset the overlay?" icon="fas fa-rotate-left" onClose={() => setReset(false)}>
        <p>The durations go back to 60, 5 and 30 minutes and the timer on your live overlay resets.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setReset(false)}>Cancel</button><button type="button" className="btn btn-danger" onClick={resetAll}><i className="fas fa-rotate-left" /> Reset Overlay</button></div></Modal>}
    </section>
  )
}

function TaskManager({ page, setPage, toast }: { page: Page; setPage: (p: Page) => void; toast: (t: Toast) => void }) {
  const ts = page.task_settings
  const [open, setOpen] = useState(true)
  const [pts, setPts] = useState(String(ts.default_reward_points))
  const [busy, setBusy] = useState('')
  const [data, setData] = useState<{ s: STask[]; u: UTask[] } | null>(null)
  const [failed, setFailed] = useState(false)
  const [edit, setEdit] = useState<STask | null>(null), [del, setDel] = useState<STask | null>(null)
  const [proj, setProj] = useState('__all')
  const ptsBad = !/^\d+$/.test(pts) || Number(pts) > 100000
  const load = () => post<{ success: boolean; streamer_tasks: STask[]; user_tasks: UTask[] }>({ action: 'ch_get_tasks' }).then((r) => { setData({ s: r.streamer_tasks || [], u: r.user_tasks || [] }); setFailed(false) }).catch(() => setFailed(true))
  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function saveTS(next: TaskSettings, msg: string) {
    try { await post({ action: 'ch_save_settings', require_approval: String(next.require_approval), default_reward_points: String(next.default_reward_points), allow_user_tasks: String(next.allow_user_tasks), task_visible_overlay: String(next.task_visible_overlay) }); setPage({ ...page, task_settings: next }); toast({ kind: 'ok', text: msg }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
  }
  async function toggle(k: keyof TaskSettings, v: boolean) { setBusy(k); await saveTS({ ...ts, [k]: v ? 1 : 0 }, 'Saved.'); setBusy('') }
  async function cmd(c: string, v: boolean) {
    setBusy('c-' + c)
    try { await post({ action: 'wcmd_save_command_status', command: c, enabled: v ? '1' : '0' }); setPage({ ...page, commands: { ...page.commands, [c]: v ? 1 : 0 } }); toast({ kind: 'ok', text: `!${c} ${v ? 'on' : 'off'}.` }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  async function act(k: string, body: Record<string, string>, ok: (r: { reward?: { awarded: boolean; points_awarded: number; new_total: number } }) => string) {
    setBusy(k)
    try { const r = await post<{ success: boolean; reward?: { awarded: boolean; points_awarded: number; new_total: number } }>(body); toast({ kind: 'ok', text: ok(r) }); await load() }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy('')
  }
  const reward = (u: string) => (r: { reward?: { awarded: boolean; points_awarded: number; new_total: number } }) => r.reward?.awarded ? `${u} earned ${r.reward.points_awarded} points, now ${r.reward.new_total}.` : 'Done.'
  const key = (p: string | null) => (p ? p : '__default')
  const counts = new Map<string, number>()
  data?.u.forEach((t) => counts.set(key(t.project), (counts.get(key(t.project)) ?? 0) + 1))
  const projOpts: Opt[] = [{ value: '__all', label: `All projects (${data?.u.length ?? 0})` }, { value: '__default', label: `No project (${counts.get('__default') ?? 0})` }, ...[...counts.keys()].filter((k) => k !== '__default').sort().map((k) => ({ value: k, label: `${k} (${counts.get(k)})` }))]
  const users = data?.u.filter((t) => proj === '__all' || key(t.project) === proj) ?? []
  const title = (t: STask) => <>{t.backlog_position != null && <span className="muted">#{t.backlog_position} </span>}{t.title}</>
  const st = (s: string) => s ? cap(s) : '-'
  const empty = (text: string) => <p className="mp-lempty muted">{failed ? 'Tasks could not load.' : !data ? 'Loading' : text}</p>
  const sw = (k: keyof TaskSettings, l: string) => <div className="ws-check"><Switch on={!!ts[k]} busy={busy === k} label={l} onChange={(v) => toggle(k, v)} /><span>{l}</span></div>
  return (
    <section className="card ws-card" aria-label="Channel Task Manager">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list-check" /> Channel Task Manager</h3>
        <div className="ws-hbtns">
          <button type="button" className="btn ws-b-micro ws-copy" disabled={!page.has_key} onClick={() => copyLink('tasks', 'Combined task list link', toast)}><i className="fas fa-copy" /> Copy Link (Combined)</button>
          <button type="button" className="btn ws-b-micro ws-copy" disabled={!page.has_key} onClick={() => copyLink('streamer', 'Streamer task list link', toast)}><i className="fas fa-copy" /> Copy Link (Streamer)</button>
          <button type="button" className="btn ws-b-micro ws-copy" disabled={!page.has_key} onClick={() => copyLink('viewers', 'Users task list link', toast)}><i className="fas fa-copy" /> Copy Link (Users)</button>
          <button type="button" className="btn btn-ghost mp-x" aria-expanded={open} aria-label={open ? 'Hide task settings' : 'Show task settings'} onClick={() => setOpen(!open)}><i className={`fas fa-chevron-${open ? 'up' : 'down'}`} /></button></div></div>
      {open && <>
        <div className="ws-cols ws-cols2">
          <div className="ws-col">
            <label className="ws-h" htmlFor="ws-pts">Default reward points per task</label>
            <div className="ws-inline"><span className={`mp-numbox${ptsBad ? ' is-bad' : ''}`}><input id="ws-pts" type="number" inputMode="numeric" min={0} max={100000} aria-invalid={ptsBad} aria-describedby="ws-pts-h" value={pts} onChange={(e) => setPts(e.target.value)} /><span className="mp-unit">pts</span></span>
              <button type="button" className="btn" disabled={ptsBad || busy === 'pts' || Number(pts) === ts.default_reward_points} onClick={async () => { setBusy('pts'); await saveTS({ ...ts, default_reward_points: Number(pts) }, 'Default reward points saved.'); setBusy('') }}><i className={busy === 'pts' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save Settings</button></div>
            <span id="ws-pts-h" className={`small ${ptsBad ? 'mp-bad' : 'muted'}`}>{ptsBad ? 'Enter 0 to 100000.' : 'Applied when a new task is created without a custom value.'}</span>
          </div>
          <div className="ws-col">
            <h4 className="ws-h">Toggles</h4>
            {sw('require_approval', 'Require streamer approval before awarding points')}
            {sw('allow_user_tasks', 'Allow viewers to submit tasks')}
            {sw('task_visible_overlay', 'Show tasks on overlay')}
            <span className="small muted">Toggles save automatically.</span>
          </div>
        </div>
        <h4 className="ws-h">Chat commands</h4>
        <p className="small muted">Turn individual task chat commands on or off. Changes apply to the bot immediately.</p>
        <div className="ws-cmds">
          {COMMANDS.map(([c, help]) => <div key={c} className="ws-check"><Switch on={!!page.commands[c]} busy={busy === 'c-' + c} label={`!${c}`} onChange={(v) => cmd(c, v)} /><span><code>!{c}</code> - {help}</span></div>)}
        </div>
      </>}
      <div className="ws-cols ws-cols2 ws-tasks">
        <div className="ws-col">
          <div className="ws-h-row"><h4 className="ws-h"><i className="fas fa-list-check" /> Streamer Tasks</h4>
            <button type="button" className="btn" onClick={() => setEdit({ id: 0, title: '', description: '', category: 'General', status: 'active', reward_points: ts.default_reward_points, project: '', backlog_position: null })}><i className="fas fa-plus" /> Add Task</button></div>
          {data && data.s.length ? <ul className="mp-llist">{data.s.map((t) => (
            <li key={t.id} className="mp-lrow ws-row">
              <span className="av-ftxt"><b>{title(t)}</b><span className="muted">{[t.project, `${t.reward_points} pts`].filter(Boolean).join(', ')}</span></span>
              <span className="mp-lr"><span className={`pill ${STATUS_PILL[t.status] ?? 'pill-off'}`}>{st(t.status)}</span>
                {t.status !== 'completed' && <button type="button" className="btn btn-ghost mp-x" aria-label={`Mark ${t.title} done`} disabled={!!busy} onClick={() => act('d' + t.id, { action: 'ch_complete_streamer_task', id: String(t.id) }, () => 'Task done.')}><i className={busy === 'd' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-check'} /></button>}
                <button type="button" className="btn btn-ghost mp-x" aria-label={`Edit ${t.title}`} onClick={() => setEdit(t)}><i className="fas fa-pen" /></button>
                <button type="button" className="btn btn-ghost mp-x" aria-label={`Delete ${t.title}`} onClick={() => setDel(t)}><i className="fas fa-trash" /></button></span>
            </li>))}</ul> : empty('No streamer tasks yet.')}
          {failed && <button type="button" className="btn btn-ghost" onClick={load}>Try again</button>}
        </div>
        <div className="ws-col">
          <div className="ws-h-row"><h4 className="ws-h"><i className="fas fa-users" /> Viewer Tasks</h4>
            {data && data.u.length > 0 && <div className="mp-sel ws-proj"><Combobox label="Project" value={proj} onChange={setProj} options={projOpts} /></div>}</div>
          {users.length ? <ul className="mp-llist">{users.map((t) => { const ap = APPROVAL[t.approval_status] ?? APPROVAL.auto, pend = t.approval_status === 'pending_approval'
            return <li key={t.id} className="mp-lrow ws-row">
              <span className="av-ftxt"><b>{t.user_name}: {title(t)}</b><span className="muted">{[t.project, `${t.reward_points} pts`].filter(Boolean).join(', ')}</span></span>
              <span className="mp-lr"><span className={`pill ${STATUS_PILL[t.status] ?? 'pill-off'}`}>{st(t.status)}</span><span className={`pill ${ap[0]}`}>{ap[1]}</span>
                {t.status === 'active' && <button type="button" className="btn btn-ghost mp-x" aria-label={`Mark ${t.user_name}'s task done`} disabled={!!busy} onClick={() => act('c' + t.id, { action: 'ch_complete_user_task', id: String(t.id) }, reward(t.user_name))}><i className={busy === 'c' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-check'} /></button>}
                {pend && <button type="button" className="btn btn-ghost mp-x" aria-label={`Approve and award ${t.user_name}`} disabled={!!busy} onClick={() => act('a' + t.id, { action: 'ch_approve_user_task', id: String(t.id) }, reward(t.user_name))}><i className={busy === 'a' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-award'} /></button>}
                {pend && <button type="button" className="btn btn-ghost mp-x" aria-label={`Reject ${t.user_name}'s task`} disabled={!!busy} onClick={() => act('r' + t.id, { action: 'ch_reject_user_task', id: String(t.id) }, () => 'Task rejected.')}><i className={busy === 'r' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /></button>}</span>
            </li> })}</ul> : empty(data?.u.length ? 'No viewer tasks in this project.' : 'No viewer tasks yet.')}
        </div>
      </div>
      {edit && <TaskModal t={edit} onClose={() => setEdit(null)} onSave={async (b) => { try { await post(b); toast({ kind: 'ok', text: edit.id ? 'Task saved.' : 'Task added.' }); setEdit(null); load() } catch (e) { return (e as Error).message } return '' }} />}
      {del && <Modal title="Delete this task?" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p><b>{del.title}</b> is removed from your list and the overlay. This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Keep it</button><button type="button" className="btn btn-danger" disabled={!!busy} onClick={() => { setDel(null); act('x', { action: 'ch_delete_streamer_task', id: String(del.id) }, () => 'Task deleted.') }}><i className={busy === 'x' ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Delete</button></div></Modal>}
    </section>
  )
}

function TaskModal({ t, onClose, onSave }: { t: STask; onClose: () => void; onSave: (b: Record<string, string>) => Promise<string> }) {
  const [f, setF] = useState({ title: t.title, description: t.description || '', project: t.project || '', category: t.category || 'General', reward_points: String(t.reward_points) })
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const ptsBad = !/^\d+$/.test(f.reward_points) || Number(f.reward_points) > 100000
  const invalid = !f.title.trim() || f.title.length > 255 || f.description.length > 1000 || ptsBad
  return (
    <Modal title={t.id ? 'Edit task' : 'Add task'} icon={t.id ? 'fas fa-pen' : 'fas fa-plus'} onClose={onClose} wide>
      <form className="cmd-form" onSubmit={async (e) => { e.preventDefault(); if (invalid) return; setBusy(true)
        const b: Record<string, string> = { action: t.id ? 'ch_update_streamer_task' : 'ch_create_streamer_task', title: f.title.trim(), description: f.description.trim(), project: f.project.trim(), category: f.category.trim() || 'General', reward_points: f.reward_points }
        if (t.id) { b.id = String(t.id); b.status = 'active' }
        setErr(await onSave(b)); setBusy(false) }}>
        <div className="fld fld-wide"><label htmlFor="wt-t">Title</label><input id="wt-t" className="input" maxLength={255} data-autofocus placeholder="What are you working on?" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></div>
        <div className="fld fld-wide"><label htmlFor="wt-d">Description</label><textarea id="wt-d" className="input" rows={2} maxLength={1000} placeholder="Optional" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /><Counter n={f.description.length} max={1000} /></div>
        <div className="fld"><label htmlFor="wt-p">Project</label><input id="wt-p" className="input" maxLength={100} placeholder="e.g. Coding, Gaming" value={f.project} onChange={(e) => setF({ ...f, project: e.target.value })} /></div>
        <div className="fld"><label htmlFor="wt-c">Category</label><input id="wt-c" className="input" maxLength={100} value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })} /></div>
        <div className="fld"><label htmlFor="wt-r">Reward points</label><input id="wt-r" className={`input${ptsBad ? ' is-bad' : ''}`} type="number" inputMode="numeric" min={0} max={100000} value={f.reward_points} onChange={(e) => setF({ ...f, reward_points: e.target.value })} />{ptsBad && <small className="counter over">Enter 0 to 100000.</small>}</div>
        {!!t.id && t.status !== 'active' && <p className="fld-wide muted small">Saving sets this task back to active.</p>}
        {err && <p className="fld-wide mp-bad small" role="alert">{err}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || invalid}><i className={busy ? 'fas fa-spinner fa-spin' : t.id ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {busy ? 'Saving' : t.id ? 'Save changes' : 'Add task'}</button></div>
      </form>
    </Modal>
  )
}

