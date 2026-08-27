import { useEffect, useState } from 'react'
import { fetchSession, type RoadmapSession } from './api'
import { applyTheme, readTheme, type ThemeName } from './theme'
import BoardPage from './pages/BoardPage'
import TimelinePage from './pages/TimelinePage'
import AdminPage from './pages/AdminPage'

function currentView(): 'board' | 'timeline' | 'admin' {
  const p = window.location.pathname
  if (p.includes('/admin')) return 'admin'
  if (p.includes('timeline.php')) return 'timeline'
  return 'board'
}

const empty: RoadmapSession = {
  ok: false,
  logged_in: false,
  is_admin: false,
  username: null,
  display_name: null,
  profile_image: null,
  csrf_token: '',
}

export default function App() {
  const view = currentView()
  const [session, setSession] = useState<RoadmapSession | null>(null)
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())

  useEffect(() => { applyTheme(theme, false) }, [theme])
  useEffect(() => {
    fetchSession().then(setSession).catch(() => setSession(empty))
  }, [])

  const s = session || empty
  const title = view === 'admin' ? 'Roadmap Admin' : view === 'timeline' ? 'Timeline' : 'Development Roadmap'

  return (
    <>
      <div id="sp-sidebar-overlay" className={'sp-sidebar-overlay' + (sidebarOpen ? ' open' : '')} onClick={() => setSidebarOpen(false)} />
      <div className="sp-layout">
        <aside id="sp-sidebar" className={'sp-sidebar' + (sidebarOpen ? ' open' : '')}>
          <div className="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" />
            <div className="sp-brand-text">
              <span className="sp-brand-title">BotOfTheSpecter</span>
              <span className="sp-brand-sub">Roadmap</span>
            </div>
          </div>
          <nav className="sp-nav">
            <div className="sp-nav-section">
              <div className="sp-nav-label">Navigation</div>
              <a href="/index.php" className={'sp-nav-link' + (view === 'board' ? ' active' : '')}><i className="fa-solid fa-map" /> Roadmap</a>
              <a href="/timeline.php" className={'sp-nav-link' + (view === 'timeline' ? ' active' : '')}><i className="fa-solid fa-timeline" /> Timeline</a>
              {s.is_admin && (
                <a href="/admin/" className={'sp-nav-link' + (view === 'admin' ? ' active' : '')}>
                  <i className="fa-solid fa-screwdriver-wrench" /> Admin Panel
                  <span className="sp-badge sp-badge-accent" style={{ marginLeft: 'auto', fontSize: '0.6rem' }}>Admin</span>
                </a>
              )}
            </div>
            <div className="sp-nav-section">
              <div className="sp-nav-label">Resources</div>
              <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-gauge" /> Dashboard <i className="fa-solid fa-arrow-up-right-from-square sp-link-ext" />
              </a>
              <a href="https://support.botofthespecter.com/" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-circle-question" /> Support <i className="fa-solid fa-arrow-up-right-from-square sp-link-ext" />
              </a>
              <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-brands fa-github" /> GitHub <i className="fa-solid fa-arrow-up-right-from-square sp-link-ext" />
              </a>
            </div>
          </nav>
          <div className="sp-sidebar-footer">
            {s.logged_in ? (
              <>
                <div className="sp-user-block">
                  {s.profile_image ? (
                    <img src={s.profile_image} alt="" className="sp-user-avatar" />
                  ) : (
                    <div className="sp-user-avatar-placeholder"><i className="fa-solid fa-user" /></div>
                  )}
                  <div style={{ minWidth: 0 }}>
                    <div className="sp-user-name">{s.display_name || s.username}</div>
                    <div className="sp-user-role">{s.is_admin ? 'Admin' : 'Viewer'}</div>
                  </div>
                </div>
                <a href="/logout.php" className="sp-nav-link sp-text-small"><i className="fa-solid fa-right-from-bracket" /> Log Out</a>
              </>
            ) : (
              <a href="/login.php" className="sp-btn sp-btn-primary" style={{ width: '100%', justifyContent: 'center' }}>
                <i className="fa-brands fa-twitch" /> Log In
              </a>
            )}
          </div>
        </aside>
        <div className="sp-main">
          <header className="sp-topbar">
            <button type="button" className="sp-hamburger" aria-label="Open menu" onClick={() => setSidebarOpen((o) => !o)}>
              <i className="fa-solid fa-bars" />
            </button>
            <span className="sp-topbar-title">{title}</span>
            <div className="sp-topbar-actions">
              <button
                type="button"
                className="sp-theme-toggle"
                aria-label="Toggle light or dark theme"
                onClick={() => {
                  const next = theme === 'light' ? 'dark' : 'light'
                  applyTheme(next, true)
                  setTheme(next)
                }}
              >
                <i className={theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'} />
              </button>
              {!s.logged_in && (
                <a href="/login.php" className="sp-btn sp-btn-secondary sp-btn-sm">
                  <i className="fa-brands fa-twitch" /> Log In
                </a>
              )}
            </div>
          </header>
          <main className="sp-content sp-content-wide">
            {view === 'board' && <BoardPage session={s} />}
            {view === 'timeline' && <TimelinePage />}
            {view === 'admin' && s.is_admin && <AdminPage session={s} />}
            {view === 'admin' && session && !s.is_admin && (
              <div className="sp-alert sp-alert-danger">Admin only. <a href="/login.php">Sign in</a> with an admin account.</div>
            )}
          </main>
          <footer className="sp-footer">
            &copy; 2023&ndash;{new Date().getFullYear()} BotOfTheSpecter. All rights reserved.<br />
            BotOfTheSpecter is operated under the business name &quot;YourStreamingTools&quot;, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).<br />
            Not affiliated with Twitch Interactive, Inc., Discord Inc., or any other platform.
          </footer>
        </div>
      </div>
    </>
  )
}
