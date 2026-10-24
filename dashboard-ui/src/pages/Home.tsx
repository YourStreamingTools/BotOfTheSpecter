import { useEffect, useState } from 'react'
import type { Me } from '../auth'
import { fetchHome, type HomeData } from '../home'

// In-app navigation, same pushState + popstate the sidebar uses.
function goTo(e: React.MouseEvent, path: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return
  e.preventDefault(); window.history.pushState({}, '', path); window.dispatchEvent(new PopStateEvent('popstate')); window.scrollTo(0, 0)
}
const fmt = (n: number | undefined) => (n ?? 0).toLocaleString()

function uptime(s: number): string {
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
  return h ? `${h}h ${m}m` : `${m}m`
}

function ago(iso: string | null): string {
  if (!iso) return ''
  const d = (Date.now() - new Date(iso).getTime()) / 1000
  if (d < 60) return 'just now'
  if (d < 3600) return `${Math.floor(d / 60)}m ago`
  if (d < 86400) return `${Math.floor(d / 3600)}h ago`
  return `${Math.floor(d / 86400)}d ago`
}

const LIFETIME: Array<[string, string, string]> = [
  ['commands', 'Commands run', 'fa-terminal'],
  ['songs', 'Songs requested', 'fa-music'],
  ['welcomed', 'Viewers welcomed', 'fa-hand'],
  ['shoutouts', 'Shoutouts', 'fa-bullhorn'],
  ['rewards', 'Rewards fulfilled', 'fa-gift'],
  ['quotes', 'Quotes saved', 'fa-quote-left'],
  ['deaths', 'Deaths tracked', 'fa-skull'],
  ['points', 'Points given', 'fa-coins'],
  ['interactions', 'Hugs, kisses, high fives', 'fa-heart'],
]

type Win = Record<string, unknown>
function growth(w: Win, k: string): [string, string | null] {
  const v = w[k] as unknown
  if (k === 'subs' && v && typeof v === 'object') {
    const o = v as Record<string, number>
    const parts = [['T1', o.t1], ['T2', o.t2], ['T3', o.t3], ['Prime', o.prime]].filter(([, n]) => n).map(([l, n]) => `${l} ${n}`)
    return [fmt(o.total), parts.length ? parts.join(' · ') : null]
  }
  if (k === 'tips' && v && typeof v === 'object') {
    const o = v as { count: number; amount: number }
    return [o.amount.toLocaleString(undefined, { maximumFractionDigits: 2 }), `${fmt(o.count)} tip${o.count === 1 ? '' : 's'}`]
  }
  if (k === 'raids' && v && typeof v === 'object') {
    const o = v as { count: number; viewers: number }
    return [fmt(o.count), `${fmt(o.viewers)} viewers`]
  }
  return [fmt(typeof v === 'number' ? v : 0), null]
}

const WINDOW: Array<[string, string]> = [
  ['followers', 'New followers'], ['subs', 'New subs'], ['bits', 'Bits'], ['tips', 'Tips'],
  ['raids', 'Raids'], ['new_viewers', 'New viewers'], ['new_quotes', 'New quotes'], ['chat_messages', 'Chat messages'],
]

const EVENT: Record<string, [string, string]> = {
  follow: ['fa-heart', 'followed'], sub: ['fa-star', 'subscribed'], cheer: ['fa-gem', 'cheered'],
  tip: ['fa-sack-dollar', 'tipped'], raid: ['fa-people-group', 'raided'], redeem: ['fa-gift', 'redeemed'],
  quote: ['fa-quote-left', 'quote added'],
}

const ACTIONS: Array<[string, string, string, string]> = [
  ['Bot control', 'Start, stop and update your bot', 'fa-robot', '/bot'],
  ['Commands', 'Custom commands and responses', 'fa-terminal', '/custom-commands'],
  ['Media player', 'Song queue, limits and requesters', 'fa-circle-play', '/media-player'],
  ['Channel rewards', 'Channel point rewards', 'fa-gift', '/channel-rewards'],
  ['Alerts', 'Alerts and ad break colour', 'fa-bell', '/alerts'],
  ['Discord bot', 'Discord bot settings', 'fa-brands fa-discord', '/discordbot'],
]

const NEW: Array<[string, string]> = [
  ['Song request limits', 'Max per viewer in the queue (0 = unlimited) and a new max per viewer per stream. Mods, the broadcaster and the bot owner are never limited.'],
  ['!permitsong @user [count]', 'Lets a viewer skip every song request limit for that many requests.'],
  ['!srreset [@user]', 'Resets per-stream song counts for everyone or one viewer. Counts also reset when you go live.'],
  ['Timed messages', 'Pause while a VOD rerun plays and start again after it.'],
  ['Stream Bingo', 'Events called close together are grouped into one chat line.'],
  ['!convert', 'Replies use friendly unit symbols, like "100°F is 37.78°C".'],
]

function Skeleton({ rows = 3 }: { rows?: number }) {
  return <div className="skel-list">{Array.from({ length: rows }, (_, i) => <span key={i} className="skel" />)}</div>
}

function Board<T>({ title, icon, items, render }: { title: string; icon: string; items: T[] | undefined; render: (x: T) => [string, string] }) {
  return (
    <div className="card board">
      <h3><i className={`fa-solid ${icon}`} /> {title}</h3>
      {items === undefined ? <Skeleton rows={5} />
        : items.length === 0 ? <p className="empty">Nothing yet</p>
        : <ol>{items.map((x, i) => { const [a, b] = render(x); return <li key={i}><span>{a}</span><b>{b}</b></li> })}</ol>}
    </div>
  )
}

export default function Home({ me }: { me: Me }) {
  const [win, setWin] = useState('7d')
  const [data, setData] = useState<HomeData | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    setError(false)
    fetchHome(win).then(setData).catch(() => setError(true))
  }, [win])

  const live = data?.live
  const lb = data ? data.leaderboards : undefined
  const items = data?.activity?.items

  return (
    <div className="home">
      <section className="hero card">
        <div className="hero-main">
          {me.profile_image && <img className="hero-avatar" src={me.profile_image} alt="" />}
          <div>
            <h2>Welcome back, {me.display_name || me.username}</h2>
            {!data && !error ? <span className="skel w-40" />
              : live ? (
                <div className="live-line">
                  <span className={`pill ${live.online ? 'pill-live' : 'pill-off'}`}>
                    <span className="dot" /> {live.online ? 'Live' : 'Offline'}
                  </span>
                  {live.online && <span className="muted">{fmt(live.viewer_count)} viewers · {uptime(live.uptime_seconds)}</span>}
                  {live.online && live.game && <span className="muted">{live.game}</span>}
                </div>
              ) : <span className="muted">Stream status unavailable</span>}
            {live?.online && live.title && <p className="hero-title">{live.title}</p>}
          </div>
        </div>
        {data?.dashboard_version && <span className="pill">v{data.dashboard_version}</span>}
      </section>

      {error && (
        <div className="card notice notice-error">
          <i className="fa-solid fa-triangle-exclamation" /> Could not load your stats.
          <button className="ghost sm" onClick={() => { setData(null); setError(false); fetchHome(win).then(setData).catch(() => setError(true)) }}>Retry</button>
        </div>
      )}

      <section>
        <div className="section-head"><h3>What your bot did for you <span className="tag">all time</span></h3></div>
        <div className="stat-grid">
          {LIFETIME.map(([k, label, icon]) => (
            <div className="card stat" key={k}>
              <i className={`fa-solid ${icon}`} />
              <div>
                {data?.summary ? <b>{fmt(data.summary.lifetime[k])}</b> : error ? <b>-</b> : <span className="skel w-55" />}
                <span>{label}</span>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section>
        <div className="section-head">
          <h3>Channel growth</h3>
          <div className="seg" role="tablist">
            {[['today', 'Today'], ['7d', '7 days'], ['30d', '30 days']].map(([v, l]) => (
              <button key={v} className={win === v ? 'on' : ''} onClick={() => win !== v && (setData(null), setWin(v))}>{l}</button>
            ))}
          </div>
        </div>
        <div className="stat-grid small">
          {WINDOW.map(([k, label]) => (
            <div className="card stat" key={k}>
              <div>
                {data?.summary ? (() => {
                  const [val, sub] = growth(data.summary.window as Win, k)
                  return <><b>{val}</b><span>{label}</span>{sub && <small>{sub}</small>}</>
                })() : <>{error ? <b>-</b> : <span className="skel w-40" />}<span>{label}</span></>}
              </div>
            </div>
          ))}
        </div>
      </section>

      <div className="two-col">
        <section>
          <div className="section-head"><h3>Quick actions</h3></div>
          <div className="action-grid">
            {ACTIONS.map(([t, d, icon, href]) => (
              <a className="card action" key={href} href={href}
                onClick={(e) => goTo(e, href)}>
                <i className={icon.startsWith('fa-brands') ? icon : `fa-solid ${icon}`} />
                <div><b>{t}</b><span>{d}</span></div>
              </a>
            ))}
          </div>
        </section>
        <section>
          <div className="section-head"><h3>Recent activity</h3></div>
          <div className="card feed">
            {items === undefined && !error ? <Skeleton rows={6} />
              : !items || items.length === 0 ? <p className="empty">No follows, subs, cheers or raids yet. They show up here as they happen.</p>
              : <ul>{items.map((e, i) => {
                  const [icon, verb] = EVENT[e.type] ?? ['fa-circle', e.type]
                  return (
                    <li key={i}>
                      <i className={`fa-solid ${icon}`} />
                      <span><b>{e.actor || 'Someone'}</b> {verb}{e.detail ? ` ${e.detail}` : ''}</span>
                      <time>{ago(e.at)}</time>
                    </li>
                  )
                })}</ul>}
          </div>
        </section>
      </div>

      <section>
        <div className="section-head"><h3>Community</h3></div>
        <div className="board-grid">
          <Board title="Top commands" icon="fa-terminal" items={lb === undefined ? undefined : lb?.top_commands ?? []} render={(x) => [`!${x.command}`, fmt(x.count)]} />
          <Board title="Chat leaders" icon="fa-comments" items={lb === undefined ? undefined : lb?.chat_leaders ?? []} render={(x) => [x.username, fmt(x.messages)]} />
          <Board title="Top songs" icon="fa-music" items={lb === undefined ? undefined : lb?.top_songs ?? []} render={(x) => [x.artist ? `${x.song} · ${x.artist}` : x.song, fmt(x.count)]} />
          <Board title="Top rewards" icon="fa-gift" items={lb === undefined ? undefined : lb?.top_rewards ?? []} render={(x) => [x.reward_title, fmt(x.count)]} />
          <Board title="Watch streaks" icon="fa-fire" items={lb === undefined ? undefined : lb?.streaks ?? []} render={(x) => [x.username, `${x.current} (best ${x.highest})`]} />
          <Board title="Deaths by game" icon="fa-skull" items={lb === undefined ? undefined : lb?.deaths_by_game ?? []} render={(x) => [x.game, fmt(x.deaths)]} />
        </div>
      </section>

      <section>
        <div className="section-head"><h3>New in 5.9</h3></div>
        <div className="card whatsnew">
          {NEW.map(([t, d]) => <div key={t}><b>{t}</b><span>{d}</span></div>)}
        </div>
      </section>

      <section>
        <div className="section-head"><h3>Links</h3></div>
        <div className="links">
          <a className="btn ghost" href="https://botofthespecter.com/sso.php?target=support" target="_blank" rel="noopener"><i className="fa-solid fa-life-ring" /> Support</a>
          <a className="btn ghost" href="https://botofthespecter.com/sso.php?target=roadmap" target="_blank" rel="noopener"><i className="fa-solid fa-map" /> Roadmap</a>
          <a className="btn ghost" href="https://yourchat.botofthespecter.com/" target="_blank" rel="noopener"><i className="fa-solid fa-comments" /> YourChat</a>
          <a className="btn ghost" href="/music" onClick={(e) => goTo(e, '/music')}><i className="fa-solid fa-music" /> DMCA-free music</a>
        </div>
      </section>
    </div>
  )
}
