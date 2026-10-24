import { useEffect, useMemo, useState } from 'react'
import Modal from '../components/Modal'
import { get } from '../bot'

// Port of dashboard/emote-vault.php through the generated mirror
// public/api/emote-vault/emote-vault.php (same Twitch, 7TV, BTTV and FFZ calls).
const API = '/api/emote-vault/emote-vault.php'

type Source = 'twitch' | '7tv' | 'bttv' | 'ffz'
type Emote = { id: string; source: Source; source_id: string; name: string; preview_url: string; download_url: string; animated: boolean; kind: string; tier: string; ext: string; width: number; height: number }
type List = { success: boolean; emotes: Emote[]; errors: Record<string, string> | []; zip_available: boolean; zip_max: number; channel: string }

const LABEL: Record<Source, string> = { twitch: 'Twitch', '7tv': '7TV', bttv: 'BetterTTV', ffz: 'FrankerFaceZ' }
const SHORT: Record<Source, string> = { twitch: 'Twitch', '7tv': '7TV', bttv: 'BTTV', ffz: 'FFZ' }
const SOURCES: Source[] = ['twitch', '7tv', 'bttv', 'ffz']

function kindLabel(e: Emote): string {
  if (e.kind === 'subscriptions') {
    const n = ({ '1000': '1', '2000': '2', '3000': '3' } as Record<string, string>)[e.tier] ?? (e.tier ? String(parseInt(e.tier, 10) || '') : '')
    return n ? `Tier ${n}` : 'Subscriber'
  }
  if (e.kind === 'follower') return 'Follower'
  if (e.kind === 'bitstier') return 'Bits'
  if (e.kind === 'shared') return 'Shared'
  return 'Channel'
}
const dlHref = (e: Emote) => `${API}?${new URLSearchParams({ ajax_action: 'download', url: e.download_url, name: e.name || 'emote', ext: e.ext || 'png' })}`

export default function EmoteVault() {
  const [list, setList] = useState<List | null>(null)
  const [err, setErr] = useState('')
  const [loading, setLoading] = useState(true)
  const [src, setSrc] = useState<'all' | Source>('all')
  const [q, setQ] = useState('')
  const [anim, setAnim] = useState(false)
  const [open, setOpen] = useState<Emote | null>(null)
  const [zipping, setZipping] = useState(false)
  const [zipMsg, setZipMsg] = useState('')

  async function load() {
    setLoading(true); setErr('')
    try {
      const r = await get<List>(`${API}?ajax_action=list`, 30000)
      if (!r.success) throw new Error('bad')
      setList(r)
    } catch { setErr('Could not load emotes. Please try again.') }
    setLoading(false)
  }
  useEffect(() => { load() }, [])

  const all = list?.emotes ?? []
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return all.filter((e) => (src === 'all' || e.source === src) && (!anim || e.animated) && (!n || e.name.toLowerCase().includes(n)))
  }, [all, src, q, anim])
  const counts = useMemo(() => { const c: Record<string, number> = {}; all.forEach((e) => { c[e.source] = (c[e.source] ?? 0) + 1 }); return c }, [all])
  const errors = list && !Array.isArray(list.errors) ? Object.keys(list.errors) as Source[] : []

  async function zip() {
    if (!list) return
    if (!shown.length) { setZipMsg('Nothing to download for the current filters.'); return }
    if (shown.length > list.zip_max) { setZipMsg('Too many emotes to zip at once. Narrow the filters and try again.'); return }
    setZipping(true); setZipMsg('')
    try {
      const r = await fetch(`${API}?ajax_action=zip`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: shown.map((e) => ({ name: e.name, url: e.download_url, ext: e.ext })) }) })
      if (!r.ok || (r.headers.get('Content-Type') ?? '').includes('application/json')) {
        let m = 'Could not build the zip file.'
        try { const j = await r.json() as { error?: string }; if (j.error) m = j.error } catch { /* keep default */ }
        setZipMsg(m); return
      }
      const href = URL.createObjectURL(await r.blob())
      const a = document.createElement('a'); a.href = href; a.download = `${list.channel || 'channel'}-emote-vault.zip`
      document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(href)
    } catch { setZipMsg('Could not build the zip file.') } finally { setZipping(false) }
  }

  return (
    <div className="page vault">
      <div className="section-head">
        <h2><i className="fas fa-icons" /> Emote Vault</h2>
        <div className="vault-acts">
          <button type="button" className="btn btn-ghost" onClick={load} disabled={loading}><i className={`fas fa-rotate${loading ? ' fa-spin' : ''}`} /> Refresh</button>
          {list?.zip_available && shown.length > 0 && <button type="button" className="btn" onClick={zip} disabled={zipping}><i className={zipping ? 'fas fa-spinner fa-spin' : 'fas fa-file-zipper'} /> {zipping ? 'Preparing download' : `Download ${shown.length === all.length ? 'all' : 'visible'} (${shown.length})`}</button>}
        </div>
      </div>
      <p className="muted">Browse your channel's official Twitch emotes plus 7TV, BetterTTV and FrankerFaceZ. Download the full-size file for any emote.</p>
      {errors.map((s) => <div key={s} className="notice notice-warn"><i className="fas fa-triangle-exclamation" /> <span>{LABEL[s] ?? s} could not be reached.</span></div>)}
      {zipMsg && <div className="notice notice-err" role="status"><i className="fas fa-triangle-exclamation" /> <span>{zipMsg}</span></div>}
      {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}

      <div className="vault-stats" aria-busy={!list}>
        {[['Total', all.length] as const, ...SOURCES.map((s) => [LABEL[s], counts[s] ?? 0] as const)].map(([l, n]) => (
          <div key={l} className="card vault-stat">{list ? <b>{n}</b> : <span className="skel skel-num" />}<span>{l}</span></div>
        ))}
      </div>

      <section className="card vault-lib">
        <div className="vault-tools">
          <label className="media-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search by name" aria-label="Search emotes" value={q} onChange={(e) => setQ(e.target.value)} /></label>
          <div className="seg vault-seg" role="group" aria-label="Source">
            {(['all', ...SOURCES] as const).map((s) => <button key={s} type="button" aria-pressed={src === s} className={src === s ? 'on' : ''} onClick={() => setSrc(s)}>{s === 'all' ? 'All' : SHORT[s]}</button>)}
          </div>
          <label className="media-check-all"><input type="checkbox" checked={anim} onChange={(e) => setAnim(e.target.checked)} /> Animated only</label>
        </div>
        {list && all.length > 0 && <p className="muted small">{shown.length === all.length ? `${all.length} emotes` : `Showing ${shown.length} of ${all.length}`}</p>}
        {!list && !err && <div className="vault-grid">{Array.from({ length: 12 }, (_, i) => <div key={i} className="vault-item" aria-hidden="true"><span className="vault-art skel" /><span className="skel skel-line" /></div>)}</div>}
        {list && !all.length && <p className="muted media-empty">No channel emotes were found on Twitch, 7TV, BetterTTV or FrankerFaceZ.</p>}
        {list && all.length > 0 && !shown.length && <p className="muted media-empty">No emotes match the current filters.</p>}
        {shown.length > 0 && (
          <div className="vault-grid">
            {shown.map((e) => (
              <article key={e.id} className="vault-item">
                <button type="button" className="vault-art" aria-label={`Preview ${e.name}`} onClick={() => setOpen(e)}><img src={e.preview_url} alt="" loading="lazy" decoding="async" /></button>
                <b className="vault-name" title={e.name}>{e.name}</b>
                <div className="vault-badges"><span className={`pill vault-src-${e.source}`}>{SHORT[e.source] ?? e.source}</span><span className="pill">{kindLabel(e)}</span>{e.animated && <span className="pill pill-info">Animated</span>}</div>
                <a className="btn btn-ghost vault-dl" href={dlHref(e)} aria-label={`Download ${e.name}`}><i className="fas fa-download" /> Download</a>
              </article>
            ))}
          </div>
        )}
      </section>

      {open && (
        <Modal title={open.name} icon="fas fa-icons" onClose={() => setOpen(null)}>
          <div className="vault-big"><img src={open.download_url} alt={open.name} /></div>
          <p className="muted vault-meta">{[LABEL[open.source], kindLabel(open), open.animated ? 'Animated' : '', open.width && open.height ? `${open.width} × ${open.height}` : ''].filter(Boolean).join(' · ')}</p>
          <a className="btn modal-ok" href={dlHref(open)}><i className="fas fa-download" /> Download</a>
        </Modal>
      )}
    </div>
  )
}
