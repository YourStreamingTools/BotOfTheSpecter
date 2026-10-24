import { useEffect, useMemo, useRef, useState } from 'react'
import Modal from '../components/Modal'
import Combobox from '../components/Combobox'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/music.php via public/api/music/ (scripts/sync-live-api.sh).
const API = '/api/music/music.php'
type File = { filename: string; title: string; artist: string; size: number }
type Source = 'system' | 'user' | 'both'
type List = { success: boolean; storage_used: number; max_storage: number; storage_percentage: number; music_source: Source; playlist_filter: string[]; user_files: File[]; system_files: File[] }
type State = { success: boolean; connected: boolean; overlay?: boolean; settings?: { volume: number | null; repeat: boolean; shuffle: boolean }; now_playing?: { title: string; artist: string; file: string } | null }
type Track = { key: string; file: string; title: string; artist: string; user: boolean }
const SOURCES = [{ value: 'system', label: 'Built-in (DMCA-free)' }, { value: 'user', label: 'My uploads' }, { value: 'both', label: 'Built-in and my uploads' }]
const clean = (s: string) => s.replace(/^USER:/, '').replace(/\.mp3$/i, '').replace(/_/g, ' ')
const mb = (b: number) => `${(b / 1048576).toFixed(b >= 104857600 ? 0 : 1)} MB`
const visible = () => document.visibilityState === 'visible'
const pl = (n: number, w: string) => `${n} ${w}${n === 1 ? '' : 's'}`
function useCommit(cb: (v: number) => void) {
  const ref = useRef<HTMLInputElement>(null), f = useRef(cb); f.current = cb
  useEffect(() => { const el = ref.current; if (!el) return; const h = () => f.current(Number(el.value)); el.addEventListener('change', h); return () => el.removeEventListener('change', h) })
  return ref
}

export default function Music() {
  const [list, setList] = useState<List | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [st, setSt] = useState<State | null>(null)
  const [excl, setExcl] = useState<Set<string>>(new Set())
  const [local, setLocal] = useState(false)
  const [playing, setPlaying] = useState(false)
  const [cur, setCur] = useState<string | null>(null)
  const [np, setNp] = useState<{ title: string; artist: string } | null>(null)
  const [vol, setVol] = useState(10)
  const [rep, setRep] = useState(false), [shf, setShf] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const audio = useRef<HTMLAudioElement>(null)
  const saveT = useRef(0)

  const load = async () => {
    try { const r = await get<List>(API); if (!r.success) throw new Error(); setList(r); setExcl(new Set(r.playlist_filter)); setErr('') }
    catch { setErr('Your music could not load. Refresh to try again.') }
  }
  const refresh = async (spin = false) => {
    if (spin) setBusy('refresh')
    try {
      const r = await get<State>(`${API}?beta=state`); setSt(r)
      if (r.settings) { if (r.settings.volume !== null && !local) setVol(r.settings.volume); setRep(r.settings.repeat); setShf(r.settings.shuffle) }
      if (!local && r.connected) { setNp(r.now_playing ? { title: clean(r.now_playing.title), artist: r.now_playing.artist } : null); setCur(r.now_playing?.file ?? null); setPlaying(!!r.now_playing) }
    } catch { setSt({ success: false, connected: false }) }
    if (spin) setBusy(null)
  }
  useEffect(() => { load(); refresh() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  // The live page keeps a socket open; beta asks the same questions every 15s while the tab is visible.
  useEffect(() => { if (local) return; const t = setInterval(() => { if (visible()) refresh() }, 15000); return () => clearInterval(t) }, [local]) // eslint-disable-line react-hooks/exhaustive-deps

  const source: Source = list?.music_source ?? 'system'
  const tracks = useMemo<Track[]>(() => !list ? [] : [
    ...list.user_files.map((f) => ({ key: 'USER:' + f.filename, file: f.filename, title: clean(f.title || f.filename), artist: f.artist || '', user: true })),
    ...list.system_files.map((f) => ({ key: f.filename, file: f.filename, title: clean(f.title || f.filename), artist: f.artist || '', user: false })),
  ], [list])
  const inSource = useMemo(() => tracks.filter((t) => source === 'both' || (source === 'user') === t.user), [tracks, source])
  const rotation = inSource.filter((t) => !excl.has(t.key))

  async function post(body: Record<string, string>) {
    const r = await send<{ success: boolean; message?: string; live?: boolean }>(API, body)
    if (!r.success) throw new Error(r.message || 'That did not save. Please try again.')
    return r
  }
  const loopFields = (r = rep, s = shf, v = vol) => ({ repeat: r ? '1' : '0', shuffle: s ? '1' : '0', volume: String(v) })
  async function cmd(command: string, extra: Record<string, string> = {}, key = command) {
    setBusy(key)
    try { await post({ action: 'command', command, ...extra }); return true }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }); return false }
    finally { setBusy(null) }
  }

  // Playback: on this page through the audio element, otherwise the overlay through the websocket (same as live).
  const pick = (from: string | null, dir: 1 | -1, shuffle = shf) => {
    const en = rotation; if (!en.length) return null
    const i = en.findIndex((t) => t.key === from)
    if (i < 0) return dir === 1 ? en[0] : en[en.length - 1]
    if (shuffle && dir === 1 && en.length > 1) { let n; do { n = en[Math.floor(Math.random() * en.length)] } while (n.key === from); return n }
    return en[(i + dir + en.length) % en.length]
  }
  function playLocal(t: Track) {
    const a = audio.current; if (!a) return
    a.src = t.user ? `/api/music/serve.php?file=${encodeURIComponent(t.file)}` : `https://cdn.botofthespecter.com/music/${encodeURIComponent(t.file)}`
    a.volume = vol / 100; a.play().catch(() => setToast({ kind: 'err', text: 'This song could not be played here.' }))
    setCur(t.key); setNp({ title: t.title, artist: t.artist }); setPlaying(true)
    // The live page also announces local songs to the overlay.
    post({ action: 'command', command: 'play_song', file: t.key }).catch(() => {})
  }
  async function playSong(t: Track) {
    if (local) return playLocal(t)
    if (await cmd('play_song', { file: t.key }, 'p' + t.key)) { setCur(t.file); setNp({ title: t.title, artist: t.artist }); setPlaying(true) }
  }
  async function toggle() {
    if (local) {
      const a = audio.current; if (!a) return
      if (!a.src || cur === null) { const f = pick(null, 1, false); if (f) playLocal(f) } else if (a.paused) { a.play(); setPlaying(true) } else { a.pause(); setPlaying(false) }
      return
    }
    if (await cmd(playing ? 'pause' : 'play', {}, 'pp')) setPlaying(!playing)
  }
  async function skip(dir: 1 | -1) {
    if (local) { const n = pick(cur, dir); if (n) playLocal(n); return }
    if (await cmd(dir === 1 ? 'next' : 'prev', {}, dir === 1 ? 'next' : 'prev')) setTimeout(() => refresh(), 1200)
  }
  async function setLoop(r: boolean, s: boolean) {
    const pr = rep, ps = shf; setRep(r); setShf(s)
    if (!(await cmd('loop', loopFields(r, s), r !== pr ? 'rep' : 'shf'))) { setRep(pr); setShf(ps) }
  }
  const volRef = useCommit((v) => { if (local) { if (audio.current) audio.current.volume = v / 100 } else cmd('volume', { volume: String(v) }, 'vol') })
  function ended() {
    if (rep && cur) { const t = tracks.find((x) => x.key === cur); if (t) return playLocal(t) }
    const en = rotation, i = en.findIndex((t) => t.key === cur)
    if (!shf && i === en.length - 1) { setPlaying(false); setCur(null); setNp(null); return }
    const n = pick(cur, 1); if (n) playLocal(n)
  }
  function setLocalMode(v: boolean) {
    setLocal(v)
    if (!v) { audio.current?.pause(); setPlaying(false); refresh() } else { setPlaying(false); setCur(null); setNp(null) }
  }
  // Same shortcuts as live: Space play or pause, arrows previous and next, R repeat, S shuffle.
  const keys = useRef<(e: KeyboardEvent) => void>(() => {})
  keys.current = (e) => {
    const t = e.target as HTMLElement
    if (t.closest('input, textarea, select, [role=dialog], [contenteditable=true], button, [role=combobox]') || e.ctrlKey || e.metaKey || e.altKey) return
    if (e.code === 'Space') { e.preventDefault(); toggle() } else if (e.code === 'ArrowLeft') { e.preventDefault(); skip(-1) } else if (e.code === 'ArrowRight') { e.preventDefault(); skip(1) } else if (e.code === 'KeyR') { e.preventDefault(); setLoop(!rep, shf) } else if (e.code === 'KeyS') { e.preventDefault(); setLoop(rep, !shf) }
  }
  useEffect(() => { const h = (e: KeyboardEvent) => keys.current(e); document.addEventListener('keydown', h); return () => document.removeEventListener('keydown', h) }, [])

  function setFilter(next: Set<string>) {
    setExcl(next); clearTimeout(saveT.current)
    saveT.current = window.setTimeout(() => { post({ action: 'save_filter', music_playlist_filter: JSON.stringify([...next]), ...loopFields() }).catch((e) => setToast({ kind: 'err', text: (e as Error).message })) }, 400)
  }
  async function saveSource(v: Source) {
    if (!list || v === source) return
    const prev = list.music_source; setList({ ...list, music_source: v })
    try { await post({ action: 'save_source', music_source: v, ...loopFields() }); setToast({ kind: 'ok', text: 'Music source saved.' }) }
    catch (e) { setList({ ...list, music_source: prev }); setToast({ kind: 'err', text: (e as Error).message }) }
  }

  const status = local ? { cls: 'pill-info', txt: 'Playing on this page' } : !st ? { cls: 'pill-off', txt: 'Checking' } : !st.connected ? { cls: 'pill-err', txt: 'Music service offline' } : !st.overlay ? { cls: 'pill-warn', txt: 'Overlay not open' } : np ? { cls: 'pill-ok', txt: 'Overlay playing' } : { cls: 'pill-off', txt: 'Overlay idle' }
  const curKey = local ? cur : (cur ? tracks.find((t) => t.file === cur)?.key ?? null : null)
  return (
    <div className="page cmds mp mu">
      <section className="card mu-player" aria-label="Player">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-music" /> VOD Music <span className={`pill ${status.cls}`}>{status.txt}</span></h3></div>
        <div className="mu-now">
          <div className="mu-now-t">
            <span className="mu-lbl">Now playing</span>
            {np ? <b className="mu-song" title={np.artist ? `${np.title} - ${np.artist}` : np.title}>{np.title}{np.artist && <span className="muted"> {np.artist}</span>}</b> : <b className="mu-song muted">Nothing playing</b>}
          </div>
          {!local && <button type="button" className="btn btn-ghost mu-ic" aria-label="Refresh now playing" disabled={busy === 'refresh'} onClick={() => refresh(true)}><i className={`fas fa-rotate${busy === 'refresh' ? ' fa-spin' : ''}`} /></button>}
        </div>
        <div className="mu-ctl">
          <div className="mu-trans">
            <button type="button" className="btn btn-ghost mu-ic" aria-label="Previous" disabled={busy === 'prev' || !rotation.length} onClick={() => skip(-1)}><i className="fas fa-backward-step" /></button>
            <button type="button" className="btn mu-pp" aria-label={playing ? 'Pause' : 'Play'} disabled={busy === 'pp' || !rotation.length} onClick={toggle}><i className={busy === 'pp' ? 'fas fa-spinner fa-spin' : playing ? 'fas fa-pause' : 'fas fa-play'} /></button>
            <button type="button" className="btn btn-ghost mu-ic" aria-label="Next" disabled={busy === 'next' || !rotation.length} onClick={() => skip(1)}><i className="fas fa-forward-step" /></button>
          </div>
          <div className="mu-modes">
            <button type="button" className={`mu-mode${rep ? ' on' : ''}`} aria-pressed={rep} disabled={busy === 'rep'} onClick={() => setLoop(!rep, shf)}><i className="fas fa-repeat" /> Repeat one</button>
            <button type="button" className={`mu-mode${shf ? ' on' : ''}`} aria-pressed={shf} disabled={busy === 'shf'} onClick={() => setLoop(rep, !shf)}><i className="fas fa-shuffle" /> Shuffle</button>
          </div>
        </div>
        <div className="mp-rows">
          <div className="mp-row"><div className="mp-row-l"><label htmlFor="mu-vol">Volume</label><span className="muted">{local ? 'Volume on this page.' : 'Overlay volume, sent when you let go.'}</span></div><div className="mp-row-r mp-volr"><input id="mu-vol" type="range" min={0} max={100} value={vol} ref={volRef} onChange={(e) => setVol(Number(e.target.value))} /><b className="mp-volv">{vol}%</b></div></div>
          <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Play on this page</span><span className="muted">Listen here instead of controlling the overlay.</span></div><div className="mp-row-r"><Switch on={local} label="Play on this page" onChange={setLocalMode} /></div></div>
        </div>
        <p className="mu-keys muted">Shortcuts: <kbd>Space</kbd> play or pause, <kbd>←</kbd> <kbd>→</kbd> previous and next, <kbd>R</kbd> repeat, <kbd>S</kbd> shuffle.</p>
        <audio ref={audio} onEnded={ended} onError={() => { if (local && cur) { setPlaying(false); setToast({ kind: 'err', text: 'This song could not be loaded.' }) } }} />
      </section>
      {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
      {list && <LibraryCard list={list} onSource={saveSource} post={post} toast={setToast} reload={load} />}
      {list && <PlaylistCard list={list} tracks={tracks} inSource={inSource} excl={excl} setFilter={setFilter} curKey={curKey} playing={playing} busy={busy} play={playSong} post={post} toast={setToast} reload={load} />}
      {!list && !err && <section className="card"><div className="cmd-list">{[0, 1, 2, 3].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div></section>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

function LibraryCard({ list, onSource, post, toast, reload }: { list: List; onSource: (v: Source) => void; post: (b: Record<string, string>) => Promise<unknown>; toast: (t: Toast) => void; reload: () => Promise<void> }) {
  const [pct, setPct] = useState<number | null>(null)
  const [drag, setDrag] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  void post
  const used = list.storage_used, max = list.max_storage, p = Math.max(0, Math.min(100, list.storage_percentage))
  const level = p >= 90 ? 'err' : p >= 70 ? 'warn' : 'ok'
  function upload(fl: FileList | null) {
    if (!fl || !fl.length || pct !== null) return
    const all = [...fl], ok = all.filter((f) => /\.mp3$/i.test(f.name)), skipped = all.length - ok.length
    if (!ok.length) { toast({ kind: 'err', text: 'Only MP3 files can be uploaded.' }); return }
    if (ok.length > 20) { toast({ kind: 'err', text: 'Upload up to 20 files at a time.' }); return }
    const size = ok.reduce((s, f) => s + f.size, 0)
    if (used + size > max) { toast({ kind: 'err', text: `Not enough space. These files need ${mb(size)} and ${mb(Math.max(0, max - used))} is free.` }); return }
    const fd = new FormData(); fd.append('action', 'upload'); ok.forEach((f) => fd.append('userMusicFiles[]', f, f.name))
    const x = new XMLHttpRequest(); x.open('POST', API)
    x.upload.onprogress = (e) => { if (e.lengthComputable) setPct(Math.round((e.loaded / e.total) * 100)) }
    x.onload = () => {
      setPct(null); if (input.current) input.current.value = ''
      try {
        const j = JSON.parse(x.responseText) as { success: boolean; added?: number; tried?: number; messages?: string[]; message?: string }
        const fails = (j.messages ?? []).filter((m) => !/^Uploaded:/i.test(m))
        const text = j.message ?? (j.success ? `Uploaded ${pl(j.added ?? ok.length, 'song')}.` : [j.added ? `Uploaded ${j.added} of ${j.tried}.` : '', ...fails].filter(Boolean).join(' ') || 'Upload failed.')
        toast({ kind: j.success ? 'ok' : 'err', text: text + (skipped ? ` Skipped ${skipped} that ${skipped === 1 ? 'is not an MP3' : 'are not MP3s'}.` : '') })
      } catch { toast({ kind: 'err', text: x.status === 413 ? 'Upload failed. The files are too large.' : x.status === 401 ? 'Your session expired. Sign in again.' : 'Upload failed. Try again.' }) }
      reload()
    }
    x.onerror = () => { setPct(null); toast({ kind: 'err', text: 'Upload failed. Check your connection and try again.' }) }
    setPct(0); x.send(fd)
  }
  return (
    <section className={`card mu-lib${drag ? ' is-drag' : ''}`} aria-label="Music source and uploads"
      onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) setDrag(false) }} onDrop={(e) => { e.preventDefault(); setDrag(false); upload(e.dataTransfer.files) }}>
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Source and uploads</h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Music source</span><span className="muted">What the overlay plays from. Saved right away.</span></div><div className="mp-row-r mp-sel"><Combobox label="Music source" searchable={false} value={list.music_source} onChange={(v) => onSource(v as Source)} options={SOURCES} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Storage</span><span className="muted">Shared with your sound, video and walk-on uploads.</span></div>
          <div className="mp-row-r mu-store"><span className={`mu-bar mu-bar-${level}`} role="progressbar" aria-label="Storage used" aria-valuenow={Math.round(p)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${p}%` }} /></span><span className="mp-hint muted">{mb(used)} of {mb(max)}</span></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Upload MP3s</span><span className="muted">Choose or drop up to 20 files. You must have the rights to every file you upload.</span></div>
          <div className="mp-row-r">{pct !== null ? <><span className="mu-bar mu-bar-ok mu-upbar"><span style={{ width: `${pct}%` }} /></span><span className="mp-hint muted">{pct}%</span></> : <span className="mp-hint muted mu-drophint">{drag ? 'Drop to upload' : ''}</span>}
            <input ref={input} id="mu-files" type="file" accept=".mp3,audio/mpeg" multiple hidden onChange={(e) => upload(e.target.files)} />
            <button type="button" className="btn" disabled={pct !== null} onClick={() => input.current?.click()}><i className={pct !== null ? 'fas fa-spinner fa-spin' : 'fas fa-upload'} /> {pct !== null ? 'Uploading' : 'Choose files'}</button></div></div>
      </div>
    </section>
  )
}

function PlaylistCard({ list, tracks, inSource, excl, setFilter, curKey, playing, busy, play, post, toast, reload }: { list: List; tracks: Track[]; inSource: Track[]; excl: Set<string>; setFilter: (s: Set<string>) => void; curKey: string | null; playing: boolean; busy: string | null; play: (t: Track) => void; post: (b: Record<string, string>) => Promise<unknown>; toast: (t: Toast) => void; reload: () => Promise<void> }) {
  const source = list.music_source
  const [tab, setTab] = useState<'system' | 'user'>(source === 'user' ? 'user' : 'system')
  useEffect(() => { if (source !== 'both') setTab(source) }, [source])
  const [fq, setFq] = useState(''), [fs, setFs] = useState<'all' | 'on' | 'off'>('all')
  const [ren, setRen] = useState<Track | null>(null), [rv, setRv] = useState(''), [rbusy, setRbusy] = useState(false), [rerr, setRerr] = useState('')
  const [del, setDel] = useState<Track | null>(null), [dbusy, setDbusy] = useState(false)
  const pool = source === 'both' ? tracks.filter((t) => (tab === 'user') === t.user) : inSource
  const needle = fq.trim().toLowerCase()
  const nOn = pool.filter((t) => !excl.has(t.key)).length
  const rows = pool.filter((t) => (fs === 'all' || (fs === 'on') === !excl.has(t.key)) && (!needle || t.title.toLowerCase().includes(needle) || t.artist.toLowerCase().includes(needle)))
  const nUser = tracks.filter((t) => t.user).length, nSys = tracks.length - nUser
  const total = inSource.filter((t) => !excl.has(t.key)).length
  const setAll = (on: boolean) => { const n = new Set(excl); inSource.forEach((t) => (on ? n.delete(t.key) : n.add(t.key))); setFilter(n) }
  async function doRename() {
    if (!ren) return
    const v = rv.trim(); if (!v) { setRerr('Enter a name.'); return }
    if (v === ren.file.replace(/\.mp3$/i, '')) { setRen(null); return }
    setRbusy(true); setRerr('')
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'rename', file: ren.file, new_name: v })
      if (!r.success) throw new Error(r.message || 'Rename failed.')
      setRen(null); toast({ kind: 'ok', text: r.message || 'Renamed.' }); await reload()
    } catch (e) { setRerr((e as Error).message) }
    setRbusy(false)
  }
  async function doDelete() {
    if (!del) return
    const d = del
    setDel(null)
    setDbusy(true)
    try { await post({ action: 'delete', file: d.file }); toast({ kind: 'ok', text: `${d.title} deleted.` }); await reload() }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setDbusy(false)
  }
  return (
    <section className="card mp-lists mu-pl" aria-label="Playlist">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list" /> Playlist <span className={`pill ${total ? 'pill-ok' : 'pill-off'}`}>{total} in rotation</span></h3>
        {inSource.length > 0 && <div className="mu-bulk"><button type="button" className="btn btn-ghost" disabled={total === inSource.length} onClick={() => setAll(true)}>Include all</button><button type="button" className="btn btn-ghost" disabled={!total} onClick={() => setAll(false)}>Exclude all</button></div>}</div>
      {source === 'both' && <div className="mp-ltabs" role="tablist" aria-label="Library">
        <button type="button" role="tab" aria-selected={tab === 'system'} className={tab === 'system' ? 'on' : ''} onClick={() => setTab('system')}>Built-in<span className="mp-cnt">{nSys}</span></button>
        <button type="button" role="tab" aria-selected={tab === 'user'} className={tab === 'user' ? 'on' : ''} onClick={() => setTab('user')}>My uploads<span className="mp-cnt">{nUser}</span></button>
      </div>}
      {pool.length > 0 && <p className="mp-lhelp muted">Switch a song off to keep it out of rotation. Select a title to play it.</p>}
      {pool.length > 0 && <div className="mp-lf">
        <div className="mp-fseg" role="group" aria-label="Rotation filter">{([['all', 'All', pool.length], ['on', 'In rotation', nOn], ['off', 'Excluded', pool.length - nOn]] as const).map(([v, l, c]) => <button key={v} type="button" aria-pressed={fs === v} className={fs === v ? 'on' : ''} onClick={() => setFs(v)}>{l}<span className="mp-cnt">{c}</span></button>)}</div>
        <label className="mp-fsearch"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input type="search" placeholder="Search songs" aria-label="Search songs" value={fq} onChange={(e) => setFq(e.target.value)} /></label>
      </div>}
      {!pool.length ? <div className="mp-lempty"><span className="muted">{(source === 'both' ? tab : source) === 'user' ? 'No uploads yet. Choose files above to add your own songs.' : 'The built-in library is empty right now.'}</span></div>
        : !rows.length ? <div className="mp-lempty"><span className="muted">No songs match these filters.</span><button type="button" className="btn btn-quiet" onClick={() => { setFq(''); setFs('all') }}>Clear filters</button></div>
        : <ul className="mp-llist mu-list">{rows.map((t) => {
          const on = !excl.has(t.key), now = curKey === t.key
          return (
            <li key={t.key} className={`mp-lrow mu-row${now ? ' is-now' : ''}${on ? '' : ' is-off'}`}>
              <button type="button" className="mu-play" aria-label={`Play ${t.title}`} disabled={busy === 'p' + t.key} onClick={() => play(t)}>
                <i className={busy === 'p' + t.key ? 'fas fa-spinner fa-spin' : now && playing ? 'fas fa-volume-high' : 'fas fa-play'} aria-hidden="true" />
                <span className="mu-tt"><b>{t.title}</b>{t.artist && <span className="muted">{t.artist}</span>}</span>
              </button>
              <span className="mp-lr">
                <span className={`mp-hint ${now ? 'mp-ok' : 'muted'}`}>{now ? (playing ? 'Playing' : 'Paused') : on ? '' : 'Excluded'}</span>
                <Switch on={on} label={`Include ${t.title} in rotation`} onChange={(v) => { const n = new Set(excl); if (v) n.delete(t.key); else n.add(t.key); setFilter(n) }} />
                {t.user ? <>
                  <button type="button" className="btn btn-ghost mp-x" aria-label={`Rename ${t.title}`} onClick={() => { setRen(t); setRv(t.file.replace(/\.mp3$/i, '')); setRerr('') }}><i className="fas fa-pen" /></button>
                  <button type="button" className="btn btn-ghost mp-x" aria-label={`Delete ${t.title}`} onClick={() => setDel(t)}><i className="fas fa-trash" /></button>
                </> : null}
              </span>
            </li>
          )
        })}</ul>}
      {ren && <Modal title="Rename song" icon="fas fa-pen" onClose={() => setRen(null)}>
        <form onSubmit={(e) => { e.preventDefault(); doRename() }}>
          <label className="mp-lbl" htmlFor="mu-rn">New name</label>
          <input id="mu-rn" className={`input mu-rn${rerr ? ' is-bad' : ''}`} maxLength={200} value={rv} data-autofocus aria-invalid={!!rerr} aria-describedby="mu-rn-h" onChange={(e) => { setRv(e.target.value); setRerr('') }} />
          <p id="mu-rn-h" className={`small ${rerr ? 'mp-bad' : 'muted'}`}>{rerr || 'The file name is the playlist title. Example: Artist - Song Title'}</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setRen(null)}>Cancel</button><button type="submit" className="btn" disabled={rbusy || !rv.trim()}><i className={rbusy ? 'fas fa-spinner fa-spin' : 'fas fa-check'} /> Rename</button></div>
        </form>
      </Modal>}
      {del && <Modal title="Delete this song?" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p><b>{del.title}</b> is removed from your uploads and the overlay. This cannot be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Keep song</button><button type="button" className="btn btn-danger" disabled={dbusy} onClick={doDelete}><i className={dbusy ? 'fas fa-spinner fa-spin' : 'fas fa-trash'} /> Delete</button></div>
      </Modal>}
    </section>
  )
}
