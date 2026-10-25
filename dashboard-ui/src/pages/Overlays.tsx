import { UrlReveal } from '../components/UrlReveal'
import { useEffect, useState, type ReactNode } from 'react'
import Modal from '../components/Modal'
import Combobox from '../components/Combobox'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/overlays.php via public/api/overlays/ (scripts/sync-live-api.sh). Retired cards are left out.
const API = '/api/overlays/overlays.php'
// Same endpoint the Alerts page uses: OVERLAY_REFRESH reloads every overlay open on this channel's key.
const NOTIFY = '/api/alerts/notify.php'
type Credits = { scroll_speed: number; text_color: string; font_family: string; looping: boolean }
type Page = { success: boolean; has_key: boolean; fonts: string[]; counters?: { name: string; count: number }[]; credits: Credits }
type Group = 'alerts' | 'screen' | 'characters'
type Ov = { id: string; icon: string; name: string; desc: ReactNode; opts?: ReactNode; group: Group; manage?: [string, string]; credits?: boolean }
const OVERLAYS: Ov[] = [
  { id: 'alerts', icon: 'fas fa-bell', name: 'Specter Alerts', group: 'alerts', manage: ['/alerts', 'Alerts'], desc: 'Every alert, sound, video, walk-on, TTS, death, weather, Discord, Ko-fi, Patreon and Fourthwall event in one source.' },
  { id: 'mediaplayer', icon: 'fas fa-play-circle', name: 'Media Player', group: 'alerts', manage: ['/media-player', 'Media Player'], desc: 'Plays viewer song requests through a YouTube player. No Spotify needed.' },
  { id: 'music', icon: 'fas fa-music', name: 'VOD Music', group: 'alerts', manage: ['/music', 'VOD Music'], desc: 'DMCA-free music for your broadcasts and VODs.', opts: <><code>&amp;nowplaying</code> shows the song title, <code>&amp;color=white</code> sets its colour.</> },
  { id: 'spotify', icon: 'fab fa-spotify', name: 'Spotify Now Playing', group: 'alerts', desc: 'Your current Spotify track. Needs Spotify linked. Scales to the source size.', opts: <><code>&amp;theme=</code> <code>terminal</code> (default), <code>pill</code>, <code>card</code> or <code>macwindow</code>.</> },
  { id: 'chat', icon: 'fas fa-comments', name: 'Chat', group: 'screen', desc: 'Twitch chat as a transparent source. Every scene using this URL shares one chat history.', opts: <><code>&amp;max=30</code> sets the message cap (default 20).</> },
  { id: 'credits', icon: 'fas fa-scroll', name: 'Stream ending credits', group: 'screen', credits: true, desc: 'Scrolls the followers, subscribers, donors and cheerers from this stream.' },
  { id: 'todolist', icon: 'fas fa-list-check', name: 'To Do List', group: 'screen', desc: 'Your stream tasks.', opts: <><code>&amp;category=1</code> for one category, <code>&amp;category=1,3</code> for several.</> },
  { id: 'subathon', icon: 'fas fa-hourglass-half', name: 'Subathon', group: 'screen', desc: 'A countdown timer for your subathon.' },
  { id: 'maker', icon: 'fas fa-palette', name: 'Makers & Crafting', group: 'screen', manage: ['/makers', 'Makers & Crafting'], desc: <>Your current project, finished builds or ideas, updated live with <code>!craft</code>.</> },
  { id: 'closed-captions', icon: 'fas fa-closed-captioning', name: 'Closed Captions', group: 'screen', manage: ['/closed-captions', 'Closed Captions'], desc: 'Free live captions of your microphone. Start the captioner on the Closed Captions page.' },
  { id: 'avatar', icon: 'fas fa-user-astronaut', name: 'Avatar', group: 'characters', manage: ['/avatar', 'Avatar'], desc: 'A PNG-tuber that talks, blinks and bobs. Set the art on the Avatar page.' },
  { id: 'pet', icon: 'fas fa-paw', name: 'Pet', group: 'characters', manage: ['/pet', 'Pet'], desc: 'A sprite pet that reacts to chat and stream events and keeps its stats.' },
]
const GROUPS: [Group | 'all', string][] = [['all', 'All'], ['alerts', 'Audio'], ['screen', 'On screen'], ['characters', 'Characters']]
const BUILTIN = ['deaths', 'stream_deaths', 'hugs', 'kisses', 'highfives', 'typos', 'lurkers']
const MASK = '••••••••••••'
function goTo(e: React.MouseEvent, path: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
  e.preventDefault(); window.history.pushState({}, '', path); window.dispatchEvent(new PopStateEvent('popstate')); window.scrollTo(0, 0)
}
async function fetchUrl(o: string, extra: Record<string, string> = {}) {
  const r = await get<{ success: boolean; url?: string; message?: string }>(`${API}?${new URLSearchParams({ beta: 'url', o, ...extra })}`)
  if (!r.success || !r.url) throw new Error(r.message || 'Could not get the overlay URL.')
  return r.url
}

export default function Overlays() {
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [fq, setFq] = useState(''), [fg, setFg] = useState<Group | 'all'>('all')
  const [credits, setCredits] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  async function refreshAll() {
    setRefreshing(true)
    try { const r = await send<{ success: boolean; message?: string }>(NOTIFY, { event: 'OVERLAY_REFRESH' }); setToast(r.success ? { kind: 'ok', text: 'Refresh sent. Every open overlay will reload in OBS shortly.' } : { kind: 'err', text: r.message || 'Could not reach your overlays.' }) }
    catch { setToast({ kind: 'err', text: 'Could not reach your overlays.' }) }
    setRefreshing(false)
  }
  useEffect(() => { get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r) }).catch(() => setErr('The overlays page could not load. Refresh to try again.')) }, [])
  const needle = fq.trim().toLowerCase()
  const rows = OVERLAYS.filter((o) => (fg === 'all' || o.group === fg) && (!needle || o.name.toLowerCase().includes(needle) || (typeof o.desc === 'string' && o.desc.toLowerCase().includes(needle))))
  const noKey = page && !page.has_key
  return (
    <div className="page cmds mp ov">
      <section className="card mp-lists ov-list" aria-label="Overlay links">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-layer-group" /> Overlays <span className="pill pill-ok">{OVERLAYS.length} available</span></h3>
          <button type="button" className="btn btn-ghost" disabled={refreshing || !page || !!noKey} title="Reload every overlay you have open in OBS" onClick={refreshAll}><i className={`fas fa-rotate${refreshing ? ' fa-spin' : ''}`} /> Refresh overlays</button></div>
        <p className="mp-lhelp muted">Add a link as a browser source in OBS, Streamlabs or XSplit. Links contain your channel key, so keep them out of screen shares.</p>
        <p className="mp-lhelp muted ov-retired"><span className="pill pill-off">Retired</span> The separate alert, sound, video, walk-on, TTS, death, weather, Discord, Ko-fi, Patreon and Fourthwall overlays no longer play anything. Remove them from OBS and use Specter Alerts.</p>
        {noKey && <div className="notice notice-err"><i className="fas fa-key" /> <span>Your account has no channel key yet, so overlay links cannot be made. Open your Profile to create one.</span></div>}
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        <div className="mp-lf">
          <div className="mp-fseg" role="group" aria-label="Overlay type">{GROUPS.map(([v, l]) => <button key={v} type="button" aria-pressed={fg === v} className={fg === v ? 'on' : ''} onClick={() => setFg(v)}>{l}<span className="mp-cnt">{v === 'all' ? OVERLAYS.length : OVERLAYS.filter((o) => o.group === v).length}</span></button>)}</div>
          <label className="mp-fsearch"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input type="search" placeholder="Search overlays" aria-label="Search overlays" value={fq} onChange={(e) => setFq(e.target.value)} /></label>
        </div>
        {!rows.length ? <div className="mp-lempty"><span className="muted">No overlays match these filters.</span><button type="button" className="btn btn-quiet" onClick={() => { setFq(''); setFg('all') }}>Clear filters</button></div>
          : <ul className="mp-llist">{rows.map((o) => (
            <li key={o.id} className="mp-lrow ov-row">
              <div className="ov-main">
                <i className={`${o.icon} ov-ic`} aria-hidden="true" />
                <div className="ov-txt">
                  <b>{o.name}</b>
                  <span className="muted">{o.desc}</span>
                  {o.opts && <span className="muted ov-opts">{o.opts}</span>}
                  <UrlReveal name={o.name} mask={`https://overlay.botofthespecter.com/${o.id === 'alerts' ? '' : o.id + '.php'}?code=${MASK}`} disabled={!!noKey} toast={setToast} fetchUrl={() => fetchUrl(o.id)} />
                </div>
              </div>
              {(o.credits || o.manage) && <span className="mp-lr ov-acts">
                {o.credits && <button type="button" className="btn btn-ghost mp-x" aria-label="Credits settings" title="Credits settings" disabled={!page} onClick={() => setCredits(true)}><i className="fas fa-gear" /></button>}
                {o.manage && <a className="btn btn-ghost mp-x" href={o.manage[0]} aria-label={`Open ${o.manage[1]}`} title={`Open ${o.manage[1]}`} onClick={(e) => goTo(e, o.manage![0])}><i className="fas fa-arrow-up-right-from-square" /></a>}
              </span>}
            </li>
          ))}</ul>}
      </section>
      <CounterCard disabled={!!noKey} toast={setToast} counters={page?.counters ?? []} />
      {credits && page && <CreditsModal page={page} onClose={() => setCredits(false)} onSaved={(c) => { setPage({ ...page, credits: c }); setCredits(false); setToast({ kind: 'ok', text: 'Credits settings saved.' }) }} />}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}

// Same colours overlay/counters.php accepts: 3 or 6 digit hex, or its named list.
const NAMED = ['white', 'black', 'red', 'green', 'blue', 'yellow', 'orange', 'purple', 'pink', 'cyan', 'magenta', 'gray', 'grey', 'brown', 'transparent', 'aqua', 'lime', 'maroon', 'navy', 'olive', 'silver', 'teal', 'gold', 'indigo', 'violet']
const HEX_RE = /^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/
const colourOk = (v: string) => HEX_RE.test(v) || NAMED.includes(v.toLowerCase())
// Swatch value for the native picker: hex as is, named colours resolved by the browser.
function toHex(v: string): string | null {
  const t = v.trim(); if (!t) return null
  const m = t.match(HEX_RE); if (m) { const h = m[1]; return '#' + (h.length === 3 ? h.split('').map((c) => c + c).join('') : h).toLowerCase() }
  if (!NAMED.includes(t.toLowerCase()) || t.toLowerCase() === 'transparent') return null
  const c = document.createElement('canvas').getContext('2d'); if (!c) return null
  c.fillStyle = '#000'; c.fillStyle = t; return /^#[0-9a-f]{6}$/i.test(c.fillStyle) ? c.fillStyle : null
}
const TYPES = [
  { value: 'html', label: 'Overlay', hint: 'Styled, for OBS' },
  { value: 'text', label: 'Text', hint: 'Event Name: 5' },
  { value: 'number', label: 'Number', hint: '5' },
  { value: 'name', label: 'Name', hint: 'Event Name' },
  { value: 'json', label: 'JSON', hint: 'For your own code' },
]
function ColourField({ id, label, help, value, set, ph, transparent }: { id: string; label: string; help: ReactNode; value: string; set: (s: string) => void; ph: string; transparent?: boolean }) {
  const bad = value.trim() !== '' && !colourOk(value.trim())
  const hex = toHex(value), clear = transparent && (value.trim() === '' || value.trim().toLowerCase() === 'transparent')
  return (
    <div className="mp-row"><div className="mp-row-l"><label htmlFor={id}>{label}</label><span id={id + '-h'} className={bad ? 'mp-bad' : 'muted'}>{bad ? 'Use a hex code like ff0000, or a colour name like white.' : help}</span></div>
      <div className="mp-row-r ov-colr">
        <span className={`ov-swwrap${clear ? ' is-clear' : ''}`}><input type="color" className="ov-sw" aria-label={`Pick ${label.toLowerCase()}`} value={hex ?? (transparent ? '#000000' : '#ffffff')} onChange={(e) => set(e.target.value.slice(1))} /></span>
        <input id={id} className={`input ov-hexin ov-cin${bad ? ' is-bad' : ''}`} maxLength={20} placeholder={ph} aria-invalid={bad} aria-describedby={id + '-h'} value={value} onChange={(e) => set(e.target.value)} />
        {transparent && <button type="button" className={`mu-mode ov-tr${clear ? ' on' : ''}`} aria-pressed={!!clear} onClick={() => set('')}><i className="fas fa-chess-board" /> Transparent</button>}
      </div></div>
  )
}
function CounterCard({ disabled, toast, counters }: { disabled: boolean; toast: (t: Toast) => void; counters: { name: string; count: number }[] }) {
  const [name, setName] = useState(''), [label, setLabel] = useState(''), [color, setColor] = useState(''), [bg, setBg] = useState(''), [type, setType] = useState('html')
  const badC = color.trim() !== '' && !colourOk(color.trim()), badB = bg.trim() !== '' && !colourOk(bg.trim())
  const badL = label.trim().length > 50
  const ready = name.trim() !== '' && /^[A-Za-z0-9_-]{1,100}$/.test(name.trim()) && !badC && !badB && !badL && !disabled
  const html = type === 'html'
  async function counterUrl() {
    const extra: Record<string, string> = { counter: name.trim() }
    if (label.trim()) extra.label = label.trim()
    if (!html) extra.type = type
    if (html && color.trim()) extra.color = color.trim()
    if (html && bg.trim()) extra.bg = bg.trim()
    return fetchUrl('counters', extra)
  }
  const badN = name.trim() !== '' && !/^[A-Za-z0-9_-]{1,100}$/.test(name.trim())
  const chip = (n: string, sub?: string) => <button key={n} type="button" className={`mu-mode${name.trim() === n ? ' on' : ''}`} aria-pressed={name.trim() === n} onClick={() => setName(n)}>{n}{sub && <span className="mp-cnt">{sub}</span>}</button>
  return (
    <section className="card ov-counter" aria-label="Counter display">
      <div className="card-title-row"><h3 className="card-h"><i className="fas fa-hashtag" /> Counter display</h3></div>
      <div className="mp-rows">
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="ov-cn">Counter</label><span id="ov-cn-h" className={badN ? 'mp-bad' : 'muted'}>{badN ? 'Letters, numbers, _ and - only.' : <>The name from <code>(count.NAME)</code> in a custom command, e.g. <code>frog</code> for <code>(count.frog)</code>. Plain <code>(count)</code> uses the command name.</>}</span></div>
          <div className="mp-row-r"><input id="ov-cn" className={`input ov-in${badN ? ' is-bad' : ''}`} maxLength={100} placeholder="e.g. frog" aria-invalid={badN} aria-describedby="ov-cn-h" value={name} onChange={(e) => setName(e.target.value)} /></div></div>
        {counters.length > 0 && <div className="ov-chips" role="group" aria-label="Your counters"><span className="ov-chips-l muted">Yours</span>{counters.map((c) => chip(c.name, String(c.count)))}</div>}
        <div className="ov-chips" role="group" aria-label="Built-in counters"><span className="ov-chips-l muted">Built-in</span>{BUILTIN.map((b) => chip(b))}</div>
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="ov-cl">Display text</label><span id="ov-cl-h" className={badL ? 'mp-bad' : 'muted'}>{badL ? 'Up to 50 characters.' : 'Shown on screen instead of the counter name.'}</span></div>
          <div className="mp-row-r"><input id="ov-cl" className={`input ov-in${badL ? ' is-bad' : ''}`} maxLength={60} placeholder="e.g. Event Name" aria-invalid={badL} aria-describedby="ov-cl-h" value={label} onChange={(e) => setLabel(e.target.value)} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Output</span><span className="muted">What the URL returns.</span></div><div className="mp-row-r mp-sel"><Combobox label="Output" searchable={false} value={type} onChange={setType} options={TYPES} /></div></div>
        {html && <>
          <ColourField id="ov-cc" label="Text colour" help="Blank picks black or white to contrast with the background." value={color} set={setColor} ph="white or ffffff" />
          <ColourField id="ov-cb" label="Background" help="Transparent unless you pick one." value={bg} set={setBg} ph="000000" transparent />
        </>}
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">{html ? 'Add as a browser source.' : 'For your own code or another tool, not an OBS source.'}</span></div>
          <div className="mp-row-r mp-urlbox"><UrlReveal name="Counter" mask={`https://overlay.botofthespecter.com/counters.php?code=${MASK}`} placeholder={!name.trim() ? 'Enter a counter name' : undefined} copied="Counter URL copied." disabled={!ready} toast={toast}
            resetKey={[name, label, color, bg, type].join('|')} fetchUrl={counterUrl} /></div></div>
      </div>
    </section>
  )
}

function CreditsModal({ page, onClose, onSaved }: { page: Page; onClose: () => void; onSaved: (c: Credits) => void }) {
  const [c, setC] = useState<Credits>(page.credits)
  const [hex, setHex] = useState(page.credits.text_color)
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const hexOk = /^#[0-9A-Fa-f]{6}$/.test(hex)
  const dirty = JSON.stringify(c) !== JSON.stringify(page.credits)
  async function save() {
    setBusy(true); setErr('')
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'save_credits', scroll_speed: String(c.scroll_speed), text_color: c.text_color, font_family: c.font_family, looping: c.looping ? '1' : '0' })
      if (!r.success) throw new Error(r.message || 'Failed to save settings.')
      onSaved(c)
    } catch (e) { setErr((e as Error).message) }
    setBusy(false)
  }
  return (
    <Modal title="Credits settings" icon="fas fa-scroll" onClose={onClose}>
      <div className="mp-rows ov-modal">
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="ov-sp">Scroll speed</label><span className="muted">Pixels per second.</span></div><div className="mp-row-r mp-volr ov-speed"><input id="ov-sp" type="range" min={10} max={200} value={c.scroll_speed} onChange={(e) => setC({ ...c, scroll_speed: Number(e.target.value) })} /><b className="mp-volv">{c.scroll_speed}</b></div></div>
        <div className="mp-row"><div className="mp-row-l"><label htmlFor="ov-hex">Text colour</label><span className={hexOk ? 'muted' : 'mp-bad'}>{hexOk ? 'Hex, like #FFFFFF.' : 'Enter a hex colour like #FFFFFF.'}</span></div>
          <div className="mp-row-r"><input type="color" className="ov-sw" aria-label="Pick text colour" value={hexOk ? hex : c.text_color} onChange={(e) => { setHex(e.target.value.toUpperCase()); setC({ ...c, text_color: e.target.value.toUpperCase() }) }} />
            <input id="ov-hex" className={`input ov-hexin${hexOk ? '' : ' is-bad'}`} maxLength={7} aria-invalid={!hexOk} value={hex} onChange={(e) => { const v = e.target.value; setHex(v); if (/^#[0-9A-Fa-f]{6}$/.test(v)) setC({ ...c, text_color: v.toUpperCase() }) }} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Font</span></div><div className="mp-row-r mp-sel"><Combobox label="Font" value={c.font_family} onChange={(v) => setC({ ...c, font_family: v })} options={page.fonts.map((f) => ({ value: f, label: f }))} /></div></div>
        <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Loop</span><span className="muted">Start again when the list ends.</span></div><div className="mp-row-r"><Switch on={c.looping} label="Loop credits" onChange={(v) => setC({ ...c, looping: v })} /></div></div>
      </div>
      {err && <p className="mp-bad small">{err}</p>}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="button" className="btn" disabled={busy || !hexOk || !dirty} onClick={save}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save</button></div>
    </Modal>
  )
}
