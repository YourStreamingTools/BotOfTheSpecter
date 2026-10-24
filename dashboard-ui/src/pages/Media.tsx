import { useEffect, useMemo, useRef, useState } from 'react'
import Combobox, { type ComboOption } from '../components/Combobox'
import Modal from '../components/Modal'
import TwitchUserPicker from '../components/TwitchUserPicker'
import { get, post } from '../bot'

// Port of dashboard/media.php. Same folder (/var/www/media/<user>) and tables,
// through the generated mirror public/api/media/media.php.
const API = '/api/media/media.php'
const OLD = 'https://dashboard.botofthespecter.com'
const EXTS = ['mp3', 'mp4', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'webm']
const ACCEPT = EXTS.map((e) => '.' + e).join(',')

type FileRow = { path: string; size: number; type: 'audio' | 'video' | 'image' | 'other'; preview: '' | 'image' | 'video' | 'audio'; mtime: number }
type Walkon = { user_id: string; user_name: string; mode: string }
type AlertRef = { id: number; category: string; variant: string; type: string }
type MediaData = {
  sound_alerts: Record<string, string[]>; video_alerts: Record<string, string[]>; twitch_events: Record<string, string[]>
  walkons: Record<string, Walkon[]>; alert_builder: Record<string, AlertRef[]>
  rewards: { reward_id: string; reward_title: string }[]; reward_titles: Record<string, string>
  reward_used_sound: string[]; reward_used_video: string[]; twitch_events_list: string[]; seen_users: string[]
}
type List = { success: boolean; files: FileRow[]; base_url: string; storage: { used: number; max: number; percentage: number }; media_data: MediaData; error?: string }
type Filter = 'all' | 'rewards' | 'events' | 'walkons' | 'unused' | 'videos' | 'images'

const FILTERS: ComboOption[] = [
  { value: 'all', label: 'All files' }, { value: 'rewards', label: 'With rewards' }, { value: 'events', label: 'With events' },
  { value: 'walkons', label: 'Walk-ons' }, { value: 'unused', label: 'Unused' }, { value: 'videos', label: 'Videos' }, { value: 'images', label: 'Images' },
]

export function fmtBytes(b: number): string {
  if (b >= 1048576) return `${(b / 1048576).toFixed(1)} MB`
  if (b >= 1024) return `${(b / 1024).toFixed(1)} KB`
  return `${b} B`
}
const base = (p: string) => p.slice(p.lastIndexOf('/') + 1)
const folderOf = (p: string) => (p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '')
const ext = (p: string) => p.slice(p.lastIndexOf('.') + 1).toLowerCase()
const pl = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`
const urlFor = (baseUrl: string, p: string) => baseUrl + p.split('/').map(encodeURIComponent).join('/')

// Same lookups as the live page: a mapping can be keyed by the full path or the bare name.
function maps<T>(m: Record<string, T[]> | undefined, f: string): T[] {
  if (!m) return []
  const a = m[f] ?? [], b = base(f) !== f ? m[base(f)] ?? [] : []
  return [...a, ...b]
}
function usage(d: MediaData, f: FileRow) {
  const rewards = maps(d.sound_alerts, f.path).length + maps(d.video_alerts, f.path).length
  const events = maps(d.twitch_events, f.path).length
  const walkons = maps(d.walkons, f.path).length
  const alerts = maps(d.alert_builder, f.path).length
  const parts = [rewards && pl(rewards, 'reward'), events && pl(events, 'event'), walkons && pl(walkons, 'walk-on'), alerts && pl(alerts, 'alert')].filter(Boolean) as string[]
  return { rewards, events, walkons, alerts, total: rewards + events + walkons + alerts, summary: parts.length ? parts.join(' · ') : 'Unused' }
}
function icon(t: FileRow['type']) { return t === 'audio' ? 'fa-solid fa-music' : t === 'video' ? 'fa-solid fa-film' : t === 'image' ? 'fa-solid fa-image' : 'fa-solid fa-file' }

export default function Media() {
  const [list, setList] = useState<List | null>(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [folder, setFolder] = useState('')
  const [sel, setSel] = useState<Set<string>>(new Set())
  const [open, setOpen] = useState<FileRow | null>(null)
  const [preview, setPreview] = useState<FileRow | null>(null)
  const [renaming, setRenaming] = useState<FileRow | null>(null)
  const [deleting, setDeleting] = useState<string[] | null>(null)
  const [locked, setLocked] = useState<{ names: string[]; text: string } | null>(null)
  const [toast, setToast] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null)

  async function load() {
    try {
      const r = await get<List>(`${API}?ajax_action=list`, 30000)
      if (!r.success) throw new Error(r.error || 'failed')
      setList(r); setErr('')
    } catch { setErr('Could not load your media library. Try again in a moment.') }
  }
  useEffect(() => { load() }, [])
  useEffect(() => { if (!toast) return; const t = setTimeout(() => setToast(null), 6000); return () => clearTimeout(t) }, [toast])

  const d = list?.media_data
  const rows = useMemo(() => {
    if (!list || !d) return { folders: [] as { name: string; count: number }[], files: [] as FileRow[] }
    const needle = q.trim().toLowerCase()
    const match = (f: FileRow) => {
      const u = usage(d, f)
      if (filter === 'rewards' && !u.rewards) return false
      if (filter === 'events' && !u.events) return false
      if (filter === 'walkons' && !u.walkons) return false
      if (filter === 'unused' && u.total) return false
      if (filter === 'videos' && f.type !== 'video') return false
      if (filter === 'images' && f.type !== 'image') return false
      return true
    }
    // Searching or filtering looks through every folder, like the live page.
    if (needle || filter !== 'all') {
      return { folders: [], files: list.files.filter((f) => match(f) && (!needle || f.path.toLowerCase().includes(needle))) }
    }
    const counts = new Map<string, number>()
    if (!folder) list.files.forEach((f) => { const fo = folderOf(f.path); if (fo) counts.set(fo, (counts.get(fo) ?? 0) + 1) })
    return {
      folders: [...counts].map(([name, count]) => ({ name, count })).sort((a, b) => a.name.localeCompare(b.name)),
      files: list.files.filter((f) => folderOf(f.path) === folder),
    }
  }, [list, d, q, filter, folder])

  function toggle(p: string) { setSel((s) => { const n = new Set(s); n.has(p) ? n.delete(p) : n.add(p); return n }) }
  const allOn = rows.files.length > 0 && rows.files.every((f) => sel.has(f.path))

  function askDelete(paths: string[]) {
    if (!d || !list) return
    const busy = paths.map((p) => list.files.find((f) => f.path === p)).filter((f): f is FileRow => !!f && usage(d, f).total > 0)
    if (busy.length) { setLocked({ names: busy.map((f) => base(f.path)), text: busy.length === 1 ? usage(d, busy[0]).summary : '' }); return }
    setDeleting(paths)
  }
  async function doDelete(paths: string[]) {
    setDeleting(null)
    const body = new URLSearchParams()
    paths.forEach((p) => body.append('delete_files[]', p))
    try {
      const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body })
      const j = await r.json() as { success: boolean; status: string }
      setToast({ kind: /fail|still linked/i.test(j.status) ? 'err' : 'ok', text: j.status || 'Done.' })
    } catch { setToast({ kind: 'err', text: 'Delete failed. Try again.' }) }
    setSel(new Set()); load()
  }
  async function test(f: FileRow) {
    try {
      const ev = f.type === 'video' ? 'VIDEO_ALERT' : 'SOUND_ALERT'
      const r = await post<{ success: boolean; message?: string }>('/api/media/notify_event.php', { event: ev, [f.type === 'video' ? 'video' : 'sound']: f.path })
      setToast(r.success ? { kind: 'ok', text: `Sent ${base(f.path)} to your overlay.` } : { kind: 'err', text: r.message || 'Test playback failed.' })
    } catch { setToast({ kind: 'err', text: 'Test playback failed.' }) }
  }

  return (
    <div className="page media">
      <div className="section-head">
        <h2><i className="fas fa-photo-film" /> Media</h2>
        {list && <span className="pill">{pl(list.files.length, 'file')}</span>}
      </div>
      <p className="muted">Upload audio (MP3), video (MP4 or WEBM) or images (PNG, JPG, GIF or WEBP) to your shared library. Open a file to attach it to channel point rewards, Twitch events or walk-ons. The same files power the Specter Alerts builder.</p>
      {list ? <StorageBar s={list.storage} /> : <div className="card media-storage"><span className="skel skel-line" /></div>}
      {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span> <button className="btn sm" type="button" onClick={load}>Retry</button></div>}
      <Uploader storage={list?.storage} onDone={(msg, ok) => { setToast({ kind: ok ? 'ok' : 'err', text: msg }); load() }} />
      {toast && <div className={`notice notice-${toast.kind === 'ok' ? 'ok' : 'err'} media-toast`} role="status"><i className={toast.kind === 'ok' ? 'fas fa-check' : 'fas fa-triangle-exclamation'} /> <span className="pre">{toast.text}</span> <button type="button" className="combo-x" aria-label="Dismiss" onClick={() => setToast(null)}><i className="fa-solid fa-xmark" /></button></div>}

      <section className="card media-lib">
        <div className="media-tools">
          <label className="media-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search files" aria-label="Search files" value={q} onChange={(e) => setQ(e.target.value)} /></label>
          <div className="media-filter"><Combobox label="Filter" options={FILTERS} value={filter} onChange={(v) => setFilter(v as Filter)} searchable={false} /></div>
        </div>
        <div className="media-bar">
          <label className="media-check-all"><input type="checkbox" checked={allOn} disabled={!rows.files.length} onChange={() => setSel(allOn ? new Set() : new Set(rows.files.map((f) => f.path)))} /> Select all</label>
          {sel.size > 0 && <button type="button" className="btn btn-danger" onClick={() => askDelete([...sel])}><i className="fas fa-trash" /> Delete {sel.size}</button>}
        </div>
        {folder && !q && filter === 'all' && (
          <nav className="crumbs" aria-label="Folder"><button type="button" className="crumb" onClick={() => setFolder('')}><i className="fa-solid fa-house" /> Library</button><span aria-hidden="true">/</span><b>{folder}</b></nav>
        )}
        {!list && !err && <ul className="media-list">{[0, 1, 2, 3].map((i) => <li key={i} className="media-row"><span className="skel skel-line" /></li>)}</ul>}
        {list && rows.folders.length + rows.files.length === 0 && <p className="muted media-empty">{list.files.length ? 'No files match.' : 'No files yet. Upload your first file above.'}</p>}
        {list && d && (
          <ul className="media-list">
            {rows.folders.map((fo) => (
              <li key={fo.name} className="media-row media-folder">
                <span className="media-check-wrap" aria-hidden="true" />
                <button type="button" className="media-thumb" aria-label={`Open folder ${fo.name}`} onClick={() => setFolder(fo.name)}><i className="fa-solid fa-folder" /></button>
                <button type="button" className="media-name" onClick={() => setFolder(fo.name)}>{fo.name}</button>
                <span className="media-meta">{pl(fo.count, 'file')}</span>
              </li>
            ))}
            {rows.files.map((f) => {
              const u = usage(d, f)
              return (
                <li key={f.path} className="media-row">
                  <label className="media-check-wrap"><input type="checkbox" className="media-check" aria-label={`Select ${base(f.path)}`} checked={sel.has(f.path)} onChange={() => toggle(f.path)} /></label>
                  {f.preview ? (
                    <button type="button" className="media-thumb" aria-label={`Preview ${base(f.path)}`} onClick={() => setPreview(f)}>
                      {f.preview === 'image' ? <img src={urlFor(list.base_url, f.path)} alt="" loading="lazy" decoding="async" /> : <i className={icon(f.type)} />}
                    </button>
                  ) : <span className="media-thumb" aria-hidden="true"><i className={icon(f.type)} /></span>}
                  <button type="button" className="media-name" title={f.path} onClick={() => setOpen(f)}>
                    <span>{(q || filter !== 'all') && folderOf(f.path) ? <span className="muted">{folderOf(f.path)}/</span> : null}{base(f.path)}</span>
                    <small className="media-meta">{ext(f.path).toUpperCase()} · {fmtBytes(f.size)} · <span className={u.total ? '' : 'media-unused'}>{u.summary}</span></small>
                  </button>
                  <span className="media-acts">
                    {(f.type === 'audio' || f.type === 'video') && <button type="button" className="icon-btn icon-ok" title="Test playback" aria-label={`Test ${base(f.path)} on your overlay`} onClick={() => test(f)}><i className="fas fa-play" /></button>}
                    <button type="button" className="icon-btn" title="Rename" aria-label={`Rename ${base(f.path)}`} onClick={() => setRenaming(f)}><i className="fas fa-pencil" /></button>
                    {u.total ? <button type="button" className="icon-btn" title="In use. Remove its links before deleting." aria-label={`${base(f.path)} is in use`} onClick={() => setLocked({ names: [base(f.path)], text: u.summary })}><i className="fas fa-lock" /></button>
                      : <button type="button" className="icon-btn icon-err" title="Delete" aria-label={`Delete ${base(f.path)}`} onClick={() => askDelete([f.path])}><i className="fas fa-trash" /></button>}
                  </span>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {open && list && d && <FileModal f={open} list={list} onClose={() => setOpen(null)} onChange={load} onTest={() => test(open)} onPreview={() => setPreview(open)} />}
      {preview && list && (
        <Modal title={base(preview.path)} icon={icon(preview.type)} onClose={() => setPreview(null)} wide>
          <div className="media-preview">
            {preview.preview === 'image' && <img src={urlFor(list.base_url, preview.path)} alt={base(preview.path)} />}
            {preview.preview === 'video' && <video src={urlFor(list.base_url, preview.path)} controls playsInline preload="metadata" />}
            {preview.preview === 'audio' && <audio src={urlFor(list.base_url, preview.path)} controls preload="metadata" />}
          </div>
        </Modal>
      )}
      {renaming && <RenameModal f={renaming} onClose={() => setRenaming(null)} onDone={(m, ok) => { setRenaming(null); setToast({ kind: ok ? 'ok' : 'err', text: m }); load() }} />}
      {deleting && (
        <Modal title={deleting.length === 1 ? 'Delete file?' : 'Delete files?'} icon="fas fa-trash" onClose={() => setDeleting(null)}>
          <p>{deleting.length === 1 ? `Are you sure you want to delete "${base(deleting[0])}"?` : `Are you sure you want to delete the selected ${deleting.length} files?`} This cannot be undone.</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDeleting(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => doDelete(deleting)}><i className="fas fa-trash" /> Yes, delete</button></div>
        </Modal>
      )}
      {locked && (
        <Modal title={locked.names.length === 1 ? 'File is in use' : 'Some files are still in use'} icon="fas fa-lock" onClose={() => setLocked(null)}>
          {locked.names.length === 1 ? <p><b>{locked.names[0]}</b> is still attached to {locked.text.toLowerCase()}.</p>
            : <><p>Remove the links on these files before deleting:</p><ul className="media-locked">{locked.names.map((n) => <li key={n}>{n}</li>)}</ul></>}
          <p className="muted">Open the file to remove its channel point reward, Twitch event or walk-on links. For alert builder usage, open <a href={`${OLD}/alerts.php`}>Specter Alerts</a> and clear the file from those variants. Then try the delete again.</p>
          <div className="modal-acts"><button type="button" className="btn" onClick={() => setLocked(null)}>OK</button></div>
        </Modal>
      )}
    </div>
  )
}

function StorageBar({ s }: { s: List['storage'] }) {
  const pct = Math.max(0, Math.min(100, s.percentage))
  const level = pct >= 90 ? 'err' : pct >= 70 ? 'warn' : 'ok'
  const r = 22, c = 2 * Math.PI * r
  return (
    <section className="card media-storage" aria-label="Storage">
      <div className={`gauge gauge-sm gauge-${level}`} role="img" aria-label={`${Math.round(pct)}% of storage used`}>
        <svg viewBox="0 0 56 56"><circle className="gauge-track" cx="28" cy="28" r={r} /><circle className="gauge-fill" cx="28" cy="28" r={r} strokeDasharray={c} strokeDashoffset={c * (1 - pct / 100)} /></svg>
        <div className="gauge-mid"><b>{Math.round(pct)}%</b></div>
      </div>
      <div className="media-storage-text">
        <b>{fmtBytes(s.used)} of {fmtBytes(s.max)} used</b>
        <span className="muted">{fmtBytes(Math.max(0, s.max - s.used))} free</span>
      </div>
      {level !== 'ok' && <span className={`pill pill-${level === 'err' ? 'live' : 'warn'}`}>{level === 'err' ? 'Almost full' : 'Filling up'}</span>}
    </section>
  )
}

type Up = { name: string; size: number; error?: string }
function Uploader({ storage, onDone }: { storage?: List['storage']; onDone: (msg: string, ok: boolean) => void }) {
  const [files, setFiles] = useState<File[]>([])
  const [drag, setDrag] = useState(false)
  const [pct, setPct] = useState<number | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const free = storage ? Math.max(0, storage.max - storage.used) : Infinity
  const checked: Up[] = useMemo(() => {
    let used = 0
    return files.map((f) => {
      if (!EXTS.includes(ext(f.name))) return { name: f.name, size: f.size, error: 'Not an allowed file type' }
      if (used + f.size > free) return { name: f.name, size: f.size, error: 'Not enough storage left' }
      used += f.size
      return { name: f.name, size: f.size }
    })
  }, [files, free])
  const ok = files.filter((_, i) => !checked[i].error)
  function add(list: FileList | null) { if (list?.length) setFiles((f) => [...f, ...Array.from(list)]) }
  function upload() {
    if (!ok.length) return
    const fd = new FormData()
    ok.forEach((f) => fd.append('filesToUpload[]', f, f.name))
    const x = new XMLHttpRequest()
    x.open('POST', API)
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
    x.upload.onprogress = (e) => { if (e.lengthComputable) setPct(Math.round((e.loaded / e.total) * 100)) }
    x.onload = () => {
      setPct(null)
      try {
        const j = JSON.parse(x.responseText) as { success: boolean; status: string }
        const text = j.status.replace(/<br\s*\/?>/g, '\n').replace(/<[^>]+>/g, '').trim()
        onDone(text || (j.success ? 'Upload complete.' : 'Upload failed.'), j.success)
        if (j.success) { setFiles([]); if (input.current) input.current.value = '' }
      } catch { onDone(x.status === 413 ? 'Upload failed. The files are too large.' : x.status === 401 ? 'Your session expired. Sign in again.' : 'Upload failed. Try again.', false) }
    }
    x.onerror = () => { setPct(null); onDone('Upload failed. Check your connection and try again.', false) }
    setPct(0)
    x.send(fd)
  }
  return (
    <section className="card media-up">
      <div className={`drop${drag ? ' is-drag' : ''}`}
        onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={() => setDrag(false)}
        onDrop={(e) => { e.preventDefault(); setDrag(false); add(e.dataTransfer.files) }}>
        <i className="fa-solid fa-cloud-arrow-up" aria-hidden="true" />
        <p><b>Drag files here</b> or</p>
        <button type="button" className="btn btn-ghost" onClick={() => input.current?.click()} disabled={pct !== null}><i className="fa-solid fa-folder-open" /> Choose files</button>
        <input ref={input} type="file" multiple accept={ACCEPT} hidden onChange={(e) => { add(e.target.files); e.target.value = '' }} />
        <small className="muted">MP3, MP4, WEBM, PNG, JPG, GIF or WEBP</small>
      </div>
      {files.length > 0 && (
        <>
          <ul className="up-list">
            {checked.map((u, i) => (
              <li key={i} className={u.error ? 'is-bad' : ''}>
                <span className="up-name"><span>{u.name}</span>{u.error && <small className="up-err"><i className="fas fa-triangle-exclamation" /> {u.error}</small>}</span><span className="muted up-size">{fmtBytes(u.size)}</span>
                <button type="button" className="combo-x" aria-label={`Remove ${u.name}`} disabled={pct !== null} onClick={() => setFiles((f) => f.filter((_, j) => j !== i))}><i className="fa-solid fa-xmark" /></button>
              </li>
            ))}
          </ul>
          {pct !== null && <div className="up-progress" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Upload progress"><span style={{ width: `${pct}%` }} /><b>{pct < 100 ? `Uploading ${pct}%` : 'Processing on the server'}</b></div>}
          <div className="modal-acts">
            <button type="button" className="btn btn-ghost" disabled={pct !== null} onClick={() => setFiles([])}>Clear</button>
            <button type="button" className="btn" disabled={!ok.length || pct !== null} onClick={upload}><i className="fa-solid fa-upload" /> Upload {ok.length ? pl(ok.length, 'file') : ''}</button>
          </div>
        </>
      )}
    </section>
  )
}

function RenameModal({ f, onClose, onDone }: { f: FileRow; onClose: () => void; onDone: (m: string, ok: boolean) => void }) {
  const e = '.' + ext(f.path)
  const [name, setName] = useState(base(f.path).slice(0, -e.length))
  const [busy, setBusy] = useState(false)
  async function go(ev: React.FormEvent) {
    ev.preventDefault()
    if (!name.trim()) return
    setBusy(true)
    try {
      const r = await post<{ ok?: boolean; success?: boolean; message?: string; error?: string; new?: string }>(API, { action: 'rename', old_file: f.path, new_name: name.trim() + e })
      const ok = !!(r.ok ?? r.success)
      onDone(r.message || (ok ? `Renamed to ${r.new ?? name}.` : 'Rename failed.'), ok)
    } catch { onDone('Rename failed.', false) }
  }
  return (
    <Modal title="Rename file" icon="fas fa-pencil" onClose={onClose}>
      <form onSubmit={go} className="rename-form">
        <label className="field-label" htmlFor="rn">New name</label>
        <div className="rename-row"><input id="rn" className="input" data-autofocus value={name} maxLength={200} onChange={(x) => setName(x.target.value)} /><span className="muted">{e}</span></div>
        <p className="muted small">Rewards, events, walk-ons and alerts that use this file are updated to the new name.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || !name.trim()}>Rename</button></div>
      </form>
    </Modal>
  )
}

function FileModal({ f, list, onClose, onChange, onTest, onPreview }: { f: FileRow; list: List; onClose: () => void; onChange: () => void; onTest: () => void; onPreview: () => void }) {
  const d = list.media_data
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const [user, setUser] = useState('')
  const [mode, setMode] = useState('sound')
  const isVideo = f.type === 'video'
  const rewards = maps(isVideo ? d.video_alerts : d.sound_alerts, f.path)
  const events = maps(d.twitch_events, f.path)
  const walkons = maps(d.walkons, f.path)
  const alerts = maps(d.alert_builder, f.path)
  const usedRewards = new Set(isVideo ? d.reward_used_video : d.reward_used_sound)
  const rewardOpts: ComboOption[] = d.rewards.filter((r) => !usedRewards.has(r.reward_id)).map((r) => ({ value: r.reward_id, label: r.reward_title }))
  const eventOpts: ComboOption[] = d.twitch_events_list.filter((e) => !events.includes(e)).map((e) => ({ value: e, label: e }))

  async function send(fields: Record<string, string>) {
    setBusy(true); setMsg('')
    try {
      const r = await post<{ success: boolean }>(API, fields)
      if (!r.success) setMsg('That did not save. Try again.')
      onChange()
    } catch { setMsg('That did not save. Try again.') }
    setBusy(false)
  }
  const rewardField: Record<string, string> = isVideo ? { media_type: 'video_alert_mapping', video_file: f.path } : { media_type: 'sound_alert_mapping', sound_file: f.path }
  async function addWalkon(ev: React.FormEvent) {
    ev.preventDefault()
    const login = user.trim().replace(/^@/, '').toLowerCase()
    if (!login) return
    setBusy(true); setMsg(`Looking up @${login}`)
    try {
      const r = await get<{ success: boolean; user_id?: string; user_name?: string; error?: string }>(`${API}?action=helix_lookup_user&login=${encodeURIComponent(login)}`)
      if (!r.success || !r.user_id) { setMsg(r.error || 'Lookup failed.'); setBusy(false); return }
      setUser('')
      await send({ media_type: 'walkon_mapping', action: 'add', media_file: f.path, twitch_user_id: r.user_id, twitch_user_name: r.user_name ?? login, mode: isVideo ? 'video' : mode })
    } catch { setMsg('Lookup failed.'); setBusy(false) }
  }
  const head = f.type === 'video' ? 'MP4 video' : f.type === 'audio' ? 'MP3 audio' : f.type === 'image' ? `${ext(f.path).toUpperCase()} image` : 'File'
  return (
    <Modal title={base(f.path)} icon={icon(f.type)} onClose={onClose} wide>
      <div className="fm-top">
        <span className="pill">{head}</span><span className="pill">{fmtBytes(f.size)}</span>
        <span className="fm-acts">
          {f.preview && <button type="button" className="btn btn-ghost sm" onClick={onPreview}><i className="fas fa-eye" /> Preview</button>}
          {(f.type === 'audio' || f.type === 'video') && <button type="button" className="btn btn-quiet sm" onClick={onTest}><i className="fas fa-play" /> Test on overlay</button>}
        </span>
      </div>
      {msg && <p className="muted small" role="status">{msg}</p>}
      {(f.type === 'audio' || f.type === 'video') && (
        <>
          <Section title={isVideo ? 'Channel point rewards (video)' : 'Channel point rewards'}>
            <Chips items={rewards.map((id) => ({ key: id, label: d.reward_titles[id] ?? '(unknown reward)', remove: () => send({ ...rewardField, action: 'remove', reward_id: id }) }))} busy={busy} />
            {rewardOpts.length > 0 ? <Combobox key={`r${rewards.length}`} label="Add channel point reward" placeholder="Search rewards" options={rewardOpts} value="" onChange={(v) => v && send({ ...rewardField, action: 'add', reward_id: v })} />
              : <p className="muted small">{d.rewards.length ? 'Every reward already has a file.' : 'No channel point rewards found. Sync them from Twitch on the old dashboard first.'}</p>}
          </Section>
          {!isVideo && (
            <Section title="Twitch events">
              <Chips items={events.map((e) => ({ key: e, label: e, remove: () => send({ media_type: 'twitch_event_mapping', action: 'remove', sound_file: f.path, twitch_alert_id: e }) }))} busy={busy} />
              {eventOpts.length > 0 && <Combobox key={`e${events.length}`} label="Add Twitch event" options={eventOpts} value="" searchable={false} onChange={(v) => v && send({ media_type: 'twitch_event_mapping', action: 'add', sound_file: f.path, twitch_alert_id: v })} />}
            </Section>
          )}
          <Section title="Walk-ons">
            <Chips items={walkons.map((w) => ({ key: w.user_id, label: `@${w.user_name}${w.mode === 'sound_overlay' ? ' · picture and name' : w.mode === 'video' ? ' · video' : ''}`, remove: () => send({ media_type: 'walkon_mapping', action: 'remove', media_file: f.path, twitch_user_id: w.user_id }) }))} busy={busy} />
            <form className="walkon-add" onSubmit={addWalkon}>
              <div className="walkon-user"><TwitchUserPicker label="Twitch username" value={user} onChange={setUser} /></div>
              {!isVideo && <Combobox label="Walk-on mode" searchable={false} value={mode} onChange={setMode} options={[{ value: 'sound', label: 'Sound only' }, { value: 'sound_overlay', label: 'Sound plus picture and name' }]} />}
              <button className="btn" disabled={busy || !user.trim()}><i className="fas fa-user-plus" /> Add user</button>
            </form>
          </Section>
        </>
      )}
      {(f.type === 'image' || alerts.length > 0) && (
        <Section title="Used by Alert Builder">
          {alerts.length ? <Chips items={alerts.map((a) => ({ key: `${a.id}${a.type}`, label: `${a.category} · ${a.variant} (${a.type})` }))} busy={busy} />
            : <p className="muted small">Not attached to any alert variant yet. Open <a href={`${OLD}/alerts.php`}>Specter Alerts</a> and use Browse library to assign it.</p>}
        </Section>
      )}
    </Modal>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="fm-sec"><h4>{title}</h4>{children}</section>
}
function Chips({ items, busy }: { items: { key: string; label: string; remove?: () => void }[]; busy: boolean }) {
  if (!items.length) return <p className="muted small">No links yet.</p>
  return (
    <ul className="chips">
      {items.map((c) => <li key={c.key} className="chip"><span>{c.label}</span>{c.remove && <button type="button" className="chip-x" disabled={busy} aria-label={`Remove ${c.label}`} onClick={c.remove}><i className="fa-solid fa-xmark" /></button>}</li>)}
    </ul>
  )
}
