import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { CAT_ICON, type RoadmapItem } from '../api'

export type LaneField = 'none' | 'priority' | 'subcategory'

export type MoveRequest = {
  id: number
  category: string
  priority?: string
  orderedIds: number[]
}

type Props = {
  categories: string[]
  items: RoadmapItem[]
  subcategories: string[]
  wipLimits: Record<string, number>
  hiddenCategories: string[]
  laneField: LaneField
  canDrag: boolean
  renderCard: (item: RoadmapItem) => ReactNode
  onMove: (move: MoveRequest) => void
  onSetLimit: (category: string, limit: number | null) => void
}

type Lane = { key: string; label: string; items: RoadmapItem[] }
type Target = { cat: string; lane: string; index: number }
type Drag = {
  id: number
  width: number
  height: number
  offX: number
  offY: number
  x: number
  y: number
  origin: Target
}

const PRIORITY_ORDER = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']
const COLLAPSE_KEY = 'rm-admin-collapsed'
const DRAG_THRESHOLD = 5

function readCollapsed(): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(COLLAPSE_KEY)
    if (raw) return JSON.parse(raw)
  } catch {
    /* ignore */
  }
  return { COMPLETED: true }
}

function laneKeyOf(item: RoadmapItem, field: LaneField): string {
  if (field === 'priority') return item.priority
  if (field === 'subcategory') return item.subcategories[0] || 'UNASSIGNED'
  return 'all'
}

export default function KanbanBoard(props: Props) {
  const { categories, items, subcategories, wipLimits, hiddenCategories, laneField, canDrag, renderCard, onMove, onSetLimit } = props
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>(readCollapsed)
  const [drag, setDrag] = useState<Drag | null>(null)
  const [target, setTarget] = useState<Target | null>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [limitText, setLimitText] = useState('')
  const dragRef = useRef<Drag | null>(null)
  const targetRef = useRef<Target | null>(null)
  const pending = useRef<{ id: number; x: number; y: number; offX: number; offY: number; rect: DOMRect; origin: Target } | null>(null)
  const propsRef = useRef({ items, laneField, categories, onMove, collapsed })
  propsRef.current = { items, laneField, categories, onMove, collapsed }

  const visibleCats = categories.filter((c) => !hiddenCategories.includes(c))

  function toggleCollapse(cat: string) {
    setCollapsed((prev) => {
      const next = { ...prev, [cat]: !prev[cat] }
      try {
        localStorage.setItem(COLLAPSE_KEY, JSON.stringify(next))
      } catch {
        /* ignore */
      }
      return next
    })
  }

  const lanes: Lane[] = useMemo(() => {
    if (laneField === 'none') return [{ key: 'all', label: '', items }]
    let keys: string[]
    if (laneField === 'priority') keys = PRIORITY_ORDER
    else keys = [...subcategories, 'UNASSIGNED']
    const known = new Set(keys)
    for (const it of items) {
      const k = laneKeyOf(it, laneField)
      if (!known.has(k)) { known.add(k); keys = [...keys, k] }
    }
    return keys.map((k) => ({
      key: k,
      label: k === 'UNASSIGNED' ? 'No subcategory' : k,
      items: items.filter((it) => laneKeyOf(it, laneField) === k),
    }))
  }, [items, laneField, subcategories])

  const dragging = drag !== null
  const shownLanes = lanes.filter((l) => l.items.length > 0 || (dragging && laneField === 'priority'))

  const cellItems = useCallback((lane: Lane, cat: string) => lane.items.filter((it) => it.category === cat), [])

  // Locate the cell and insert index under a pointer position.
  function hitTest(x: number, y: number, dragId: number): Target | null {
    const el = document.elementFromPoint(x, y) as HTMLElement | null
    const cell = el?.closest('[data-cell]') as HTMLElement | null
    if (!cell) return null
    const cat = cell.dataset.cat || ''
    const lane = cell.dataset.lane || 'all'
    const origin = dragRef.current?.origin
    if (propsRef.current.laneField === 'subcategory' && origin && lane !== origin.lane) return null
    const cards = Array.from(cell.querySelectorAll<HTMLElement>('[data-card-id]')).filter((c) => Number(c.dataset.cardId) !== dragId)
    let index = 0
    for (const c of cards) {
      const r = c.getBoundingClientRect()
      if (y > r.top + r.height / 2) index += 1
    }
    return { cat, lane, index }
  }

  const finishDrag = useCallback((commit: boolean) => {
    const d = dragRef.current
    const t = targetRef.current
    dragRef.current = null
    targetRef.current = null
    pending.current = null
    setDrag(null)
    setTarget(null)
    document.body.classList.remove('rm-dragging-body')
    if (!commit || !d || !t) return
    const { items: its, laneField: lf, onMove: move, collapsed: col } = propsRef.current
    const dragged = its.find((i) => i.id === d.id)
    if (!dragged) return
    const same = t.cat === d.origin.cat && t.lane === d.origin.lane && t.index === d.origin.index
    if (same) return
    // Items of the target cell (without the dragged card) in their current order.
    const colItems = its.filter((i) => i.category === t.cat && i.id !== d.id)
    const cellList = colItems.filter((i) => laneKeyOf(i, lf) === t.lane)
    let insertAt: number
    if (col[t.cat]) insertAt = colItems.length
    else if (cellList[t.index]) insertAt = colItems.indexOf(cellList[t.index])
    else if (cellList.length) insertAt = colItems.indexOf(cellList[cellList.length - 1]) + 1
    else insertAt = colItems.length
    const ordered = [...colItems.slice(0, insertAt), dragged, ...colItems.slice(insertAt)].map((i) => i.id)
    const req: MoveRequest = { id: d.id, category: t.cat, orderedIds: ordered }
    if (lf === 'priority' && t.lane !== dragged.priority) req.priority = t.lane
    move(req)
  }, [])

  useEffect(() => {
    function onMoveEv(e: PointerEvent) {
      const p = pending.current
      if (!dragRef.current && p) {
        if (Math.hypot(e.clientX - p.x, e.clientY - p.y) < DRAG_THRESHOLD) return
        const d: Drag = {
          id: p.id, width: p.rect.width, height: p.rect.height, offX: p.offX, offY: p.offY,
          x: e.clientX, y: e.clientY, origin: p.origin,
        }
        dragRef.current = d
        targetRef.current = p.origin
        document.body.classList.add('rm-dragging-body')
        setDrag(d)
        setTarget(p.origin)
        return
      }
      const d = dragRef.current
      if (!d) return
      e.preventDefault()
      const nd = { ...d, x: e.clientX, y: e.clientY }
      dragRef.current = nd
      setDrag(nd)
      const t = hitTest(e.clientX, e.clientY, d.id)
      const cur = targetRef.current
      if (t && (!cur || cur.cat !== t.cat || cur.lane !== t.lane || cur.index !== t.index)) {
        targetRef.current = t
        setTarget(t)
      }
      // Scroll the page when the pointer is near the top or bottom edge.
      const edge = 70
      if (e.clientY < edge) window.scrollBy(0, -14)
      else if (e.clientY > window.innerHeight - edge) window.scrollBy(0, 14)
    }
    function onUp() {
      if (dragRef.current) finishDrag(true)
      pending.current = null
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && dragRef.current) finishDrag(false)
    }
    window.addEventListener('pointermove', onMoveEv, { passive: false })
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', onUp)
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('pointermove', onMoveEv)
      window.removeEventListener('pointerup', onUp)
      window.removeEventListener('pointercancel', onUp)
      window.removeEventListener('keydown', onKey)
      document.body.classList.remove('rm-dragging-body')
    }
  }, [finishDrag])

  function onCardPointerDown(e: React.PointerEvent<HTMLDivElement>, item: RoadmapItem, lane: string, index: number) {
    if (!canDrag || e.button !== 0) return
    const el = e.target as HTMLElement
    if (el.closest('button, select, a, input, textarea, label')) return
    // Touch and pen use the grip so the page can still scroll.
    if (e.pointerType !== 'mouse' && !el.closest('.rm-kb-grip')) return
    const card = e.currentTarget
    const rect = card.getBoundingClientRect()
    pending.current = {
      id: item.id, x: e.clientX, y: e.clientY,
      offX: e.clientX - rect.left, offY: e.clientY - rect.top, rect,
      origin: { cat: item.category, lane, index },
    }
    if (e.pointerType === 'mouse') e.preventDefault()
  }

  function startEdit(cat: string) {
    setEditing(cat)
    setLimitText(wipLimits[cat] ? String(wipLimits[cat]) : '')
  }
  function saveLimit(cat: string) {
    const n = parseInt(limitText, 10)
    onSetLimit(cat, Number.isFinite(n) && n > 0 ? n : null)
    setEditing(null)
  }

  const template = visibleCats.map((c) => (collapsed[c] ? '3rem' : 'minmax(0, 1fr)')).join(' ')
  const draggedItem = drag ? items.find((i) => i.id === drag.id) : undefined
  const totalByCat = (cat: string) => items.filter((i) => i.category === cat).length

  return (
    <div className="rm-kb" style={{ ['--kb-template' as string]: template }}>
      <div className="rm-kb-row rm-kb-heads">
        {visibleCats.map((cat) => {
          const count = totalByCat(cat)
          const limit = wipLimits[cat]
          const hit = !!limit && count >= limit
          const over = !!limit && count > limit
          const label = limit ? count + ' / ' + limit : String(count)
          if (collapsed[cat]) {
            return (
              <button
                key={cat}
                type="button"
                className={'rm-kb-head rm-kb-head-collapsed' + (hit ? ' rm-kb-wip-hit' : '')}
                onClick={() => toggleCollapse(cat)}
                title={'Expand ' + cat}
              >
                <i className="fa-solid fa-angles-right" />
                <span className="rm-kb-vlabel">{cat} · {label}</span>
              </button>
            )
          }
          return (
            <div key={cat} className={'rm-kb-head' + (hit ? ' rm-kb-wip-hit' : '') + (over ? ' rm-kb-wip-over' : '')}>
              <div className="rm-kb-head-main">
                <span className="rm-kb-head-title">
                  <i className={'fa-solid fa-' + (CAT_ICON[cat] || 'folder')} />
                  {cat}
                </span>
                <span className="rm-kb-head-tools">
                  <span className="rm-kb-count" title={limit ? 'WIP limit ' + limit : 'No WIP limit'}>
                    {hit && <i className="fa-solid fa-triangle-exclamation" />} {label}
                  </span>
                  <button type="button" className="rm-kb-icon-btn" onClick={() => (editing === cat ? setEditing(null) : startEdit(cat))} title="Set WIP limit">
                    <i className="fa-solid fa-gauge-high" />
                  </button>
                  <button type="button" className="rm-kb-icon-btn" onClick={() => toggleCollapse(cat)} title="Collapse column">
                    <i className="fa-solid fa-angles-left" />
                  </button>
                </span>
              </div>
              {editing === cat && (
                <form className="rm-kb-limit-form" onSubmit={(e) => { e.preventDefault(); saveLimit(cat) }}>
                  <input
                    className="sp-input rm-kb-limit-input"
                    type="number"
                    min={1}
                    max={999}
                    autoFocus
                    placeholder="No limit"
                    value={limitText}
                    onChange={(e) => setLimitText(e.target.value)}
                  />
                  <button type="submit" className="sp-btn sp-btn-primary sp-btn-sm">Save</button>
                  <button type="button" className="sp-btn sp-btn-ghost sp-btn-sm" onClick={() => { setLimitText(''); onSetLimit(cat, null); setEditing(null) }}>Clear</button>
                </form>
              )}
            </div>
          )
        })}
      </div>

      {shownLanes.map((lane) => (
        <div key={lane.key} className="rm-kb-lane">
          {laneField !== 'none' && (
            <div className="rm-kb-lane-head">
              <span className={laneField === 'priority' ? 'rm-tag rm-tag-' + ({ LOW: 'success', MEDIUM: 'info', HIGH: 'warning', CRITICAL: 'danger' } as Record<string, string>)[lane.key] : 'rm-kb-lane-name'}>{lane.label}</span>
              <span className="rm-kb-lane-count">{lane.items.length}</span>
            </div>
          )}
          <div className="rm-kb-row">
            {visibleCats.map((cat) => {
              const cards = cellItems(lane, cat).filter((it) => !drag || it.id !== drag.id)
              const isTarget = target && target.cat === cat && target.lane === lane.key
              const showPlaceholder = !!drag && !!isTarget
              const nodes: ReactNode[] = []
              cards.forEach((it, i) => {
                if (showPlaceholder && target!.index === i) nodes.push(<div key="ph" className="rm-kb-placeholder" style={{ height: drag!.height }} />)
                nodes.push(
                  <div
                    key={it.id}
                    data-card-id={it.id}
                    className={'rm-kb-card' + (canDrag ? ' rm-kb-draggable' : '')}
                    onPointerDown={(e) => onCardPointerDown(e, it, lane.key, i)}
                  >
                    {canDrag && <span className="rm-kb-grip" title="Drag to move"><i className="fa-solid fa-grip-vertical" /></span>}
                    {renderCard(it)}
                  </div>,
                )
              })
              if (showPlaceholder && target!.index >= cards.length) nodes.push(<div key="ph" className="rm-kb-placeholder" style={{ height: drag!.height }} />)
              if (collapsed[cat]) {
                return (
                  <div key={cat} data-cell data-cat={cat} data-lane={lane.key} className={'rm-kb-cell rm-kb-cell-collapsed' + (showPlaceholder ? ' rm-kb-cell-over' : '')}>
                    {cards.length > 0 && <span className="rm-kb-cell-count">{cards.length}</span>}
                  </div>
                )
              }
              return (
                <div key={cat} data-cell data-cat={cat} data-lane={lane.key} className={'rm-kb-cell' + (showPlaceholder ? ' rm-kb-cell-over' : '')}>
                  <div className="rm-kb-cell-label">{cat}</div>
                  {nodes}
                  {!nodes.length && <div className="rm-kb-empty">No items</div>}
                </div>
              )
            })}
          </div>
        </div>
      ))}

      {drag && draggedItem && createPortal(
        <div
          className="rm-kb-ghost"
          style={{ width: drag.width, left: drag.x - drag.offX, top: drag.y - drag.offY }}
        >
          {renderCard(draggedItem)}
        </div>,
        document.body,
      )}
    </div>
  )
}
