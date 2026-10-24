import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { get } from '../bot'
import Modal from '../components/Modal'
import { ToastBar, send, type Toast } from './commands/shared'
import { Confirm } from './insights/shared'

// Port of dashboard/streaming.php (the stream hub): Library, Twitch VODs,
// Reruns and Setup (Recording, YouTube, S3, Ingest, Forwarding).
// Endpoint: public/api/streaming/streaming.php (scripts/sync-live-api.sh).
// YouTube linking still runs on the live youtubelink.php.
const API = '/api/streaming/streaming.php'
const YT_LINK = 'https://dashboard.botofthespecter.com/youtubelink.php?connect=1'
type Act = { state: 'none' | 'done' | 'busy' | 'limit' | 'send' | 'retry'; status?: string; percent?: number; reason?: string }
type Row = { name: string; title: string; kind: string; on_s3: boolean; extended: boolean; size: number; length: string; in_progress: boolean; upload_hold: boolean; expires_unix: number; rerun_hold: boolean; actions: boolean; url: string; tid: string; duration_raw: string; restore: 'none' | 'copying' | 'retry' | 'store'; restore_percent: number; s3_key: string; can_extend: boolean; can_delete: boolean; yt: Act; s3: Act }
type Stats = { files: number; pulling: number; yt_uploading: number; yt_queued: number; s3_uploading: number; s3_queued: number; failed_pulls: string[]; used_bytes: number; quota_bytes: number; unlimited: boolean }
type VodState = { store: { state: 'pulling' | 'stored' | 'store' | 'none'; phase?: string; pos?: number }; yt: Act; s3: Act }
type Pull = { status?: string; phase?: string; percent?: number | null; title?: string; filename?: string; vod_id?: string; queue_position?: number; bytes?: number; bytes_total?: number }
type Job = { status?: string; percent?: number; title?: string; filename?: string; bytes_sent?: number; bytes_total?: number }
type Rerun = { id: number; status: string; when: string; game: string; length: string; items: { title: string; full_title: string; length: string; status: string; reason: string }[]; current_position: number | null; cancel_requested: boolean; reason: string }
type Cand = { token: string; display: string; title: string; game_id: string; game_name: string; duration_seconds: number }
type Page = {
  success: boolean; message: string; message_kind: string; act_as: boolean; rows: Row[]; stats: Stats; vod_states: Record<string, VodState>; pulls: Pull[]; remoteFileError: string
  youtube_jobs: Record<string, Job>; s3_jobs: Record<string, Job>; twitch_videos: { id: string; title: string; duration: string }[]; twitch_capped: boolean; twitch_error: string
  can_youtube: boolean; can_upload: boolean; can_s3: boolean; can_ingest: boolean
  youtube: { configured: boolean; linked: boolean; needs_reauth: boolean; channel_title: string; channel_thumbnail: string; channel_id: string }
  s3: { connected: boolean; endpoint?: string; region?: string; bucket?: string; prefix?: string; access_key?: string; secret_set?: boolean; secret_last4?: string; path_style?: boolean; auto_copy?: boolean; last_error?: string }
  reruns_ready: boolean; reruns: Rerun[]; rerun_candidates: Cand[]; rerun: { prefix: string; title_max: number; max_items: number; min: string; max: string; has_key: boolean }; timezone: string; auto_record: boolean
  ingest: { has_slot: boolean; unlimited: boolean; rtmps: string; specter_key: string; twitch_key: string; forward_to_twitch: boolean }
  forward: { service: string; label: string; icon: string; stream_key: string; enabled: boolean }[]
}
type Poll = Pick<Page, 'rerun_candidates' | 'rows' | 'stats' | 'vod_states' | 'pulls' | 'remoteFileError' | 'youtube_jobs' | 's3_jobs' | 'can_upload' | 'can_s3'>
type Res = { ok?: boolean; success?: boolean; message?: string; error?: string; kind?: string }
const TABS = [['library', 'Library', 'fas fa-folder-open'], ['import', 'Twitch VODs', 'fab fa-twitch'], ['reruns', 'Reruns', 'fas fa-redo-alt'], ['setup', 'Setup', 'fas fa-cog']] as const
type Tab = typeof TABS[number][0]
const readTab = (): Tab => { const t = new URLSearchParams(location.search).get('tab'); return (TABS.some((x) => x[0] === t) ? t : 'library') as Tab }
function Opt({ id, on, set, label, help, disabled }: { id: string; on: boolean; set: (v: boolean) => void; label: string; help?: string; disabled?: boolean }) {
  return <div className="st-opt"><label className="dc-check" htmlFor={id}><input id={id} type="checkbox" checked={on} disabled={disabled} onChange={(e) => set(e.target.checked)} /> <span>{label}</span></label>{help && <p className="muted small st-opt-help">{help}</p>}</div>
}

function bytes(n: number) { if (n < 1024) return `${n} B`; if (n < 1048576) return `${+(n / 1024).toFixed(2)} KB`; if (n < 1073741824) return `${+(n / 1048576).toFixed(2)} MB`; return `${+(n / 1073741824).toFixed(2)} GB` }
const pct = (p?: number) => (p && p > 0 ? ` ${Math.round(p * 10) / 10}%` : '')
const YT_ST: Record<string, string> = { queued: 'Queued', pulling: 'Downloading from Twitch', uploading: 'Uploading' }
const S3_ST: Record<string, string> = { queued: 'Queued for S3', uploading: 'Uploading to S3', pulling: 'S3: waiting for download' }
const LIMIT: Record<string, string> = { too_long: 'YouTube only accepts videos up to 12 hours. This VOD is too long to send.', too_large: 'YouTube only accepts files up to 256 GB. This VOD is too large to send.' }
const RR_BADGE: Record<string, string> = { scheduled: 'pill-info', live: 'pill-ok', done: 'pill-off', failed: 'pill-err', cancelled: 'pill-off', skipped: 'pill-warn' }
const RR_ST: Record<string, string> = { scheduled: 'Scheduled', live: 'Live now', done: 'Finished', failed: 'Failed', cancelled: 'Cancelled', skipped: 'Skipped' }
const IT_BADGE: Record<string, string> = { staging: 'pill-warn', staged: 'pill-info', playing: 'pill-ok', done: 'pill-off', failed: 'pill-err', skipped: 'pill-warn', cancelled: 'pill-off' }
const IT_ST: Record<string, string> = { staging: 'Copying', staged: 'Ready', playing: 'Playing', done: 'Played', failed: 'Failed', skipped: 'Skipped', cancelled: 'Cancelled' }
function ytSecs(d: string) { const m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/.exec(d || ''); return m && d ? (+(m[1] || 0)) * 3600 + (+(m[2] || 0)) * 60 + (+(m[3] || 0)) : null }
function fmtLen(s: number) { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m` }
function left(unix: number, now: number) { const s = unix - now; if (s <= 0) return 'Expired'; const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60); return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m` }
function Pill({ k, children, title }: { k: string; children: ReactNode; title?: string }) { return <span className={`pill ${k}`} title={title}>{children}</span> }

export default function Streaming() {
  const [p, setP] = useState<Page | null>(null), [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null), [tab, setTab] = useState<Tab>(readTab)
  const [hashTick, setHashTick] = useState(0), [busy, setBusy] = useState(''), [ask, setAsk] = useState<{ title: string; body: ReactNode; yes: string; danger?: boolean; run: () => void } | null>(null)
  async function load() {
    try { const r = await get<Page>(API, 40000); setP(r); setErr(''); if (r.message) setToast({ kind: ['is-success', 'is-info'].includes(r.message_kind) ? 'ok' : 'err', text: r.message }) }
    catch { setErr('The streaming hub could not load. Refresh to try again.') }
  }
  useEffect(() => { load() }, [])
  useEffect(() => { const f = () => { setTab(readTab()); setHashTick((n) => n + 1) }; addEventListener('popstate', f); return () => removeEventListener('popstate', f) }, [])
  // The live page polls every 5 seconds for downloads, uploads and the file list.
  const poll = useRef<() => Promise<void>>(async () => {})
  poll.current = async () => {
    if (document.hidden) return
    try { const r = await get<Poll>(`${API}?ajax=vods&_ts=${Date.now()}`, 20000); setP((o) => o && { ...o, rerun_candidates: r.rerun_candidates ?? o.rerun_candidates, rows: r.rows, stats: r.stats, vod_states: { ...o.vod_states, ...r.vod_states }, pulls: r.pulls, remoteFileError: r.remoteFileError, youtube_jobs: r.youtube_jobs, s3_jobs: r.s3_jobs, can_upload: r.can_upload, can_s3: r.can_s3 }) } catch { /* next tick */ }
  }
  useEffect(() => { const t = setInterval(() => poll.current(), 5000); return () => clearInterval(t) }, [])
  useEffect(() => { if (p && tab === 'setup' && location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView({ block: 'start' }) }, [!!p, tab, hashTick])
  function go(t: Tab) { setTab(t); history.pushState(null, '', `/streaming?tab=${t}`) }
  async function act(key: string, body: Record<string, string>, after: 'poll' | 'load' = 'poll') {
    setBusy(key)
    try {
      const r = await send<Res>(API, body)
      const ok = r.ok ?? r.success ?? false
      setToast({ kind: ok ? 'ok' : 'err', text: r.message || r.error || (ok ? 'Done.' : 'That did not work. Please try again.') })
      if (after === 'load') await load(); else await poll.current()
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message.startsWith('http_') ? 'That did not work. Please try again.' : (e as Error).message }) }
    setBusy('')
  }
  if (!p) return <div className="page cmds st">{err ? <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div> : <section className="card" aria-busy="true">{[0, 1, 2].map((i) => <span key={i} className="skel skel-line" />)}</section>}</div>
  const tabs = TABS.filter(([k]) => (k !== 'import' || p.can_youtube) && (k !== 'reruns' || p.reruns_ready))
  const cur = tabs.some((t) => t[0] === tab) ? tab : 'library'
  const st = p.stats
  return (
    <div className="page cmds st">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card st-stats" aria-label="Streaming overview">
        <div className="st-stat"><span>Files</span><b>{st.files}</b></div>
        <div className={`st-stat${st.pulling ? ' warn' : ''}`}><span>Downloading</span><b>{st.pulling}</b></div>
        <div className={`st-stat${st.yt_uploading + st.yt_queued ? ' warn' : ''}`}><span>YouTube uploading</span><b>{st.yt_uploading}</b><small>{st.yt_queued} queued</small></div>
        <div className={`st-stat${st.s3_uploading + st.s3_queued ? ' warn' : ''}`}><span>S3 uploading</span><b>{st.s3_uploading}</b><small>{st.s3_queued} queued</small></div>
        <div className={`st-stat${st.unlimited ? ' ok' : ''}`}><span>Recording storage</span><b>{bytes(st.used_bytes)}</b><small>{st.unlimited ? 'No storage cap' : `of ${bytes(st.quota_bytes)}`}</small></div>
        {!st.unlimited && st.quota_bytes > 0 && <div className="st-bar" role="progressbar" aria-label="Storage used" aria-valuenow={Math.round(st.used_bytes / st.quota_bytes * 100)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${Math.min(100, st.used_bytes / st.quota_bytes * 100)}%` }} /></div>}
      </section>
      <div className="seg st-tabs" role="tablist" aria-label="Streaming sections">
        {tabs.map(([k, l, i]) => <button key={k} type="button" role="tab" aria-selected={cur === k} className={cur === k ? 'on' : ''} onClick={() => go(k)}><i className={i} /> {l}</button>)}
      </div>
      {cur === 'library' && <Library p={p} busy={busy} act={act} ask={setAsk} toast={setToast} refresh={() => poll.current()} />}
      {cur === 'import' && <Import p={p} busy={busy} act={act} />}
      {cur === 'reruns' && <Reruns p={p} busy={busy} act={act} ask={setAsk} toast={setToast} goSetup={() => { go('setup'); setTimeout(() => document.getElementById('ingest')?.scrollIntoView(), 50) }} />}
      {cur === 'setup' && <Setup p={p} busy={busy} act={act} ask={setAsk} toast={setToast} />}
      {ask && <Confirm title={ask.title} yes={ask.yes} danger={ask.danger !== false} onClose={() => setAsk(null)} onYes={() => { const r = ask.run; setAsk(null); r() }}>{ask.body}</Confirm>}
    </div>
  )
}
type Ctx = { p: Page; busy: string; act: (k: string, b: Record<string, string>, after?: 'poll' | 'load') => void; ask?: (a: { title: string; body: ReactNode; yes: string; danger?: boolean; run: () => void }) => void; toast?: (t: Toast) => void }

function Btn({ k, busy, children, onClick, cls = 'btn btn-ghost', disabled, title }: { k: string; busy: string; children: ReactNode; onClick: () => void; cls?: string; disabled?: boolean; title?: string }) {
  return <button type="button" className={cls} disabled={disabled || busy === k} title={title} onClick={onClick}>{busy === k ? <i className="fas fa-spinner fa-spin" /> : null}{children}</button>
}
// badge: the status pill (Type column); otherwise only the button (Action column).
function YtCell({ a, k, busy, onSend, badge }: { a: Act; k: string; busy: string; onSend: () => void; badge?: boolean }) {
  if (a.state === 'none' || (!!badge !== (a.state === 'done' || a.state === 'busy'))) return null
  if (a.state === 'done') return <Pill k="pill-ok">On YouTube</Pill>
  if (a.state === 'busy') return <Pill k="pill-warn">{YT_ST[a.status || ''] || 'Queued'}{pct(a.percent)}</Pill>
  if (a.state === 'limit') return <span title={LIMIT[a.reason || ''] || 'Over the YouTube upload limit.'}><button type="button" className="btn btn-ghost cm-btn" disabled>Send to YouTube</button></span>
  return <Btn k={k} busy={busy} cls="btn btn-ghost cm-btn" onClick={onSend}>{a.state === 'retry' ? 'Retry YouTube upload' : 'Send to YouTube'}</Btn>
}
// badge: the status pill (Type column); otherwise only the button (Action column).
function S3Cell({ a, k, busy, onSend, badge }: { a: Act; k: string; busy: string; onSend: () => void; badge?: boolean }) {
  if (a.state === 'none' || (!!badge !== (a.state === 'done' || a.state === 'busy'))) return null
  if (a.state === 'done') return <Pill k="pill-ok">On my S3</Pill>
  if (a.state === 'busy') return <Pill k="pill-warn">{S3_ST[a.status || ''] || 'Queued for S3'}{pct(a.percent)}</Pill>
  return <Btn k={k} busy={busy} cls="btn btn-ghost cm-btn" onClick={onSend}>{a.state === 'retry' ? 'Retry S3 copy' : 'Send to my S3'}</Btn>
}
// Transfer speed from byte samples taken on each poll (port of the old page's transferRate, 30 second window).
type Sample = { t: number; b: number }
const RATES = new Map<string, Sample[]>()
function rate(key: string, b: number | undefined): number | null {
  const n = Number(b) || 0, now = Date.now()
  let sm = RATES.get(key) || []
  if (sm.length && n < sm[sm.length - 1].b) sm = []
  const last = sm[sm.length - 1]
  if (!last || now - last.t >= 1000) sm.push({ t: now, b: n }); else { last.t = now; last.b = n }
  while (sm.length > 2 && sm[0].t < now - 30000) sm.shift()
  if (sm.length > 12) sm.splice(0, sm.length - 12)
  RATES.set(key, sm)
  if (sm.length < 2) return null
  const dt = (sm[sm.length - 1].t - sm[0].t) / 1000, d = sm[sm.length - 1].b - sm[0].b
  return dt < 2 || d < 0 ? null : d / dt
}
const speed = (key: string, b: number | undefined) => { const r = rate(key, b); return r !== null && isFinite(r) && r > 0 ? ` · ${bytes(Math.round(r))}/s` : '' }
const sizeOf = (sent?: number, total?: number) => { const a = Number(sent) || 0, t = Number(total) || 0; return a > 0 ? ` · ${bytes(a)}${t > a ? ` / ${bytes(t)}` : ''}` : '' }
function Jobs({ p }: { p: Page }) {
  const yt = Object.entries(p.youtube_jobs).filter(([, j]) => j.status === 'uploading' || j.status === 'pulling')
  const s3 = Object.entries(p.s3_jobs).filter(([, j]) => j.status === 'uploading')
  const pulls = p.pulls.filter((j) => j.status === 'pulling')
  if (!yt.length && !s3.length && !pulls.length && !p.stats.failed_pulls.length) return null
  const bar = (key: string, label: string, v: number | null, extra = '') => <div key={key} className="st-job"><div className="st-job-h"><span>{label}</span><span>{v !== null ? `${Math.round(v * 10) / 10}%` : ''}{extra}</span></div><progress max={100} value={v ?? undefined} aria-label={label} /></div>
  return <div className="st-jobs">
    {pulls.map((j, i) => { const ph = j.phase === 'saving' ? 'Saving to backend storage...' : j.phase === 'queued' ? (j.queue_position ? `Queued for download (#${j.queue_position} in line)` : 'Queued for download') : 'Downloading...'; return bar('p' + i, `${j.title || j.filename || j.vod_id || ''} - ${ph}`, j.phase === 'queued' || typeof j.percent !== 'number' ? null : Math.max(0, Math.min(100, j.percent)), j.phase === 'queued' ? '' : sizeOf(j.bytes, j.bytes_total) + speed(`${j.phase === 'saving' ? 'save' : 'pull'}:${j.vod_id || j.filename || i}`, j.bytes)) })}
    {yt.map(([n, j]) => bar('y' + n, `${j.title || n} - ${YT_ST[j.status || '']}`, j.status === 'uploading' ? Math.max(0, Math.min(100, j.percent || 0)) : null, sizeOf(j.bytes_sent, j.bytes_total) + speed('yt:' + n, j.bytes_sent)))}
    {s3.map(([n, j]) => bar('s' + n, `${j.title || j.filename || n} - Uploading to S3`, Math.max(0, Math.min(100, j.percent || 0)), sizeOf(j.bytes_sent, j.bytes_total) + speed('s3:' + n, j.bytes_sent)))}
    {p.stats.failed_pulls.map((t, i) => <div key={'f' + i} className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{t} - Download failed</span></div>)}
  </div>
}

function Library({ p, busy, act, ask, toast, refresh }: Ctx & { refresh: () => void }) {
  const [now, setNow] = useState(() => Math.floor(Date.now() / 1000)), [pick, setPick] = useState<Set<string>>(new Set()), [links, setLinks] = useState<string | null>(null)
  useEffect(() => { const t = setInterval(() => setNow(Math.floor(Date.now() / 1000)), 30000); return () => clearInterval(t) }, [])
  // Like the old page's displayName(): a file still named twitch-<id> gets its real VOD title from the Twitch list or its download job.
  const rows = useMemo(() => {
    const named = (r: Row) => {
      const id = r.tid || (/^twitch-(\d{1,20})(\.mp4)?(\.part)?$/i.exec(r.name)?.[1] ?? '')
      if (!id || !/^twitch-\d{1,20}$/i.test(r.title.replace(/\.mp4(\.part)?$/i, ''))) return r
      const t = p.twitch_videos.find((v) => v.id === id)?.title || p.pulls.find((j) => j.vod_id === id)?.title || ''
      return t ? { ...r, title: t } : r
    }
    return [...p.rows].map(named).sort((a, b) => b.expires_unix - a.expires_unix)
  }, [p.rows, p.twitch_videos, p.pulls])
  const pickable = rows.filter((r) => r.actions)
  function copyLinks() {
    const seen = new Set<string>()
    const out = pickable.filter((r) => pick.has(r.name)).map((r) => r.url).filter((u) => !seen.has(u) && !!seen.add(u))
    if (!out.length) return toast?.({ kind: 'err', text: 'Select at least one stored VOD.' })
    setLinks(out.join('\n'))
  }
  // Match a storing row to its pull job so the pill follows the job phase (queued, downloading, saving).
  const storePhase = (r: Row) => p.pulls.find((j) => (j.filename && j.filename === r.name) || (j.vod_id && (r.tid === j.vod_id || r.name === `twitch-${j.vod_id}` || r.name === `twitch-${j.vod_id}.mp4`)))?.phase
  const kindPill = (r: Row) => r.kind === 'recording' ? <Pill k="pill-warn">Recording (In Progress)</Pill> : r.kind === 'storing' ? <Pill k="pill-warn">{storePhase(r) === 'saving' ? 'Saving...' : storePhase(r) === 'queued' ? 'Queued...' : 'Downloading...'}</Pill> : r.kind === 'stored' ? <Pill k="pill-info">Stored</Pill> : r.kind === 's3' ? <Pill k="pill-ok">S3</Pill> : <Pill k="pill-info">Recorded</Pill>
  return (
    <section className="card" aria-label="Library">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-folder-open" /> Library</h3>
        <div className="row-btns"><button type="button" className="btn btn-ghost" disabled={!pick.size} onClick={copyLinks}><i className="fas fa-link" /> Copy selected links</button><button type="button" className="btn btn-ghost" onClick={refresh}><i className="fas fa-sync-alt" /> Refresh</button></div></div>
      <p className="muted">Recorded files come from live ingest. Stored files are Twitch VODs you saved. Both share this storage. Send a finished file to YouTube from this list.</p>
      <Jobs p={p} />
      {p.remoteFileError ? <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>{p.remoteFileError}</span></div>
        : !rows.length ? <div className="st-empty"><i className="fas fa-folder-open" /> No files found in recorder directories for this user yet.</div>
        : <>
          {pickable.length > 0 && <label className="dc-check st-m-only"><input type="checkbox" checked={pick.size === pickable.length} onChange={(e) => setPick(e.target.checked ? new Set(pickable.map((r) => r.name)) : new Set())} /> <span>Select all</span></label>}
          <div className="bp-table cm-table st-table"><table>
            <thead><tr>
              <th className="st-chk">{pickable.length > 0 && <input type="checkbox" aria-label="Select all" title="Select all" checked={pick.size === pickable.length} onChange={(e) => setPick(e.target.checked ? new Set(pickable.map((r) => r.name)) : new Set())} />}</th>
              <th>File</th><th>Type</th><th>Size</th><th>Length</th><th>Removed in</th><th className="cm-c">Action</th>
            </tr></thead>
            <tbody>{rows.map((r) => (
              <tr key={r.name}>
                <td className="st-chk">{r.actions && <input type="checkbox" aria-label={`Select ${r.title}`} checked={pick.has(r.name)} onChange={(e) => { const n = new Set(pick); if (e.target.checked) n.add(r.name); else n.delete(r.name); setPick(n) }} />}</td>
                <td data-l="File" className="st-title">{r.title}</td>
                <td data-l="Type"><div className="st-badges">{kindPill(r)}{r.on_s3 && r.kind !== 's3' && <Pill k="pill-ok">S3</Pill>}{r.extended && <Pill k="pill-off">Extended</Pill>}{r.actions && <>{r.restore === 'copying' && <Pill k="pill-warn">Storing on server{pct(r.restore_percent)}</Pill>}<YtCell a={r.yt} k={'yt' + r.name} busy={busy} onSend={() => act('yt' + r.name, { action: 'send_library_youtube', filename: r.name, file_title: r.title, twitch_video_id: r.tid, file_size: String(r.size), file_duration: r.duration_raw })} badge /><S3Cell a={r.s3} k={'s3' + r.name} busy={busy} onSend={() => act('s3' + r.name, { action: 'send_library_s3', filename: r.name, file_title: r.title })} badge /></>}</div></td>
                <td data-l="Size" className="st-num">{bytes(r.size)}</td>
                <td data-l="Length" className="st-num">{r.length}</td>
                <td data-l="Removed in">{r.in_progress ? '-' : r.upload_hold ? 'Kept until the upload finishes' : r.expires_unix ? left(r.expires_unix, now) : '-'}
                  {r.rerun_hold && <> <Pill k="pill-info" title="Kept until its scheduled rerun is over. If it has expired by then, it is removed straight after.">Rerun scheduled</Pill></>}</td>
                <td className="cm-c st-acts-td">{r.actions ? <div className="st-acts">
                  <a className="btn cm-btn" href={r.url}><i className="fas fa-download" /> Download</a>
                  {r.restore !== 'none' && r.restore !== 'copying' && <Btn k={'rs' + r.name} busy={busy} cls="btn btn-ghost cm-btn" onClick={() => act('rs' + r.name, { action: 'restore_s3_local', object_key: r.s3_key })}>{r.restore === 'retry' ? 'Retry store on server' : 'Store on server'}</Btn>}
                  {r.can_extend && <Btn k={'ex' + r.name} busy={busy} cls="btn btn-ghost cm-btn" onClick={() => act('ex' + r.name, { action: 'extend_recording', file: r.name })}>Extend 3 days</Btn>}
                  <YtCell a={r.yt} k={'yt' + r.name} busy={busy} onSend={() => act('yt' + r.name, { action: 'send_library_youtube', filename: r.name, file_title: r.title, twitch_video_id: r.tid, file_size: String(r.size), file_duration: r.duration_raw })} />
                  <S3Cell a={r.s3} k={'s3' + r.name} busy={busy} onSend={() => act('s3' + r.name, { action: 'send_library_s3', filename: r.name, file_title: r.title })} />
                  {r.can_delete && <Btn k={'del' + r.name} busy={busy} cls="btn btn-danger cm-btn" onClick={() => ask?.({ title: 'Delete this file?', body: <p>This removes <b>{r.title}</b> from library storage. You cannot undo this.</p>, yes: 'Yes, delete it', run: () => act('del' + r.name, { action: 'delete_recording', file: r.name }) })}>Delete</Btn>}
                </div> : <span className="muted">-</span>}</td>
              </tr>))}</tbody>
          </table></div>
        </>}
      {links !== null && <Modal title="Download links" icon="fas fa-link" onClose={() => setLinks(null)} wide>
        <p className="muted">One address per line. The last part of each address is the video title, so the saved file keeps that name.</p>
        <textarea className="input mono" rows={8} readOnly value={links} aria-label="Download links" onFocus={(e) => e.target.select()} />
        <div className="modal-acts"><button type="button" className="btn" onClick={() => navigator.clipboard?.writeText(links).then(() => toast?.({ kind: 'ok', text: 'Links copied.' }))}><i className="fas fa-copy" /> Copy selected links</button></div>
      </Modal>}
    </section>
  )
}

function Import({ p, busy, act }: Ctx) {
  const def = (d: string): VodState => ({ store: { state: p.act_as ? 'none' : 'store' }, yt: p.can_upload ? ((ytSecs(d) ?? 0) > 12 * 3600 ? { state: 'limit', reason: 'too_long' } : { state: 'send' }) : { state: 'none' }, s3: p.can_s3 ? { state: 'send' } : { state: 'none' } })
  return (
    <section className="card" aria-label="Twitch VODs">
      <h3 className="card-h"><i className="fab fa-twitch" /> Twitch VODs</h3>
      <p className="muted">Store a copy of a Twitch VOD on Sydney (counts toward VOD storage, kept 24 hours unless you extend). Send to YouTube after connecting your channel.</p>
      <Jobs p={p} />
      {p.twitch_videos.length > 0 && <p className="muted small">{p.twitch_capped ? `Showing the latest ${p.twitch_videos.length} videos from Twitch.` : `Showing ${p.twitch_videos.length} videos from Twitch.`}</p>}
      {p.twitch_error ? <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>{p.twitch_error}</span></div>
        : !p.twitch_videos.length ? <div className="st-empty"><i className="fab fa-twitch" /> No Twitch videos were returned for this channel.</div>
        : <div className="bp-table cm-table st-table"><table>
          <thead><tr><th>Title</th><th>Duration</th><th className="cm-c">Action</th></tr></thead>
          <tbody>{p.twitch_videos.map((v) => { const s = p.vod_states[v.id] ?? def(v.duration); return (
            <tr key={v.id}>
              <td data-l="Title" className="st-title">{v.title}<div className="st-badges st-sub">{s.store.state === 'pulling' ? <Pill k="pill-warn">{s.store.phase === 'queued' && s.store.pos ? `Queued for download (#${s.store.pos} in line)` : s.store.phase === 'saving' ? 'Saving to backend storage...' : s.store.phase === 'queued' ? 'Queued for download' : 'Downloading...'}</Pill> : s.store.state === 'stored' && <Pill k="pill-ok">Stored</Pill>}<YtCell a={s.yt} k={'yt' + v.id} busy={busy} onSend={() => act('yt' + v.id, { action: 'send_twitch_youtube', vod_id: v.id, vod_title: v.title, vod_duration: v.duration })} badge /><S3Cell a={s.s3} k={'s3' + v.id} busy={busy} onSend={() => act('s3' + v.id, { action: 'send_twitch_s3', vod_id: v.id, vod_title: v.title })} badge /></div></td>
              <td data-l="Duration" className="st-num">{v.duration}</td>
              <td className="cm-c st-acts-td"><div className="st-acts">
                {s.store.state === 'store' && <Btn k={'st' + v.id} busy={busy} cls="btn cm-btn" onClick={() => act('st' + v.id, { action: 'store_twitch_vod', vod_id: v.id, vod_title: v.title })}>Store VOD</Btn>}
                <YtCell a={s.yt} k={'yt' + v.id} busy={busy} onSend={() => act('yt' + v.id, { action: 'send_twitch_youtube', vod_id: v.id, vod_title: v.title, vod_duration: v.duration })} />
                <S3Cell a={s.s3} k={'s3' + v.id} busy={busy} onSend={() => act('s3' + v.id, { action: 'send_twitch_s3', vod_id: v.id, vod_title: v.title })} />
              </div></td>
            </tr>) })}</tbody>
        </table></div>}
    </section>
  )
}

type Item = { token: string; display: string; title: string; dur: number; game_id: string; game_name: string }
function Reruns({ p, busy, act, ask, toast, goSetup }: Ctx & { goSetup: () => void }) {
  const R = p.rerun
  const [items, setItems] = useState<Item[]>([]), [title, setTitle] = useState(''), [titleTouched, setTitleTouched] = useState(false), [perVod, setPerVod] = useState(false)
  const [cat, setCat] = useState<{ id: string; name: string } | null>(null), [catTouched, setCatTouched] = useState(false), [q, setQ] = useState(''), [res, setRes] = useState<{ id: string; name: string; box_art_url?: string }[] | null>(null)
  const [at, setAt] = useState('')
  useEffect(() => {
    if (!titleTouched) setTitle(items[0]?.title.slice(0, R.title_max) ?? '')
    if (!catTouched) { const f = items.find((i) => i.game_id); setCat(f ? { id: f.game_id, name: f.game_name } : null) }
  }, [items]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    const s = q.trim(); if (!s) { setRes(null); return }
    const t = setTimeout(async () => { try { setRes(await get<{ id: string; name: string; box_art_url?: string }[]>(`${API}?rerun_categories=${encodeURIComponent(s.slice(0, 100))}`)) } catch { setRes([]) } }, 300)
    return () => clearTimeout(t)
  }, [q])
  // Files deleted, expired or finished elsewhere update the picker via the poll;
  // drop any playlist entry whose file is no longer a candidate.
  useEffect(() => {
    const ok = new Set(p.rerun_candidates.map((c) => c.token))
    const gone = items.filter((i) => !ok.has(i.token))
    if (!gone.length) return
    setItems(items.filter((i) => ok.has(i.token)))
    toast?.({ kind: 'err', text: gone.length === 1 ? `"${gone[0].display}" is no longer in your library, so it was removed from the playlist.` : `${gone.length} VODs are no longer in your library, so they were removed from the playlist.` })
  }, [p.rerun_candidates]) // eslint-disable-line react-hooks/exhaustive-deps
  const total = items.reduce((a, i) => a + i.dur, 0)
  const full = items.length >= R.max_items
  const mv = (i: number, d: number) => setItems((x) => { const c = [...x], j = i + d; if (j < 0 || j >= c.length) return x; [c[i], c[j]] = [c[j], c[i]]; return c })
  function schedule() {
    if (!items.length) return toast?.({ kind: 'err', text: 'Add at least one VOD to the playlist.' })
    if (!title.trim()) return toast?.({ kind: 'err', text: 'Enter a stream title.' })
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(at)) return toast?.({ kind: 'err', text: 'Pick a valid start date and time.' })
    const b: Record<string, string> = { action: 'rerun_schedule', rerun_title: title.trim(), rerun_at: at, rerun_game_id: cat?.id ?? '', rerun_game_name: cat?.name ?? '' }
    if (perVod) b.rerun_per_vod_titles = '1'
    items.forEach((it, i) => { b[`items[${i}][vod]`] = it.token; b[`items[${i}][title]`] = it.title.slice(0, R.title_max) })
    act('sched', b, 'load')
  }
  const blocked = !R.has_key ? <div className="notice notice-warn"><i className="fas fa-key" /> <span>Reruns need your Twitch stream key. <button type="button" className="link-btn" onClick={goSetup}>Add it under Setup, Ingest.</button></span></div>
    : p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>Reruns can't be changed while acting as another user.</span></div>
    : !p.rerun_candidates.length ? <div className="st-empty"><i className="fas fa-film" /> No finished VODs in your library yet. Record a stream or store a Twitch VOD first.</div> : null
  return <>
    <section className="card st-rr" aria-label="Schedule a rerun">
      <h3 className="card-h"><i className="fas fa-redo-alt" /> Schedule a rerun</h3>
      <p className="muted">Pick stored VODs and a start time. Specter streams them to your Twitch channel with your stream key as one continuous stream, in order, titled "{R.prefix}{'{title}'}" with the category you choose.</p>
      {blocked ?? <>
        <h4 className="mp-h">1. Pick VODs</h4>
        <div className="bp-table cm-table st-table st-pick-table"><table>
          <thead><tr><th>File</th><th>Length</th><th>Category</th><th className="cm-c">Action</th></tr></thead>
          <tbody>{p.rerun_candidates.map((c) => <tr key={c.token}>
            <td data-l="File" className="st-title">{c.display}</td>
            <td data-l="Length" className="st-num">{fmtLen(c.duration_seconds)}</td>
            <td data-l="Category">{c.game_name || '-'}</td>
            <td className="cm-c"><button type="button" className="btn btn-ghost cm-btn" disabled={full} onClick={() => setItems([...items, { token: c.token, display: c.display, title: c.title, dur: c.duration_seconds, game_id: c.game_id, game_name: c.game_name }])}><i className="fas fa-plus" /> Add</button></td>
          </tr>)}</tbody>
        </table></div>
        <h4 className="mp-h">2. Order</h4>
        <p className="muted small">VODs play in this order as one stream. VODs with different video settings (for example 720p next to 1080p) reconnect for a moment between them.</p>
        {!items.length ? <div className="st-empty">Add a VOD above to start the playlist.</div> : <ol className="st-playlist">{items.map((it, i) => <li key={i} className="st-pl-item">
          <span className="st-pl-num">{i + 1}.</span>
          <div className="st-pl-body"><div className="small">{it.display}</div>
            {perVod && <div className="st-title-row"><span className="st-prefix">{R.prefix}</span><input className="input" aria-label={`Title for VOD ${i + 1}`} maxLength={R.title_max} value={it.title} onChange={(e) => setItems(items.map((x, j) => j === i ? { ...x, title: e.target.value } : x))} /></div>}</div>
          <div className="row-btns"><button type="button" className="btn btn-ghost" aria-label="Move up" disabled={!i} onClick={() => mv(i, -1)}><i className="fas fa-arrow-up" /></button><button type="button" className="btn btn-ghost" aria-label="Move down" disabled={i === items.length - 1} onClick={() => mv(i, 1)}><i className="fas fa-arrow-down" /></button><button type="button" className="btn btn-danger" aria-label="Remove from playlist" onClick={() => setItems(items.filter((_, j) => j !== i))}><i className="fas fa-times" /></button></div>
        </li>)}</ol>}
        <h4 className="mp-h">3. Title and category</h4>
        <div className="fld"><label htmlFor="rr-title">Stream title</label><div className="st-title-row"><span className="st-prefix">{R.prefix}</span><input id="rr-title" className="input" maxLength={R.title_max} value={title} onChange={(e) => { setTitle(e.target.value); setTitleTouched(true) }} /></div>
          <span className="muted small">Always shown as "{R.prefix}{'{title}'}". Filled in from the first VOD until you change it.</span></div>
        <label className="dc-check"><input type="checkbox" checked={perVod} onChange={(e) => setPerVod(e.target.checked)} /> <span>Change the title to each VOD's own title as it plays</span></label>
        <div className="fld st-cat"><label htmlFor="rr-cat">Twitch category</label>
          <input id="rr-cat" className="input" type="search" autoComplete="off" maxLength={100} placeholder="Search Twitch categories, or leave empty for none" value={q} onChange={(e) => setQ(e.target.value)} />
          {res && <ul className="st-cat-res" role="listbox" aria-label="Categories">{!res.length ? <li className="muted small">No categories found</li> : res.map((g) => <li key={g.id}><button type="button" role="option" aria-selected={cat?.id === g.id} onClick={() => { setCat({ id: g.id, name: g.name }); setCatTouched(true); setQ(''); setRes(null) }}>{g.box_art_url && <img src={g.box_art_url} alt="" width={26} height={36} />}{g.name}</button></li>)}</ul>}
          <span className="muted small">{cat ? <>Category: {cat.name} <button type="button" className="link-btn" onClick={() => { setCat(null); setCatTouched(true) }}>Clear</button></> : 'No category: the Twitch category will be cleared.'}</span></div>
        <h4 className="mp-h">4. Start time</h4>
        <div className="fld"><label htmlFor="rr-at">Start time ({p.timezone})</label><input id="rr-at" className="input st-dt" type="datetime-local" min={R.min} max={R.max} value={at} onChange={(e) => setAt(e.target.value)} /></div>
        <p>Total length: <b>{items.length ? fmtLen(total) : '-'}</b></p>
        <div className="row-btns"><Btn k="sched" busy={busy} cls="btn" disabled={!items.length || !at || !title.trim()} onClick={schedule}><i className="fas fa-calendar-plus" /> Schedule rerun</Btn></div>
      </>}
    </section>
    <section className="card" aria-label="Scheduled reruns">
      <h3 className="card-h"><i className="fas fa-calendar-alt" /> Scheduled reruns</h3>
      {!p.reruns.length ? <div className="st-empty"><i className="fas fa-calendar" /> No reruns scheduled yet.</div> : <div className="bp-table cm-table st-table"><table>
        <thead><tr><th>When</th><th>VODs</th><th>Status</th><th className="cm-c">Action</th></tr></thead>
        <tbody>{p.reruns.map((r) => {
        const tally: Record<string, number> = {}
        if (r.status !== 'cancelled') r.items.forEach((i) => { if (IT_ST[i.status]) tally[i.status] = (tally[i.status] || 0) + 1 })
        const live = r.status === 'live'
        return <tr key={r.id}>
          <td data-l="When"><b>{r.when}</b><div className="muted small">{r.game || 'No category'}</div><div className="muted small">Total length: {r.length}</div></td>
          <td data-l="VODs" className="st-title"><details open={live}><summary>{r.items.length === 1 ? '1 VOD' : `${r.items.length} VODs`} {Object.entries(tally).map(([s, n]) => <Pill key={s} k={IT_BADGE[s]}>{n} {IT_ST[s]}</Pill>)}</summary>
            <ol className="st-rr-items">{r.items.map((i, n) => <li key={n}><span title={i.full_title}>{i.title}</span> <span className="muted">{i.length}</span> {r.status !== 'cancelled' && IT_ST[i.status] && <Pill k={IT_BADGE[i.status]}>{IT_ST[i.status]}</Pill>}{i.reason && <span className="muted small"> {i.reason}</span>}</li>)}</ol></details></td>
          <td data-l="Status"><Pill k={RR_BADGE[r.status] || 'pill-off'}>{RR_ST[r.status] || r.status}</Pill>
            {live && r.current_position !== null && <div className="muted small">Playing VOD {r.current_position} of {r.items.length}</div>}{live && r.cancel_requested && <div className="muted small">Stopping...</div>}{r.reason && <div className="muted small">{r.reason}</div>}</td>
          <td className="cm-c">{p.act_as ? <span className="muted">-</span> : (r.status === 'scheduled' || (live && !r.cancel_requested)) ? <Btn k={'rc' + r.id} busy={busy} cls="btn btn-danger cm-btn" onClick={() => ask?.({ title: live ? 'Stop this rerun now?' : 'Cancel this rerun?', body: <p>{live ? 'The stream to Twitch will end.' : 'It will not go live.'}</p>, yes: live ? 'Stop now' : 'Cancel rerun', run: () => act('rc' + r.id, { action: 'rerun_cancel', rerun_id: String(r.id) }, 'load') })}>{live ? 'Stop now' : 'Cancel'}</Btn>
            : !live ? <Btn k={'rm' + r.id} busy={busy} cls="btn btn-ghost cm-btn" onClick={() => act('rm' + r.id, { action: 'rerun_remove', rerun_id: String(r.id) }, 'load')}>Remove</Btn> : <span className="muted">-</span>}</td>
        </tr> })}</tbody>
      </table></div>}
    </section>
  </>
}

function Secret({ id, value, onChange, label, readOnly, warn, ask }: { id: string; value: string; onChange?: (v: string) => void; label: string; readOnly?: boolean; warn?: boolean; ask?: Ctx['ask'] }) {
  const [show, setShow] = useState(false)
  const flip = () => { if (show || !warn || !ask) return setShow(!show); ask({ title: 'Are you sure?', body: <p>Revealing your stream key on stream can be a security risk. Make sure this screen is not shared before you continue.</p>, yes: 'Yes, show it', run: () => setShow(true) }) }
  return <div className="st-secret"><input id={id} className="input mono" type={show ? 'text' : 'password'} autoComplete="off" readOnly={readOnly} maxLength={256} value={value} onChange={(e) => onChange?.(e.target.value)} />
    <button type="button" className="btn btn-ghost" aria-label={show ? `Hide ${label}` : `Show ${label}`} aria-pressed={show} onClick={flip}><i className={show ? 'fas fa-eye-slash' : 'fas fa-eye'} /></button></div>
}
function Copy({ v, toast }: { v: string; toast?: (t: Toast) => void }) { return <button type="button" className="btn btn-ghost" onClick={() => navigator.clipboard?.writeText(v).then(() => toast?.({ kind: 'ok', text: 'Copied.' }))}><i className="fas fa-copy" /> Copy</button> }

type Cam = { success: boolean; act_as: boolean; login: string; ingest_url: string; srt_url: string; web_url: string; password_set: boolean }
const CAM_API = '/api/streaming/camera.php'
function Camera({ ask, toast }: Pick<Ctx, 'ask' | 'toast'>) {
  const [c, setC] = useState<Cam | null>(null), [err, setErr] = useState(''), [pw, setPw] = useState(''), [show, setShow] = useState(false), [busy, setBusy] = useState('')
  useEffect(() => { get<Cam>(CAM_API, 20000).then(setC).catch(() => setErr('The camera settings could not load. Refresh to try again.')) }, [])
  const pwOk = /^[A-Za-z0-9_-]{8,64}$/.test(pw)
  async function save(action: 'save' | 'clear') {
    setBusy(action)
    try {
      const r = await send<{ success: boolean; message?: string; error?: string; password_set?: boolean }>(CAM_API, action === 'save' ? { action, camera_password: pw } : { action })
      toast?.({ kind: r.success ? 'ok' : 'err', text: r.message || r.error || (r.success ? 'Done.' : 'That did not work. Please try again.') })
      if (r.success) { setPw(''); setShow(false); setC((o) => o && { ...o, password_set: !!r.password_set }) }
    } catch (e) { toast?.({ kind: 'err', text: (e as Error).message || 'That did not work. Please try again.' }) }
    setBusy('')
  }
  const url = (id: string, label: string, v: string, help: ReactNode) => <div className="fld fld-wide"><label htmlFor={id}>{label}</label><div className="st-secret"><input id={id} className="input mono" readOnly value={v} /><Copy v={v} toast={toast} /></div><span className="muted small">{help}</span></div>
  return <section className="card" id="camera" aria-label="Phone Camera">
    <h3 className="card-h"><i className="fas fa-mobile-alt" /> Phone Camera</h3>
    <p className="muted">Send your phone camera to our server with the Specter Camera app. Camera streams are never recorded or forwarded; they are only relayed live so you can pull them into OBS or watch them in a browser.</p>
    {!c ? (err ? <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div> : <span className="skel skel-line" />) : <>
      <div className="st-form">
        {url('cam-ingest', 'Camera RTMPS server (in the app)', c.ingest_url, 'Your API Key (found on your profile) serves as the stream key for our servers.')}
        {url('cam-srt', 'OBS Media Source (SRT, lowest delay)', c.srt_url, 'In OBS add a Media Source, untick Local File and paste this as the input. Replace PASSWORD with your API key or the camera password below.')}
        {url('cam-web', 'Browser viewer (WebRTC)', c.web_url, <>Opens a live viewer. When it asks to sign in, use <b>{c.login}</b> as the username and your API key or the camera password.</>)}
        {!c.act_as && <div className="fld fld-wide"><label htmlFor="cam-pw">Camera viewer password</label><div className="st-secret">
          <input id="cam-pw" className="input mono" type={show ? 'text' : 'password'} autoComplete="new-password" maxLength={64} placeholder={c.password_set ? 'A camera password is set. Enter a new one to replace it.' : 'No camera password set.'} value={pw} onChange={(e) => setPw(e.target.value)} />
          <button type="button" className="btn btn-ghost" aria-label={show ? 'Hide camera password' : 'Show camera password'} aria-pressed={show} onClick={() => setShow(!show)}><i className={show ? 'fas fa-eye-slash' : 'fas fa-eye'} /></button>
        </div><span className="muted small">Lets other people (or your OBS) view the camera without your API key. 8 to 64 letters, numbers, - or _.</span></div>}
      </div>
      {c.act_as ? <p className="muted">The camera password is disabled while acting as another channel.</p> : <div className="row-btns">
        <Btn k="save" busy={busy} cls="btn" disabled={!pwOk} onClick={() => save('save')}>Save camera password</Btn>
        {c.password_set && <Btn k="clear" busy={busy} cls="btn btn-danger" onClick={() => ask?.({ title: 'Remove camera password?', body: <p>Anyone using the camera password will need your API key to watch the camera again.</p>, yes: 'Remove password', danger: true, run: () => save('clear') })}>Remove camera password</Btn>}
      </div>}
    </>}
  </section>
}

function Setup({ p, busy, act, ask, toast }: Ctx) {
  const [rec, setRec] = useState(p.auto_record)
  const s = p.s3
  const [s3f, setS3f] = useState({ s3_endpoint: s.endpoint ?? '', s3_region: s.region ?? '', s3_bucket: s.bucket ?? '', s3_prefix: s.prefix ?? '', s3_access_key: s.access_key ?? '', s3_secret_key: '' })
  const [pathStyle, setPathStyle] = useState(!!s.path_style || !s.connected), [auto, setAuto] = useState(!!s.auto_copy)
  const [tk, setTk] = useState(p.ingest.twitch_key), [fwdTw, setFwdTw] = useState(p.ingest.forward_to_twitch)
  const [fwd, setFwd] = useState(() => p.forward.map((f) => ({ ...f })))
  const y = p.youtube
  const keyOk = (k: string) => /^[\x21-\x7e]{0,256}$/.test(k)
  const s3Field = (k: keyof typeof s3f, l: string, ph = '', help?: string, wide = false) => <div className={wide ? 'fld fld-wide' : 'fld'}><label htmlFor={k}>{l}</label><input id={k} className="input" type="text" autoComplete="off" maxLength={k === 's3_endpoint' ? 512 : 256} placeholder={ph} value={s3f[k]} onChange={(e) => setS3f({ ...s3f, [k]: e.target.value })} />{help && <span className="muted small">{help}</span>}</div>
  return <div className="st-setup">
    <section className="card" id="record" aria-label="Recording">
      <h3 className="card-h"><i className="fas fa-video" /> Recording</h3>
      <p className="muted">When on, your Twitch stream is recorded automatically while you are live.</p>
      <div className="st-opts"><Opt id="auto_record" on={rec} set={setRec} disabled={p.act_as} label="Enable channel recording" /></div>
      <div className="row-btns"><Btn k="rec" busy={busy} cls="btn" disabled={p.act_as || rec === p.auto_record} onClick={() => act('rec', rec ? { save_recording_settings: '1', auto_record: 'on' } : { save_recording_settings: '1' }, 'load')}>Save</Btn></div>
    </section>
    <section className="card" id="youtube" aria-label="YouTube">
      <div className="card-title-row"><h3 className="card-h"><i className="fab fa-youtube" /> YouTube</h3>{!p.can_youtube ? <Pill k="pill-warn">Coming soon</Pill> : y.linked ? <Pill k="pill-ok">Connected</Pill> : y.needs_reauth ? <Pill k="pill-warn">Reconnect required</Pill> : <Pill k="pill-err">Not connected</Pill>}</div>
      {!p.can_youtube ? <p className="muted">YouTube VOD upload is in testing and is not available on public accounts yet.</p> : <>
        <p className="muted">Connect the YouTube channel you own if you want Specter to upload your recorded VODs. Pick the Brand Account or personal channel in Google's account chooser; uploads go to that channel.</p>
        {y.linked ? <div className="st-yt">
          {y.channel_thumbnail ? <img className="st-yt-thumb" src={y.channel_thumbnail} alt="" /> : <span className="st-yt-thumb"><i className="fab fa-youtube" /></span>}
          <div className="st-yt-main"><b>{y.channel_title}</b><span className="muted small">Channel ID: <code>{y.channel_id}</code></span></div>
          {!p.act_as && <Btn k="ytd" busy={busy} cls="btn btn-danger" onClick={() => ask?.({ title: 'Disconnect YouTube Account?', body: <p>Specter will not be able to upload VODs until you connect your channel again.</p>, yes: 'Disconnect', run: () => act('ytd', { action: 'disconnect' }, 'load') })}>Disconnect</Btn>}
        </div> : <>
          <p>Link your YouTube channel to upload VODs from Specter recordings.</p>
          {p.act_as ? <div className="notice notice-warn"><i className="fas fa-user-secret" /> <span>YouTube linking is disabled while acting as another channel.</span></div>
            : y.configured && <div className="row-btns"><a className="btn" href={YT_LINK}><i className="fab fa-youtube" /> {y.needs_reauth ? 'Reconnect YouTube' : 'Connect YouTube'}</a></div>}
        </>}
      </>}
    </section>
    <section className="card" id="s3" aria-label="Your S3 bucket">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-database" /> Your S3 bucket</h3>{s.connected ? <Pill k="pill-ok">Connected</Pill> : <Pill k="pill-off">Not connected</Pill>}</div>
      <p className="muted">Point Specter at any S3-compatible store (AWS, Cloudflare R2, Backblaze, Wasabi, MinIO, Mega S4 and more). Only finished VODs are uploaded. Copies in your bucket do not expire on Specter's clock.</p>
      {p.act_as ? <p className="muted">S3 bucket settings are disabled while acting as another channel.</p> : <>
        {s.last_error && <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>{s.last_error}</span></div>}
        <div className="st-form">
          {s3Field('s3_endpoint', 'Endpoint URL', 'https://s3.example.com', undefined, true)}
          {s3Field('s3_region', 'Region', 'us-east-1', 'Use whatever your provider expects. If they do not care, us-east-1 is fine.')}
          {s3Field('s3_bucket', 'Bucket')}
          {s3Field('s3_prefix', 'Key prefix (optional)', 'vods', 'Objects are stored as {prefix}/{video title}.mp4. Leave blank for the top of the bucket.', true)}
          {s3Field('s3_access_key', 'Access key')}
          <div className="fld"><label htmlFor="s3_secret_key">Secret key</label><input id="s3_secret_key" className="input" type="password" autoComplete="new-password" maxLength={256} placeholder={s.secret_set ? `Leave blank to keep the current secret${s.secret_last4 ? ` (ends ${s.secret_last4})` : ''}` : ''} value={s3f.s3_secret_key} onChange={(e) => setS3f({ ...s3f, s3_secret_key: e.target.value })} /></div>
        </div>
        <div className="st-opts">
          <Opt id="s3_path_style" on={pathStyle} set={setPathStyle} label="Path-style addressing" help="Leave this on for most non-AWS endpoints. Turn it off only if your provider needs virtual-hosted URLs." />
          <Opt id="s3_auto_copy" on={auto} set={setAuto} label="Copy finished stream recordings automatically" help="When a live recording is converted to MP4, Specter also queues a copy to this bucket." />
        </div>
        <div className="row-btns">
          <Btn k="s3s" busy={busy} cls="btn" disabled={!s3f.s3_endpoint.trim() || !s3f.s3_bucket.trim() || !s3f.s3_access_key.trim() || (!s.secret_set && !s3f.s3_secret_key)} onClick={() => { const b: Record<string, string> = { action: 'save_s3_settings', ...s3f }; if (pathStyle) b.s3_path_style = '1'; if (auto) b.s3_auto_copy = '1'; act('s3s', b, 'load') }}>Save and test</Btn>
          {s.connected && <Btn k="s3d" busy={busy} cls="btn btn-danger" onClick={() => ask?.({ title: 'Disconnect bucket?', body: <p>Remove this bucket from Specter? Existing objects in the bucket are not deleted.</p>, yes: 'Disconnect bucket', run: () => act('s3d', { action: 'disconnect_s3' }, 'load') })}>Disconnect bucket</Btn>}
        </div>
      </>}
    </section>
    <section className="card" id="ingest" aria-label="Sydney RTMPS ingest">
      <h3 className="card-h"><i className="fas fa-satellite-dish" /> Sydney RTMPS ingest</h3>
      {!p.can_ingest ? <>
        <h4 className="mp-h">Streaming - Limited Testing</h4>
        <p className="muted">The streaming service is open to a limited number of testers while the new infrastructure rolls out. Access is by invitation only. To ask for access, send a testing request on our support page.</p>
        <div className="row-btns"><a className="btn" href="https://support.botofthespecter.com" target="_blank" rel="noopener noreferrer"><i className="fas fa-headset" /> Visit Support Page</a></div>
      </> : <>
        {p.ingest.has_slot ? <div className="notice notice-info"><i className="fas fa-circle-info" /> <span>{p.ingest.unlimited ? 'This channel has an ingest slot with no storage cap. Files are still removed after 24 hours.' : 'This channel has an ingest slot. Live publishes are recorded on Sydney (100GB cap, 24 hours).'}</span></div>
          : <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>This channel does not have an ingest slot yet. The Sydney server refuses the publish until a slot is granted (five channels max).</span></div>}
        <p className="muted">Point OBS (or any encoder) at this server to record on Specter and optionally forward to Twitch.</p>
        <div className="st-form"><div className="fld fld-wide"><label htmlFor="rtmps">OBS server</label><div className="st-secret"><input id="rtmps" className="input mono" readOnly value={p.ingest.rtmps} /><Copy v={p.ingest.rtmps} toast={toast} /></div></div>
        {!p.act_as && <>
          <div className="fld"><label htmlFor="spk">Specter stream key</label><div className="st-secret"><Secret id="spk" value={p.ingest.specter_key} label="Specter stream key" readOnly warn ask={ask} /><Copy v={p.ingest.specter_key} toast={toast} /></div><span className="muted small">Your API Key (on your profile) is the stream key for our servers.</span></div>
          <div className="fld"><label htmlFor="tk">Twitch Stream Key</label><Secret id="tk" value={tk} onChange={setTk} label="Twitch stream key" warn ask={ask} /></div>
        </>}</div>
        {!p.act_as && <>
          <div className="st-opts"><Opt id="fwd_twitch" on={fwdTw} set={setFwdTw} label="Forward to Twitch" help="Send this stream on to your Twitch channel using the key above." /></div>
          <div className="row-btns"><Btn k="ing" busy={busy} cls="btn" disabled={!keyOk(tk)} onClick={() => { const b: Record<string, string> = { save_streaming_settings: '1', twitch_key: tk }; if (fwdTw) b.forward_to_twitch = '1'; act('ing', b, 'load') }}>Save Settings</Btn></div>
        </>}
      </>}
    </section>
    <section className="card" id="forward" aria-label="Stream Forwarding">
      <h3 className="card-h"><i className="fas fa-broadcast-tower" /> Stream Forwarding</h3>
      <p className="muted">Forwarding restreams your Twitch broadcast live to other platforms while it is recorded. It works on its own from the recording setting: you can forward without recording, or do both.</p>
      <div className="st-fwd">{fwd.map((f, i) => <div key={f.service} className="st-fwd-block">
        <div className="st-fwd-head">{f.icon && /^(https?:)?\//.test(f.icon) ? <img src={f.icon} alt="" /> : <i className={f.icon || 'fas fa-broadcast-tower'} />}<b>{f.label}</b></div>
        <Secret id={`fwd-${f.service}`} value={f.stream_key} label={`${f.label} stream key`} onChange={(v) => setFwd(fwd.map((x, j) => j === i ? { ...x, stream_key: v } : x))} />
        <Opt id={`fwd-on-${f.service}`} on={f.enabled} disabled={p.act_as} set={(v) => setFwd(fwd.map((x, j) => j === i ? { ...x, enabled: v } : x))} label="Enable forwarding" />
      </div>)}</div>
      <div className="row-btns"><Btn k="fwd" busy={busy} cls="btn" disabled={p.act_as || fwd.some((f) => !keyOk(f.stream_key))} onClick={() => { const b: Record<string, string> = { save_forward_settings: '1' }; fwd.forEach((f) => { b[`forward_${f.service}_key`] = f.stream_key; if (f.enabled) b[`forward_${f.service}_enabled`] = 'on' }); act('fwd', b, 'load') }}>Save Forwarding Settings</Btn></div>
    </section>
    <Camera ask={ask} toast={toast} />
  </div>
}
