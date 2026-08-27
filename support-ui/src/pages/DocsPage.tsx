import { useEffect, useMemo, useRef, useState } from 'react'
import { DOC_PANELS } from '../docs/panels'
import { apiGet } from '../api'

const CARDS: { id: string; icon: string; title: string; desc: string }[] = [
  { id: 'setup', icon: 'fa-solid fa-rocket', title: 'First Time Setup', desc: 'Get the bot running on your channel.' },
  { id: 'features', icon: 'fa-solid fa-star', title: 'Main Features', desc: 'Commands, games, events, tracking, and integrations.' },
  { id: 'spotify', icon: 'fa-brands fa-spotify', title: 'Spotify Setup', desc: 'Create your own Spotify app and link it.' },
  { id: 'tts', icon: 'fa-solid fa-microphone', title: 'Text-to-Speech', desc: 'Normal and Expressive voices, Channel Points TTS, and setup tips.' },
  { id: 'obs-audio', icon: 'fa-solid fa-headphones', title: 'OBS Audio Monitoring', desc: 'Hear overlay alerts, TTS, and walk-ons in OBS.' },
  { id: 'variables', icon: 'fa-solid fa-code', title: 'Variables', desc: 'Dynamic tokens for commands, timers, rewards, and event alerts.' },
  { id: 'twitch-channel-points', icon: 'fa-brands fa-twitch', title: 'Channel Points', desc: 'Sync rewards and automate redemption responses.' },
  { id: 'api', icon: 'fa-solid fa-satellite-dish', title: 'Custom API', desc: 'Auth, endpoints, and code samples for integrations.' },
  { id: 'run-yourself', icon: 'fa-solid fa-server', title: 'Run Yourself', desc: 'Self-host Specter on your own servers.' },
  { id: 'commands', icon: 'fa-solid fa-terminal', title: 'Command Reference', desc: 'All built-in bot commands.' },
  { id: 'faq', icon: 'fa-solid fa-circle-question', title: 'FAQ', desc: 'Frequently asked questions.' },
  { id: 'troubleshooting', icon: 'fa-solid fa-wrench', title: 'Troubleshooting', desc: 'Common issues and solutions.' },
]

const PANEL_IDS = [...Object.keys(DOC_PANELS), 'commands']

type CmdInfo = {
  description?: string
  aliases?: string[]
  syntax?: string | string[]
  force_level?: string
}

function hashId(): string {
  return window.location.hash.replace('#', '')
}

function setHash(id: string) {
  const next = '#' + id
  if (window.location.hash !== next) {
    history.replaceState(null, '', next)
  }
  try {
    sessionStorage.setItem('sp_active_tab', id)
  } catch {
    /* ignore */
  }
}

export default function DocsPage({ loggedIn }: { loggedIn: boolean }) {
  const [active, setActive] = useState(() => {
    const h = hashId()
    if (PANEL_IDS.includes(h)) return h
    try {
      const stored = sessionStorage.getItem('sp_active_tab') || ''
      if (PANEL_IDS.includes(stored)) return stored
    } catch {
      /* ignore */
    }
    return 'setup'
  })
  const [query, setQuery] = useState('')
  const [commands, setCommands] = useState<Record<string, CmdInfo> | null>(null)
  const rootRef = useRef<HTMLDivElement>(null)

  function go(id: string, scroll = false) {
    if (!PANEL_IDS.includes(id)) return
    setActive(id)
    setHash(id)
    if (scroll) {
      requestAnimationFrame(() => {
        const el = rootRef.current?.querySelector('.sp-tab-panel.active')
        el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      })
    }
  }

  useEffect(() => {
    const onHash = () => {
      const h = hashId()
      if (PANEL_IDS.includes(h)) setActive(h)
    }
    window.addEventListener('hashchange', onHash)
    if (hashId()) onHash()
    else setHash(active)
    return () => window.removeEventListener('hashchange', onHash)
  }, [])

  useEffect(() => {
    apiGet('/api/commands.php').then((data: { commands?: Record<string, CmdInfo> }) => {
      setCommands(data?.commands || {})
    }).catch(() => setCommands({}))
  }, [])

  useEffect(() => {
    apiGet('/api/tts-voices.php').then((data: { normal?: Record<string, string>; expressive?: Array<Record<string, string>> }) => {
      const normalHost = document.getElementById('react-tts-normal')
      if (normalHost && data?.normal) {
        normalHost.innerHTML = Object.entries(data.normal).map(([key, desc]) => {
          const label = key.charAt(0).toUpperCase() + key.slice(1)
          return `<div class="sp-card">
            <div class="sp-card-header">${label}</div>
            <div class="sp-card-body">
              <p style="color:var(--text-secondary);font-size:0.9rem;">${desc}</p>
              <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm voice-play-button" style="margin-top:0.75rem;" data-voice="${key}">
                <i class="fa-solid fa-play"></i> Play Sample
              </button>
              <audio id="audio-${key}" preload="none" style="display:none;">
                <source src="https://cdn.botofthespecter.com/help/tts/${key}_sample.mp3" type="audio/mpeg">
                <source src="https://cdn.botofthespecter.com/help/tts/${key}_sample.wav" type="audio/wav">
              </audio>
            </div>
          </div>`
        }).join('')
      }
      const exHost = document.getElementById('react-tts-expressive')
      if (exHost) {
        const list = Array.isArray(data?.expressive) ? data.expressive : []
        if (!list.length) {
          exHost.innerHTML = `<div class="sp-alert sp-alert-info" style="margin-top:1rem;">
            <i class="fa-solid fa-circle-info"></i>
            <div>Expressive voice samples will appear here once they are published to the CDN.</div>
          </div>`
        } else {
          exHost.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1rem;">` +
            list.map((ex) => {
              const name = String(ex.name || ex.slug || 'Voice')
              const file = String(ex.file || ex.filename || '')
              const audioId = String(ex.slug || name).replace(/[^a-z0-9_-]/gi, '')
              return `<div class="sp-card">
                <div class="sp-card-header">${name}</div>
                <div class="sp-card-body">
                  <p style="color:var(--text-secondary);font-size:0.9rem;">Multilingual expressive voice</p>
                  <button type="button" class="sp-btn sp-btn-secondary sp-btn-sm voice-play-button" style="margin-top:0.75rem;" data-voice="${audioId}">
                    <i class="fa-solid fa-play"></i> Play Sample
                  </button>
                  <audio id="audio-${audioId}" preload="none" style="display:none;">
                    <source src="https://cdn.botofthespecter.com/help/tts/expressive/${file}" type="audio/mpeg">
                  </audio>
                </div>
              </div>`
            }).join('') + '</div>'
        }
      }
      bindTts(document.querySelector('.sp-tab-panel[data-panel="tts"]'))
    }).catch(() => undefined)
  }, [])

  useEffect(() => {
    const root = rootRef.current
    if (!root) return

    function onClick(e: Event) {
      const t = e.target as HTMLElement
      const goto = t.closest('[data-goto]') as HTMLElement | null
      if (goto?.dataset.goto) {
        e.preventDefault()
        go(goto.dataset.goto, true)
        return
      }
      const copy = t.closest('.sp-copy-link') as HTMLElement | null
      if (copy?.dataset.copyId) {
        e.preventDefault()
        const url = window.location.origin + '/index.php#' + copy.dataset.copyId
        const original = copy.innerHTML
        const done = () => {
          copy.innerHTML = '<i class="fa-solid fa-check"></i>'
          setTimeout(() => { copy.innerHTML = original }, 1500)
        }
        if (navigator.clipboard?.writeText) {
          navigator.clipboard.writeText(url).then(done).catch(done)
        } else {
          done()
        }
        return
      }
      const faq = t.closest('.sp-faq-q') as HTMLElement | null
      if (faq) {
        const item = faq.closest('.sp-faq-item')
        if (!item) return
        const wasOpen = item.classList.contains('open')
        rootRef.current?.querySelectorAll('.sp-faq-item.open').forEach((el) => el.classList.remove('open'))
        if (!wasOpen) item.classList.add('open')
      }
    }
    root.addEventListener('click', onClick)
    return () => root.removeEventListener('click', onClick)
  }, [])

  const searchHits = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (q.length < 2) return []
    const hits: { title: string; section: string; tab: string }[] = []
    for (const card of CARDS) {
      const html = card.id === 'commands' ? '' : (DOC_PANELS[card.id] || '')
      const tmp = document.createElement('div')
      tmp.innerHTML = html
      tmp.querySelectorAll('h2, h3, h4, .sp-faq-q').forEach((el) => {
        const title = (el.textContent || '').trim()
        if (title.toLowerCase().includes(q)) {
          hits.push({ title, section: card.title, tab: card.id })
        }
      })
    }
    return hits.slice(0, 8)
  }, [query])

  const cmdEntries = commands ? Object.entries(commands) : null

  return (
    <div ref={rootRef}>
      <div className="sp-hero" style={{ textAlign: 'center', padding: '1.5rem 1rem 1.25rem', borderBottom: '1px solid var(--border)', marginBottom: '1.5rem' }}>
        <img src="https://cdn.botofthespecter.com/logo.png" alt="BotOfTheSpecter" style={{ width: 72, height: 72, borderRadius: '50%', margin: '0 auto 1rem', border: '2px solid var(--border)', display: 'block' }} />
        <h1 style={{ fontSize: '1.75rem', fontWeight: 800, marginBottom: '0.5rem' }}>BotOfTheSpecter Documentation</h1>
        <p style={{ color: 'var(--text-secondary)', maxWidth: 560, margin: '0 auto 1.5rem' }}>
          Everything you need to set up, configure, and get the most from your streaming bot.
        </p>
        <div style={{ display: 'flex', gap: '0.75rem', justifyContent: 'center', flexWrap: 'wrap' }}>
          <a href="https://github.com/YourStreamingTools/BotOfTheSpecter" target="_blank" rel="noopener" className="sp-btn sp-btn-secondary">
            <i className="fa-brands fa-github" /> View on GitHub
          </a>
          {loggedIn ? (
            <a href="/tickets.php?action=new" className="sp-btn sp-btn-primary">
              <i className="fa-solid fa-ticket" /> Submit a Support Ticket
            </a>
          ) : (
            <a href="/login.php" className="sp-btn sp-btn-primary">
              <i className="fa-brands fa-twitch" /> Log in to Submit a Ticket
            </a>
          )}
        </div>
      </div>

      <div className="sp-search-wrap" id="sp-search-wrap" style={{ maxWidth: 480, margin: '0 auto 1.5rem', display: 'block', position: 'relative' }}>
        <i className="fa-solid fa-magnifying-glass sp-search-icon" />
        <input
          type="text"
          className="sp-search-input"
          placeholder="Search docs…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          autoComplete="off"
          spellCheck={false}
          aria-label="Search documentation"
        />
        {query.trim().length >= 2 && (
          <div id="sp-search-results" className="open">
            {searchHits.length === 0 ? (
              <div className="sp-search-no-results">No results for “{query}”</div>
            ) : searchHits.map((hit) => (
              <a
                key={hit.tab + hit.title}
                className="sp-search-result-item"
                href={'#' + hit.tab}
                onClick={(e) => { e.preventDefault(); setQuery(''); go(hit.tab, true) }}
              >
                <span className="sp-search-result-title">{hit.title}</span>
                <span className="sp-search-result-section">{hit.section}</span>
              </a>
            ))}
          </div>
        )}
      </div>

      <div className="sp-doc-grid sp-mb-3">
        {CARDS.map((card) => (
          <a
            key={card.id}
            href={'#' + card.id}
            className={'sp-doc-card' + (active === card.id ? ' active' : '')}
            data-goto={card.id}
            onClick={(e) => { e.preventDefault(); go(card.id, true) }}
          >
            <div className="sp-doc-card-icon"><i className={card.icon} /></div>
            <div className="sp-doc-card-title">{card.title}</div>
            <div className="sp-doc-card-desc">{card.desc}</div>
          </a>
        ))}
      </div>

      {Object.entries(DOC_PANELS).map(([id, html]) => (
        <div
          key={id}
          className={'sp-tab-panel sp-doc-content' + (active === id ? ' active' : '')}
          data-panel={id}
          dangerouslySetInnerHTML={{ __html: html }}
        />
      ))}

      <div className={'sp-tab-panel sp-doc-content' + (active === 'commands' ? ' active' : '')} data-panel="commands">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '1rem', marginBottom: '1.5rem' }}>
          <div>
            <h1 style={{ margin: '0 0 0.25rem' }}>Command Reference</h1>
            <p style={{ margin: 0, color: 'var(--text-secondary)' }}>All commands use the <code>!</code> prefix. Some require moderator or broadcaster permissions.</p>
          </div>
          <button type="button" className="sp-btn sp-btn-ghost sp-btn-sm sp-copy-link" data-copy-id="commands" title="Copy link to this section">
            <i className="fa-solid fa-link" /> Copy link
          </button>
        </div>
        {cmdEntries === null ? (
          <p style={{ color: 'var(--text-secondary)' }}>Loading commands…</p>
        ) : cmdEntries.length === 0 ? (
          <div className="sp-alert sp-alert-warning">
            <i className="fa-solid fa-triangle-exclamation" />
            <span>Command list unavailable - could not reach the commands API.</span>
          </div>
        ) : (
          <div className="sp-table-wrap">
            <table className="sp-table sp-table-no-hover">
              <thead>
                <tr>
                  <th style={{ width: '18%' }}>Command</th>
                  <th style={{ width: '35%' }}>Description</th>
                  <th>Syntax</th>
                </tr>
              </thead>
              <tbody>
                {cmdEntries.map(([name, info]) => {
                  const aliases = info.aliases || []
                  const syntaxRaw = info.syntax
                  const syntaxList = Array.isArray(syntaxRaw) ? syntaxRaw : (syntaxRaw ? [syntaxRaw] : [])
                  const isMod = info.force_level === 'mod'
                  return (
                    <tr key={name}>
                      <td>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', flexWrap: 'wrap' }}>
                          <code>!{name}</code>
                          {isMod && <span className="sp-badge sp-badge-muted" title="Requires moderator or broadcaster">Mod</span>}
                        </div>
                        {aliases.length > 0 && (
                          <div style={{ marginTop: '0.35rem', display: 'flex', flexWrap: 'wrap', gap: '0.3rem' }}>
                            {aliases.map((alias) => <code key={alias} style={{ fontSize: '0.78rem', color: 'var(--text-secondary)' }}>!{alias}</code>)}
                          </div>
                        )}
                      </td>
                      <td>{info.description || 'No description available'}</td>
                      <td>
                        {syntaxList.length > 0 && (
                          <div className="sp-cmd-examples">
                            {syntaxList.map((ex) => <span key={ex} className="sp-cmd-example">{ex}</span>)}
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <div className="sp-alert sp-alert-info sp-mt-2">
          <i className="fa-solid fa-circle-info" />
          <span>Type <code>!commands</code> in your Twitch chat to see all active commands, including custom ones.</span>
        </div>
      </div>
    </div>
  )
}

function bindTts(panel: Element | null) {
  if (!panel) return
  let currentVoice: string | null = null
  panel.querySelectorAll('.voice-play-button[data-voice]').forEach((btnEl) => {
    const btn = btnEl as HTMLButtonElement
    if (btn.dataset.bound === '1') return
    btn.dataset.bound = '1'
    btn.addEventListener('click', () => {
      const voiceName = btn.getAttribute('data-voice')
      const audio = document.getElementById('audio-' + voiceName) as HTMLAudioElement | null
      if (!audio || !voiceName) return
      if (currentVoice === voiceName && !audio.paused) {
        audio.pause()
        audio.currentTime = 0
        btn.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample'
        currentVoice = null
        return
      }
      panel.querySelectorAll('audio').forEach((a) => {
        a.pause()
        ;(a as HTMLAudioElement).currentTime = 0
      })
      panel.querySelectorAll('.voice-play-button').forEach((b) => {
        b.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample'
      })
      audio.play().catch(() => {
        alert('Could not play audio sample. The file may not be available.')
      })
      btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop'
      currentVoice = voiceName
      audio.onended = () => {
        btn.innerHTML = '<i class="fa-solid fa-play"></i> Play Sample'
        currentVoice = null
      }
    })
  })
}
