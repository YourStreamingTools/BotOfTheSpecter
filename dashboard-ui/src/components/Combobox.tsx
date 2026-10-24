import { useEffect, useId, useMemo, useRef, useState } from 'react'

export type ComboOption = { value: string; label: string; group?: string; hint?: string; search?: string }

type Props = { options: ComboOption[]; value: string; onChange: (v: string) => void; label: string; placeholder?: string; searchable?: boolean }

// Themed replacement for a native <select>: ARIA combobox + listbox, type to
// filter, grouped options, arrows/Enter/Esc, closes on outside click. Below
// 760px the list opens as a full-width bottom sheet.
// Scroll only the listbox, never the page.
function scrollToOpt(listEl: HTMLUListElement | null, i: number, center: boolean) {
  const el = listEl?.querySelector<HTMLElement>(`[data-i="${i}"]`)
  if (!listEl || !el) return
  const top = el.offsetTop, bottom = top + el.offsetHeight
  if (center) listEl.scrollTop = top - listEl.clientHeight / 2 + el.offsetHeight / 2
  else if (top < listEl.scrollTop + 28) listEl.scrollTop = top - 28
  else if (bottom > listEl.scrollTop + listEl.clientHeight) listEl.scrollTop = bottom - listEl.clientHeight
}

export default function Combobox({ options, value, onChange, label, placeholder = 'Search', searchable = true }: Props) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const wrap = useRef<HTMLDivElement>(null)
  const list = useRef<HTMLUListElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const btn = useRef<HTMLButtonElement>(null)
  const current = options.find((o) => o.value === value)

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return options
    return options.filter((o) => `${o.label} ${o.group ?? ''} ${o.hint ?? ''} ${o.search ?? ''} ${o.value}`.toLowerCase().includes(t))
  }, [q, options])

  useEffect(() => {
    if (!open) return
    const i = Math.max(0, filtered.findIndex((o) => o.value === value))
    setActive(i)
    requestAnimationFrame(() => {
      ;(searchable ? input.current : list.current)?.focus({ preventScroll: true })
      scrollToOpt(list.current, i, true)
    })
    const out = (e: MouseEvent | TouchEvent) => { if (wrap.current && !wrap.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', out)
    document.addEventListener('touchstart', out)
    const mobile = window.matchMedia('(max-width: 760px)').matches
    if (mobile) document.body.classList.add('no-scroll')
    return () => { document.removeEventListener('mousedown', out); document.removeEventListener('touchstart', out); if (mobile) document.body.classList.remove('no-scroll') }
  }, [open])

  useEffect(() => {
    if (q.trim()) { setActive(0); return }
    const i = Math.max(0, options.findIndex((o) => o.value === value))
    setActive(i)
    requestAnimationFrame(() => scrollToOpt(list.current, i, true))
  }, [q])
  useEffect(() => { scrollToOpt(list.current, active, false) }, [active])

  const close = () => { setOpen(false); setQ(''); btn.current?.focus({ preventScroll: true }) }
  const pick = (o: ComboOption) => { onChange(o.value); close() }
  const key = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(filtered.length - 1, a + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)) }
    else if (e.key === 'Home') { e.preventDefault(); setActive(0) }
    else if (e.key === 'End') { e.preventDefault(); setActive(filtered.length - 1) }
    else if (e.key === 'Enter') { e.preventDefault(); if (filtered[active]) pick(filtered[active]) }
    else if (e.key === 'Escape') { e.preventDefault(); close() }
    else if (e.key === 'Tab') setOpen(false)
  }

  let lastGroup: string | undefined
  return (
    <div className="combo" ref={wrap}>
      <button ref={btn} type="button" className="input combo-btn" role="combobox" aria-haspopup="listbox" aria-expanded={open} aria-controls={`${id}-list`} aria-label={label}
        onClick={() => setOpen((o) => !o)}
        onKeyDown={(e) => { if (open && e.key === 'Escape') { e.preventDefault(); close(); return } if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); setOpen(true) } }}>
        <span className="combo-val">{current ? current.label : (value || <span className="combo-ph">{label}</span>)}{current?.hint && <small>{current.hint}</small>}</span>
        <i className="fa-solid fa-chevron-down" aria-hidden="true" />
      </button>
      {open && (
        <>
          <div className="combo-scrim" onClick={close} />
          <div className="combo-pop" onKeyDown={key}>
            <div className="combo-sheet-head"><b>{label}</b><button type="button" className="combo-x" aria-label="Close" onClick={close}><i className="fa-solid fa-xmark" /></button></div>
            {searchable && (
              <div className="combo-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                <input ref={input} className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} aria-label={`${label} search`}
                  role="searchbox" aria-controls={`${id}-list`} aria-activedescendant={filtered[active] ? `${id}-o${active}` : undefined} autoComplete="off" spellCheck={false} />
              </div>
            )}
            <ul ref={list} id={`${id}-list`} role="listbox" aria-label={label} tabIndex={-1} className="combo-list" aria-activedescendant={!searchable && filtered[active] ? `${id}-o${active}` : undefined}>
              {filtered.length === 0 && <li className="combo-empty">No matches</li>}
              {filtered.map((o, i) => {
                const head = o.group && o.group !== lastGroup ? o.group : null
                lastGroup = o.group
                return [
                  head && <li key={`g-${head}`} className="combo-group" role="presentation">{head}</li>,
                  <li key={o.value} id={`${id}-o${i}`} data-i={i} role="option" aria-selected={o.value === value}
                    className={`combo-opt${i === active ? ' active' : ''}${o.value === value ? ' sel' : ''}`}
                    onMouseEnter={() => setActive(i)} onMouseDown={(e) => e.preventDefault()} onClick={() => pick(o)}>
                    <span>{o.label}</span>{o.hint && <small>{o.hint}</small>}
                    {o.value === value && <i className="fa-solid fa-check" aria-hidden="true" />}
                  </li>,
                ]
              })}
            </ul>
          </div>
        </>
      )}
    </div>
  )
}

// Time zone options: grouped by the IANA region, friendly city label, current UTC offset.
const REGION: Record<string, string> = { America: 'Americas', Australia: 'Australia', Europe: 'Europe', Asia: 'Asia', Africa: 'Africa', Pacific: 'Pacific', Atlantic: 'Atlantic', Indian: 'Indian Ocean', Antarctica: 'Antarctica', Arctic: 'Arctic' }
function offsetOf(tz: string): { text: string; minutes: number } {
  try {
    const part = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'shortOffset' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')?.value ?? 'GMT'
    const m = part.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/)
    if (!m) return { text: 'UTC+0', minutes: 0 }
    const mins = (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3] ?? '0', 10))
    return { text: `UTC${m[1]}${m[2]}${m[3] ? ':' + m[3] : ''}`, minutes: mins }
  } catch { return { text: '', minutes: 0 } }
}
export function timezoneOptions(zones: string[]): ComboOption[] {
  return zones.map((z) => {
    const parts = z.split('/')
    const region = parts.length > 1 ? (REGION[parts[0]] ?? parts[0]) : 'Other'
    const city = parts[parts.length - 1].replace(/_/g, ' ')
    const mid = parts.length > 2 ? ` (${parts.slice(1, -1).join(', ').replace(/_/g, ' ')})` : ''
    const off = offsetOf(z)
    return { value: z, label: `${city}${mid}`, group: region, hint: off.text, search: z.replace(/_/g, ' '), _o: off.minutes } as ComboOption & { _o: number }
  }).sort((a, b) => (a.group ?? '').localeCompare(b.group ?? '') || a.label.localeCompare(b.label))
}
