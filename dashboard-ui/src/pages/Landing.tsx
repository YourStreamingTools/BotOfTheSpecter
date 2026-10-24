import Copyright, { sinceLaunch } from '../components/Copyright'
import { useEffect, useState } from 'react'
import { loginUrl } from '../auth'

const FEATURES: Array<[string, string, string]> = [
  ['fa-robot', 'Bot Control', 'Start, stop, and monitor your bot with real-time status updates and comprehensive logging.'],
  ['fa-terminal', 'Custom Commands', 'Create and manage custom chat commands with advanced features and permission levels.'],
  ['fa-chart-line', 'Analytics & Logs', "Track your channel's growth, monitor user activity, and analyze command usage statistics."],
  ['fa-gift', 'Channel Rewards', 'Manage Twitch channel point rewards and create engaging interactive experiences.'],
  ['fa-volume-high', 'Stream Alerts', 'Configure sound alerts, video alerts, and walk-on alerts for followers and subscribers.'],
  ['fa-plug', 'Integrations', 'Connect with Discord, Spotify, StreamElements, and other popular streaming platforms.'],
  ['fa-coins', 'Points System', 'Reward your viewers with a custom points system and create point-based mini-games.'],
  ['fa-layer-group', 'Stream Overlays', 'Create dynamic overlays for recent followers, latest donations, and now playing music.'],
  ['fa-users', 'User Management', 'Manage your community with tools for moderators, VIPs, subscribers, and regular viewers.'],
]

const NEW: Array<[string, string, string]> = [
  ['fa-list-ol', 'Song request limits', 'Cap songs per viewer in the queue and per stream. Mods, the broadcaster and the bot owner are never limited.'],
  ['fa-ticket', '!permitsong and !srreset', 'Let a viewer skip every song limit, or reset per-stream counts for everyone or one viewer.'],
  ['fa-right-left', 'Cleaner !convert replies', 'Unit conversions show friendly symbols, like "100°F is 37.78°C".'],
  ['fa-clock-rotate-left', 'Rerun aware timers', 'Timed messages pause while a VOD rerun plays.'],
  ['fa-table-cells', 'Stream Bingo', 'Called events are grouped into one chat line.'],
]

const PLANS: Array<{ name: string; price: string; icon: string; tone: string; features: string[]; free?: boolean }> = [
  { name: 'Free', price: '$0/month', icon: 'fa-rocket', tone: 'muted', free: true,
    features: ['Core Bot Features', 'Community Support', '20MB Storage', 'Shared Bot Name (BotOfTheSpecter)', 'Custom Bot Name (Experimental/Coming Soon)'] },
  { name: 'Tier 1', price: '$4.99/month', icon: 'fa-star', tone: 'blue', features: ['Song Request Command', 'Priority Support', 'Beta Access', '50MB Storage'] },
  { name: 'Tier 2', price: '$9.99/month', icon: 'fa-crown', tone: 'amber', features: ['Everything in Tier 1', 'Personal Support', 'AI Features', '100MB Storage'] },
  { name: 'Tier 3', price: '$24.99/month', icon: 'fa-gem', tone: 'red', features: ['Everything in Tier 2', '200MB Storage'] },
]



export default function Landing() {
  const [version, setVersion] = useState<string | null>(null)
  useEffect(() => {
    fetch('/api/public.php').then((r) => r.json()).then((j) => setVersion(j.dashboard_version ?? null)).catch(() => {})
  }, [])

  return (
    <div className="landing">
      <section className="l-hero">
        <img src="https://cdn.botofthespecter.com/logo.png" alt="" />
        <h1>BotOfTheSpecter Dashboard</h1>
        <p className="l-sub">Your Complete Twitch Bot Management Solution</p>
        <p className="l-desc">Take control of your Twitch channel with our powerful, feature-rich bot and dashboard. Manage commands, configure your alerts, track analytics, and so much more.</p>
        <div className="l-cta">
          <a className="btn btn-lg" href={loginUrl()}><i className="fa-brands fa-twitch" /> Login with Twitch</a>
          <a className="btn ghost btn-lg" href="#features">See features</a>
        </div>
        <p className="l-note"><i className="fa-solid fa-shield-halved" /> Your data is secure and protected using SHA-384 encryption.</p>
      </section>

      <section className="l-section">
        <div className="l-head">
          <span className="tag">5.9</span>
          <h2>What's new</h2>
        </div>
        <div className="l-grid">
          {NEW.map(([icon, t, d]) => (
            <div className="card l-feature is-new" key={t}>
              <i className={`fa-solid ${icon}`} />
              <h4>{t}</h4>
              <p>{d}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="l-section" id="features">
        <div className="l-head">
          <h2>Dashboard Features</h2>
          <p>Explore the powerful features that make BotOfTheSpecter the ultimate Twitch bot management solution, with many more features that aren't listed below.</p>
        </div>
        <div className="l-grid">
          {FEATURES.map(([icon, t, d]) => (
            <div className="card l-feature" key={t}>
              <i className={`fa-solid ${icon}`} />
              <h4>{t}</h4>
              <p>{d}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="l-section">
        <div className="l-head">
          <h2>Premium Plans</h2>
          <p>Unlock additional features and support the development of BotOfTheSpecter by subscribing to one of our premium plans via Twitch.</p>
        </div>
        <div className="l-plans">
          {PLANS.map((p) => (
            <div className={`card l-plan${p.free ? ' is-free' : ''}`} key={p.name}>
              <i className={`fa-solid ${p.icon} tone-${p.tone}`} />
              <h3>{p.name}</h3>
              <div className="l-price">{p.price}</div>
              <ul>{p.features.map((f) => <li key={f}><i className="fa-solid fa-check" /> {f}</li>)}</ul>
              {p.free ? (
                <>
                  <a className="btn" href={loginUrl()}><i className="fa-solid fa-right-to-bracket" /> Get Started</a>
                  <p className="l-free"><b>90-95% of the bot is FREE!</b></p>
                </>
              ) : (
                <a className="btn ghost" href="https://www.twitch.tv/subs/gfaundead" target="_blank" rel="noopener"><i className="fa-solid fa-circle-plus" /> Subscribe</a>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className="l-section">
        <div className="card l-final">
          <h2>Access Your Dashboard</h2>
          <p className="muted">Join the rest of the streamers who use BotOfTheSpecter to enhance and manage their Twitch channel.</p>
          <a className="btn btn-lg" href={loginUrl()}><i className="fa-brands fa-twitch" /> Login with Twitch</a>
        </div>
      </section>

      <footer className="l-footer">
        <p>&copy; 2023&ndash;{new Date().getFullYear()} BotOfTheSpecter. All rights reserved.</p>
        <p>Project has been running since 17th October 2023, 11:54:58 AEDT. As of now, it's been {sinceLaunch()} since launch.</p>
        <p>BotOfTheSpecter is a project operated under the business name &ldquo;YourStreamingTools&rdquo;, registered in Australia (ABN 20 447 022 747).</p>
        <p>This website is not affiliated with or endorsed by Twitch Interactive, Inc., Discord Inc., Spotify AB, Live Momentum Ltd., or StreamElements Inc.</p>
        <p>All trademarks, logos, and brand names are the property of their respective owners and are used for identification purposes only.</p>
        <span className="landing-legal">{version && <span className="pill">Dashboard v{version}</span>}<Copyright /></span>
      </footer>
    </div>
  )
}
