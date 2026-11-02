import { useEffect, useState } from 'react'
import Combobox from '../components/Combobox'
import Modal from '../components/Modal'
import { UrlReveal } from '../components/UrlReveal'
import { get } from '../bot'
import { send, ToastBar, type Toast } from './commands/shared'

// Port of dashboard/todolist/*.php: tasks (add, edit, mark completed, remove, search and
// filter by category), categories (add, remove; Default stays) and the OBS overlay settings.
// Endpoint: public/api/todolist/todolist.php.
const API = '/api/todolist/todolist.php'
const MASK = '••••••••••••'
type Category = { id: number; name: string }
type Task = { id: number; objective: string; category: number; completed: boolean; private: boolean; created_at: number; updated_at: number }
type Obs = { font: string; color: string; list: string; shadow: boolean; bold: boolean; font_size: number; show_completed: boolean }
type Data = { success: boolean; error?: string; categories: Category[]; tasks: Task[]; obs: Obs }
type Res = { success: boolean; message?: string; error?: string }
type Tab = 'tasks' | 'categories' | 'overlay'
const FONTS = ['Arial', 'Arial Narrow', 'Verdana', 'Times New Roman'].map((f) => ({ value: f, label: f }))
const COLORS = ['Black', 'White', 'Red', 'Blue']
const LISTS = [{ value: 'Bullet', label: 'Bullet' }, { value: 'Numbered', label: 'Numbered' }]
const ctrl = /[\x00-\x1F\x7F]/
const hex = /^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/

function ago(unix: number) {
  if (!unix) return '-'
  const s = Math.max(0, Math.floor(Date.now() / 1000) - unix)
  if (s < 60) return 'just now'
  const [n, u] = s < 3600 ? [Math.floor(s / 60), 'minute'] : s < 86400 ? [Math.floor(s / 3600), 'hour'] : [Math.floor(s / 86400), 'day']
  return `${n} ${u}${n === 1 ? '' : 's'} ago`
}

async function overlayUrl() {
  const r = await get<{ success: boolean; url?: string; message?: string }>(`/api/overlays/overlays.php?${new URLSearchParams({ beta: 'url', o: 'todolist' })}`)
  if (!r.success || !r.url) throw new Error(r.message || 'Could not get the overlay URL.')
  return r.url
}

function TaskForm({ cats, init, busy, submitLabel, onSubmit, onCancel }: {
  cats: Category[]; init: { objective: string; category: number; private: boolean }; busy: boolean; submitLabel: string
  onSubmit: (v: { objective: string; category: number; private: boolean }) => Promise<boolean>; onCancel?: () => void
}) {
  const [objective, setObjective] = useState(init.objective), [category, setCategory] = useState(String(init.category)), [priv, setPriv] = useState(init.private), [touched, setTouched] = useState(false)
  const bad = !objective.trim() || objective.trim().length > 255 || ctrl.test(objective) ? 'Enter a task of 1 to 255 characters.' : ''
  async function submit(e: React.FormEvent) {
    e.preventDefault(); setTouched(true); if (bad) return
    if (await onSubmit({ objective: objective.trim(), category: +category, private: priv }) && !onCancel) { setObjective(''); setPriv(false); setTouched(false) }
  }
  return (
    <form className="cr-form" onSubmit={submit}>
      <label className="fld"><span className="fld-label">Task</span><input className="input" maxLength={255} value={objective} data-autofocus={onCancel ? true : undefined} onChange={(e) => { setObjective(e.target.value.replace(/[\r\n]/g, '')); setTouched(true) }} /></label>
      <div className="fld"><span className="fld-label">Category</span><Combobox label="Category" searchable={cats.length > 8} value={category} onChange={setCategory} options={cats.map((c) => ({ value: String(c.id), label: c.name }))} /></div>
      <label className="cr-chk"><input type="checkbox" checked={priv} onChange={(e) => setPriv(e.target.checked)} /> <span>Private (hidden from the overlay)</span></label>
      {touched && bad && <p className="mp-bad small" role="alert">{bad}</p>}
      <div className={onCancel ? 'modal-acts' : 'rf-acts'}>
        {onCancel && <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>}
        <button className="btn" type="submit" disabled={busy || (touched && !!bad)}><i className={busy ? 'fas fa-spinner fa-spin' : onCancel ? 'fas fa-save' : 'fas fa-plus'} /> {submitLabel}</button>
      </div>
    </form>
  )
}

function ObsForm({ obs, busy, onSave }: { obs: Obs; busy: boolean; onSave: (o: Obs) => void }) {
  const [o, setO] = useState(obs)
  const [custom, setCustom] = useState(!COLORS.includes(obs.color))
  const set = <K extends keyof Obs>(k: K, v: Obs[K]) => setO({ ...o, [k]: v })
  const bad = !(COLORS.includes(o.color) || hex.test(o.color)) ? 'Colour must be a hex code like #ff8800.'
    : !(Number.isInteger(o.font_size) && o.font_size >= 8 && o.font_size <= 72) ? 'Font size must be 8 to 72.' : ''
  return (
    <form className="cr-form" onSubmit={(e) => { e.preventDefault(); if (!bad) onSave(o) }}>
      <div className="fld"><span className="fld-label">Font</span><Combobox label="Font" searchable={false} value={o.font} onChange={(v) => set('font', v)} options={FONTS} /></div>
      <div className="fld"><span className="fld-label">Font colour</span>
        <Combobox label="Font colour" searchable={false} value={custom ? 'custom' : o.color} onChange={(v) => { if (v === 'custom') { setCustom(true); set('color', hex.test(o.color) ? o.color : '#ffffff') } else { setCustom(false); set('color', v) } }}
          options={[...COLORS.map((c) => ({ value: c, label: c })), { value: 'custom', label: 'Custom colour' }]} />
        {custom && <div className="pl-choice"><input type="color" aria-label="Pick a colour" value={hex.test(o.color) && o.color.length === 7 ? o.color : '#ffffff'} onChange={(e) => set('color', e.target.value)} />
          <input className="input" aria-label="Hex colour" maxLength={7} value={o.color} onChange={(e) => set('color', e.target.value.trim())} /></div>}</div>
      <div className="fld"><span className="fld-label">List style</span><Combobox label="List style" searchable={false} value={o.list} onChange={(v) => set('list', v)} options={LISTS} /></div>
      <label className="fld rf-num"><span className="fld-label">Font size (px)</span><input className="input" type="number" inputMode="numeric" min={8} max={72} value={Number.isNaN(o.font_size) ? '' : o.font_size} onChange={(e) => set('font_size', parseInt(e.target.value, 10))} /></label>
      <label className="cr-chk"><input type="checkbox" checked={o.shadow} onChange={(e) => set('shadow', e.target.checked)} /> <span>Text shadow</span></label>
      <label className="cr-chk"><input type="checkbox" checked={o.bold} onChange={(e) => set('bold', e.target.checked)} /> <span>Bold text</span></label>
      <label className="cr-chk"><input type="checkbox" checked={o.show_completed} onChange={(e) => set('show_completed', e.target.checked)} /> <span>Show completed tasks</span></label>
      {bad && <p className="mp-bad small" role="alert">{bad}</p>}
      <div className="rf-acts"><button className="btn" type="submit" disabled={busy || !!bad}><i className={busy ? 'fas fa-spinner fa-spin' : 'fas fa-save'} /> Save overlay settings</button></div>
    </form>
  )
}

export default function TodoList() {
  const [data, setData] = useState<Data | null>(null), [err, setErr] = useState('')
  const [tab, setTab] = useState<Tab>('tasks'), [q, setQ] = useState(''), [filter, setFilter] = useState('all')
  const [busy, setBusy] = useState(''), [toast, setToast] = useState<Toast>(null)
  const [editing, setEditing] = useState<Task | null>(null), [removing, setRemoving] = useState<Task | null>(null), [removingCat, setRemovingCat] = useState<Category | null>(null)
  const [newCat, setNewCat] = useState('')
  const load = () => get<Data>(API).then((r) => { if (!r.success) throw new Error(r.error); setData(r); setErr('') }).catch((x) => setErr((x as Error).message || 'Could not load your to do list.'))
  useEffect(() => { load() }, [])
  async function act(k: string, body: Record<string, string>) {
    setBusy(k)
    try { const r = await send<Res>(API, body, true); if (!r.success) throw new Error(r.error || 'Something went wrong. Please try again.'); setToast({ kind: 'ok', text: r.message || 'Done.' }); await load(); return true }
    catch (x) { setToast({ kind: 'err', text: (x as Error).message }); return false } finally { setBusy('') }
  }
  const cats = data?.categories ?? []
  const catName = (id: number) => cats.find((c) => c.id === id)?.name ?? 'Unknown'
  const needle = q.trim().toLowerCase()
  const tasks = (data?.tasks ?? []).filter((t) => (filter === 'all' || String(t.category) === filter) && (!needle || t.objective.toLowerCase().includes(needle)))
  const catBad = !newCat.trim() ? '' : newCat.trim().length > 255 || ctrl.test(newCat) ? 'Enter a category name of 1 to 255 characters.' : cats.some((c) => c.name.toLowerCase() === newCat.trim().toLowerCase()) ? 'That category already exists.' : ''
  const tabs: [Tab, string, string][] = [['tasks', 'Tasks', 'fas fa-list-check'], ['categories', 'Categories', 'fas fa-tags'], ['overlay', 'Overlay', 'fas fa-display']]
  return (
    <div className="page cmds mp rf td">
      <div className="pg-head"><h2><i className="fas fa-list-check" /> To Do List</h2></div>
      <div className="seg mp-tabs" role="tablist" aria-label="To Do List sections">
        {tabs.map(([id, label, icon]) => <button key={id} type="button" role="tab" aria-selected={tab === id} className={tab === id ? 'on' : ''} onClick={() => setTab(id)}><i className={icon} /> {label}</button>)}
      </div>
      {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}

      {tab === 'tasks' && <>
        <section className="card" aria-label="Add a task">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-plus" /> Add a task</h3></div>
          {!data ? <span className="bp-skel" style={{ width: '60%' }} />
            : <TaskForm cats={cats} init={{ objective: '', category: cats[0]?.id ?? 1, private: false }} busy={busy === 'add'} submitLabel="Add task"
              onSubmit={(v) => act('add', { action: 'add', objective: v.objective, category: String(v.category), private: v.private ? '1' : '0' })} />}
        </section>
        <section className="card" aria-label="Tasks">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list-check" /> Tasks</h3></div>
          <div className="cr-form td-filters">
            <label className="fld"><span className="fld-label">Search</span><input className="input" type="search" placeholder="Search tasks" value={q} onChange={(e) => setQ(e.target.value)} /></label>
            <div className="fld"><span className="fld-label">Category</span><Combobox label="Filter by category" searchable={cats.length > 8} value={filter} onChange={setFilter} options={[{ value: 'all', label: 'All categories' }, ...cats.map((c) => ({ value: String(c.id), label: c.name }))]} /></div>
          </div>
          <div className="bp-table"><table>
            <thead><tr><th>Task</th><th>Category</th><th>Status</th><th>Created</th><th>Updated</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody aria-busy={!data && !err}>{err && !data ? <tr><td colSpan={6} className="mp-bad bp-empty">{err}</td></tr>
              : !data ? Array.from({ length: 3 }, (_, i) => <tr key={i} aria-hidden="true">{[70, 40, 30, 40, 40, 30].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
              : tasks.length === 0 ? <tr><td colSpan={6} className="muted bp-empty">{data.tasks.length ? 'No tasks match your search.' : 'No tasks yet. Add one above.'}</td></tr>
              : tasks.map((t) => <tr key={t.id}>
                <td data-l="Task" className="cr-name">{t.completed ? <s>{t.objective}</s> : t.objective}{t.private && <> <span className="pill" title="Hidden from the overlay"><i className="fas fa-lock" /> Private</span></>}</td>
                <td data-l="Category">{catName(t.category)}</td>
                <td data-l="Status">{t.completed ? <span className="pill pill-live">Completed</span> : <span className="muted">Not completed</span>}</td>
                <td data-l="Created" title={new Date(t.created_at * 1000).toLocaleString()}>{ago(t.created_at)}</td>
                <td data-l="Updated" title={new Date(t.updated_at * 1000).toLocaleString()}>{ago(t.updated_at)}</td>
                <td data-l="Actions"><div className="rf-acts">
                  {!t.completed && <button type="button" className="btn btn-ok" disabled={busy === `c${t.id}`} onClick={() => act(`c${t.id}`, { action: 'complete', id: String(t.id) })}><i className={busy === `c${t.id}` ? 'fas fa-spinner fa-spin' : 'fas fa-check'} /> Mark completed</button>}
                  <button type="button" className="btn btn-ghost" aria-label={`Edit ${t.objective}`} onClick={() => setEditing(t)}><i className="fas fa-pen" /> Edit</button>
                  <button type="button" className="btn btn-danger" aria-label={`Remove ${t.objective}`} onClick={() => setRemoving(t)}><i className="fas fa-trash" /> Remove</button>
                </div></td>
              </tr>)}</tbody></table></div>
        </section>
      </>}

      {tab === 'categories' && <>
        <section className="card" aria-label="Add a category">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-plus" /> Add a category</h3></div>
          <form className="cr-form" onSubmit={async (e) => { e.preventDefault(); if (!newCat.trim() || catBad) return; if (await act('cat', { action: 'add_category', category: newCat.trim() })) setNewCat('') }}>
            <label className="fld"><span className="fld-label">Category name</span><input className="input" maxLength={255} value={newCat} onChange={(e) => setNewCat(e.target.value.replace(/[\r\n]/g, ''))} /></label>
            {catBad && <p className="mp-bad small" role="alert">{catBad}</p>}
            <div className="rf-acts"><button className="btn" type="submit" disabled={busy === 'cat' || !newCat.trim() || !!catBad}><i className={busy === 'cat' ? 'fas fa-spinner fa-spin' : 'fas fa-plus'} /> Add category</button></div>
          </form>
        </section>
        <section className="card" aria-label="Categories">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-tags" /> Categories</h3></div>
          <p className="muted">The ID is what the overlay's <code>&amp;category=</code> option uses.</p>
          <div className="bp-table"><table>
            <thead><tr><th>ID</th><th>Category</th><th>Tasks</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody aria-busy={!data && !err}>{!data ? Array.from({ length: 2 }, (_, i) => <tr key={i} aria-hidden="true">{[20, 60, 20, 30].map((w, j) => <td key={j}><span className="bp-skel" style={{ width: `${w}%` }} /></td>)}</tr>)
              : cats.length === 0 ? <tr><td colSpan={4} className="muted bp-empty">No categories yet.</td></tr>
              : cats.map((c) => <tr key={c.id}>
                <td data-l="ID">{c.id}</td>
                <td data-l="Category" className="cr-name">{c.name}</td>
                <td data-l="Tasks">{data.tasks.filter((t) => t.category === c.id).length}</td>
                <td data-l="Actions">{c.id === 1 ? <span className="muted small">Default, can't be removed</span>
                  : <button type="button" className="btn btn-danger" aria-label={`Remove ${c.name}`} onClick={() => setRemovingCat(c)}><i className="fas fa-trash" /> Remove</button>}</td>
              </tr>)}</tbody></table></div>
        </section>
      </>}

      {tab === 'overlay' && <>
        <section className="card" aria-label="Overlay settings">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-palette" /> Overlay settings</h3></div>
          {!data ? <span className="bp-skel" style={{ width: '60%' }} /> : <ObsForm obs={data.obs} busy={busy === 'obs'} onSave={(o) => act('obs', {
            action: 'save_obs', font: o.font, color: o.color, list: o.list, font_size: String(o.font_size),
            shadow: o.shadow ? '1' : '0', bold: o.bold ? '1' : '0', show_completed: o.show_completed ? '1' : '0',
          })} />}
        </section>
        <section className="card" aria-label="Add to OBS">
          <div className="card-title-row"><h3 className="card-h"><i className="fas fa-display" /> Add to OBS</h3></div>
          <p className="muted">Add a Browser Source in OBS with this URL. Private tasks never show on the overlay.</p>
          <UrlReveal name="To Do List overlay" mask={`https://overlay.botofthespecter.com/todolist.php?code=${MASK}`} toast={setToast} fetchUrl={overlayUrl} />
          <ul className="muted small">
            <li>Add <code>&amp;category=1</code> to show one category, or <code>&amp;category=1,3</code> for several. The IDs are on the Categories tab.</li>
            <li>Add <code>&amp;theme=true</code> to use the overlay's built-in theme.</li>
          </ul>
        </section>
      </>}

      {editing && <Modal title="Edit task" icon="fas fa-pen" onClose={() => setEditing(null)}>
        <TaskForm cats={cats} init={editing} busy={busy === 'edit'} submitLabel="Save" onCancel={() => setEditing(null)}
          onSubmit={async (v) => { const ok = await act('edit', { action: 'edit', id: String(editing.id), objective: v.objective, category: String(v.category), private: v.private ? '1' : '0' }); if (ok) setEditing(null); return ok }} />
      </Modal>}
      {removing && <Modal title="Remove task" icon="fas fa-trash" onClose={() => setRemoving(null)}>
        <p>Remove <b>{removing.objective}</b>? This can't be undone.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setRemoving(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => { const id = removing.id; setRemoving(null); act('del', { action: 'delete', id: String(id) }) }}><i className="fas fa-trash" /> Remove</button></div>
      </Modal>}
      {removingCat && <Modal title="Remove category" icon="fas fa-trash" onClose={() => setRemovingCat(null)}>
        <p>Remove <b>{removingCat.name}</b>? Its tasks move to Default.</p>
        <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setRemovingCat(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => { const id = removingCat.id; setRemovingCat(null); act('delcat', { action: 'delete_category', id: String(id) }) }}><i className="fas fa-trash" /> Remove</button></div>
      </Modal>}
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
