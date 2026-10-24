import { useEffect, useState } from 'react'
import { applyTheme, onOsChange, readSaved, saveTheme, osTheme, type ThemeName } from '../theme'
import { expired as markExpired, markSignedIn, markSignedOut, onExpired, signIn, signInNow, wasSignedIn } from '../session'
import ThemeSwitch from '../components/ThemeSwitch'
import Copyright from '../components/Copyright'
import { ADMIN_NAV, oldUrl, type AdminLink } from './nav'
import { PAGES } from './pages'

type AdminMe = { user_id: number | null; username: string | null; display_name: string | null; profile_image: string | null; twitch_user_id: string }
type State = { kind: 'loading' } | { kind: 'signed-out' } | { kind: 'denied' } | { kind: 'error'; status: number } | { kind: 'ok'; me: AdminMe }

const loginHref = () => `/api/login.php?return=${encodeURIComponent(window.location.pathname + window.location.search)}`

async function loadMe(): Promise<State> {
  const r = await fetch('/api/me.php', { credentials: 'same-origin' })
  if (r.status === 401) return { kind: 'signed-out' }
  if (r.status === 403) return { kind: 'denied' }
  if (!r.ok) return { kind: 'error', status: r.status }
  return { kind: 'ok', me: (await r.json()) as AdminMe }
}

function usePath(): [string, (p: string) => void] {
  const [path, setPath] = useState(window.location.pathname)
  useEffect(() => {
    const on = () => setPath(window.location.pathname)
    window.addEventListener('popstate', on)
    return () => window.removeEventListener('popstate', on)
  }, [])
  return [path, (p: string) => { window.history.pushState({}, '', p); setPath(p) }]
}

const links = ADMIN_NAV.filter((n): n is AdminLink => 'label' in n)
const signinIssue = new URLSearchParams(window.location.search).get('signin')

export default function AdminApp() {
  const [path, go] = usePath()
  const [saved, setSaved] = useState<ThemeName | null>(readSaved())
  const [sys, setSys] = useState<ThemeName>(osTheme())
  const theme = saved ?? sys
  const [state, setState] = useState<State>({ kind: 'loading' })
  const [lapsed, setLapsed] = useState(false), [stuck, setStuck] = useState(false)
  const [navOpen, setNavOpen] = useState(false)
  useEffect(() => applyTheme(theme), [theme])
  useEffect(() => (saved ? undefined : onOsChange(() => setSys(osTheme()))), [saved])
  useEffect(() => onExpired(() => { setState({ kind: 'signed-out' }); setLapsed(true); const t = setTimeout(() => { if (!signIn()) setStuck(true) }, 2500); return () => clearTimeout(t) }), [])
  useEffect(() => {
    loadMe().then((s) => { if (s.kind === 'ok') markSignedIn(); else if (s.kind === 'signed-out' && wasSignedIn()) markExpired(); setState(s) })
      .catch(() => setState({ kind: 'error', status: 0 }))
  }, [])
  useEffect(() => {
    if (!navOpen) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setNavOpen(false) }
    document.addEventListener('keydown', onKey)
    document.body.classList.add('no-scroll')
    return () => { document.removeEventListener('keydown', onKey); document.body.classList.remove('no-scroll') }
  }, [navOpen])
  const choose = (t: ThemeName) => { saveTheme(t); setSaved(t) }
  const brand = <><img src="https://cdn.botofthespecter.com/logo.png" alt="" /><span>Admin Panel</span><em>beta</em></>

  if (state.kind === 'loading') return <div className="center"><i className="fa-solid fa-spinner fa-spin" /></div>
  if (state.kind !== 'ok') {
    return (
      <div className="public">
        <header className="topbar public-top">
          <div className="brand">{brand}</div>
          <div className="actions"><ThemeSwitch value={theme} onChange={choose} /></div>
        </header>
        <main className="content admin-gate">
          {state.kind === 'signed-out' && (
            <div className="card">
              <h2 className="card-h"><i className="fas fa-lock" /> Sign in required <span className="pill pill-warn">401</span></h2>
              {lapsed && <div className="notice notice-warn sess-note" role="alert"><i className="fas fa-clock-rotate-left" /> <span>Your session expired, please sign in again.{stuck ? ' Signing in did not stick, so try once more.' : ' Taking you to sign in.'}</span></div>}
              {signinIssue === 'failed' && <div className="notice notice-error" role="alert"><i className="fas fa-triangle-exclamation" /> <span>The sign-in link was invalid or expired.</span></div>}
              {signinIssue === 'denied' && <div className="notice notice-error" role="alert"><i className="fas fa-shield-halved" /> <span>That account does not have admin access.</span></div>}
              <p><a className="btn btn-block" href={loginHref()} onClick={(e) => { e.preventDefault(); signInNow() }}><i className="fas fa-right-to-bracket" /> Sign in with BotOfTheSpecter</a></p>
            </div>
          )}
          {state.kind === 'denied' && (
            <div className="card">
              <h2 className="card-h"><i className="fas fa-shield-halved" /> Admins only <span className="pill pill-err">403</span></h2>
              <p>This account does not have admin access.</p>
              <p><a className="btn btn-ghost btn-block" href="https://beta.dashboard.botofthespecter.com/"><i className="fas fa-home" /> Beta dashboard</a></p>
            </div>
          )}
          {state.kind === 'error' && (
            <div className="card">
              <h2 className="card-h"><i className="fas fa-triangle-exclamation" /> Could not load <span className="pill pill-err">{state.status || 'network'}</span></h2>
              <p><button type="button" className="btn btn-block" onClick={() => window.location.reload()}><i className="fas fa-rotate" /> Retry</button></p>
            </div>
          )}
        </main>
      </div>
    )
  }

  const me = state.me
  const current = path === '/' && !links[0].path ? links[0] : links.find((l) => l.path === path)
  const Page = current?.path ? PAGES[current.path] : undefined
  const nav = (fn?: () => void) => ADMIN_NAV.map((n, i) => 'divider' in n ? <hr key={i} className="nav-div" /> : n.path ? (
    <a key={n.old} href={n.path} className={path === n.path ? 'active' : ''} onClick={(e) => { e.preventDefault(); fn?.(); go(n.path!) }}>
      <i className={n.icon} /> <span>{n.label}</span>
    </a>
  ) : (
    <a key={n.old} href={oldUrl(n.old)} target="_blank" rel="noopener" title={`${n.label} (old admin panel)`}>
      <i className={n.icon} /> <span>{n.label}</span> <i className="fas fa-arrow-up-right-from-square nav-ext" aria-label="opens the old page" />
    </a>
  ))

  return (
    <div className="shell">
      {navOpen && <div className="drawer-overlay" onClick={() => setNavOpen(false)} aria-hidden="true" />}
      <aside className={`sidebar${navOpen ? ' open' : ''}`} id="sidebar" aria-label="Admin menu">
        <div className="brand">{brand}</div>
        <nav>{nav(() => setNavOpen(false))}</nav>
        <div className="side-foot">
          <hr className="nav-div" />
          <a href="https://beta.dashboard.botofthespecter.com/"><i className="fas fa-home" /> <span>Beta Dashboard</span></a>
          <a href="#logout" onClick={async (e) => { e.preventDefault(); await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' }); markSignedOut(); setState({ kind: 'signed-out' }) }}>
            <i className="fas fa-sign-out-alt" /> <span>Log Out</span>
          </a>
          <div className="version-row"><Copyright /></div>
        </div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="hamburger" onClick={() => setNavOpen(true)} aria-label="Open menu" aria-controls="sidebar" aria-expanded={navOpen}>
            <i className="fa-solid fa-bars" />
          </button>
          <div className="brand brand-mobile">{brand}</div>
          <h1>{current?.label ?? 'Not found'}</h1>
          <div className="actions">
            <ThemeSwitch value={theme} onChange={choose} />
            <span className="user">
              {me.profile_image && <img src={me.profile_image} alt="" />}
              <span className="user-name">{me.display_name || me.username}</span>
            </span>
          </div>
        </header>
        <main className="content">
          {Page ? <Page /> : current ? (
            <div className="card">
              <h2 className="card-h"><i className={current.icon} /> {current.label} <span className="pill pill-off">Not ported yet</span></h2>
              <p><a className="btn btn-block" href={oldUrl(current.old)}><i className="fas fa-arrow-up-right-from-square" /> Open on the old admin panel</a></p>
            </div>
          ) : (
            <div className="card">
              <h2 className="card-h"><i className="fas fa-circle-question" /> Page not found <span className="pill pill-off">404</span></h2>
              <p><a className="btn btn-block" href="/" onClick={(e) => { e.preventDefault(); go('/') }}><i className="fas fa-tachometer-alt" /> Dashboard</a></p>
            </div>
          )}
        </main>
      </div>
    </div>
  )
}
