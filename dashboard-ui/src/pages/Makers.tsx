import { useEffect, useRef, useState, type FormEvent, type PointerEvent as RPE } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { UrlReveal } from '../components/UrlReveal'
import { BgSwatches, ExpandButton, ExpandLayer, SNAP_LINES, SnapGuides, snapAxis, useExpandLayer, type Guide } from '../components/OverlayCanvas'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/makers.php, same sections in the same order: overlay URL alert,
// Overlay settings (layout, boxes table, drag-to-place canvas, style grid, toggles),
// Add project, Project library. Everything goes through public/api/makers/makers.php
// (scripts/sync-live-api.sh). No timers.
const API = '/api/makers/makers.php'
type Img = { id: number; project_id: number; media_file: string; caption: string | null }
type Proj = { id: number; title: string; description: string | null; status: string; link_url: string | null; images: Img[] }
type S = Record<string, string | number>
type Page = { success: boolean; has_key: boolean; media_base: string; settings: S }
type List = { success: boolean; featured_id: number | null; projects: Proj[] }
type Res = { success: boolean; error?: string | null }
const BOXES: [string, string][] = [['featured', 'Featured (now working on)'], ['current', 'Current projects'], ['upcoming', 'Upcoming'], ['finished', 'Finished']]
const STATUS: [string, string, string][] = [['current', 'Current', 'pill-ok'], ['upcoming', 'Upcoming', 'pill-info'], ['finished', 'Finished', 'pill-off']]
const STATUS_OPTS = STATUS.map(([value, label]) => ({ value, label }))
const LAYOUTS = [{ value: 'positioned', label: 'Each box positioned' }, { value: 'stacked-left', label: 'Stacked left' }, { value: 'stacked-right', label: 'Stacked right' }]
const CANVAS = [{ value: '1280x720', label: '1280 × 720 (720p)' }, { value: '1920x1080', label: '1920 × 1080 (1080p)' }, { value: '2560x1440', label: '2560 × 1440 (2K)' }]
const FONTS = ['Arial', 'Verdana', 'Georgia', 'Tahoma', 'Trebuchet MS', 'Times New Roman', 'Courier New', 'Inter'].map((f) => ({ value: f, label: f }))
const FITS = [{ value: 'blur', label: 'Blur behind (contain)' }, { value: 'cover', label: 'Zoom & crop (cover)' }, { value: 'contain', label: 'Fit inside (contain)' }]
const TOGGLES: [string, string][] = [['visible', 'Overlay visible'], ['show_title', 'Show title'], ['show_description', 'Show description'], ['show_link', 'Show link']]
const clamp = (v: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, v))
const SNAP_PX = 7

async function post(body: Record<string, string> | FormData) {
  let r: Res
  if (body instanceof FormData) {
    const x = await fetch(API, { method: 'POST', credentials: 'same-origin', body })
    if (x.status === 401) throw new Error('Your session expired. Sign in again.')
    if (x.status === 413) throw new Error('The server rejected the request. Your images may be too large to upload at once.')
    r = await x.json() as Res
  } else r = await send<Res>(API, body)
  if (!r.success) throw new Error(r.error || 'Could not save that change.')
  return r
}

// Drag-to-place editor, drawn with the Alerts canvas pieces (components/OverlayCanvas):
// chips sized to the real 360 x 320 box for the chosen OBS canvas, snapping to edges,
// centre, thirds and the other boxes (Alt places freely), Expand layer with Escape.
function PosCanvas({ s, set, canvas }: { s: S; set: (k: string, v: number | string) => void; canvas: string }) {
  const ref = useRef<HTMLDivElement>(null), grab = useRef<{ box: string; dx: number; dy: number } | null>(null)
  const [guide, setGuide] = useState<Guide>({ x: null, y: null }), [active, setActive] = useState('')
  const [bg, setBg] = useState('transparent'), [big, setBig] = useState(false)
  const scale = 1920 / (Number(canvas.split('x')[0]) || 1920)
  const cw = (360 / 1920) * 100 * scale, ch = (320 / 1080) * 100 * scale
  useEffect(() => { BOXES.forEach(([b]) => { const x = Number(s[`position_${b}_x`]), y = Number(s[`position_${b}_y`]); if (x > 100 - cw) set(`position_${b}_x`, +(100 - cw).toFixed(2)); if (y > 100 - ch) set(`position_${b}_y`, +(100 - ch).toFixed(2)) }) }, [cw, ch]) // eslint-disable-line react-hooks/exhaustive-deps
  useExpandLayer(big, () => setBig(false))
  function lines(box: string, axis: 'x' | 'y') {
    const l = [...SNAP_LINES]
    BOXES.forEach(([b]) => { if (b !== box) { const n = Number(s[`position_${b}_${axis}`]); const z = axis === 'x' ? cw : ch; l.push(n, n + z / 2, n + z) } })
    return l
  }
  const down = (box: string) => (e: RPE<HTMLDivElement>) => { const r = e.currentTarget.getBoundingClientRect(); e.currentTarget.setPointerCapture(e.pointerId); grab.current = { box, dx: e.clientX - r.left, dy: e.clientY - r.top }; setActive(box); e.preventDefault() }
  const move = (e: RPE<HTMLDivElement>) => {
    const g = grab.current, el = ref.current; if (!g || !el) return
    const cr = el.getBoundingClientRect()
    let x = ((e.clientX - cr.left - g.dx) / cr.width) * 100, y = ((e.clientY - cr.top - g.dy) / cr.height) * 100
    let gx: number | null = null, gy: number | null = null
    if (!e.altKey) {
      const sx = snapAxis(x, cw, lines(g.box, 'x'), (SNAP_PX / cr.width) * 100), sy = snapAxis(y, ch, lines(g.box, 'y'), (SNAP_PX / cr.height) * 100)
      if (sx) { x = sx.pos; gx = sx.line } if (sy) { y = sy.pos; gy = sy.line }
    }
    x = clamp(x, 0, 100 - cw); y = clamp(y, 0, 100 - ch)
    if (gx !== null && ![x, x + cw / 2, x + cw].some((v) => Math.abs(v - gx!) < 0.4)) gx = null
    if (gy !== null && ![y, y + ch / 2, y + ch].some((v) => Math.abs(v - gy!) < 0.4)) gy = null
    setGuide({ x: gx, y: gy }); set(`position_${g.box}_x`, +x.toFixed(2)); set(`position_${g.box}_y`, +y.toFixed(2))
  }
  const up = () => { grab.current = null; setActive(''); setGuide({ x: null, y: null }) }
  const canvasEl = (
    <div className="al-stage">
      <div ref={ref} className={`al-canvas al-bg-${bg}`} aria-label="Box placement">
        {BOXES.map(([b, l]) => <div key={b} role="img" aria-label={`${l} box, drag to position`} className={`al-drag mk-chip${Number(s[`show_${b}`]) ? '' : ' is-disabled'}${active === b ? ' is-active' : ''}`} style={{ left: `${s[`position_${b}_x`]}%`, top: `${s[`position_${b}_y`]}%`, width: `${cw}%`, height: `${ch}%` }}
          onPointerDown={down(b)} onPointerMove={move} onPointerUp={up} onPointerCancel={up}><span>{l}</span></div>)}
        <SnapGuides guide={guide} />
      </div>
    </div>
  )
  const optsEl = (
    <div className="al-prev-opts">
      <div className="al-canvas-size"><span className="fld-label">Preview size (your OBS canvas)</span><Combobox label="Preview size" searchable={false} value={canvas} onChange={(v) => set('preview_canvas', v)} options={CANVAS} /></div>
      <BgSwatches bg={bg} onChange={setBg} />
      <ExpandButton expanded={big} onToggle={() => setBig((x) => !x)} />
    </div>
  )
  return (<div className="mk-canvas-wrap">
    {!big && <div className="al-tools mk-tools">{optsEl}</div>}
    <p className="mk-hint muted mk-range">Supported range: 1280 × 720 (720p) minimum to 2560 × 1440 (2K) maximum.</p>
    {big ? <div className="al-canvas al-xv-hold"><p className="muted al-canvas-note">Open in the expanded view. Press Esc to close.</p></div> : canvasEl}
    {big && <ExpandLayer label="Expanded box placement canvas" onClose={() => setBig(false)}
      bar={<><div className="al-p-title"><i className="fas fa-palette" /> <b>Box placement</b> <span className="muted small">{canvas.replace('x', ' x ')} at true scale</span></div>{optsEl}</>}>
      {canvasEl}
    </ExpandLayer>}
  </div>)
}

function ProjectCard({ p, featured, base, run, onDelete }: { p: Proj; featured: boolean; base: string; run: (k: string, b: Record<string, string> | FormData, ok: string) => Promise<boolean>; onDelete: () => void }) {
  const [edit, setEdit] = useState(false), [f, setF] = useState({ title: p.title, status: p.status, description: p.description || '', link_url: p.link_url || '' })
  const [files, setFiles] = useState<FileList | null>(null), fileRef = useRef<HTMLInputElement>(null)
  const st = STATUS.find(([v]) => v === p.status)
  const bad = !f.title.trim() ? 'Title is required.' : f.link_url.trim() && !/^https?:\/\/\S+$/i.test(f.link_url.trim()) ? 'Link must start with http:// or https://' : ''
  async function save(e: FormEvent) { e.preventDefault(); if (bad) return; if (await run('ed' + p.id, { maker_action: 'update_project', id: String(p.id), ...f }, `${f.title.trim()} saved.`)) setEdit(false) }
  async function upload(e: FormEvent) {
    e.preventDefault(); if (!files?.length) return
    const fd = new FormData(); fd.set('maker_action', 'upload_image'); fd.set('project_id', String(p.id)); Array.from(files).forEach((x) => fd.append('imageFiles[]', x))
    if (await run('up' + p.id, fd, `${files.length === 1 ? 'Image' : `${files.length} images`} uploaded.`)) { setFiles(null); if (fileRef.current) fileRef.current.value = '' }
  }
  return (
    <article className="mk-proj">
      <div className="mk-proj-head">
        <div className="mk-proj-t"><b>#{p.id} {p.title}</b><span className={`pill ${st?.[2] ?? ''}`}>{st?.[1] ?? p.status}</span>{featured && <span className="pill mk-feat">Featured</span>}</div>
        <div className="mk-proj-acts">
          {!featured && <button type="button" className="btn btn-ghost btn-sm" title="Feature as current" aria-label={`Feature ${p.title} as current`} onClick={() => run('fe' + p.id, { maker_action: 'set_current', id: String(p.id) }, `${p.title} is now featured.`)}><i className="fas fa-star" /></button>}
          <button type="button" className="btn btn-ghost btn-sm" aria-expanded={edit} onClick={() => setEdit(!edit)}><i className="fas fa-pen-to-square" /> Edit</button>
          <button type="button" className="btn btn-ghost btn-sm mk-del" aria-label={`Delete ${p.title}`} onClick={onDelete}><i className="fas fa-trash" /></button>
        </div>
      </div>
      {edit && <form className="mk-edit" onSubmit={save}>
        <label className="fld"><span>Title</span><input className="input" maxLength={255} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></label>
        <div className="fld"><span>Status</span><Combobox label={`Status of ${p.title}`} searchable={false} value={f.status} onChange={(v) => setF({ ...f, status: v })} options={STATUS_OPTS} /></div>
        <label className="fld fld-wide"><span>Description / context</span><textarea className="input" rows={2} maxLength={2000} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></label>
        <label className="fld fld-wide"><span>Link (optional)</span><input className="input" maxLength={500} placeholder="https://..." value={f.link_url} onChange={(e) => setF({ ...f, link_url: e.target.value })} /></label>
        <div className="mk-edit-foot fld-wide">{bad && <span className="err-text">{bad}</span>}<button className="btn btn-sm" disabled={!!bad}><i className="fas fa-floppy-disk" /> Save</button></div>
      </form>}
      <div className="mk-imgs-h">Images ({p.images.length})</div>
      {p.images.length > 0 && <div className="mk-imgs">{p.images.map((i) => <div key={i.id} className="mk-img">
        <img src={base + encodeURIComponent(i.media_file)} alt={i.caption || ''} loading="lazy" />
        <button type="button" title="Remove" aria-label={`Remove image ${i.media_file}`} onClick={() => run('di' + i.id, { maker_action: 'delete_image', image_id: String(i.id) }, 'Image removed.')}>&times;</button></div>)}</div>}
      <form className="mk-upload" onSubmit={upload}>
        <input ref={fileRef} type="file" className="input" accept="image/png,image/jpeg,image/gif" multiple aria-label={`Images for ${p.title}`} onChange={(e) => setFiles(e.target.files)} />
        <button className="btn btn-ghost btn-sm" disabled={!files?.length}><i className="fas fa-upload" /> Upload images</button>
      </form>
    </article>
  )
}

export default function Makers() {
  const [page, setPage] = useState<Page | null>(null), [s, setS] = useState<S>({}), [list, setList] = useState<List | null>(null)
  const [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null), [busy, setBusy] = useState('')
  const [title, setTitle] = useState(''), [status, setStatus] = useState('current'), [del, setDel] = useState<Proj | null>(null)
  const loadList = () => get<List>(`${API}?ajax_action=list`).then((r) => { if (!r.success) throw new Error(); setList(r) }).catch(() => setErr('Your projects could not load. Refresh to try again.'))
  useEffect(() => {
    get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r); setS(r.settings) }).catch(() => setErr('The Makers & Crafting page could not load. Refresh to try again.'))
    loadList()
  }, [])
  async function run(k: string, body: Record<string, string> | FormData, ok: string) {
    setBusy(k)
    try { await post(body); setToast({ kind: 'ok', text: ok }); await loadList(); setBusy(''); return true }
    catch (e) { setToast({ kind: 'err', text: (e as Error).message }); setBusy(''); return false }
  }
  const set = (k: string, v: string | number) => setS((o) => ({ ...o, [k]: v }))
  const ci = Number(s.carousel_seconds), pr = Number(s.project_rotate_seconds)
  const hex = (v: unknown) => /^#[0-9a-f]{6}$/i.test(String(v))
  const bad = !(ci >= 2 && ci <= 60 && Number.isInteger(ci)) ? 'Image change must be 2 to 60 seconds.' : !(pr >= 3 && pr <= 120 && Number.isInteger(pr)) ? 'Project rotate must be 3 to 120 seconds.' : !hex(s.accent_color) || !hex(s.text_color) ? 'Colours must be hex like #9146FF.' : ''
  function saveSettings() {
    const b: Record<string, string> = { maker_action: 'save_settings' }
    Object.entries(s).forEach(([k, v]) => { if (/^(visible|show_)/.test(k)) { if (Number(v)) b[k] = '1' } else b[k] = String(v) })
    run('set', b, 'Overlay settings saved.')
  }
  if (err && !page) return <div className="page cmds mk"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mk"><div className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading Makers & Crafting...</p></div></div>
  const canvas = String(s.preview_canvas || '1920x1080')
  return (
    <div className="page cmds mp mk">
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="mk-alert" aria-label="Overlay URL">
        <i className="fas fa-palette mk-alert-ic" />
        <div className="mk-alert-body">
          <h2>Makers &amp; Crafting Overlay</h2>
          <p>Show your viewers what you're making. Add this browser source to OBS, then manage projects here or live from chat with <code>!craft</code>.</p>
          <span className="mk-lbl">Overlay URL</span>
          <UrlReveal mask="https://overlay.botofthespecter.com/maker.php?code=••••••••••••" disabled={!page.has_key} toast={setToast} label="Maker overlay URL" fetchUrl={async () => { const r = await get<{ success: boolean; url?: string; error?: string }>(`${API}?beta=url`); if (!r.success || !r.url) throw new Error(r.error || 'Could not get the overlay URL.'); return r.url }} />
          <p className="mk-warn"><i className="fas fa-shield-halved" /> Keep this URL private. It contains your API key, so don't show it on stream.</p>
        </div>
      </section>

      <section className="card" aria-label="Overlay settings">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Overlay settings</h3></div>
        <div className="mk-place">
          <div className="mk-place-l">
            <div className="fld"><span>Layout</span><Combobox label="Layout" searchable={false} value={String(s.box_layout)} onChange={(v) => set('box_layout', v)} options={LAYOUTS} /></div>
            <p className="mk-hint muted">Positions below apply when "Each box positioned" is selected.</p>
            <span className="mk-lbl mk-sub">Overlay boxes</span>
            <table className="mk-boxes"><thead><tr><th>Box</th><th>Show</th></tr></thead><tbody>
              {BOXES.map(([b, l]) => <tr key={b}><td>{l}</td><td><input type="checkbox" aria-label={`Show ${l}`} checked={!!Number(s[`show_${b}`])} onChange={(e) => set(`show_${b}`, e.target.checked ? 1 : 0)} /></td></tr>)}
            </tbody></table>
          </div>
          <div className="mk-place-r">
            <span className="mk-lbl">Box placement</span>
            <p className="mk-hint muted">Drag each box to place it. Applies to the "Each box positioned" layout.</p>
            <p className="mk-hint muted"><i className="fas fa-magnet" /> Boxes snap to each other and to the canvas edges, center and thirds. Hold Alt while dragging to place freely.</p>
<PosCanvas s={s} set={set} canvas={canvas} />
          </div>
        </div>
        <div className="mk-grid">
          <div className="fld"><span>Font</span><Combobox label="Font" searchable={false} value={String(s.font_family)} onChange={(v) => set('font_family', v)} options={FONTS} /></div>
          <div className="fld"><span>Image display mode</span><Combobox label="Image display mode" searchable={false} value={String(s.image_fit)} onChange={(v) => set('image_fit', v)} options={FITS} /></div>
          <label className="fld"><span>Image change (sec)</span><input className="input" type="number" min={2} max={60} value={s.carousel_seconds} onChange={(e) => set('carousel_seconds', e.target.value)} /></label>
          <label className="fld"><span>Project rotate (sec)</span><input className="input" type="number" min={3} max={120} value={s.project_rotate_seconds} onChange={(e) => set('project_rotate_seconds', e.target.value)} /></label>
          <label className="fld"><span>Accent colour</span><span className="mk-color"><input type="color" value={hex(s.accent_color) ? String(s.accent_color) : '#9146ff'} onChange={(e) => set('accent_color', e.target.value.toUpperCase())} aria-label="Accent colour picker" /><input className="input" maxLength={7} value={s.accent_color} onChange={(e) => set('accent_color', e.target.value)} aria-label="Accent colour hex" /></span></label>
          <label className="fld"><span>Text colour</span><span className="mk-color"><input type="color" value={hex(s.text_color) ? String(s.text_color) : '#ffffff'} onChange={(e) => set('text_color', e.target.value.toUpperCase())} aria-label="Text colour picker" /><input className="input" maxLength={7} value={s.text_color} onChange={(e) => set('text_color', e.target.value)} aria-label="Text colour hex" /></span></label>
        </div>
        <div className="mk-checks">{TOGGLES.map(([k, l]) => <label key={k} className="mk-check"><input type="checkbox" checked={!!Number(s[k])} onChange={(e) => set(k, e.target.checked ? 1 : 0)} /> {l}</label>)}</div>
        <div className="mk-save">{bad && <span className="err-text">{bad}</span>}<button type="button" className="btn" disabled={!!bad || busy === 'set'} onClick={saveSettings}><i className={busy === 'set' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save settings</button></div>
      </section>

      <section className="card" aria-label="Add project">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-circle-plus" /> Add project</h3></div>
        <form className="mk-add" onSubmit={async (e) => { e.preventDefault(); const t = title.trim(); if (!t) return; if (await run('add', { maker_action: 'create_project', title: t, status }, `${t} added.`)) setTitle('') }}>
          <label className="fld mk-add-t"><span>Title</span><input className="input" maxLength={255} placeholder="e.g. Hand-knit winter scarf" value={title} onChange={(e) => setTitle(e.target.value)} /></label>
          <div className="fld mk-add-s"><span>Status</span><Combobox label="Status" searchable={false} value={status} onChange={setStatus} options={STATUS_OPTS} /></div>
          <button className="btn" disabled={!title.trim() || busy === 'add'}><i className="fas fa-plus" /> Add</button>
        </form>
      </section>

      <section className="card" aria-label="Project library" aria-busy={!list}>
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-layer-group" /> Project library</h3></div>
        {!list ? (err ? <p className="err-text">{err}</p> : <div className="mk-skel"><span /><span /></div>)
          : list.projects.length === 0 ? <p className="muted mk-empty"><i className="fas fa-box-open" /> No projects yet. Add one above, or use <code>!craft new &lt;title&gt;</code> in chat.</p>
          : list.projects.map((p) => <ProjectCard key={p.id} p={p} featured={Number(list.featured_id) === p.id} base={page.media_base} run={run} onDelete={() => setDel(p)} />)}
      </section>

      {del && <Modal title="Delete project" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p>Delete <b>{del.title}</b> and its images? This can't be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { setDel(null); run('del', { maker_action: 'delete_project', id: String(del.id) }, `${del.title} deleted.`) }}><i className="fas fa-trash" /> Delete</button></div>
      </Modal>}
    </div>
  )
}
