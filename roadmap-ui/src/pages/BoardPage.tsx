import { useEffect, useMemo, useState } from 'react'
import ItemModal from '../components/ItemModal'
import {
  apiGet,
  CAT_ICON,
  PRIO_TAG,
  subTag,
  sydneyDate,
  type ItemsPayload,
  type RoadmapItem,
  type RoadmapSession,
} from '../api'

export default function BoardPage({ session }: { session: RoadmapSession }) {
  const params = new URLSearchParams(window.location.search)
  const [search, setSearch] = useState(params.get('search') || '')
  const [category, setCategory] = useState(params.get('category') || '')
  const [data, setData] = useState<ItemsPayload | null>(null)
  const [showRejected, setShowRejected] = useState(false)
  const [legend, setLegend] = useState(false)
  const [open, setOpen] = useState<RoadmapItem | null>(null)

  function load(s = search, c = category) {
    const q = new URLSearchParams()
    if (s) q.set('search', s)
    if (c) q.set('category', c)
    apiGet('/api/items.php' + (q.toString() ? '?' + q.toString() : '')).then(setData)
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    const id = parseInt(params.get('item') || '', 10)
    if (!id || !data?.items) return
    const found = data.items.find((it) => it.id === id)
    if (found) setOpen(found)
  }, [data])

  const filtered = !!(search || category)
  const byCat = useMemo(() => {
    const map: Record<string, RoadmapItem[]> = {}
    for (const cat of data?.categories || []) map[cat] = []
    for (const it of data?.items || []) {
      (map[it.category] ||= []).push(it)
    }
    return map
  }, [data])

  function applyFilters(e?: { preventDefault: () => void }) {
    e?.preventDefault()
    const u = new URL(window.location.href)
    if (search) u.searchParams.set('search', search); else u.searchParams.delete('search')
    if (category) u.searchParams.set('category', category); else u.searchParams.delete('category')
    history.replaceState({}, '', u.toString())
    load(search, category)
  }

  const colors = data?.subcat_colors || {}

  return (
    <>
      <div className="sp-page-header-row">
        <div>
          <h1 className="sp-page-title">BotOfTheSpecter Roadmap</h1>
          <p className="sp-page-subtitle">View our development progress and upcoming features</p>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          <button type="button" className="sp-btn sp-btn-secondary" onClick={() => setLegend(true)}>
            <i className="fa-solid fa-circle-info" /> Legend
          </button>
          {session.is_admin && (
            <a href="/admin/index.php" className="sp-btn sp-btn-primary">
              <i className="fa-solid fa-screwdriver-wrench" /> Admin Panel
            </a>
          )}
        </div>
      </div>

      <div className="sp-card" style={{ marginBottom: '1.5rem' }}>
        <form onSubmit={applyFilters}>
          <div className="sp-card-body">
            <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <div style={{ flex: 2, minWidth: 200 }}>
                <label className="sp-label">Search</label>
                <div className="sp-input-group">
                  <input className="sp-input" type="text" placeholder="Search roadmap items by title..." value={search} onChange={(e) => setSearch(e.target.value)} />
                  <button type="submit" className="sp-btn sp-btn-primary"><i className="fa-solid fa-magnifying-glass" /> Search</button>
                </div>
              </div>
              <div style={{ flex: 1, minWidth: 160 }}>
                <label className="sp-label">Category</label>
                <select className="sp-select" value={category} onChange={(e) => { setCategory(e.target.value); setTimeout(() => load(search, e.target.value), 0) }}>
                  <option value="">All Categories</option>
                  {(data?.categories || []).map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
              {filtered && (
                <a href="/index.php" className="sp-btn sp-btn-ghost sp-btn-sm" style={{ marginTop: '1.5rem' }}><i className="fa-solid fa-xmark" /> Clear</a>
              )}
            </div>
          </div>
        </form>
      </div>

      {filtered ? (
        <div className="sp-card">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1rem' }}>
            <h2 style={{ fontSize: '1rem', fontWeight: 600 }}>
              <i className="fa-solid fa-filter" style={{ color: 'var(--accent-hover)', marginRight: '0.4rem' }} />
              Search Results {search ? `for “${search}”` : ''} {category ? `in ${category}` : ''}
            </h2>
            <span style={{ fontSize: '0.875rem', color: 'var(--text-muted)' }}>
              <strong style={{ color: 'var(--text-primary)' }}>{data?.items.length || 0}</strong> result{(data?.items.length || 0) !== 1 ? 's' : ''} found
            </span>
          </div>
          {!data?.items.length ? (
            <div className="sp-alert sp-alert-warning"><i className="fa-solid fa-triangle-exclamation" /> No roadmap items found matching your search criteria.</div>
          ) : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: '0.75rem' }}>
              {data.items.map((item) => (
                <Card key={item.id} item={item} colors={colors} onOpen={() => setOpen(item)} />
              ))}
            </div>
          )}
        </div>
      ) : (
        <>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: '0.75rem' }}>
            <button type="button" className="sp-btn sp-btn-ghost sp-btn-sm" onClick={() => setShowRejected((v) => !v)}>
              <i className={'fa-solid ' + (showRejected ? 'fa-eye-slash' : 'fa-eye')} /> {showRejected ? 'Hide Rejected' : 'Show Rejected'}
            </button>
          </div>
          <div className="rm-board">
            {(data?.categories || []).map((cat) => (
              <div key={cat} className="rm-column" data-category={cat} style={cat === 'REJECTED' && !showRejected ? { display: 'none' } : undefined}>
                <div className="rm-column-head">
                  <span><i className={'fa-solid fa-' + (CAT_ICON[cat] || 'folder')} style={{ marginRight: '0.4rem' }} />{cat}</span>
                  <span className="sp-badge">{(byCat[cat] || []).length}</span>
                </div>
                <div className="rm-column-body">
                  {!(byCat[cat] || []).length ? <div className="rm-empty-state">No items</div> : (byCat[cat] || []).map((item) => (
                    <Card key={item.id} item={item} colors={colors} onOpen={() => setOpen(item)} />
                  ))}
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {legend && (
        <div className="rm-modal open">
          <div className="rm-modal-backdrop" onClick={() => setLegend(false)} />
          <div className="rm-modal-card rm-modal-card-sm">
            <div className="rm-modal-head">
              <span className="rm-modal-title"><i className="fa-solid fa-circle-info" style={{ color: 'var(--accent-hover)', marginRight: '0.4rem' }} />Legend</span>
              <button type="button" className="rm-modal-close" onClick={() => setLegend(false)}><i className="fa-solid fa-xmark" /></button>
            </div>
            <div className="rm-modal-body">
              <div className="rm-legend-grid">
                <div className="rm-legend-group">
                  <h4>Priority Levels</h4>
                  <div className="rm-legend-items">
                    <span className="rm-tag rm-tag-success">Low</span>
                    <span className="rm-tag rm-tag-info">Medium</span>
                    <span className="rm-tag rm-tag-warning">High</span>
                    <span className="rm-tag rm-tag-danger">Critical</span>
                  </div>
                </div>
                <div className="rm-legend-group">
                  <h4>Subcategories</h4>
                  <div className="rm-legend-items">
                    {(data?.subcategories || []).map((s) => (
                      <span key={s.name} className={'rm-tag rm-tag-' + s.color}>{s.name}</span>
                    ))}
                  </div>
                </div>
              </div>
            </div>
            <div className="rm-modal-foot">
              <button type="button" className="sp-btn sp-btn-secondary" onClick={() => setLegend(false)}>Close</button>
            </div>
          </div>
        </div>
      )}

      {open && (
        <ItemModal item={open} colors={colors} session={session} admin={session.is_admin} onClose={() => setOpen(null)} />
      )}
    </>
  )
}

function Card({ item, colors, onOpen }: { item: RoadmapItem; colors: Record<string, string>; onOpen: () => void }) {
  return (
    <div className={'rm-card rm-card-' + item.priority.toLowerCase()}>
      <div className="rm-card-title">{item.title}</div>
      <div className="rm-card-meta">{sydneyDate(item.created_at)}</div>
      <div className="rm-card-tags">
        {item.subcategories.map((s) => <span key={s} className={'rm-tag rm-tag-' + subTag(s, colors)}>{s}</span>)}
        {item.website_types.map((w) => <span key={w} className="rm-tag rm-tag-info">{w}</span>)}
        <span className={'rm-tag rm-tag-' + (PRIO_TAG[item.priority] || 'light')}>{item.priority}</span>
      </div>
      <button type="button" className="sp-btn sp-btn-secondary sp-btn-sm sp-btn-full" onClick={onOpen}>
        <i className="fa-solid fa-circle-info" /> Details
      </button>
    </div>
  )
}
