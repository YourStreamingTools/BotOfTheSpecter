import { useEffect, useState, type MouseEvent, type ReactNode } from 'react'
import { applyTheme, readTheme, type ThemeName } from './theme'

const EMAIL = 'contact@yourstreamingtools.com'
const NAV_OFFSET = 72
const LOGO = 'https://cdn.yourstreamingtools.com/img/logo.ico'

function scrollToId(id: string) {
  const target = document.getElementById(id.replace(/^#/, ''))
  if (!target) return
  const top = target.getBoundingClientRect().top + window.pageYOffset - NAV_OFFSET
  window.scrollTo({ top, behavior: 'smooth' })
}

function onHashClick(e: MouseEvent<HTMLAnchorElement>) {
  const href = e.currentTarget.getAttribute('href')
  if (!href || !href.startsWith('#') || href === '#') return
  const el = document.querySelector(href)
  if (!el) return
  e.preventDefault()
  scrollToId(href)
}

function useReveal() {
  useEffect(() => {
    const nodes = document.querySelectorAll('.hs-card, .yst-section-header, .yst-contact-card, .yst-stat')
    if (!('IntersectionObserver' in window)) {
      nodes.forEach((el) => el.classList.add('is-visible'))
      return
    }
    nodes.forEach((el) => el.classList.add('yst-reveal'))
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
            io.unobserve(entry.target)
          }
        })
      },
      { threshold: 0.1 },
    )
    nodes.forEach((el) => io.observe(el))
    return () => io.disconnect()
  }, [])
}

function StatNumber({ value }: { value: number }) {
  const [n, setN] = useState(0)
  const [node, setNode] = useState<HTMLDivElement | null>(null)
  useEffect(() => {
    if (!node) return
    let raf = 0
    const run = () => {
      const duration = 1200
      const t0 = performance.now()
      const tick = (now: number) => {
        const p = Math.min(1, (now - t0) / duration)
        const eased = 1 - Math.pow(1 - p, 3)
        setN(Math.round(value * eased))
        if (p < 1) raf = requestAnimationFrame(tick)
      }
      raf = requestAnimationFrame(tick)
    }
    if (!('IntersectionObserver' in window)) {
      run()
      return () => cancelAnimationFrame(raf)
    }
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          run()
          io.disconnect()
        }
      },
      { threshold: 0.4 },
    )
    io.observe(node)
    return () => {
      io.disconnect()
      cancelAnimationFrame(raf)
    }
  }, [node, value])
  return (
    <div className="yst-stat-number" data-count={value} ref={setNode}>
      {n}
    </div>
  )
}

function fireEmail() {
  const Swal = window.Swal
  if (!Swal) {
    window.location.href = 'mailto:' + EMAIL
    return
  }
  void Swal.fire({
    icon: 'info',
    title: 'Reach us by email',
    html:
      '<p style="margin:0 0 1rem;">Drop us a line at:</p>' +
      '<code style="display:inline-block;padding:0.5rem 0.9rem;border-radius:8px;' +
      'background:var(--bg-input);border:1px solid var(--border);' +
      'color:var(--accent-hover);font-weight:600;">' +
      EMAIL +
      '</code>',
    showDenyButton: true,
    confirmButtonText: '<i class="fa-solid fa-copy"></i> Copy address',
    denyButtonText: '<i class="fa-solid fa-paper-plane"></i> Open mail app',
  }).then((res) => {
    if (res.isConfirmed) {
      navigator.clipboard
        .writeText(EMAIL)
        .then(() => {
          void Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'Email address copied to clipboard.',
            timer: 1800,
            showConfirmButton: false,
          })
        })
        .catch(() => {
          window.location.href = 'mailto:' + EMAIL
        })
    } else if (res.isDenied) {
      window.location.href = 'mailto:' + EMAIL
    }
  })
}

function fireSuggest() {
  const Swal = window.Swal
  if (!Swal) {
    window.location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('Tool suggestion')
    return
  }
  void Swal.fire({
    title: 'Suggest a tool',
    html:
      '<input id="swal-name" class="swal2-input" placeholder="Your name (optional)">' +
      '<input id="swal-idea" class="swal2-input" placeholder="What should we build?">' +
      '<textarea id="swal-detail" class="swal2-textarea" placeholder="Why is this useful?"></textarea>',
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: '<i class="fa-solid fa-paper-plane"></i> Send idea',
    preConfirm: () => {
      const idea = (document.getElementById('swal-idea') as HTMLInputElement | null)?.value.trim() || ''
      if (!idea) {
        Swal.showValidationMessage('Tell us what to build, even just a sentence.')
        return false
      }
      return {
        name: (document.getElementById('swal-name') as HTMLInputElement | null)?.value.trim() || '',
        idea,
        detail: (document.getElementById('swal-detail') as HTMLTextAreaElement | null)?.value.trim() || '',
      }
    },
  }).then((res) => {
    if (!res.isConfirmed || !res.value) return
    const subject = encodeURIComponent('Tool suggestion: ' + res.value.idea)
    const body = encodeURIComponent(
      'From: ' + (res.value.name || 'Anonymous') + '\n\nIdea:\n' + res.value.idea + '\n\nDetails:\n' + (res.value.detail || '(none)'),
    )
    void Swal.fire({
      icon: 'success',
      title: 'Thanks!',
      html: 'Opening your mail app to send this to <code>' + EMAIL + '</code>.',
      timer: 2200,
      showConfirmButton: false,
    })
    window.setTimeout(() => {
      window.location.href = 'mailto:' + EMAIL + '?subject=' + subject + '&body=' + body
    }, 600)
  })
}

function NavLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a className="hs-topnav-link" href={href} onClick={onHashClick}>
      {children}
    </a>
  )
}

export default function App() {
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())
  const [mobileOpen, setMobileOpen] = useState(false)
  const [backTop, setBackTop] = useState(false)
  const year = new Date().getFullYear()

  useEffect(() => {
    applyTheme(theme, false)
  }, [theme])

  useEffect(() => {
    const onStorage = (e: StorageEvent) => {
      if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) {
        setTheme(e.newValue)
      }
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  useEffect(() => {
    const onScroll = () => {
      const y = window.pageYOffset || document.documentElement.scrollTop
      setBackTop(y > 600)
    }
    window.addEventListener('scroll', onScroll, { passive: true })
    onScroll()
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

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

  useReveal()

  const closeMobile = () => setMobileOpen(false)
  const onMobileNav = (e: MouseEvent<HTMLAnchorElement>) => {
    onHashClick(e)
    closeMobile()
  }

  return (
    <>
      <a className="skip-link" href="#main">
        Skip to content
      </a>

      <nav className="hs-topnav" id="siteNav" role="navigation" aria-label="main navigation">
        <div className="hs-topnav-inner">
          <a className="hs-topnav-brand" href="#home" aria-label="YourStreamingTools home" onClick={onHashClick}>
            <img src={LOGO} alt="" width={32} height={32} aria-hidden="true" />
            <span className="hs-topnav-brand-name">
              YourStreaming<span className="accent">Tools</span>
            </span>
          </a>
          <div className="hs-topnav-links" id="hsDesktopNav">
            <NavLink href="#about">About</NavLink>
            <NavLink href="#team">Team</NavLink>
            <NavLink href="#projects">Projects</NavLink>
            <NavLink href="#contact">Contact</NavLink>
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
            <a className="hs-btn hs-btn-primary hs-btn-sm" href="https://botofthespecter.com/" target="_blank" rel="noopener">
              <i className="fa-solid fa-robot" aria-hidden="true" /> BotOfTheSpecter
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
        <a className="hs-topnav-link" href="#about" onClick={onMobileNav}>
          <i className="fa-solid fa-circle-info" aria-hidden="true" /> About
        </a>
        <a className="hs-topnav-link" href="#team" onClick={onMobileNav}>
          <i className="fa-solid fa-users" aria-hidden="true" /> Team
        </a>
        <a className="hs-topnav-link" href="#projects" onClick={onMobileNav}>
          <i className="fa-solid fa-cubes" aria-hidden="true" /> Projects
        </a>
        <a className="hs-topnav-link" href="#contact" onClick={onMobileNav}>
          <i className="fa-solid fa-envelope" aria-hidden="true" /> Contact
        </a>
        <div className="hs-mobile-nav-cta">
          <a className="hs-btn hs-btn-primary hs-btn-block" href="https://botofthespecter.com/" target="_blank" rel="noopener">
            <i className="fa-solid fa-robot" aria-hidden="true" /> BotOfTheSpecter
          </a>
        </div>
      </div>

      <main className="hs-main" id="main">
        <section className="yst-hero" id="home">
          <div className="yst-hero-bg" aria-hidden="true" />
          <div className="yst-hero-inner hs-container">
            <div className="hs-hero-eyebrow">
              <i className="fa-solid fa-circle-dot" aria-hidden="true" /> Built for the streaming community
            </div>
            <h1 className="hs-hero-title">
              Streaming tools
              <br />
              <span className="yst-gradient-text">that just work.</span>
            </h1>
            <p className="hs-hero-tagline">
              By Streamers, For Streamers — open, focused utilities to help you broadcast better, grow your community, and spend
              less time fighting your tools.
            </p>
            <div className="hs-hero-ctas">
              <a className="hs-btn hs-btn-primary hs-btn-lg" href="#projects" onClick={onHashClick}>
                <i className="fa-solid fa-rocket" aria-hidden="true" />
                <span>Explore Projects</span>
              </a>
              <a className="hs-btn hs-btn-ghost hs-btn-lg" href="#team" onClick={onHashClick}>
                <i className="fa-solid fa-users" aria-hidden="true" />
                <span>Meet the Team</span>
              </a>
            </div>
            <div className="yst-hero-tech">
              <span className="yst-tech-label">Powered by</span>
              <i className="fa-brands fa-twitch" title="Twitch" aria-label="Twitch" />
              <i className="fa-brands fa-discord" title="Discord" aria-label="Discord" />
              <i className="fa-brands fa-github" title="GitHub" aria-label="GitHub" />
              <i className="fa-brands fa-youtube" title="YouTube" aria-label="YouTube" />
              <i className="fa-brands fa-node-js" title="Node.js" aria-label="Node.js" />
              <i className="fa-brands fa-php" title="PHP" aria-label="PHP" />
            </div>
          </div>
          <a className="yst-hero-scroll" href="#about" aria-label="Scroll to about" onClick={onHashClick}>
            <i className="fa-solid fa-chevron-down" aria-hidden="true" />
          </a>
        </section>

        <section className="yst-section" id="about">
          <div className="hs-container">
            <header className="yst-section-header">
              <span className="yst-eyebrow">About</span>
              <h2 className="yst-section-title">Built from the chat, for the chat.</h2>
              <p className="yst-section-subtitle">
                YourStreamingTools was born out of the same frustrations every streamer hits: clunky overlays, fragile bots, and
                tools that never quite fit. So we built our own.
              </p>
            </header>
            <div className="yst-stats">
              <div className="yst-stat">
                <div className="yst-stat-icon">
                  <i className="fa-solid fa-calendar-days" aria-hidden="true" />
                </div>
                <StatNumber value={2} />
                <div className="yst-stat-label">
                  Years active<span className="muted">+</span>
                </div>
              </div>
              <div className="yst-stat">
                <div className="yst-stat-icon">
                  <i className="fa-solid fa-cubes" aria-hidden="true" />
                </div>
                <StatNumber value={5} />
                <div className="yst-stat-label">
                  Live projects<span className="muted">+</span>
                </div>
              </div>
              <div className="yst-stat">
                <div className="yst-stat-icon">
                  <i className="fa-solid fa-people-group" aria-hidden="true" />
                </div>
                <StatNumber value={50} />
                <div className="yst-stat-label">
                  Streamers helped<span className="muted">+</span>
                </div>
              </div>
              <div className="yst-stat">
                <div className="yst-stat-icon">
                  <i className="fa-solid fa-code-branch" aria-hidden="true" />
                </div>
                <div className="yst-stat-number">OSS</div>
                <div className="yst-stat-label">Open source friendly</div>
              </div>
            </div>
          </div>
        </section>

        <section className="yst-section yst-section-alt" id="team">
          <div className="hs-container">
            <header className="yst-section-header">
              <span className="yst-eyebrow">Team</span>
              <h2 className="yst-section-title">The people behind it.</h2>
              <p className="yst-section-subtitle">A small crew with big plans — and a community that keeps us honest.</p>
            </header>
            <div className="yst-grid yst-grid-2">
              <article className="hs-card yst-team-card">
                <div className="yst-team-avatar">
                  <img
                    src="https://static-cdn.jtvnw.net/jtv_user_pictures/64ee8bd4-94bc-4aa0-8dc4-d1f9537d2f9d-profile_image-300x300.png"
                    alt="Lachlan (gfaUnDead)"
                    width={96}
                    height={96}
                  />
                </div>
                <h3 className="hs-card-title">
                  Lachlan <span className="muted">(gfaUnDead)</span>
                </h3>
                <p className="yst-card-eyebrow">Lead Developer &amp; Founder</p>
                <p className="yst-card-text">
                  The dev behind YourStreamingTools, bringing real-world streaming experience to every project — and a strong
                  opinion on bot uptime.
                </p>
                <div className="yst-tags">
                  <span className="yst-tag">
                    <i className="fa-brands fa-php" aria-hidden="true" /> PHP
                  </span>
                  <span className="yst-tag">
                    <i className="fa-brands fa-node-js" aria-hidden="true" /> Node
                  </span>
                  <span className="yst-tag">
                    <i className="fa-brands fa-python" aria-hidden="true" /> Python
                  </span>
                </div>
              </article>
              <article className="hs-card yst-team-card">
                <div className="yst-team-avatar">
                  <i className="fa-solid fa-people-group" aria-hidden="true" />
                </div>
                <h3 className="hs-card-title">The Community</h3>
                <p className="yst-card-eyebrow">Contributors &amp; Testers</p>
                <p className="yst-card-text">
                  Streamers, mods, and viewers who file bugs, ship PRs, and tell us when our ideas are bad. Our roadmap lives in
                  their feedback.
                </p>
                <div className="yst-tags">
                  <span className="yst-tag">
                    <i className="fa-brands fa-discord" aria-hidden="true" /> Discord
                  </span>
                  <span className="yst-tag">
                    <i className="fa-brands fa-github" aria-hidden="true" /> GitHub
                  </span>
                  <span className="yst-tag">
                    <i className="fa-brands fa-twitch" aria-hidden="true" /> Twitch
                  </span>
                </div>
              </article>
            </div>
          </div>
        </section>

        <section className="yst-section" id="projects">
          <div className="hs-container">
            <header className="yst-section-header">
              <span className="yst-eyebrow">Projects</span>
              <h2 className="yst-section-title">What we ship.</h2>
              <p className="yst-section-subtitle">Tools you can actually use, today — and a few we&apos;re still cooking.</p>
            </header>
            <div className="yst-grid yst-grid-3">
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-robot" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-live">
                    <i className="fa-solid fa-circle" aria-hidden="true" /> Live
                  </span>
                </div>
                <h3 className="hs-card-title">BotOfTheSpecter</h3>
                <p className="yst-card-eyebrow">Advanced Twitch chat bot</p>
                <p className="yst-card-text">
                  A full-featured Twitch chat bot with moderation, custom commands, integrations, and a dashboard built for
                  serious streamers.
                </p>
                <a href="https://botofthespecter.com/" target="_blank" rel="noopener" className="hs-btn hs-btn-primary hs-btn-block">
                  <i className="fa-solid fa-arrow-up-right-from-square" aria-hidden="true" />
                  <span>Visit project</span>
                </a>
              </article>
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-music" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-live">
                    <i className="fa-solid fa-circle" aria-hidden="true" /> Live
                  </span>
                </div>
                <h3 className="hs-card-title">DMCA-Free Music</h3>
                <p className="yst-card-eyebrow">Browser-source music player</p>
                <p className="yst-card-text">
                  DMCA-safe music for streamers, with automatic VoD track separation so your archive stays clean even when the
                  live mix is loud.
                </p>
                <a href="https://botofthespecter.com/" target="_blank" rel="noopener" className="hs-btn hs-btn-secondary hs-btn-block">
                  <i className="fa-solid fa-link" aria-hidden="true" />
                  <span>Inside BotOfTheSpecter</span>
                </a>
              </article>
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-list-check" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-live">
                    <i className="fa-solid fa-circle" aria-hidden="true" /> Live
                  </span>
                </div>
                <h3 className="hs-card-title">YourListOnline</h3>
                <p className="yst-card-eyebrow">Todo lists for creators</p>
                <p className="yst-card-text">
                  A focused, no-nonsense todo manager — perfect for stream prep, content calendars, and shipping that side
                  project.
                </p>
                <a href="https://botofthespecter.com/" target="_blank" rel="noopener" className="hs-btn hs-btn-secondary hs-btn-block">
                  <i className="fa-solid fa-link" aria-hidden="true" />
                  <span>Inside BotOfTheSpecter</span>
                </a>
              </article>
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-clock" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-archived">
                    <i className="fa-solid fa-box-archive" aria-hidden="true" /> Archived
                  </span>
                </div>
                <h3 className="hs-card-title">API Services</h3>
                <p className="yst-card-eyebrow">Time, weather &amp; quote APIs</p>
                <p className="yst-card-text">
                  The legacy APIs that powered our earliest overlays. Retired, but the lessons live on inside today&apos;s
                  projects.
                </p>
                <button type="button" className="hs-btn hs-btn-ghost hs-btn-block" disabled>
                  <i className="fa-solid fa-box-archive" aria-hidden="true" />
                  <span>Legacy service</span>
                </button>
              </article>
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-screwdriver-wrench" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-dev">
                    <i className="fa-solid fa-hammer" aria-hidden="true" /> In dev
                  </span>
                </div>
                <h3 className="hs-card-title">Streaming Tools</h3>
                <p className="yst-card-eyebrow">Utilities, overlays &amp; widgets</p>
                <p className="yst-card-text">
                  A growing kit of small, opinionated tools — countdowns, alert relays, scene helpers — that solve specific
                  streaming problems well.
                </p>
                <button type="button" className="hs-btn hs-btn-ghost hs-btn-block" disabled>
                  <i className="fa-solid fa-hammer" aria-hidden="true" />
                  <span>In development</span>
                </button>
              </article>
              <article className="hs-card">
                <div className="yst-project-head">
                  <span className="yst-project-icon">
                    <i className="fa-solid fa-lightbulb" aria-hidden="true" />
                  </span>
                  <span className="yst-status-pill yst-status-soon">
                    <i className="fa-solid fa-sparkles" aria-hidden="true" /> Soon
                  </span>
                </div>
                <h3 className="hs-card-title">What&apos;s Next</h3>
                <p className="yst-card-eyebrow">Future projects</p>
                <p className="yst-card-text">
                  Ideas brewing in the lab — analytics, smarter overlays, community tooling. Want a say in what ships next?
                </p>
                <button type="button" className="hs-btn hs-btn-secondary hs-btn-block" onClick={fireSuggest}>
                  <i className="fa-solid fa-comment-dots" aria-hidden="true" />
                  <span>Suggest a tool</span>
                </button>
              </article>
            </div>
          </div>
        </section>

        <section className="yst-section yst-section-alt" id="contact">
          <div className="hs-container">
            <header className="yst-section-header">
              <span className="yst-eyebrow">Contact</span>
              <h2 className="yst-section-title">Say hi.</h2>
              <p className="yst-section-subtitle">Questions, feedback, collabs, weird ideas — all welcome.</p>
            </header>
            <div className="yst-contact-card">
              <div className="yst-contact-blurb">
                <h3>Pick the channel that fits.</h3>
                <p>
                  We hang out in Discord most, ship code on GitHub, and do shouts on Twitter. Email works too — promise we
                  read it.
                </p>
              </div>
              <div className="yst-contact-grid">
                <a className="yst-contact-tile" href="https://github.com/YourStreamingTools" target="_blank" rel="noopener">
                  <i className="fa-brands fa-github" aria-hidden="true" />
                  <div>
                    <strong>GitHub</strong>
                    <span>Source &amp; issues</span>
                  </div>
                </a>
                <a className="yst-contact-tile" href="https://discord.com/invite/ANwEkpauHJ" target="_blank" rel="noopener">
                  <i className="fa-brands fa-discord" aria-hidden="true" />
                  <div>
                    <strong>Discord</strong>
                    <span>Community chat</span>
                  </div>
                </a>
                <a className="yst-contact-tile" href="https://twitter.com/Tools4Streaming" target="_blank" rel="noopener">
                  <i className="fa-brands fa-x-twitter" aria-hidden="true" />
                  <div>
                    <strong>Twitter / X</strong>
                    <span>Updates &amp; news</span>
                  </div>
                </a>
                <a
                  className="yst-contact-tile"
                  href="#"
                  onClick={(e) => {
                    e.preventDefault()
                    fireEmail()
                  }}
                >
                  <i className="fa-solid fa-envelope" aria-hidden="true" />
                  <div>
                    <strong>Email</strong>
                    <span>{EMAIL}</span>
                  </div>
                </a>
              </div>
            </div>
          </div>
        </section>
      </main>

      <footer className="hs-footer" role="contentinfo">
        <div className="yst-footer-inner">
          <div className="yst-footer-brand">
            <img src={LOGO} alt="" width={32} height={32} aria-hidden="true" />
            <div>
              <strong>YourStreamingTools</strong>
              <span className="muted">By Streamers, For Streamers</span>
            </div>
          </div>
          <div className="yst-footer-links">
            <a href="#about" onClick={onHashClick}>
              About
            </a>
            <a href="#projects" onClick={onHashClick}>
              Projects
            </a>
            <a href="#contact" onClick={onHashClick}>
              Contact
            </a>
            <a href="https://github.com/YourStreamingTools" target="_blank" rel="noopener">
              GitHub
            </a>
          </div>
          <div className="yst-footer-meta">
            <p>
              &copy; {year} YourStreamingTools. Made with <i className="fa-solid fa-heart yst-heart" aria-hidden="true" /> for
              the streaming community.
            </p>
          </div>
        </div>
      </footer>

      <button
        className={'yst-back-top' + (backTop ? ' is-visible' : '')}
        aria-label="Back to top"
        onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
      >
        <i className="fa-solid fa-arrow-up" aria-hidden="true" />
      </button>
    </>
  )
}
