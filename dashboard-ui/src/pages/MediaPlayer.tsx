import { UrlReveal } from '../components/UrlReveal'
import { useEffect, useMemo, useRef, useState } from 'react'
import Modal from '../components/Modal'
import Combobox from '../components/Combobox'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'
import SpotifyAccount from './SpotifyAccount'

// Port of dashboard/media_player.php via public/api/media-player/ (scripts/sync-live-api.sh).
const API = '/api/media-player/media-player.php'
const SPOTIFY = '/api/media-player/spotify.php'
type Track = { id: number; video_id: string; title: string; requested_by: string; duration_seconds: number }
type Queue = { now_playing: Track | null; queue: Track[] }
type Settings = { enabled: number; max_song_seconds: number; max_queue_length: number; per_viewer_limit: number; volume: number; artist_limit_count: number; artist_limit_period: 'stream' | 'week' | 'month'; artist_limit_scope: 'all' | 'listed' | 'except_listed'; same_song_minutes: number; per_viewer_stream_limit: number }
type Ban = { id: number; type: 'keyword' | 'video_id'; value: string }
type Artist = { id: number; artist_name: string; limit_count: number | null }
type Row = { song_name?: string; artist_name?: string; title?: string; uploader?: string | null; requested_by?: string; requested_at?: string; age_seconds?: number; request_count?: number }
type Stats = { total: number; unique: number; today: number; top: Row[]; recent: Row[] } | null
type Page = { settings: Settings; banlist: Ban[]; artists: Artist[]; queue: Queue; spotify: { connected: boolean; actAs: boolean }; analytics: { spotify: Stats; youtube: Stats } }
type Sp = { success: boolean; active?: boolean; is_playing?: boolean; progress_ms?: number; error?: string; device?: { name: string; supports_volume: boolean; volume_percent: number }; disallows?: Record<string, boolean>; track?: { name: string; artists: string; duration_ms: number; album_art: string; requested_by: string | null } | null; upcoming?: { name: string; artists: string; requested_by?: string | null }[] }
const SP_ERR: Record<string, string> = {
  no_device: 'No active Spotify device. Open Spotify on a device to control playback.',
  premium: 'Spotify Premium is required to control playback.',
  expired: 'Spotify session is refreshing. Try again in a moment.',
  rate_limited: 'Too many requests. Please slow down.',
  generic: 'Spotify request failed. Please try again.',
}
const SCOPES = [{ value: 'all', label: 'Every artist' }, { value: 'listed', label: 'Only listed artists' }, { value: 'except_listed', label: 'Every artist except listed' }]
const PERIODS = [{ value: 'stream', label: 'This stream' }, { value: 'week', label: 'This week' }, { value: 'month', label: 'This month' }]
const NUMS: [keyof Settings, number, number][] = [['max_song_seconds', 30, 86400], ['max_queue_length', 1, 500], ['per_viewer_limit', 0, 1000], ['per_viewer_stream_limit', 0, 1000], ['artist_limit_count', 0, 1000], ['same_song_minutes', 0, 525600]]

const fmtLen = (sec: number) => { const s = Math.max(0, Number(sec) || 0); if (!s) return ''; const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : m) + ':' + String(r).padStart(2, '0') }
const fmtMs = (ms: number) => { const s = Math.max(0, Math.floor(ms / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` }
const ago = (a = 0) => (a < 60 ? 'just now' : a < 3600 ? `${Math.floor(a / 60)} min ago` : a < 86400 ? `${Math.floor(a / 3600)} h ago` : `${Math.floor(a / 86400)} d ago`)
// 1200 -> "20 min", 43200 -> "12 hours", 5400 -> "1 h 30 min"
const dur = (sec: number) => { if (!sec) return ''; const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), r = sec % 60; if (h >= 48 && !m) return `${Math.round(h / 24 * 10) / 10} days`; return [h && (m || r ? `${h} h` : `${h} ${h === 1 ? 'hour' : 'hours'}`), m && `${m} min`, r && `${r} sec`].filter(Boolean).join(' ') }
type MTab = 'local' | 'spotify' | 'global'
const MTABS: [MTab, string, string][] = [['local', 'Local Player', 'fas fa-tv'], ['spotify', 'Spotify Player', 'fab fa-spotify'], ['global', 'Global Settings', 'fas fa-sliders']]
const readMTab = (): MTab => { const t = new URLSearchParams(location.search).get('tab'); return t === 'spotify' || t === 'global' ? t : 'local' }
const LOCAL_KEYS = ['enabled', 'max_song_seconds', 'max_queue_length']
const visible = () => document.visibilityState === 'visible'
// Native change fires once when a range is released (mouse, touch or keys), like the live page.
function useCommit(cb: (v: number) => void) {
  const ref = useRef<HTMLInputElement>(null), f = useRef(cb); f.current = cb
  useEffect(() => { const el = ref.current; if (!el) return; const h = () => f.current(Number(el.value)); el.addEventListener('change', h); return () => el.removeEventListener('change', h) })
  return ref
}

export default function MediaPlayer() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [q, setQ] = useState<Queue | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [askClear, setAskClear] = useState(false)
  const [tab, setTab] = useState<MTab>(readMTab)
  useEffect(() => { const f = () => setTab(readMTab()); window.addEventListener('popstate', f); return () => window.removeEventListener('popstate', f) }, [])
  function pick(t: MTab) { if (t === tab) return; history.pushState(null, '', t === 'local' ? '/media-player' : `/media-player?tab=${t}`); setTab(t) }
  const load = async () => {
    try { const r = await get<Page & { success: boolean }>(API); if (!r.success) throw new Error(); setPage(r); setQ(r.queue); setErr('') }
    catch { setErr('The media player settings could not load. Refresh to try again.') }
  }
  useEffect(() => { load() }, [])
  // The live page hears MEDIA_QUEUE_UPDATE over the websocket; beta reads the same rows every 5s.
  useEffect(() => {
    const t = setInterval(async () => { if (!visible()) return; try { const r = await get<{ success: boolean; queue: Queue }>(`${API}?beta=queue`); if (r.success) setQ(r.queue) } catch { /* keep the last queue */ } }, 5000)
    return () => clearInterval(t)
  }, [])
  async function post(body: Record<string, string>) {
    const r = await send<{ success: boolean; message?: string } & Partial<Page>>(API, body)
    if (!r.success) throw new Error(r.message || 'That did not save. Please try again.')
    return r
  }
  async function command(c: string, extra: Record<string, string> = {}, key = c) {
    setBusy(key)
    try { const r = await post({ action: 'media_command', command: c, ...extra }); if (r.queue) setQ(r.queue as Queue) }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    setBusy(null)
  }
  const n = q?.queue.length ?? 0
  return (
    <div className="page cmds mp">
      <div className="seg mp-tabs" role="tablist" aria-label="Media player sections">{MTABS.map(([t, l, ic]) => <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'on' : ''} onClick={() => pick(t)}><i className={ic} /> {l}</button>)}</div>
      {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
      {tab === 'local' && <>
        <section className="card mp-card" aria-label="Request queue" aria-busy={!q}>
          <div className="card-title-row">
            <h3 className="card-h"><i className="fas fa-list-ol" /> Request Queue {q && <span className={`pill ${n ? 'pill-ok' : 'pill-off'}`}>{n} queued</span>}</h3>
            <div className="mp-acts">
              <button type="button" className="btn btn-ghost" disabled={!q?.now_playing || busy === 'skip'} onClick={() => command('skip')}><i className={busy === 'skip' ? 'fas fa-spinner fa-spin' : 'fas fa-forward'} /> Skip</button>
              <button type="button" className="btn btn-ghost" disabled={!n || busy === 'clear'} onClick={() => setAskClear(true)}><i className="fas fa-trash" /> Clear</button>
            </div>
          </div>
          {!q ? <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div> : <>
            {q.now_playing && <div className="mp-track mp-playing"><span className="pill pill-ok"><i className="fas fa-play" /> Now playing</span><div className="mp-track-main"><b className="mp-title">{q.now_playing.title}</b>{q.now_playing.requested_by && <span className="mp-req"><i className="fas fa-user" /> {q.now_playing.requested_by}</span>}</div>{fmtLen(q.now_playing.duration_seconds) && <span className="mp-len">{fmtLen(q.now_playing.duration_seconds)}</span>}</div>}
            {n > 0 ? <ol className="mp-list">{q.queue.map((t, i) => (
              <li key={t.id} className="mp-track"><span className="mp-pos">{i + 1}</span><div className="mp-track-main"><b className="mp-title">{t.title}</b>{t.requested_by && <span className="mp-req"><i className="fas fa-user" /> {t.requested_by}</span>}</div>{fmtLen(t.duration_seconds) && <span className="mp-len">{fmtLen(t.duration_seconds)}</span>}
                <button type="button" className="btn btn-ghost mp-x" aria-label={`Remove ${t.title} from queue`} disabled={busy === 'r' + t.id} onClick={() => command('remove', { id: String(t.id) }, 'r' + t.id)}><i className={busy === 'r' + t.id ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /></button></li>
            ))}</ol> : <div className="mp-empty"><i className="fas fa-music" /><div><b>The queue is empty</b><p className="muted small">Viewers can add songs with <code>!songrequest</code></p></div></div>}
          </>}
        </section>
        {page && <SettingsCard part="local" s={page.settings} post={post} toast={setToast} onSaved={(s) => setPage({ ...page, settings: s })} command={command} />}
        {page?.analytics.youtube && <StatsCard kind="youtube" st={page.analytics.youtube} />}
      </>}
      {tab === 'spotify' && <>
        {page?.spotify.connected ? <SpotifyCard actAs={page.spotify.actAs} /> : page && <div className="notice notice-info"><i className="fas fa-circle-info" /> <span>Link your Spotify account below to control playback here.</span></div>}
        <SpotifyAccount toast={setToast} />
        {page?.analytics.spotify && <StatsCard kind="spotify" st={page.analytics.spotify} />}
      </>}
      {tab === 'global' && <>
        {page && <SettingsCard part="global" s={page.settings} post={post} toast={setToast} onSaved={(s) => setPage({ ...page, settings: s })} command={command} />}
        {page && <ListsCard page={page} setPage={setPage} post={post} toast={setToast} />}
      </>}
      {askClear && <Modal title="Clear the queue?" icon="fas fa-trash" onClose={() => setAskClear(false)}>
        <p>This removes all {n} queued {n === 1 ? 'song' : 'songs'}. The song playing now keeps playing.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setAskClear(false)}>Keep queue</button><button type="button" className="btn btn-danger" onClick={() => { setAskClear(false); command('clear') }}><i className="fas fa-trash" /> Clear queue</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

function SettingsCard({ part, s, post, toast, onSaved, command }: { part: 'local' | 'global'; s: Settings; post: (b: Record<string, string>) => Promise<unknown>; toast: (t: Toast) => void; onSaved: (s: Settings) => void; command: (c: string, extra?: Record<string, string>, key?: string) => Promise<void> }) {
  const [f, setF] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(s).map(([k, v]) => [k, String(v)])))
  const [vol, setVol] = useState(Number(s.volume))
  const [saving, setSaving] = useState(false)
  const volRef = useCommit((v) => command('volume', { value: String(v) }, 'vol'))
  const mine = (k: string) => k !== 'volume' && (part === 'local') === LOCAL_KEYS.includes(k)
  const dirty = Object.entries(s).some(([k, v]) => mine(k) && String(v) !== f[k])
  const bad = NUMS.filter(([k]) => mine(k)).find(([k, lo, hi]) => !/^\d+$/.test(f[k] ?? '') || Number(f[k]) < lo || Number(f[k]) > hi)
  const set = (k: string, v: string) => setF((o) => ({ ...o, [k]: v }))
  async function save() {
    setSaving(true)
    const mix = Object.fromEntries(Object.entries(s).map(([k, v]) => [k, mine(k) ? f[k] : String(v)]))
    try { await post({ action: 'save_settings', ...mix, volume: String(vol) }); onSaved({ ...s, ...Object.fromEntries(Object.entries(mix).filter(([k]) => k !== 'volume').map(([k, v]) => [k, k.startsWith('artist_limit_p') || k === 'artist_limit_scope' ? v : Number(v)])), volume: vol } as Settings); toast({ kind: 'ok', text: 'Song request settings saved.' }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  const num = (k: keyof Settings, label: string, unit: string, help: string, hint: (v: number) => string) => {
    const lim = NUMS.find(([n]) => n === k)!, raw = f[k] ?? '', v = Number(raw)
    const invalid = !/^\d+$/.test(raw) || v < lim[1] || v > lim[2]
    return (
      <div className="mp-row">
        <div className="mp-row-l"><label htmlFor={`mp-${k}`}>{label}</label><span id={`mp-${k}-h`} className={invalid ? 'mp-bad' : 'muted'}>{invalid ? `Enter ${lim[1]} to ${lim[2]}.` : help}</span></div>
        <div className="mp-row-r"><span className="mp-hint muted">{!invalid ? hint(v) : ''}</span><span className={`mp-numbox${invalid ? ' is-bad' : ''}`}><input id={`mp-${k}`} type="number" inputMode="numeric" min={lim[1]} max={lim[2]} aria-invalid={invalid} aria-describedby={`mp-${k}-h`} value={raw} onChange={(e) => set(k, e.target.value)} /><span className="mp-unit">{unit}</span></span></div>
      </div>
    )
  }
  const sel = (label: string, help: string, value: string, k: string, options: { value: string; label: string }[]) => (
    <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">{label}</span><span className="muted">{help}</span></div><div className="mp-row-r mp-sel"><Combobox label={label} searchable={false} value={value} onChange={(v) => set(k, v)} options={options} /></div></div>
  )
  const on = f.enabled === '1'
  return (
    <section className="card mp-settings" aria-label="Song request settings">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> {part === 'local' ? 'Local player settings' : 'Global song request settings'} {part === 'local' && <span className={`pill ${Number(s.enabled) ? 'pill-ok' : 'pill-off'}`}>{Number(s.enabled) ? 'On' : 'Off'}</span>}</h3></div>
      <div className="mp-rows">
        {part === 'local' ? <>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Enable song requests</span><span className="muted">Viewers use <code>!songrequest</code>; songs play through the overlay in OBS.</span></div><div className="mp-row-r"><Switch on={on} label="Enable song requests" onChange={(v) => set('enabled', v ? '1' : '0')} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="mp-vol">Overlay volume</label><span className="muted">Applies to the overlay as soon as you let go.</span></div><div className="mp-row-r mp-volr"><input id="mp-vol" type="range" min={0} max={100} value={vol} ref={volRef} onChange={(e) => setVol(Number(e.target.value))} /><b className="mp-volv">{vol}%</b></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">Add as an OBS browser source. It contains your channel key, so keep it out of screen shares.</span></div>
          <div className="mp-row-r mp-urlbox"><UrlReveal mask="https://overlay.botofthespecter.com/mediaplayer.php?code=••••••••••••" fail="Could not get the overlay URL." toast={toast}
            fetchUrl={async () => { const r = await get<{ success: boolean; url: string }>(`${API}?beta=source_url`); if (!r.success) throw new Error(); return r.url }} /></div></div>
        <h4 className="mp-h">Queue</h4>
        {num('max_song_seconds', 'Max song length', 'sec', 'Longer videos are refused.', dur)}
        {num('max_queue_length', 'Max queue length', 'songs', 'Requests are refused once the queue is full.', () => '')}
        </> : <>
        <h4 className="mp-h">Per viewer <span>0 means unlimited. Mods and the broadcaster are not limited.</span></h4>
        {num('per_viewer_limit', 'Songs in the queue at once', 'songs', "How many of one viewer's songs can wait at a time.", (v) => (v ? '' : 'Unlimited'))}
        {num('per_viewer_stream_limit', 'Songs per stream', 'songs', 'Total requests one viewer can make this stream.', (v) => (v ? '' : 'Unlimited'))}
        <p className="mp-tip muted">Mods can use <code>!srreset [@user]</code> to reset this stream's counts, or <code>!permitsong @user [count]</code> to allow extra requests.</p>
        <h4 className="mp-h">Repeats</h4>
        {num('same_song_minutes', 'Same song gap', 'min', 'Wait before the same song can be requested again. 0 turns this off.', (v) => (v ? dur(v * 60) : 'Off'))}
        <h4 className="mp-h">Artist limits</h4>
        {num('artist_limit_count', 'Global artist limit', 'songs', 'Cap per artist in the period below. 0 turns it off.', (v) => (v ? '' : 'Off'))}
        {sel('Limit applies to', 'Listed means the artist limit list below. Custom limits there always win.', f.artist_limit_scope, 'artist_limit_scope', SCOPES)}
        {sel('Period', 'When the artist counts reset.', f.artist_limit_period, 'artist_limit_period', PERIODS)}
        </>}
      </div>
      <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
        <span className={`muted small mp-save-msg${bad ? ' is-bad' : ''}`}>{bad ? <span className="mp-bad">Fix the highlighted field to save.</span> : part === 'local' ? 'Changes apply to the next song request.' : 'These apply to Spotify and local player requests.'}</span>
        {dirty && <span className="pill pill-warn">Unsaved changes</span>}
        <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => setF((o) => ({ ...o, ...Object.fromEntries(Object.entries(s).filter(([k]) => mine(k)).map(([k, v]) => [k, String(v)])) }))}><i className="fas fa-rotate-left" /> Discard</button>
        <button type="button" className="btn" disabled={!dirty || saving || !!bad} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Save'}</button>
      </div>
    </section>
  )
}

function ListsCard({ page, setPage, post, toast }: { page: Page; setPage: (p: Page) => void; post: (b: Record<string, string>) => Promise<Partial<Page> & { success: boolean }>; toast: (t: Toast) => void }) {
  const [tab, setTab] = useState<'artists' | 'bans'>('artists')
  const [busy, setBusy] = useState<string | null>(null)
  const [an, setAn] = useState(''), [al, setAl] = useState('')
  const [bt, setBt] = useState<'keyword' | 'video_id'>('keyword'), [bv, setBv] = useState('')
  const [fq, setFq] = useState(''), [fa, setFa] = useState<'all' | 'custom' | 'global'>('all'), [fb, setFb] = useState<'all' | 'keyword' | 'video_id'>('all')
  async function act(key: string, body: Record<string, string>, ok: string, after?: () => void) {
    setBusy(key)
    try { await post(body); const r = await get<Page & { success: boolean }>(API); if (r.success) setPage({ ...page, banlist: r.banlist, artists: r.artists }); after?.(); toast({ kind: 'ok', text: ok }) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
    setBusy(null)
  }
  const needle = fq.trim().toLowerCase()
  const artists = page.artists.filter((a) => (fa === 'all' || (fa === 'custom') === (a.limit_count !== null)) && (!needle || a.artist_name.toLowerCase().includes(needle)))
  const bans = page.banlist.filter((b) => (fb === 'all' || b.type === fb) && (!needle || b.value.toLowerCase().includes(needle)))
  const nCustom = page.artists.filter((a) => a.limit_count !== null).length, nKw = page.banlist.filter((b) => b.type === 'keyword').length
  const limitBad = al.trim() !== '' && (!/^\d+$/.test(al.trim()) || Number(al) > 1000)
  const filters = (aria: string, opts: readonly (readonly [string, string, number])[], cur: string, set: (v: never) => void, ph: string) => (
    <div className="mp-lf">
      <div className="mp-fseg" role="group" aria-label={aria}>{opts.map(([v, l, c]) => <button key={v} type="button" aria-pressed={cur === v} className={cur === v ? 'on' : ''} onClick={() => set(v as never)}>{l}<span className="mp-cnt">{c}</span></button>)}</div>
      <label className="mp-fsearch"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input type="search" placeholder={ph} aria-label={ph} value={fq} onChange={(e) => setFq(e.target.value)} /></label>
    </div>
  )
  const empty = (txt: string, clear: () => void) => <div className="mp-lempty"><span className="muted">{txt}</span><button type="button" className="btn btn-quiet" onClick={clear}>Clear filters</button></div>
  return (
    <section className="card mp-lists" aria-label="Artist limit list and ban list">
      <div className="mp-ltabs" role="tablist" aria-label="Lists">
        <button type="button" role="tab" aria-selected={tab === 'artists'} className={tab === 'artists' ? 'on' : ''} onClick={() => { setTab('artists'); setFq('') }}>Artist limits<span className="mp-cnt">{page.artists.length}</span></button>
        <button type="button" role="tab" aria-selected={tab === 'bans'} className={tab === 'bans' ? 'on' : ''} onClick={() => { setTab('bans'); setFq('') }}>Ban list<span className="mp-cnt">{page.banlist.length}</span></button>
      </div>
      {tab === 'artists' ? <div role="tabpanel" aria-label="Artist limit list">
        <p className="mp-lhelp muted">Blank uses the global limit, 0 means no limit for that artist.</p>
        <form className="mp-ladd" onSubmit={(e) => { e.preventDefault(); if (an.trim() && !limitBad) act('add-a', { action: 'add_artist_limit', artist_name: an.trim(), artist_limit_override: al.trim() }, `${an.trim()} added.`, () => { setAn(''); setAl('') }) }}>
          <input className="input mp-lname" maxLength={255} placeholder="Artist name" aria-label="Artist name" value={an} onChange={(e) => setAn(e.target.value)} />
          <span className={`mp-numbox${limitBad ? ' is-bad' : ''}`}><input type="number" inputMode="numeric" min={0} max={1000} placeholder="Global" aria-label="Custom limit" aria-invalid={limitBad} value={al} onChange={(e) => setAl(e.target.value)} /><span className="mp-unit">songs</span></span>
          <button type="submit" className="btn" disabled={!an.trim() || limitBad || busy === 'add-a'}><i className={busy === 'add-a' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add</button>
          {limitBad && <span className="mp-bad mp-ladd-err">Enter 0 to 1000, or leave blank.</span>}
        </form>
        {page.artists.length > 0 && filters('Limit type', [['all', 'All', page.artists.length], ['custom', 'Custom', nCustom], ['global', 'Global', page.artists.length - nCustom]], fa, setFa, 'Search artists')}
        {!page.artists.length ? <div className="mp-lempty"><span className="muted">No artists yet. Add one above to give them their own limit.</span></div> : !artists.length ? empty('No artists match these filters.', () => { setFq(''); setFa('all') })
          : <ul className="mp-llist">{artists.map((a) => <ArtistRow key={a.id} a={a} post={post} toast={toast} busy={busy === 'a' + a.id} onSaved={(v) => setPage({ ...page, artists: page.artists.map((x) => (x.id === a.id ? { ...x, limit_count: v } : x)) })} onRemove={() => act('a' + a.id, { action: 'del_artist_limit', id: String(a.id) }, `${a.artist_name} removed.`)} />)}</ul>}
      </div> : <div role="tabpanel" aria-label="Ban list">
        <p className="mp-lhelp muted">Requests whose title contains a keyword, or that match a video ID, are refused.</p>
        <form className="mp-ladd" onSubmit={(e) => { e.preventDefault(); if (bv.trim()) act('add-b', { action: 'add_ban', ban_type: bt, ban_value: bv.trim() }, 'Ban added.', () => setBv('')) }}>
          <div className="mp-ltype"><Combobox label="Ban type" searchable={false} value={bt} onChange={(v) => setBt(v as 'keyword' | 'video_id')} options={[{ value: 'keyword', label: 'Keyword' }, { value: 'video_id', label: 'Video ID' }]} /></div>
          <input className="input mp-lname" maxLength={255} aria-label={bt === 'keyword' ? 'Keyword to ban' : 'Video ID to ban'} placeholder={bt === 'video_id' ? 'Video ID, e.g. dQw4w9WgXcQ' : 'Keyword to ban'} value={bv} onChange={(e) => setBv(e.target.value)} />
          <button type="submit" className="btn" disabled={!bv.trim() || busy === 'add-b'}><i className={busy === 'add-b' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add</button>
        </form>
        {page.banlist.length > 0 && filters('Ban type filter', [['all', 'All', page.banlist.length], ['keyword', 'Keywords', nKw], ['video_id', 'Videos', page.banlist.length - nKw]], fb, setFb, 'Search bans')}
        {!page.banlist.length ? <div className="mp-lempty"><span className="muted">Nothing is banned yet.</span></div> : !bans.length ? empty('No bans match these filters.', () => { setFq(''); setFb('all') })
          : <ul className="mp-llist">{bans.map((b) => <li key={b.id} className="mp-lrow mp-ban"><span title={b.value} className={`mp-lv${b.type === 'video_id' ? ' mono' : ''}`}>{b.value}</span><span className="mp-lr"><span className="mp-hint muted">{b.type === 'video_id' ? 'Video ID' : 'Keyword'}</span>
            <button type="button" className="btn btn-ghost mp-x" aria-label={`Remove ban on ${b.value}`} disabled={busy === 'b' + b.id} onClick={() => act('b' + b.id, { action: 'del_ban', id: String(b.id) }, 'Ban removed.')}><i className={busy === 'b' + b.id ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /></button></span></li>)}</ul>}
      </div>}
    </section>
  )
}

// Same autosave as live: 700ms after typing, on blur or Enter.
function ArtistRow({ a, post, toast, busy, onSaved, onRemove }: { a: Artist; post: (b: Record<string, string>) => Promise<unknown>; toast: (t: Toast) => void; busy: boolean; onSaved: (v: number | null) => void; onRemove: () => void }) {
  const [v, setV] = useState(a.limit_count === null ? '' : String(a.limit_count))
  const [tick, setTick] = useState(false)
  const last = useRef(v), timer = useRef(0), cur = useRef(v)
  const isBad = (x: string) => x.trim() !== '' && (!/^\d+$/.test(x.trim()) || Number(x) > 1000)
  const bad = isBad(v)
  async function save() {
    clearTimeout(timer.current)
    const x = cur.current
    if (x === last.current || isBad(x)) return
    try { await post({ action: 'save_artist_limit', id: String(a.id), artist_limit_override: x.trim() }); last.current = x; onSaved(x.trim() === '' ? null : Number(x)); setTick(true); setTimeout(() => setTick(false), 1500) }
    catch (e) { toast({ kind: 'err', text: (e as Error).message }) }
  }
  const n = v.trim() === '' ? null : Number(v)
  const hint = bad ? 'Enter 0 to 1000' : tick ? 'Saved' : n === null ? 'Uses global' : n === 0 ? 'No limit' : 'Custom'
  return (
    <li className="mp-lrow mp-artist">
      <span className="mp-lv" title={a.artist_name}>{a.artist_name}</span>
      <span className="mp-lr">
        <span className={`mp-hint ${bad ? 'mp-bad' : tick ? 'mp-ok' : 'muted'}`} aria-live="polite">{tick && <i className="fas fa-check" />} {hint}</span>
        <span className={`mp-numbox${bad ? ' is-bad' : ''}`}><input type="number" inputMode="numeric" min={0} max={1000} placeholder="Global" aria-label={`Custom limit for ${a.artist_name}`} aria-invalid={bad} value={v}
          onChange={(e) => { setV(e.target.value); cur.current = e.target.value; clearTimeout(timer.current); timer.current = window.setTimeout(save, 700) }}
          onBlur={save} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save() } }} /><span className="mp-unit">songs</span></span>
        <button type="button" className="btn btn-ghost mp-x" aria-label={`Remove ${a.artist_name}`} disabled={busy} onClick={onRemove}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-xmark'} /></button>
      </span>
    </li>
  )
}

function SpotifyCard({ actAs }: { actAs: boolean }) {
  const [st, setSt] = useState<Sp | null>(null)
  const [msg, setMsg] = useState('')
  const [prog, setProg] = useState(0)
  const [vol, setVol] = useState<number | null>(null)
  const holdVol = useRef(0)
  const spVolRef = useCommit((v) => { holdVol.current = Date.now() + 3000; call('volume', { value: String(v) }) })
  const poll = async () => {
    try { const r = await get<Sp>(`${SPOTIFY}?action=state`); setSt(r); setProg(r.progress_ms || 0); if (Date.now() > holdVol.current && r.device) setVol(r.device.volume_percent); setMsg(!r.success || !r.active || !r.track ? SP_ERR.no_device : '') }
    catch { setSt((s) => s || { success: false }); setMsg((m) => m || SP_ERR.generic) }
  }
  useEffect(() => {
    if (actAs) return
    poll()
    const p = setInterval(() => { if (visible()) poll() }, 5000)
    const onVis = () => { if (visible()) poll() }
    document.addEventListener('visibilitychange', onVis)
    return () => { clearInterval(p); document.removeEventListener('visibilitychange', onVis) }
  }, [actAs]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!st?.is_playing || !st.track?.duration_ms) return
    const t = setInterval(() => { if (visible()) setProg((p) => Math.min(st.track!.duration_ms, p + 1000)) }, 1000)
    return () => clearInterval(t)
  }, [st])
  async function call(action: string, extra: Record<string, string> = {}, wait = 400) {
    try { const r = await send<{ success: boolean; error?: string }>(SPOTIFY, { action, ...extra }); if (!r.success) setMsg(SP_ERR[r.error || 'generic'] || SP_ERR.generic) }
    catch { setMsg(SP_ERR.generic) }
    if (action !== 'volume') setTimeout(poll, wait)
  }
  const t = st?.active ? st.track : null, dis = st?.disallows || {}, playing = !!st?.is_playing
  // Spotify's queue can start with the track that is already playing; skip those.
  const same = (u: { name: string; artists: string }) => !!t && u.name === t.name && u.artists === t.artists
  const up = (st?.upcoming || []).filter((u, i, a) => !(same(u) && a.slice(0, i).every(same))).slice(0, 5)
  return (
    <section className="card mp-card" aria-label="Spotify player" aria-busy={!actAs && !st}>
      <div className="card-title-row"><h3 className="card-h"><i className="fab fa-spotify mp-green" /> Spotify Player {t && <span className={`pill ${playing ? 'pill-ok' : 'pill-warn'}`}><i className={playing ? 'fas fa-play' : 'fas fa-pause'} /> {playing ? 'Playing' : 'Paused'}</span>}</h3></div>
      {actAs ? <div className="notice notice-warn"><i className="fas fa-circle-exclamation" /> <span>Spotify playback control is disabled while acting as another user.</span></div>
        : !st ? <div className="mp-sp-now"><span className="skel mp-art" /><div className="mp-track-main"><span className="skel skel-line" /><span className="skel skel-line" /></div></div> : <>
          {msg && <div className="notice notice-info mp-sp-msg" role="status"><i className="fas fa-circle-info" /> <span>{msg}</span></div>}
          {t && <div className="mp-sp-body"><div className="mp-sp-main">
            <div className="mp-sp-now">{t.album_art ? <img className="mp-art" src={t.album_art} alt="" /> : <span className="mp-art" />}<div className="mp-track-main"><b className="mp-title">{t.name}</b><span className="muted">{t.artists}</span><span className="mp-meta">{t.requested_by && <span><i className="fas fa-user" /> {t.requested_by}</span>}{st.device?.name && <span><i className="fas fa-mobile-screen" /> {st.device.name}</span>}</span></div></div>
            {t.duration_ms > 0 && <div className="mp-prog"><span>{fmtMs(prog)}</span><div className="mp-bar" role="progressbar" aria-label="Track progress" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round((prog / t.duration_ms) * 100)}><i style={{ width: `${Math.min(100, (prog / t.duration_ms) * 100)}%` }} /></div><span>{fmtMs(t.duration_ms)}</span></div>}
            <div className="mp-sp-ctl">
              <div className="mp-sp-btns"><button type="button" className="btn btn-ghost" aria-label="Previous" disabled={!!dis.skipping_prev} onClick={() => call('previous')}><i className="fas fa-backward-step" /></button>
              <button type="button" className="btn" aria-pressed={playing} disabled={playing ? !!dis.pausing : !!dis.resuming} onClick={() => call(playing ? 'pause' : 'play', {}, 300)}><i className={playing ? 'fas fa-pause' : 'fas fa-play'} /> {playing ? 'Pause' : 'Play'}</button>
              <button type="button" className="btn btn-ghost" aria-label="Next" disabled={!!dis.skipping_next} onClick={() => call('next')}><i className="fas fa-forward-step" /></button></div>
              {st.device?.supports_volume && vol !== null && <div className="fld mp-range mp-sp-vol"><label htmlFor="sp-vol"><i className="fas fa-volume-high" /> Volume <span className="pill">{vol}%</span></label><input id="sp-vol" type="range" min={0} max={100} value={vol} onChange={(e) => { holdVol.current = Date.now() + 3000; setVol(Number(e.target.value)) }} ref={spVolRef} /></div>}
            </div></div>
            {up.length > 0 && <div className="mp-up"><span className="fld-label">Up next</span><ol className="mp-uplist">{up.map((u, i) => <li key={i}><span className="mp-upn">{i + 1}</span><span className="mp-upt"><span className="mp-upname">{u.name}</span>{u.artists && <span className="muted"> {u.artists}</span>}</span>{u.requested_by && <span className="mp-req"><i className="fas fa-user" /> {u.requested_by}</span>}</li>)}</ol></div>}
          </div>}
        </>}
    </section>
  )
}

function StatsCard({ kind, st }: { kind: 'spotify' | 'youtube'; st: NonNullable<Stats> }) {
  const yt = kind === 'youtube'
  const main = (r: Row) => (yt ? r.title : r.song_name) || '', sub = (r: Row) => (yt ? r.uploader : r.artist_name) || ''
  const stats = useMemo(() => [['fa-music', 'Total requests', st.total], ['fa-fingerprint', yt ? 'Unique videos' : 'Unique songs', st.unique], ['fa-calendar-day', 'Requests today', st.today]] as const, [st, yt])
  return (
    <section className="card mp-stats-card" aria-label={yt ? 'YouTube song requests' : 'Spotify song requests'}>
      <div className="card-title-row"><h3 className="card-h"><i className={yt ? 'fab fa-youtube mp-red' : 'fab fa-spotify mp-green'} /> {yt ? 'YouTube Song Requests' : 'Spotify Song Requests'}</h3></div>
      <div className="mp-stats">{stats.map(([i, l, v]) => <div key={l} className="mp-stat"><span className="muted small"><i className={`fas ${i}`} /> {l}</span><b>{Number(v).toLocaleString()}</b></div>)}</div>
      {!st.top.length && !st.recent.length ? <p className="muted">No song requests have been recorded yet. Analytics will appear here once viewers start using <code>!songrequest</code>.</p> : (
        <div className="mp-two">
          <div><h4 className="al-sec"><i className="fas fa-trophy" /> {yt ? 'Top requested videos' : 'Top requested songs'}</h4><ol className="mp-list">{st.top.map((r, i) => <li key={i} className="mp-track"><span className="mp-pos">{i + 1}</span><div className="mp-track-main"><b className="mp-title">{main(r)}</b>{sub(r) && <span className="muted small">{sub(r)}</span>}</div><span className="pill">{r.request_count} {Number(r.request_count) === 1 ? 'request' : 'requests'}</span></li>)}</ol></div>
          <div><h4 className="al-sec"><i className="fas fa-clock-rotate-left" /> Recent requests</h4><ul className="mp-list">{st.recent.map((r, i) => <li key={i} className="mp-track"><div className="mp-track-main"><b className="mp-title">{main(r)}</b>{sub(r) && <span className="muted small">{sub(r)}</span>}<span className="mp-req"><i className="fas fa-user" /> {r.requested_by}</span></div><time className="muted small mp-len" dateTime={r.requested_at} title={r.requested_at}>{ago(r.age_seconds)}</time></li>)}</ul></div>
        </div>
      )}
    </section>
  )
}
