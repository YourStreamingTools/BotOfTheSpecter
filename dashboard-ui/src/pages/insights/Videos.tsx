import { useEffect, useRef, useState } from 'react'
import { get } from '../../bot'
import { send, ToastBar, type Toast } from '../commands/shared'
import { Confirm, notOk } from './shared'

// Port of dashboard/videos.php: Archive VODs / Highlights / Uploads / Clips
// tabs, the media card grid (newest first, as Twitch returns them), clip
// Load 20 More / Load All, Delete and Send to YouTube.
// Endpoint: public/api/videos/videos.php.
const API = '/api/videos/videos.php'
const OLD = 'https://dashboard.botofthespecter.com'
type Tab = 'videos' | 'highlights' | 'uploads' | 'clips'
type YT = { state: 'none' | 'coming_soon' | 'connect' | 'done' | 'busy' | 'limit' | 'upload'; label?: string; title?: string }
type Card = { id: string; title: string; description: string; thumbnail: string; url: string; views: number; type: string; duration: string; date: string; creator: string; broadcaster: string; featured: boolean; vod_offset: number | null; landscape: string; portrait: string; youtube: YT }
const TABS: [Tab, string, string, string][] = [
  ['videos', 'Archive VODs', 'fas fa-photo-video', 'Archive VODs'], ['highlights', 'Highlights', 'fas fa-star', 'Highlighted Videos'],
  ['uploads', 'Uploads', 'fas fa-upload', 'Uploaded Videos'], ['clips', 'Clips', 'fas fa-film', 'Clips'],
]
const EMPTY: Record<Tab, string> = { videos: 'No archive VODs found for this channel.', highlights: 'No highlights found for this channel.', uploads: 'No uploaded videos found for this channel.', clips: 'No clips found for this channel.' }
const FAIL = 'Unable to load videos. Please try again.'
const readTab = (): Tab => { const t = new URLSearchParams(location.search).get('tab'); return t === 'highlights' || t === 'uploads' || t === 'clips' ? t : 'videos' }
const num = (n: number) => n.toLocaleString('en-US')

function MediaCard({ c, clips, busy, onDelete, onYt }: { c: Card; clips: boolean; busy: boolean; onDelete: (c: Card) => void; onYt: (c: Card) => void }) {
  const y = c.youtube
  return <div className="card vd-card">
    <div className="vd-thumb">{c.thumbnail ? <img src={c.thumbnail} alt="Video thumbnail" loading="lazy" /> : <span><i className="fas fa-image" /> No Thumbnail</span>}</div>
    <div className="vd-body">
      <p className="vd-title">{c.title}</p>
      {!clips && <p className="muted vd-desc">{c.description || 'No description provided.'}</p>}
      <div className="vd-tags"><span className="pill pill-info">{c.type}</span><span className="pill pill-accent">{c.duration}</span>{clips && <span className="pill pill-ok">{c.featured ? 'featured' : 'not featured'}</span>}</div>
      {clips && <><p className="vd-meta"><b>Creator:</b> {c.creator || 'Unknown'}</p><p className="vd-meta"><b>Broadcaster:</b> {c.broadcaster || 'Unknown'}</p><p className="vd-meta"><b>VOD Offset:</b> {c.vod_offset !== null ? `${num(c.vod_offset)}s` : 'N/A'}</p></>}
      <p className="vd-meta"><b>Views:</b> {num(c.views)}</p>
      <p className="vd-meta"><b>{clips ? 'Created:' : 'Published:'}</b> {c.date}</p>
      <div className="vd-btns">
        {c.url ? <a className="btn cm-btn" href={c.url} target="_blank" rel="noopener noreferrer"><i className="fas fa-external-link-alt" /> Open</a> : <button type="button" className="btn btn-ghost cm-btn" disabled>No URL</button>}
        {!clips && c.id && <>
          <button type="button" className="btn btn-danger cm-btn" disabled={busy} onClick={() => onDelete(c)}><i className="fas fa-trash" /> Delete</button>
          {y.state === 'coming_soon' ? <button type="button" className="btn btn-warn cm-btn" disabled><i className="fas fa-clock" /> {y.label}</button>
            : y.state === 'connect' ? <a className="btn btn-ghost cm-btn" href={`${OLD}/streaming.php#youtube`}><i className="fab fa-youtube" /> {y.label}</a>
            : y.state === 'done' ? <span className="pill pill-ok">{y.label}</span>
            : y.state === 'busy' ? <span className="pill pill-warn">{y.label}</span>
            : y.state === 'limit' ? <span title={y.title}><button type="button" className="btn btn-ghost cm-btn" disabled><i className="fab fa-youtube" /> {y.label}</button></span>
            : y.state === 'upload' ? <button type="button" className="btn btn-ghost cm-btn" disabled={busy} onClick={() => onYt(c)}><i className="fab fa-youtube" /> {y.label}</button> : null}
        </>}
        {clips && <>
          {c.landscape && <a className="btn cm-btn" href={c.landscape} target="_blank" rel="noopener noreferrer" download><i className="fas fa-download" /> Landscape</a>}
          {c.portrait && <a className="btn cm-btn" href={c.portrait} target="_blank" rel="noopener noreferrer" download><i className="fas fa-download" /> Portrait</a>}
          {!c.landscape && !c.portrait && <button type="button" className="btn btn-ghost cm-btn" disabled><i className="fas fa-download" /> Download unavailable</button>}
        </>}
      </div>
    </div>
  </div>
}

const Skel = ({ n }: { n: number }) => <>{Array.from({ length: n }, (_, k) => <div key={k} className="card vd-card" aria-hidden="true"><span className="bp-skel vd-thumb" /><div className="vd-body adm-log-skel">{[80, 60, 30, 50, 40].map((w, j) => <span key={j} className="bp-skel" style={{ width: `${w}%` }} />)}</div></div>)}</>

export default function Videos() {
  const [tab, setTab] = useState<Tab>(readTab), [cards, setCards] = useState<Card[] | null>(null), [err, setErr] = useState('')
  const [flash, setFlash] = useState<{ ok: boolean; text: string } | null>(null), [toast, setToast] = useState<Toast>(null)
  const [next, setNext] = useState(0), [more, setMore] = useState(false), [total, setTotal] = useState<number | null>(null), [capped, setCapped] = useState(false)
  const [loading, setLoading] = useState<'' | 'more' | 'all'>(''), [askAll, setAskAll] = useState(false)
  const [del, setDel] = useState<Card | null>(null), [yt, setYt] = useState<Card | null>(null), [busy, setBusy] = useState('')
  const seq = useRef(0)
  const clips = tab === 'clips'
  async function loadTab(t: Tab) {
    const n = ++seq.current
    setCards(null); setErr(''); setNext(0); setMore(false); setTotal(null); setCapped(false)
    try {
      if (t !== 'clips') {
        const r = await get<{ success: boolean; cards?: Card[]; message?: string }>(`${API}?ajax=videos&tab=${t}`, 60000)
        if (n !== seq.current) return
        if (!r.success) throw new Error(r.message || FAIL)
        setCards(r.cards ?? [])
      } else {
        get<{ success: boolean; total_count?: number; capped?: boolean }>(`${API}?ajax=clips&ajax_action=count`, 60000).then((r) => { if (n === seq.current && r.success) { setTotal(Number(r.total_count ?? 0)); setCapped(!!r.capped) } }).catch(() => {})
        await batch('more', true, n)
      }
    } catch (e) { if (notOk(e) && n === seq.current) { setErr((e as Error).message && !(e as Error).message.startsWith('http_') && (e as Error).message !== 'signal is aborted without reason' ? (e as Error).message : FAIL); setCards([]) } }
  }
  async function batch(mode: 'more' | 'all', replace: boolean, n = seq.current, from = 0) {
    setLoading(mode)
    try {
      const r = await get<{ success: boolean; cards?: Card[]; next_offset?: number; has_more?: boolean; total_count?: number; message?: string }>(`${API}?ajax=clips&ajax_action=batch&offset=${replace ? 0 : from}&mode=${mode}`, 120000)
      if (n !== seq.current) return
      if (!r.success) throw new Error(r.message || 'Unable to load more clips.')
      setCards((c) => replace ? r.cards ?? [] : [...(c ?? []), ...(r.cards ?? [])])
      setNext(Number(r.next_offset ?? 0)); setMore(!!r.has_more)
      if (typeof r.total_count !== 'undefined') setTotal(Number(r.total_count))
    } catch (e) {
      if (!notOk(e) || n !== seq.current) return
      const m = (e as Error).message
      const text = m && !m.startsWith('http_') && m !== 'signal is aborted without reason' ? m : 'Please try again.'
      if (replace) { setErr(text); setCards([]) } else setToast({ kind: 'err', text: `Unable to load clips: ${text}` })
    } finally { if (n === seq.current) setLoading('') }
  }
  useEffect(() => { loadTab(tab) }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const f = () => setTab(readTab()); window.addEventListener('popstate', f); return () => window.removeEventListener('popstate', f) }, [])
  function go(t: Tab) { if (t === tab) return; history.pushState(null, '', t === 'videos' ? '/videos' : `/videos?tab=${t}`); setFlash(null); setTab(t) }
  async function doDelete() {
    if (!del) return
    setBusy(del.id)
    try {
      const r = await send<{ success: boolean; message?: string; error?: string }>(API, { action: 'delete_video', video_id: del.id })
      setFlash(r.success ? { ok: true, text: r.message || 'Video deleted from Twitch.' } : { ok: false, text: r.error || 'Delete failed.' })
      setDel(null); if (r.success) loadTab(tab)
    } catch (e) { setFlash({ ok: false, text: (e as Error).message }); setDel(null) }
    finally { setBusy('') }
  }
  async function doYt() {
    if (!yt) return
    const c = yt
    setBusy(c.id); setYt(null)
    try {
      const r = await send<{ ok: boolean; status?: string; message?: string; error?: string }>(API, { action: 'youtube_upload_twitch_vod', video_id: c.id, title: c.title.slice(0, 300) }, true)
      if (r.ok) {
        setCards((cs) => cs && cs.map((x) => x.id === c.id ? { ...x, youtube: r.status === 'done' ? { state: 'done', label: 'On YouTube' } : { state: 'busy', label: 'Queued' } } : x))
        setToast({ kind: 'ok', text: r.message || 'Queued for YouTube upload.' })
      } else setToast({ kind: 'err', text: r.error || 'Could not queue the YouTube upload.' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Could not queue the YouTube upload.' }) }
    finally { setBusy('') }
  }
  const loaded = cards?.length ?? 0, remaining = total !== null ? Math.max(total - loaded, 0) : 0
  const status = total !== null ? (capped ? `Loaded ${loaded} clips (count capped at ${total}).` : `Loaded ${loaded} of ${total} clips.`) : `Loaded ${loaded} clips.`
  const head = TABS.find(([t]) => t === tab)!
  return (
    <div className="page cmds cm">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card" aria-label={head[3]}>
        <h3 className="card-h"><i className="fas fa-photo-video" /> {head[3]}</h3>
        <div className="seg vd-tabs" role="tablist">{TABS.map(([t, l, i]) => <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'on' : ''} onClick={() => go(t)}><i className={i} /> {l}</button>)}</div>
        {flash && <div className={`notice ${flash.ok ? 'notice-ok' : 'notice-err'}`} role="status"><span>{flash.text}</span></div>}
        {err && <div className="notice notice-err" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {cards && cards.length === 0 && !err && !loading && <div className="notice notice-info"><span>{EMPTY[tab]}</span></div>}
        {clips && <div className="card vd-clipbar"><p className="muted">{status}</p><div className="vd-btns">
          <button type="button" className="btn cm-btn" disabled={!!loading || !more} onClick={() => batch('more', false, seq.current, next)}>Load 20 More</button>
          <button type="button" className="btn btn-warn cm-btn" disabled={!!loading || !more} onClick={() => setAskAll(true)}>{total !== null && remaining > 0 ? `Load All (${remaining} more)` : 'Load All'}</button>
        </div></div>}
        <div className="vd-grid" aria-busy={!cards || !!loading}>
          {cards === null ? <Skel n={6} /> : <>{cards.map((c) => <MediaCard key={c.id || c.title} c={c} clips={clips} busy={busy === c.id} onDelete={setDel} onYt={setYt} />)}{loading && <Skel n={loading === 'all' ? 6 : 3} />}</>}
        </div>
      </section>
      {del && <Confirm title="Delete Twitch Video?" yes="Yes, delete video" busy={busy === del.id} onYes={doDelete} onClose={() => setDel(null)}><p>This video will be deleted from Twitch and this action is irreversible.</p><p className="muted small">{del.title}</p></Confirm>}
      {yt && <Confirm title="Upload this VOD to YouTube?" yes="Download and upload" danger={false} onYes={doYt} onClose={() => setYt(null)}><p>Specter will download this Twitch VOD on the stream server with FFmpeg, then upload the MP4 to your connected YouTube channel.</p></Confirm>}
      {askAll && <Confirm title="Load all clips?" yes="Yes, load all" onYes={() => { setAskAll(false); batch('all', false, seq.current, next) }} onClose={() => setAskAll(false)}>
        <p>{remaining > 0 ? `You have ${total ?? loaded} clips in total (${remaining} remaining to load). This can take a long time. Continue?` : `You currently have ${total ?? loaded} loaded clips. Loading all remaining clips can take a long time. Continue?`}</p>
      </Confirm>}
    </div>
  )
}
