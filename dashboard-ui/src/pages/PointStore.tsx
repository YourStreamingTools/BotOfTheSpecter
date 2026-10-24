import { useEffect, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/point_store.php: header and intro, how-it-works notice with the
// members store link, Store settings card (three switches, two numbers, Save),
// Catalog items card (Add item, table with Edit / Enable or Disable / delete) and
// the Add / Edit item modal. Endpoint: public/api/point-store. No timers.
const API = '/api/point-store/point-store.php'
type Item = { id: number; title: string; slug: string | null; description: string | null; cost: number; item_type: string; enabled: number | string; cooldown_seconds: number; sort_order: number; max_per_stream: number | null; stock: number | null; payload_decoded: { sound?: string; video?: string; text?: string } }
type Settings = { enabled: number; paused: number; stream_online_only: number; global_cooldown_seconds: number; max_purchases_per_user_per_stream: number | null }
type Page = { success: boolean; point_name: string; store_url: string; settings: Settings }
type List = { success: boolean; items: Item[]; sound_files: string[]; video_files: string[] }
type Res = { success: boolean; error?: string; message?: string }
const TYPES: [string, string][] = [['sound_alert', 'Sound alert'], ['video_alert', 'Video alert'], ['tts', 'Text-to-speech'], ['chat_message', 'Chat message']]
const typeLabel = (t: string) => TYPES.find(([v]) => v === t)?.[1] ?? t
const intOk = (v: string, lo: number, hi: number, blank = false) => (blank && v.trim() === '') || (/^-?\d+$/.test(v.trim()) && +v >= lo && +v <= hi)
const summary = (p: Item['payload_decoded']) => p?.sound || p?.video || p?.text || ''

function ItemModal({ item, list, pn, onClose, onSaved }: { item: Item | null; list: List; pn: string; onClose: () => void; onSaved: (msg: string) => void }) {
  const p = item?.payload_decoded || {}
  const [f, setF] = useState({ title: item?.title ?? '', description: item?.description ?? '', cost: String(item?.cost ?? 100), item_type: item?.item_type ?? 'sound_alert',
    sound_file: p.sound ?? '', video_file: p.video ?? '', tts_text: item?.item_type === 'tts' ? p.text ?? '' : '', chat_text: item?.item_type === 'chat_message' ? p.text ?? '' : '',
    cooldown_seconds: String(item?.cooldown_seconds ?? 0), sort_order: String(item?.sort_order ?? 0), max_per_stream: item?.max_per_stream != null ? String(item.max_per_stream) : '', stock: item?.stock != null ? String(item.stock) : '', enabled: item ? Number(item.enabled) === 1 : true })
  const [busy, setBusy] = useState(false), [err, setErr] = useState('')
  const set = (k: string, v: string | boolean) => setF({ ...f, [k]: v })
  const t = f.item_type
  const bad = !f.title.trim() ? 'Add a title.' : f.title.length > 150 ? 'Title must be 150 characters or fewer.' : f.description.length > 500 ? 'Description must be 500 characters or fewer.'
    : !intOk(f.cost, 1, 1e9) ? 'Price must be a whole number of at least 1.'
    : t === 'sound_alert' && !f.sound_file ? 'Choose a sound file.' : t === 'video_alert' && !f.video_file ? 'Choose a video file.'
    : t === 'tts' && !f.tts_text.trim() ? 'Add the TTS text.' : t === 'chat_message' && !f.chat_text.trim() ? 'Add the chat message.' : t === 'chat_message' && /^[/.]/.test(f.chat_text.trim()) ? 'Chat messages cannot start with / or .'
    : !intOk(f.cooldown_seconds, 0, 86400) ? 'Item cooldown must be 0 to 86400 seconds.' : !intOk(f.sort_order, -100000, 100000) ? 'Sort order must be a whole number.'
    : !intOk(f.max_per_stream, 1, 1000, true) ? 'Max per stream must be 1 to 1000, or empty for unlimited.' : !intOk(f.stock, 0, 1000000, true) ? 'Stock must be 0 or more, or empty for unlimited.' : ''
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (bad) return
    setBusy(true); setErr('')
    const { enabled, ...rest } = f
    try { const r = await send<Res>(API, { action: 'save_item', item_id: String(item?.id ?? 0), ...rest, ...(enabled ? { item_enabled: '1' } : {}) }); if (!r.success) throw new Error(r.error || 'Could not save the item.'); onSaved(r.message || 'Store item saved.') }
    catch (x) { setErr((x as Error).message) }
    setBusy(false)
  }
  const fileSel = (k: 'sound_file' | 'video_file', files: string[], label: string, none: string) => <div className="fld fld-wide"><span className="fld-label">{label}</span>
    {files.length ? <Combobox label={label} value={f[k]} onChange={(v) => set(k, v)} placeholder="Select a file..." options={files.map((x) => ({ value: x, label: x }))} /> : <p className="mp-bad small">{none}</p>}</div>
  return (
    <Modal title={item ? 'Edit item' : 'Add item'} icon={item ? 'fas fa-pen-to-square' : 'fas fa-plus'} onClose={onClose} wide>
      <form className="ps-form" onSubmit={save}>
        <label className="fld fld-wide"><span className="fld-label">Title</span><input className="input" maxLength={150} value={f.title} onChange={(e) => set('title', e.target.value)} data-autofocus /></label>
        <label className="fld fld-wide"><span className="fld-label">Description <span className="muted small">Optional. Shown on the members store page.</span></span><textarea className="input" rows={2} maxLength={500} value={f.description} onChange={(e) => set('description', e.target.value)} /></label>
        <label className="fld"><span className="fld-label">Price ({pn})</span><input className="input" type="number" inputMode="numeric" min={1} value={f.cost} onChange={(e) => set('cost', e.target.value)} /></label>
        <div className="fld"><span className="fld-label">Item type</span><Combobox label="Item type" searchable={false} value={t} onChange={(v) => set('item_type', v)} options={TYPES.map(([value, label]) => ({ value, label }))} /></div>
        {t === 'sound_alert' && fileSel('sound_file', list.sound_files, 'Sound file (Media library)', 'No MP3 files in your Media library. Upload audio on the Media page first.')}
        {t === 'video_alert' && fileSel('video_file', list.video_files, 'Video file (Media library)', 'No MP4 files in your Media library. Upload video on the Media page first.')}
        {t === 'tts' && <label className="fld fld-wide"><span className="fld-label">TTS text <span className="muted small">Spoken when redeemed. Keep it short.</span></span><textarea className="input" rows={2} maxLength={300} value={f.tts_text} onChange={(e) => set('tts_text', e.target.value)} /></label>}
        {t === 'chat_message' && <label className="fld fld-wide"><span className="fld-label">Chat message <span className="muted small">The bot posts this when the item is bought.</span></span><textarea className="input" rows={2} maxLength={400} value={f.chat_text} onChange={(e) => set('chat_text', e.target.value.replace(/\n/g, ' '))} /></label>}
        <div className="ps-three fld-wide">
          <label className="fld"><span className="fld-label">Item cooldown (sec)</span><input className="input" type="number" min={0} value={f.cooldown_seconds} onChange={(e) => set('cooldown_seconds', e.target.value)} /></label>
          <label className="fld"><span className="fld-label">Sort order</span><input className="input" type="number" value={f.sort_order} onChange={(e) => set('sort_order', e.target.value)} /></label>
          <label className="fld"><span className="fld-label">Max / stream</span><input className="input" type="number" min={1} placeholder="Unlimited" value={f.max_per_stream} onChange={(e) => set('max_per_stream', e.target.value)} /></label>
        </div>
        <label className="fld fld-wide"><span className="fld-label">Stock <span className="muted small">Empty for unlimited. At 0 the item cannot be bought.</span></span><input className="input" type="number" min={0} placeholder="Unlimited" value={f.stock} onChange={(e) => set('stock', e.target.value)} /></label>
        <div className="ps-sw fld-wide"><Switch on={f.enabled} label="Item enabled in store" onChange={(v) => set('enabled', v)} /><span>Item enabled in store</span></div>
        {(bad || err) && <p className="mp-bad small fld-wide">{err || bad}</p>}
        <div className="modal-acts fld-wide"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn" disabled={busy || !!bad}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save item</button></div>
      </form>
    </Modal>
  )
}

export default function PointStore() {
  const [page, setPage] = useState<Page | null>(null), [list, setList] = useState<List | null>(null)
  const [f, setF] = useState<Record<string, string>>({}), [sw, setSw] = useState<Record<string, boolean>>({})
  const [err, setErr] = useState(''), [listErr, setListErr] = useState(''), [toast, setToast] = useState<Toast>(null), [busy, setBusy] = useState('')
  const [modal, setModal] = useState<Item | null | undefined>(undefined), [del, setDel] = useState<Item | null>(null)
  const seed = (s: Settings) => { setF({ global_cooldown_seconds: String(s.global_cooldown_seconds), max_purchases_per_user_per_stream: s.max_purchases_per_user_per_stream == null ? '' : String(s.max_purchases_per_user_per_stream) }); setSw({ enabled: !!s.enabled, paused: !!s.paused, stream_online_only: !!s.stream_online_only }) }
  const loadPage = () => get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r); seed(r.settings) })
  const loadList = () => get<List>(`${API}?ajax_action=list`).then((r) => { if (!r.success) throw new Error(); setList(r); setListErr('') }).catch(() => setListErr('Something went wrong. Please try again.'))
  useEffect(() => { loadPage().catch(() => setErr('The Point Store page could not load. Refresh to try again.')); loadList() }, []) // eslint-disable-line react-hooks/exhaustive-deps
  async function act(k: string, body: Record<string, string>, after: () => Promise<unknown>) {
    setBusy(k)
    try { const r = await send<Res>(API, body); if (!r.success) throw new Error(r.error || 'Something went wrong. Please try again.'); setToast({ kind: 'ok', text: r.message || 'Saved.' }); await after(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  if (err) return <div className="page cmds mp ps"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp ps"><section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p></section></div>
  const pn = page.point_name, s = page.settings
  const cdOk = intOk(f.global_cooldown_seconds ?? '', 0, 86400), mxOk = intOk(f.max_purchases_per_user_per_stream ?? '', 1, 1000, true)
  const dirty = sw.enabled !== !!s.enabled || sw.paused !== !!s.paused || sw.stream_online_only !== !!s.stream_online_only || f.global_cooldown_seconds !== String(s.global_cooldown_seconds) || f.max_purchases_per_user_per_stream !== (s.max_purchases_per_user_per_stream == null ? '' : String(s.max_purchases_per_user_per_stream))
  const SW: [string, string, string][] = [['enabled', 'Store enabled', 'When off, viewers cannot buy items.'], ['paused', 'Paused', 'Block purchases for now without turning the store off.'], ['stream_online_only', 'Only while live', 'Reject purchases when the channel is offline.']]
  return (
    <div className="page cmds mp ps">
      <div className="pg-head"><h2><i className="fas fa-store" /> Point Store</h2><p className="muted">Create items viewers can buy with {pn} via chat (<code>!store</code>) or the members store page.</p></div>
      <div className="notice notice-info ps-how"><i className="fas fa-store" />
        <div><b>How the Point Store works</b>
          <p>Add items below and set a price in {pn}. Upload sounds (MP3) and videos (MP4) on the Media page first. Share your store at <a href={page.store_url} target="_blank" rel="noreferrer">{page.store_url.replace('https://', '')}</a>. Chat: <code>!store</code> / <code>!store &lt;item&gt;</code>. Pause anytime without deleting your catalog.</p></div></div>
      <section className="card" aria-label="Store settings">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-sliders" /> Store settings <span className={`pill ${s.enabled && !s.paused ? 'pill-ok' : s.enabled ? 'pill-warn' : 'pill-off'}`}>{s.enabled ? (s.paused ? 'Paused' : 'Open') : 'Off'}</span></h3></div>
        <div className="ps-sws">{SW.map(([k, l, h]) => <div key={k} className="ps-swcol"><div className="ps-sw"><Switch on={!!sw[k]} label={l} onChange={(v) => setSw({ ...sw, [k]: v })} /><b>{l}</b></div><span className="muted small">{h}</span></div>)}</div>
        <div className="ps-two">
          <label className="fld"><span className="fld-label">Global cooldown (seconds)</span><input className="input" type="number" min={0} value={f.global_cooldown_seconds ?? ''} aria-invalid={!cdOk} onChange={(e) => setF({ ...f, global_cooldown_seconds: e.target.value })} /><span className={cdOk ? 'muted small' : 'mp-bad small'}>{cdOk ? 'Minimum time between store purchases for the same viewer. 0 = none.' : 'Use 0 to 86400.'}</span></label>
          <label className="fld"><span className="fld-label">Max buys per viewer per stream</span><input className="input" type="number" min={1} placeholder="Unlimited" value={f.max_purchases_per_user_per_stream ?? ''} aria-invalid={!mxOk} onChange={(e) => setF({ ...f, max_purchases_per_user_per_stream: e.target.value })} /><span className={mxOk ? 'muted small' : 'mp-bad small'}>{mxOk ? 'Leave empty for unlimited.' : 'Use 1 to 1000, or leave empty.'}</span></label>
        </div>
        <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
          <span className="muted small">Viewers see changes on their next purchase.</span>
          {dirty && <span className="pill pill-warn">Unsaved changes</span>}
          <button type="button" className="btn btn-ghost" disabled={!dirty || busy === 'set'} onClick={() => seed(s)}><i className="fas fa-rotate-left" /> Discard</button>
          <button type="button" className="btn" disabled={!dirty || busy === 'set' || !cdOk || !mxOk} onClick={() => act('set', { action: 'update_settings', ...f, ...Object.fromEntries(Object.entries(sw).filter(([, v]) => v).map(([k]) => [k, '1'])) }, loadPage)}><i className={busy === 'set' ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> Save settings</button>
        </div>
      </section>
      <section className="card" aria-label="Catalog items">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-box-open" /> Catalog items</h3><button type="button" className="btn" disabled={!list} onClick={() => setModal(null)}><i className="fas fa-plus" /> Add item</button></div>
        <div className="bp-table ps-table">
          <table>
            <thead><tr><th>Item</th><th>Type</th><th>Cost ({pn})</th><th>Reward</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody aria-busy={!list && !listErr}>
              {listErr ? <tr><td colSpan={6} className="muted bp-empty">{listErr}</td></tr>
                : !list ? Array.from({ length: 4 }, (_, i) => <tr key={i} aria-hidden="true">{[70, 50, 40, 60, 30, 80].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
                : list.items.length === 0 ? <tr><td colSpan={6} className="muted bp-empty">No store items yet. Click "Add item" to create your first reward.</td></tr>
                : list.items.map((it) => { const on = Number(it.enabled) === 1, sm = summary(it.payload_decoded)
                  return <tr key={it.id}>
                    <td data-l="Item"><b className="ps-title">{it.title}</b>{it.slug && <div className="muted small"><code>!store {it.slug}</code></div>}</td>
                    <td data-l="Type">{typeLabel(it.item_type)}</td>
                    <td data-l="Cost" className="bp-pts">{Number(it.cost).toLocaleString()}</td>
                    <td data-l="Reward" className="ps-sum" title={sm}>{sm}</td>
                    <td data-l="Status"><span className={`pill ${on ? 'pill-ok' : 'pill-off'}`}>{on ? 'Enabled' : 'Disabled'}</span></td>
                    <td><div className="ps-acts">
                      <button type="button" className="btn btn-ghost" onClick={() => setModal(it)}><i className="fas fa-pen-to-square" /> Edit</button>
                      <button type="button" className="btn btn-ghost" disabled={busy === 't' + it.id} onClick={() => act('t' + it.id, { action: 'toggle_item', item_id: String(it.id) }, loadList)}>{on ? 'Disable' : 'Enable'}</button>
                      <button type="button" className="btn btn-danger" aria-label={`Delete ${it.title}`} onClick={() => setDel(it)}><i className="fas fa-trash" /></button>
                    </div></td></tr> })}
            </tbody>
          </table>
        </div>
      </section>
      {modal !== undefined && list && <ItemModal item={modal} list={list} pn={pn} onClose={() => setModal(undefined)} onSaved={(m) => { setModal(undefined); setToast({ kind: 'ok', text: m }); loadList() }} />}
      {del && <Modal title="Delete item" icon="fas fa-trash" onClose={() => setDel(null)}>
        <p>Delete <b>{del.title}</b> from the store? This can't be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" disabled={busy === 'del'} onClick={() => { setDel(null); act('del', { action: 'delete_item', item_id: String(del.id) }, loadList) }}><i className="fas fa-trash" /> Delete</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
