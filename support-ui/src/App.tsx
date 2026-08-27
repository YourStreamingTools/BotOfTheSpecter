import { useEffect, useState } from 'react'
import { fetchSession, type SupportSession } from './api'
import { applyTheme, readTheme, type ThemeName } from './theme'
import DocsPage from './pages/DocsPage'
import TicketsPage from './pages/TicketsPage'
import BetaPage from './pages/BetaPage'

const DOC_LINKS: { href: string; icon: string; label: string; hash?: string }[] = [
  { href: '/index.php', icon: 'fa-solid fa-house', label: 'Home' },
  { href: '/index.php#setup', icon: 'fa-solid fa-rocket', label: 'First Time Setup', hash: 'setup' },
  { href: '/index.php#features', icon: 'fa-solid fa-star', label: 'Main Features', hash: 'features' },
  { href: '/index.php#spotify', icon: 'fa-brands fa-spotify', label: 'Spotify Setup', hash: 'spotify' },
  { href: '/index.php#tts', icon: 'fa-solid fa-microphone', label: 'Text-to-Speech', hash: 'tts' },
  { href: '/index.php#obs-audio', icon: 'fa-solid fa-headphones', label: 'OBS Audio Monitoring', hash: 'obs-audio' },
  { href: '/index.php#variables', icon: 'fa-solid fa-code', label: 'Variables', hash: 'variables' },
  { href: '/index.php#twitch-channel-points', icon: 'fa-brands fa-twitch', label: 'Channel Points', hash: 'twitch-channel-points' },
  { href: '/index.php#api', icon: 'fa-solid fa-satellite-dish', label: 'Custom API', hash: 'api' },
  { href: '/index.php#run-yourself', icon: 'fa-solid fa-server', label: 'Run Yourself', hash: 'run-yourself' },
  { href: '/index.php#commands', icon: 'fa-solid fa-terminal', label: 'Command Reference', hash: 'commands' },
  { href: '/index.php#faq', icon: 'fa-solid fa-circle-question', label: 'FAQ', hash: 'faq' },
  { href: '/index.php#troubleshooting', icon: 'fa-solid fa-wrench', label: 'Troubleshooting', hash: 'troubleshooting' },
]

function currentView(): 'docs' | 'tickets' | 'beta' {
  const p = window.location.pathname
  if (p.includes('tickets.php')) return 'tickets'
  if (p.includes('beta.php')) return 'beta'
  return 'docs'
}

const emptySession: SupportSession = {
  ok: false,
  logged_in: false,
  is_staff: false,
  is_registered: false,
  username: null,
  display_name: null,
  profile_image: null,
  csrf_token: null,
  dashboard_version: '',
}

export default function App() {
  const view = currentView()
  const [session, setSession] = useState<SupportSession | null>(null)
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())
  const [hash, setHash] = useState(() => window.location.hash.replace('#', ''))

  useEffect(() => {
    applyTheme(theme, false)
  }, [theme])

  useEffect(() => {
    fetchSession().then(setSession).catch(() => setSession(emptySession))
  }, [])

  useEffect(() => {
    const onHash = () => setHash(window.location.hash.replace('#', ''))
    window.addEventListener('hashchange', onHash)
    return () => window.removeEventListener('hashchange', onHash)
  }, [])

  const loggedIn = !!session?.logged_in
  const staff = !!session?.is_staff
  const displayName = session?.display_name || session?.username || ''
  const title = view === 'tickets' ? 'Support Tickets' : view === 'beta' ? 'Beta Programs' : 'BotOfTheSpecter Documentation'

  return (
    <>
      <div id="sp-sidebar-overlay" className={'sp-sidebar-overlay' + (sidebarOpen ? ' open' : '')} onClick={() => setSidebarOpen(false)} />
      <div className="sp-layout">
        <aside id="sp-sidebar" className={'sp-sidebar' + (sidebarOpen ? ' open' : '')}>
          <div className="sp-brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" />
            <div className="sp-brand-text">
              <span className="sp-brand-title">BotOfTheSpecter</span>
              <span className="sp-brand-sub">Support Portal</span>
            </div>
          </div>
          <nav className="sp-nav">
            <div className="sp-nav-section">
              <div className="sp-nav-label">Documentation</div>
              {DOC_LINKS.map((link) => {
                const active = view === 'docs' && ((link.hash && hash === link.hash) || (!link.hash && !hash && link.href === '/index.php'))
                return (
                  <a key={link.href} href={link.href} className={'sp-nav-link' + (active ? ' active' : '')}>
                    <i className={link.icon} /> {link.label}
                  </a>
                )
              })}
            </div>
            <div className="sp-nav-section">
              <div className="sp-nav-label">Resources</div>
              <a href="https://api.botofthespecter.com/docs" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-book" /> API Docs <i className="fa-solid fa-arrow-up-right-from-square" style={{ fontSize: '0.65rem', opacity: 0.5, marginLeft: 'auto' }} />
              </a>
              <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-brands fa-github" /> GitHub <i className="fa-solid fa-arrow-up-right-from-square" style={{ fontSize: '0.65rem', opacity: 0.5, marginLeft: 'auto' }} />
              </a>
              <a href="https://dashboard.botofthespecter.com/dashboard.php" target="_blank" rel="noopener" className="sp-nav-link">
                <i className="fa-solid fa-gauge" /> Dashboard <i className="fa-solid fa-arrow-up-right-from-square" style={{ fontSize: '0.65rem', opacity: 0.5, marginLeft: 'auto' }} />
              </a>
            </div>
            <div className="sp-nav-section">
              <div className="sp-nav-label">Support</div>
              {loggedIn ? (
                <>
                  <a href="/tickets.php" className={'sp-nav-link' + (view === 'tickets' ? ' active' : '')}><i className="fa-solid fa-ticket" /> My Tickets</a>
                  <a href="/tickets.php?action=new" className="sp-nav-link"><i className="fa-solid fa-plus" /> Submit a Ticket</a>
                  <a href="/beta.php" className={'sp-nav-link' + (view === 'beta' ? ' active' : '')}><i className="fa-solid fa-flask" /> Beta Programs</a>
                  {staff && (
                    <a href="/tickets.php?view=queue" className="sp-nav-link sp-nav-link-staff">
                      <i className="fa-solid fa-headset" /> Staff Queue <span className="sp-badge sp-badge-accent" style={{ marginLeft: 'auto', fontSize: '0.65rem' }}>Staff</span>
                    </a>
                  )}
                </>
              ) : (
                <>
                  <a href="/login.php" className="sp-nav-link"><i className="fa-solid fa-right-to-bracket" /> Log in to Submit</a>
                  <a href="/beta.php" className={'sp-nav-link' + (view === 'beta' ? ' active' : '')}><i className="fa-solid fa-flask" /> Beta Programs</a>
                </>
              )}
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
                    <div className="sp-user-role">{staff ? 'Staff' : 'User'}</div>
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
              <button className="sp-theme-toggle" type="button" aria-label="Toggle light or dark theme" onClick={() => {
                const next = theme === 'light' ? 'dark' : 'light'
                applyTheme(next, true)
                setTheme(next)
              }}>
                <i className={theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'} />
              </button>
              {loggedIn && !staff && view === 'docs' && (
                <a href="/tickets.php?action=new" className="sp-btn sp-btn-primary sp-btn-sm">
                  <i className="fa-solid fa-plus" /> New Ticket
                </a>
              )}
              {!loggedIn && (
                <a href="/login.php" className="sp-btn sp-btn-secondary sp-btn-sm">
                  <i className="fa-brands fa-twitch" /> Log In
                </a>
              )}
            </div>
          </header>
          <main className="sp-content">
            {view === 'docs' && <DocsPage loggedIn={loggedIn} />}
            {view === 'tickets' && session && <TicketsPage session={session} />}
            {view === 'beta' && session && <BetaPage session={session} />}
            {(view === 'tickets' || view === 'beta') && !session && (
              <p style={{ color: 'var(--text-secondary)' }}>Loading…</p>
            )}
          </main>
          <footer className="sp-footer">
            &copy; 2023&ndash;{new Date().getFullYear()} BotOfTheSpecter. All rights reserved.<br />
            BotOfTheSpecter is operated under the business name &quot;YourStreamingTools&quot;, registered in Australia (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).<br />
            Not affiliated with Twitch Interactive, Inc., Discord Inc., Spotify AB, or StreamElements Inc.<br />
            All trademarks are the property of their respective owners.
            {session?.dashboard_version && (
              <>
                <br />
                <span style={{ color: 'var(--text-muted)', fontSize: '0.72rem' }}>Portal v{session.dashboard_version}</span>
              </>
            )}
          </footer>
        </div>
      </div>
    </>
  )
}
