import { useEffect, useState } from 'react'
import { fetchSession, type HomeSession } from './api'
import { applyTheme, readTheme, type ThemeName } from './theme'
import { projectTimeHtml } from './projectTime'
import HomePage from './pages/HomePage'
import PrivacyPage from './pages/PrivacyPage'
import TermsPage from './pages/TermsPage'
import FeedbackPage from './pages/FeedbackPage'
import StatusPage from './pages/StatusPage'

type View = 'home' | 'privacy' | 'terms' | 'feedback' | 'status'

function currentView(): View {
  const p = window.location.pathname
  if (p.includes('status.php')) return 'status'
  if (p.includes('privacy-policy')) return 'privacy'
  if (p.includes('terms-of-service')) return 'terms'
  if (p.includes('feedback')) return 'feedback'
  return 'home'
}

const TITLES: Record<View, string> = {
  home: 'BotOfTheSpecter',
  privacy: 'BotOfTheSpecter - Privacy Policy',
  terms: 'BotOfTheSpecter - Terms of Service',
  feedback: 'BotOfTheSpecter — Feedback',
  status: 'BotOfTheSpecter Status',
}

const DESCRIPTIONS: Record<View, string> = {
  home: 'BotOfTheSpecter is a Twitch-first streaming platform with a Discord extension, OBS overlays, dashboard tools, and optional integrations for music, tips, and more.',
  privacy: 'Privacy Policy for BotOfTheSpecter — how we collect, use, share, and protect personal information across our Twitch bot, dashboard, overlays, and related services.',
  terms: 'Terms of Service for BotOfTheSpecter — rules for using our Twitch bot, dashboard, overlays, integrations, and related services.',
  feedback: 'Feedback and bug reports have moved to the unified support ticket system.',
  status: 'Live BotOfTheSpecter system status.',
}

const empty: HomeSession = {
  ok: false,
  logged_in: false,
  username: null,
  display_name: null,
  dashboard_version: '',
}

export default function App() {
  const view = currentView()
  const [session, setSession] = useState<HomeSession | null>(null)
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())
  const [mobileOpen, setMobileOpen] = useState(false)
  const [uptime] = useState(() => projectTimeHtml())

  useEffect(() => {
    applyTheme(theme, false)
  }, [theme])

  useEffect(() => {
    fetchSession().then(setSession).catch(() => setSession(empty))
  }, [])

  useEffect(() => {
    document.title = TITLES[view]
    const meta = document.querySelector('meta[name="description"]')
    if (meta) meta.setAttribute('content', DESCRIPTIONS[view])
  }, [view])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMobileOpen(false)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    if (!mobileOpen) return
    const onClick = (e: Event) => {
      const nav = document.getElementById('hsMobileNav')
      const btn = document.querySelector('.hs-hamburger')
      const t = e.target as Node | null
      if (t && nav && btn && !nav.contains(t) && !btn.contains(t)) setMobileOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [mobileOpen])

  useEffect(() => {
    const existing = document.getElementById('hs-jsonld')
    if (existing) existing.remove()
    const payloads: Record<View, Record<string, string> | null> = {
      home: {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: 'BotOfTheSpecter',
        description: DESCRIPTIONS.home,
        url: 'https://botofthespecter.com/',
      },
      privacy: {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: 'BotOfTheSpecter - Privacy Policy',
        description: DESCRIPTIONS.privacy,
        url: 'https://botofthespecter.com/privacy-policy.php',
      },
      terms: {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: 'BotOfTheSpecter - Terms of Service',
        description: DESCRIPTIONS.terms,
        url: 'https://botofthespecter.com/terms-of-service.php',
      },
      feedback: {
        '@context': 'https://schema.org',
        '@type': 'WebPage',
        name: 'BotOfTheSpecter - Feedback',
        description: DESCRIPTIONS.feedback,
        url: 'https://botofthespecter.com/feedback.php',
      },
      status: null,
    }
    const payload = payloads[view]
    if (!payload) return
    const el = document.createElement('script')
    el.type = 'application/ld+json'
    el.id = 'hs-jsonld'
    el.text = JSON.stringify(payload)
    document.head.appendChild(el)
    return () => {
      el.remove()
    }
  }, [view])

  if (view === 'status') {
    return <StatusPage />
  }

  const s = session || empty
  const displayName = s.display_name || s.username || 'Account'
  const year = new Date().getFullYear()

  return (
    <>
      <nav className="hs-topnav" role="navigation" aria-label="main navigation">
        <div className="hs-topnav-inner">
          <a href="/" className="hs-topnav-brand" aria-label="BotOfTheSpecter Home">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" width={32} height={32} />
            <span className="hs-topnav-brand-name">BotOfTheSpecter</span>
          </a>
          <div className="hs-topnav-links" id="hsDesktopNav">
            <a href="/" className={'hs-topnav-link' + (view === 'home' ? ' active' : '')}>
              Home
            </a>
            <a href="/privacy-policy.php" className={'hs-topnav-link' + (view === 'privacy' ? ' active' : '')}>
              Privacy Policy
            </a>
            <a href="/terms-of-service.php" className={'hs-topnav-link' + (view === 'terms' ? ' active' : '')}>
              Terms of Service
            </a>
            <a href="/feedback.php" className={'hs-topnav-link' + (view === 'feedback' ? ' active' : '')}>
              Feedback
            </a>
          </div>
          <div className="hs-topnav-right">
            <button
              className="hs-theme-toggle"
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
            <a href="https://dashboard.botofthespecter.com/dashboard.php" className="hs-btn hs-btn-primary hs-btn-sm">
              <i className="fa-solid fa-gauge-high" /> Dashboard
            </a>
            <button
              className={'hs-hamburger' + (mobileOpen ? ' open' : '')}
              aria-label="Toggle navigation menu"
              aria-expanded={mobileOpen}
              aria-controls="hsMobileNav"
              onClick={() => setMobileOpen((v) => !v)}
            >
              <span /><span /><span />
            </button>
          </div>
        </div>
      </nav>
      <div className={'hs-mobile-nav' + (mobileOpen ? ' open' : '')} id="hsMobileNav" role="navigation" aria-label="mobile navigation">
        <a href="/" className={'hs-topnav-link' + (view === 'home' ? ' active' : '')} onClick={() => setMobileOpen(false)}>
          <i className="fa-solid fa-house" /> Home
        </a>
        <a
          href="/privacy-policy.php"
          className={'hs-topnav-link' + (view === 'privacy' ? ' active' : '')}
          onClick={() => setMobileOpen(false)}
        >
          <i className="fa-solid fa-shield-halved" /> Privacy Policy
        </a>
        <a
          href="/terms-of-service.php"
          className={'hs-topnav-link' + (view === 'terms' ? ' active' : '')}
          onClick={() => setMobileOpen(false)}
        >
          <i className="fa-solid fa-file-lines" /> Terms of Service
        </a>
        <a
          href="/feedback.php"
          className={'hs-topnav-link' + (view === 'feedback' ? ' active' : '')}
          onClick={() => setMobileOpen(false)}
        >
          <i className="fa-solid fa-comment" /> Feedback
        </a>
        <div className="hs-mobile-nav-cta">
          <a
            href="https://dashboard.botofthespecter.com/dashboard.php"
            className="hs-btn hs-btn-primary"
            style={{ width: '100%', justifyContent: 'center' }}
          >
            <i className="fa-solid fa-gauge-high" /> Dashboard
          </a>
        </div>
      </div>
      <main className="hs-main">
        <div className="hs-container">
          {view === 'privacy' && <PrivacyPage />}
          {view === 'terms' && <TermsPage />}
          {view === 'feedback' && <FeedbackPage />}
          {view === 'home' && <HomePage loggedIn={s.logged_in} displayName={displayName} />}
        </div>
      </main>
      <footer className="hs-footer" role="contentinfo">
        <div className="hs-footer-inner">
          <div className="hs-footer-version">
            <span className="hs-version-badge">Dashboard v{s.dashboard_version || '…'}</span>
          </div>
          <p>
            &copy; 2023&ndash;{year} BotOfTheSpecter. All rights reserved.
            <br />
            {uptime.since}
            <br />
            {uptime.elapsed ? (
              <>
                {uptime.elapsed}
                <br />
              </>
            ) : null}
            BotOfTheSpecter is operated under the business name &ldquo;YourStreamingTools&rdquo;, registered in Australia
            (ABN&nbsp;20&nbsp;447&nbsp;022&nbsp;747).
            <br />
            Not affiliated with Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., StreamElements Inc., or
            Kick Streaming Pty Ltd.
            <br />
            All trademarks and brand names are property of their respective owners and are used for identification purposes
            only.
          </p>
        </div>
      </footer>
    </>
  )
}
