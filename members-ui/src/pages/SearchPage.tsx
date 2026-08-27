import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import { fetchSuggestions, type SuggestItem } from '../api'

export default function SearchPage() {
  const [query, setQuery] = useState('')
  const [suggestions, setSuggestions] = useState<SuggestItem[]>([])
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const wrapRef = useRef<HTMLDivElement>(null)
  const timer = useRef<number | null>(null)

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (!wrapRef.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  function go(username: string) {
    const u = username.trim()
    if (!u) return
    window.location.href = '/' + encodeURIComponent(u) + '/'
  }

  function onInput(value: string) {
    setQuery(value)
    setActive(-1)
    if (timer.current) window.clearTimeout(timer.current)
    const q = value.trim()
    if (!q) {
      setSuggestions([])
      setOpen(false)
      return
    }
    timer.current = window.setTimeout(() => {
      fetchSuggestions(q)
        .then((data) => {
          setSuggestions(data)
          setOpen(data.length > 0)
        })
        .catch(() => {
          setSuggestions([])
          setOpen(false)
        })
    }, 200)
  }

  function onKey(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActive((i) => Math.min(i + 1, suggestions.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => Math.max(i - 1, -1))
    } else if (e.key === 'Enter') {
      if (active >= 0 && suggestions[active]) {
        e.preventDefault()
        go(suggestions[active].username)
      }
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <>
      <div className="sp-page-header">
        <div className="sp-page-header-row">
          <div>
            <h1>Member Lookup</h1>
            <p>Search for a Twitch channel using BotOfTheSpecter.</p>
          </div>
        </div>
      </div>
      <div className="ms-search-card">
        <form
          onSubmit={(e) => {
            e.preventDefault()
            go(active >= 0 && suggestions[active] ? suggestions[active].username : query)
          }}
        >
          <div className="ms-search-row">
            <div className="ac-wrapper" ref={wrapRef}>
              <input
                type="text"
                id="user_search"
                name="user"
                className="sp-input"
                placeholder="Enter a Twitch username…"
                autoComplete="off"
                required
                value={query}
                onChange={(e) => onInput(e.target.value)}
                onKeyDown={onKey}
              />
              {open && (
                <div id="ac-dropdown" className="ac-dropdown">
                  {suggestions.map((item, i) => (
                    <div
                      key={item.username}
                      className={'ac-item' + (i === active ? ' is-active' : '')}
                      onMouseDown={(e) => {
                        e.preventDefault()
                        go(item.username)
                      }}
                    >
                      {item.avatar ? (
                        <img className="ac-avatar" src={item.avatar} alt="" onError={(e) => { (e.target as HTMLImageElement).style.display = 'none' }} />
                      ) : (
                        <span className="ac-avatar ac-avatar-placeholder"><i className="fas fa-user" /></span>
                      )}
                      <span className="ac-name">{item.display_name}</span>
                      <span className="ac-username">@{item.username}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <button type="submit" className="sp-btn sp-btn-primary">
              <i className="fa-solid fa-magnifying-glass" /> Search
            </button>
          </div>
        </form>
      </div>
      <div className="sp-card" style={{ marginTop: '1.5rem' }}>
        <div className="sp-card-header">
          <i className="fa-solid fa-circle-info" />
          <h2>Member Information</h2>
        </div>
        <div className="sp-card-body">
          <div className="sp-doc-grid">
            <a href="/freegames.php" className="sp-doc-card">
              <div className="sp-doc-card-icon"><i className="fa-solid fa-gamepad" /></div>
              <div className="sp-doc-card-title">FreeStuff (System): Recent Free Games</div>
              <div className="sp-doc-card-desc">System-wide announcements of free games used by our Discord and Twitch bots. The Twitch bot posts the most recent free game in chat.</div>
            </a>
          </div>
        </div>
      </div>
    </>
  )
}
