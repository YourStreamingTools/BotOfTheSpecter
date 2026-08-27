import { useEffect, useState } from 'react'
import { fetchSession, type MembersSession } from './api'
import { applyTheme, readTheme, type ThemeName } from './theme'
import SearchPage from './pages/SearchPage'
import ChannelPage from './pages/ChannelPage'
import FreeGamesPage from './pages/FreeGamesPage'
import StorePage from './pages/StorePage'

const RESERVED = new Set([
  'app',
  'api',
  'login.php',
  'logout.php',
  'index.php',
  'freegames.php',
  'autocomplete.php',
  'store.php',
  'style.css',
  'includes',
  'css',
  'js',
  'favicon.ico',
  'favicon.svg',
])

type View =
  | { name: 'search' }
  | { name: 'freegames' }
  | { name: 'channel'; user: string }
  | { name: 'store'; user: string }

function currentView(): View {
  const raw = window.location.pathname
  const path = raw.replace(/\/+$/, '') || '/'
  if (path === '/app' || path.startsWith('/app/')) return { name: 'search' }
  const parts = path.split('/').filter(Boolean)
  if (parts.length === 0 || parts[0] === 'index.php') return { name: 'search' }
  if (parts[0] === 'freegames.php') return { name: 'freegames' }
  if (parts.length >= 2 && parts[1].toLowerCase() === 'store') {
    return { name: 'store', user: parts[0].toLowerCase() }
  }
  if (RESERVED.has(parts[0].toLowerCase())) return { name: 'search' }
  return { name: 'channel', user: parts[0].toLowerCase() }
}

const emptySession: MembersSession = {
  ok: false,
  logged_in: false,
  username: null,
  display_name: null,
  profile_image: null,
  twitch_user_id: null,
  store_csrf: null,
  dashboard_version: '',
}

export default function App() {
  const view = currentView()
  const [session, setSession] = useState<MembersSession | null>(null)
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())

  useEffect(() => {
    applyTheme(theme, false)
  }, [theme])

  useEffect(() => {
    fetchSession()
      .then((s) => {
        if (s && !s.logged_in) {
          const returnTo = window.location.pathname + window.location.search
          window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo)
          return
        }
        setSession(s || emptySession)
      })
      .catch(() => setSession(emptySession))
  }, [])

  const loggedIn = !!session?.logged_in
  const displayName = session?.display_name || session?.username || ''
  const title =
    view.name === 'freegames' ? 'Free Games'
      : view.name === 'store' ? 'Point Store'
        : view.name === 'channel' ? 'Members'
          : 'Members'

  const showBack = view.name !== 'search'
  const backHref = view.name === 'store' ? '/' + encodeURIComponent(view.user) : '/'
  const backLabel = view.name === 'store' ? 'Channel' : 'Back to Search'

  return (
    <>
      <div
        id="sp-sidebar-overlay"
        className={'sp-sidebar-overlay' + (sidebarOpen ? ' open' : '')}
        onClick={() => setSidebarOpen(false)}
      />
      <div className="sp-layout">
        <aside id="sp-sidebar" className={'sp-sidebar' + (sidebarOpen ? ' open' : '')}>
          <div className="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" />
            <div className="sp-brand-text">
              <span className="sp-brand-title">BotOfTheSpecter</span>
              <span className="sp-brand-sub">Members Portal</span>
            </div>
          </div>
          <nav className="sp-nav">
            <div className="sp-nav-section">
              <div className="sp-nav-label">Navigation</div>
              <a href="/" className={'sp-nav-link' + (view.name === 'search' ? ' active' : '')}>
                <i className="fa-solid fa-magnifying-glass" /> Search Channels
              </a>
              <a href="/freegames.php" className={'sp-nav-link' + (view.name === 'freegames' ? ' active' : '')}>
                <i className="fa-solid fa-gamepad" /> Free Games
              </a>
            </div>
            <div className="sp-nav-section">
              <div className="sp-nav-label">Resources</div>
              <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-gauge" /> Dashboard <i className="fa-solid fa-arrow-up-right-from-square" style={{ fontSize: '0.65rem', opacity: 0.5, marginLeft: 'auto' }} />
              </a>
              <a href="https://support.botofthespecter.com" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-circle-question" /> Support <i className="fa-solid fa-arrow-up-right-from-square" style={{ fontSize: '0.65rem', opacity: 0.5, marginLeft: 'auto' }} />
              </a>
            </div>
          </nav>
          <div className="sp-sidebar-footer">
            {loggedIn ? (
              <>
                <div className="sp-user-block">
                  {session?.profile_image ? (
                    <img src={session.profile_image} alt={displayName} className="sp-user-avatar" />
                  ) : (
                    <div className="sp-user-avatar-placeholder"><i className="fa-solid fa-user" /></div>
                  )}
                  <div style={{ minWidth: 0 }}>
                    <div className="sp-user-name">{displayName}</div>
                    <div className="sp-user-role">Member</div>
                  </div>
                </div>
                <a href="/logout.php" className="sp-nav-link sp-text-small">
                  <i className="fa-solid fa-right-from-bracket" /> Log Out
                </a>
              </>
            ) : (
              <a href="/login.php" className="sp-btn sp-btn-primary" style={{ width: '100%', justifyContent: 'center' }}>
                <i className="fa-solid fa-right-to-bracket" /> Log In
              </a>
            )}
          </div>
        </aside>
        <div className="sp-main">
          <header className="sp-topbar">
            <button className="sp-hamburger" aria-label="Open menu" type="button" onClick={() => setSidebarOpen((o) => !o)}>
              <i className="fa-solid fa-bars" />
            </button>
            <span className="sp-topbar-title">{title}</span>
            <div className="sp-topbar-actions">
              <button
                className="sp-theme-toggle"
                type="button"
                aria-label="Toggle light or dark theme"
                title="Toggle theme"
                onClick={() => {
                  const next = theme === 'light' ? 'dark' : 'light'
                  applyTheme(next, true)
                  setTheme(next)
                }}
              >
                <i className={theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'} />
              </button>
              {showBack && (
                <a href={backHref} className="sp-btn sp-btn-secondary sp-btn-sm">
                  <i className="fa-solid fa-arrow-left" /> {backLabel}
                </a>
              )}
            </div>
          </header>
          <main className="sp-content">
            {view.name === 'search' && <SearchPage />}
            {view.name === 'freegames' && <FreeGamesPage />}
            {view.name === 'channel' && <ChannelPage username={view.user} />}
            {view.name === 'store' && <StorePage channel={view.user} />}
          </main>
          <footer className="sp-footer">
            &copy; 2023&ndash;{new Date().getFullYear()} BotOfTheSpecter &mdash; All rights reserved.
          </footer>
        </div>
      </div>
    </>
  )
}
