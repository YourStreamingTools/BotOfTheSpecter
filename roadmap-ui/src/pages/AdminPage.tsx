import { useEffect, useMemo, useState, type FormEvent } from 'react'
import ItemModal from '../components/ItemModal'
import {
  apiGet,
  apiPost,
  CAT_ICON,
  PRIO_TAG,
  subTag,
  sydneyDate,
  type ItemsPayload,
  type RoadmapItem,
  type RoadmapSession,
  type Subcat,
} from '../api'

const NEXT: Record<string, { label: string; icon: string; btn: string; category?: string; status?: string }> = {
  REQUESTS: { label: 'Start Work', icon: 'play', btn: 'sp-btn-info', category: 'IN PROGRESS' },
  'IN PROGRESS': { label: 'Send to Beta', icon: 'flask', btn: 'sp-btn-primary', category: 'BETA TESTING' },
  'BETA TESTING': { label: 'Complete', icon: 'check', btn: 'sp-btn-success', status: 'completed' },
  REJECTED: { label: 'Restore', icon: 'rotate-left', btn: 'sp-btn-warning', category: 'REQUESTS' },
}

export default function AdminPage({ session }: { session: RoadmapSession }) {
  const [data, setData] = useState<ItemsPayload | null>(null)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('')
  const [showRejected, setShowRejected] = useState(false)
  const [open, setOpen] = useState<RoadmapItem | null>(null)
  const [edit, setEdit] = useState<RoadmapItem | null>(null)
  const [flash, setFlash] = useState<{ type: string; msg: string } | null>(null)
  const [title, setTitle] = useState('')
  const [desc, setDesc] = useState('')
  const [addCat, setAddCat] = useState('REQUESTS')
  const [addPrio, setAddPrio] = useState('MEDIUM')
  const [addSubs, setAddSubs] = useState<string[]>([])
  const [addWeb, setAddWeb] = useState<string[]>([])
  const [files, setFiles] = useState<File[]>([])
  const [newSub, setNewSub] = useState('')
  const [newColor, setNewColor] = useState('light')

  function load() {
    const q = new URLSearchParams()
    if (search) q.set('search', search)
    if (category) q.set('category', category)
    apiGet('/api/items.php' + (q.toString() ? '?' + q.toString() : '')).then(setData)
  }
  useEffect(() => { load() }, [])
  useEffect(() => {
    if (data?.subcategories?.length && addSubs.length === 0) {
      setAddSubs([data.subcategories[0].name])
    }
  }, [data, addSubs.length])

  const colors = data?.subcat_colors || {}
  const byCat = useMemo(() => {
    const map: Record<string, RoadmapItem[]> = {}
    for (const cat of data?.categories || []) map[cat] = []
    for (const it of data?.items || []) (map[it.category] ||= []).push(it)
    return map
  }, [data])

  async function admin(body: Record<string, unknown>) {
    const res = await apiPost('/api/admin.php', { ...body, csrf_token: session.csrf_token })
    if (res?.ok) {
      setFlash({ type: 'success', msg: res.message || 'Saved.' })
      load()
    } else {
      setFlash({ type: 'danger', msg: res?.error || 'Request failed.' })
    }
    return res
  }

  async function addItem(e: FormEvent) {
    e.preventDefault()
    const res = await admin({
      action: 'add',
      title,
      description: desc,
      category: addCat,
      priority: addPrio,
      subcategory: addSubs,
      website_type: addWeb,
    })
    if (res?.ok && res.id && files.length) {
      for (const file of files) {
        const fd = new FormData()
        fd.append('file', file)
        fd.append('item_id', String(res.id))
        fd.append('csrf_token', session.csrf_token)
        await fetch('/admin/upload-attachment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      }
    }
    if (res?.ok) {
      setTitle(''); setDesc(''); setFiles([])
    }
  }

  async function saveEdit(e: FormEvent) {
    e.preventDefault()
    if (!edit) return
    await admin({
      action: 'edit_item',
      id: edit.id,
      title: edit.title,
      description: edit.description,
      category: edit.category,
      priority: edit.priority,
      subcategory: edit.subcategories,
      website_type: edit.website_types,
    })
    setEdit(null)
  }

  return (
    <>
      {flash && (
        <div className={'sp-alert sp-alert-' + flash.type}>
          <i className={'fa-solid ' + (flash.type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark')} />
          <span>{flash.msg}</span>
        </div>
      )}
      <div className="sp-page-header-row">
        <div>
          <h1 className="sp-page-title">Roadmap Admin</h1>
          <p className="sp-page-subtitle">Create, move, and update roadmap items</p>
        </div>
        <a href="/index.php" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-map" /> Public board</a>
      </div>

      <div className="sp-card" style={{ marginBottom: '1.5rem' }}>
        <form onSubmit={(e) => { e.preventDefault(); load() }}>
          <div className="sp-card-body" style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
            <input className="sp-input" placeholder="Search title…" value={search} onChange={(e) => setSearch(e.target.value)} />
            <select className="sp-select" value={category} onChange={(e) => setCategory(e.target.value)}>
              <option value="">All Categories</option>
              {(data?.categories || []).map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
            <button type="submit" className="sp-btn sp-btn-primary"><i className="fa-solid fa-magnifying-glass" /> Filter</button>
          </div>
        </form>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: '0.75rem' }}>
        <button type="button" className="sp-btn sp-btn-ghost sp-btn-sm" onClick={() => setShowRejected((v) => !v)}>
          <i className={'fa-solid ' + (showRejected ? 'fa-eye-slash' : 'fa-eye')} /> {showRejected ? 'Hide Rejected' : 'Show Rejected'}
        </button>
      </div>
      <div className="rm-board">
        {(data?.categories || []).map((cat) => (
          <div key={cat} className="rm-column" style={cat === 'REJECTED' && !showRejected ? { display: 'none' } : undefined}>
            <div className="rm-column-head">
              <span><i className={'fa-solid fa-' + (CAT_ICON[cat] || 'folder')} style={{ marginRight: '0.4rem' }} />{cat}</span>
              <span className="sp-badge">{(byCat[cat] || []).length}</span>
            </div>
            <div className="rm-column-body">
              {(byCat[cat] || []).map((item) => (
                <AdminCard
                  key={item.id}
                  item={item}
                  colors={colors}
                  categories={data?.categories || []}
                  onOpen={() => setOpen(item)}
                  onEdit={() => setEdit({ ...item })}
                  onAction={(body) => admin(body)}
                />
              ))}
            </div>
          </div>
        ))}
      </div>

      <details className="rm-admin-panel" open>
        <summary className="rm-admin-panel-summary">
          <span><i className="fa-solid fa-plus" /> Add New Roadmap Item</span>
        </summary>
        <div className="sp-card rm-admin-panel-body">
          <div className="sp-card-body">
            <form onSubmit={addItem}>
              <div className="rm-form-cols">
                <div className="rm-admin-form-col">
                  <div className="sp-form-group">
                    <label className="sp-label">Title</label>
                    <input className="sp-input" required value={title} onChange={(e) => setTitle(e.target.value)} />
                  </div>
                  <div className="sp-form-group">
                    <label className="sp-label">Description</label>
                    <textarea className="sp-textarea sp-textarea-mono" rows={7} value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="Optional — supports markdown" />
                  </div>
                  <div className="sp-form-group">
                    <label className="sp-label">Attachments (optional)</label>
                    <input type="file" multiple onChange={(e) => setFiles(Array.from(e.target.files || []))} />
                  </div>
                </div>
                <div className="rm-admin-form-col">
                  <div className="sp-form-group">
                    <label className="sp-label">Category</label>
                    <select className="sp-select" value={addCat} onChange={(e) => setAddCat(e.target.value)}>
                      {(data?.categories || []).map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </div>
                  <TagPicker label="Subcategory" options={(data?.subcategories || []).map((s) => s.name)} values={addSubs} onChange={setAddSubs} />
                  {addSubs.includes('WEBSITE') && (
                    <TagPicker label="Website Type" options={['DASHBOARD', 'OVERLAYS']} values={addWeb} onChange={setAddWeb} />
                  )}
                  <div className="sp-form-group">
                    <label className="sp-label">Priority</label>
                    <select className="sp-select" value={addPrio} onChange={(e) => setAddPrio(e.target.value)}>
                      {['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                  </div>
                  <button type="submit" className="sp-btn sp-btn-primary"><i className="fa-solid fa-floppy-disk" /> Add Item</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </details>

      <details className="rm-admin-panel">
        <summary className="rm-admin-panel-summary">
          <span><i className="fa-solid fa-tags" /> Manage Subcategories</span>
        </summary>
        <div className="sp-card rm-admin-panel-body">
          <div className="sp-card-body">
            <div className="rm-admin-subcat-list">
              {(data?.subcategories || []).map((s: Subcat) => (
                <div className="rm-admin-subcat-chip" key={s.name}>
                  <span className={'rm-tag rm-tag-' + s.color}>{s.name}</span>
                  <button type="button" className="sp-btn sp-btn-danger sp-btn-sm sp-btn-icon" onClick={() => {
                    if (confirm('Remove subcategory "' + s.name + '"?')) admin({ action: 'remove_subcategory', subcategory_name: s.name })
                  }}><i className="fa-solid fa-xmark" /></button>
                </div>
              ))}
            </div>
            <form className="rm-admin-subcat-add-form" onSubmit={(e) => { e.preventDefault(); admin({ action: 'add_subcategory', subcategory_name: newSub, subcategory_color: newColor }); setNewSub('') }}>
              <div className="sp-form-group rm-admin-subcat-field">
                <label className="sp-label">New Subcategory</label>
                <input className="sp-input" value={newSub} onChange={(e) => setNewSub(e.target.value)} required placeholder="e.g. MOBILE APP" />
              </div>
              <div className="sp-form-group rm-admin-subcat-field">
                <label className="sp-label">Color</label>
                <select className="sp-select" value={newColor} onChange={(e) => setNewColor(e.target.value)}>
                  <option value="primary">Primary (Purple)</option>
                  <option value="info">Info (Blue)</option>
                  <option value="success">Success (Green)</option>
                  <option value="warning">Warning (Yellow)</option>
                  <option value="danger">Danger (Red)</option>
                  <option value="light">Light (Gray)</option>
                </select>
              </div>
              <button type="submit" className="sp-btn sp-btn-primary sp-btn-sm"><i className="fa-solid fa-plus" /> Add</button>
            </form>
          </div>
        </div>
      </details>

      {edit && (
        <div className="rm-modal open">
          <div className="rm-modal-backdrop" onClick={() => setEdit(null)} />
          <div className="rm-modal-card rm-modal-card-lg">
            <div className="rm-modal-head">
              <span className="rm-modal-title">Edit Roadmap Item</span>
              <button type="button" className="rm-modal-close" onClick={() => setEdit(null)}><i className="fa-solid fa-xmark" /></button>
            </div>
            <form onSubmit={saveEdit}>
              <div className="rm-modal-body">
                <div className="sp-form-group">
                  <label className="sp-label">Title</label>
                  <input className="sp-input" required value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} />
                </div>
                <div className="sp-form-group">
                  <label className="sp-label">Description</label>
                  <textarea className="sp-textarea sp-textarea-mono" rows={6} value={edit.description} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                  <div className="sp-form-group">
                    <label className="sp-label">Category</label>
                    <select className="sp-select" value={edit.category} onChange={(e) => setEdit({ ...edit, category: e.target.value })}>
                      {(data?.categories || []).map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </div>
                  <div className="sp-form-group">
                    <label className="sp-label">Priority</label>
                    <select className="sp-select" value={edit.priority} onChange={(e) => setEdit({ ...edit, priority: e.target.value })}>
                      {['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                  </div>
                </div>
                <TagPicker label="Subcategory" options={(data?.subcategories || []).map((s) => s.name)} values={edit.subcategories} onChange={(v) => setEdit({ ...edit, subcategories: v })} />
                {edit.subcategories.includes('WEBSITE') && (
                  <TagPicker label="Website Type" options={['DASHBOARD', 'OVERLAYS']} values={edit.website_types} onChange={(v) => setEdit({ ...edit, website_types: v })} />
                )}
              </div>
              <div className="rm-modal-foot">
                <button type="button" className="sp-btn sp-btn-ghost" onClick={() => setEdit(null)}>Cancel</button>
                <button type="submit" className="sp-btn sp-btn-primary">Save</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {open && <ItemModal item={open} colors={colors} session={session} admin onClose={() => setOpen(null)} />}
    </>
  )
}

function TagPicker({ label, options, values, onChange }: { label: string; options: string[]; values: string[]; onChange: (v: string[]) => void }) {
  return (
    <div className="sp-form-group">
      <label className="sp-label">{label}</label>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.35rem' }}>
        {options.map((opt) => {
          const on = values.includes(opt)
          return (
            <button
              key={opt}
              type="button"
              className={'sp-btn sp-btn-sm ' + (on ? 'sp-btn-primary' : 'sp-btn-ghost')}
              onClick={() => onChange(on ? values.filter((v) => v !== opt) : [...values, opt])}
            >
              {opt}
            </button>
          )
        })}
      </div>
    </div>
  )
}

function AdminCard({
  item, colors, categories, onOpen, onEdit, onAction,
}: {
  item: RoadmapItem
  colors: Record<string, string>
  categories: string[]
  onOpen: () => void
  onEdit: () => void
  onAction: (body: Record<string, unknown>) => void
}) {
  const next = NEXT[item.category]
  return (
    <div className={'rm-card rm-admin-card rm-card-' + item.priority.toLowerCase()}>
      <div className="rm-card-title">{item.title}</div>
      <div className="rm-card-meta">Created {sydneyDate(item.created_at)}{item.updated_at ? ' · Updated ' + sydneyDate(item.updated_at) : ''}</div>
      <div className="rm-card-tags">
        {item.subcategories.map((s) => <span key={s} className={'rm-tag rm-tag-' + subTag(s, colors)}>{s}</span>)}
        {item.website_types.map((w) => <span key={w} className="rm-tag rm-tag-info">{w}</span>)}
        <span className={'rm-tag rm-tag-' + (PRIO_TAG[item.priority] || 'light')}>{item.priority}</span>
      </div>
      {next && (
        <div className="rm-admin-primary-action">
          <button
            type="button"
            className={'sp-btn ' + next.btn + ' sp-btn-sm sp-btn-full'}
            onClick={() => onAction(next.status === 'completed'
              ? { action: 'update', id: item.id, status: 'completed' }
              : { action: 'update', id: item.id, category: next.category })}
          >
            <i className={'fa-solid fa-' + next.icon} /> {next.label}
          </button>
        </div>
      )}
      <div className="rm-admin-card-actions">
        <button type="button" className="sp-btn sp-btn-secondary sp-btn-sm" onClick={onEdit}><i className="fa-solid fa-pen" /> Edit</button>
        <button type="button" className="sp-btn sp-btn-ghost sp-btn-sm" onClick={onOpen}><i className="fa-solid fa-circle-info" /> Details</button>
        <select className="sp-select sp-select-sm" defaultValue="" onChange={(e) => {
          const val = e.target.value
          if (!val) return
          if (val === 'DELETE') {
            if (confirm('Delete this item?')) onAction({ action: 'delete', id: item.id })
          } else {
            onAction({ action: 'update', id: item.id, category: val })
          }
          e.target.value = ''
        }}>
          <option value="">Move…</option>
          {categories.filter((c) => c !== item.category).map((c) => <option key={c} value={c}>{c}</option>)}
          <option value="DELETE">Delete</option>
        </select>
      </div>
    </div>
  )
}
