import { useEffect, useState } from 'react'
import { applyTheme, onOsChange, readSaved, saveTheme, osTheme, type ThemeName } from './theme'
import { fetchMe, loginUrl, logout, type Me } from './auth'
import { expired as markExpired, markSignedIn, markSignedOut, onExpired, signIn, signInNow, takeReturn, wasSignedIn } from './session'
import Home from './pages/Home'
import Landing from './pages/Landing'
import Bot from './pages/Bot'
import Callback from './pages/Callback'
import NotFound from './pages/NotFound'
import Profile from './pages/Profile'
import Media from './pages/Media'
import EmoteVault from './pages/EmoteVault'
import CustomCommands from './pages/commands/Custom'
import UserCommands from './pages/commands/User'
import TimedMessages from './pages/TimedMessages'
import Alerts from './pages/Alerts'
import MediaPlayer from './pages/MediaPlayer'
import Music from './pages/Music'
import ClosedCaptions from './pages/ClosedCaptions'
import Avatar from './pages/Avatar'
import Pet from './pages/Pet'
import Socials from './pages/Socials'
import WorkingStudy from './pages/WorkingStudy'
import GameTracker from './pages/GameTracker'
import Makers from './pages/Makers'
import Subathon from './pages/Subathon'
import BotPoints from './pages/BotPoints'
import PointStore from './pages/PointStore'
import ChannelRewards from './pages/ChannelRewards'
import Raffles from './pages/Raffles'
import Polls from './pages/Polls'
import Bingo from './pages/Bingo'
import Tanggle from './pages/Tanggle'
import Counters from './pages/Counters'
import StreamStreak from './pages/StreamStreak'
import ModChannels from './pages/ModChannels'
import Followers from './pages/community/Followers'
import Subscribers from './pages/community/Subscribers'
import Mods from './pages/community/Mods'
import Vips from './pages/community/Vips'
import Moderation from './pages/community/Moderation'
import Birthdays from './pages/community/Birthdays'
import Raids from './pages/community/Raids'
import Overlays from './pages/Overlays'
import WelcomeMessages from './pages/WelcomeMessages'
import BuiltinCommands from './pages/commands/Builtin'
import Premium from './pages/Premium'
import Logs from './pages/insights/Logs'
import Notifications from './pages/insights/Notifications'
import Schedule from './pages/insights/Schedule'
import Videos from './pages/insights/Videos'
import Streaming from './pages/Streaming'
import Integrations from './pages/Integrations'
import StreamElements from './pages/StreamElements'
import StreamLabs from './pages/StreamLabs'
import Modules from './pages/Modules'
import ControllerApp from './pages/ControllerApp'
import DiscordBot from './pages/DiscordBot'
import Copyright from './components/Copyright'
import ThemeSwitch from './components/ThemeSwitch'
import { NAV, navTitle, type NavGroup } from './nav'


function usePath(): [string, (p: string) => void] {
  const [path, setPath] = useState(window.location.pathname)
  useEffect(() => {
    const on = () => setPath(window.location.pathname)
    window.addEventListener('popstate', on)
    return () => window.removeEventListener('popstate', on)
  }, [])
  const go = (p: string) => {
    window.history.pushState({}, '', p)
    setPath(window.location.pathname)
    // Same-page links that only change ?tab= or #hash (e.g. YouTube to /streaming?tab=setup#youtube).
    window.dispatchEvent(new PopStateEvent('popstate'))
  }
  return [path, go]
}

export default function App() {
  const [path, go] = usePath()
  const [saved, setSaved] = useState<ThemeName | null>(readSaved())
  const [sys, setSys] = useState<ThemeName>(osTheme())
  const theme = saved ?? sys
  const [me, setMe] = useState<Me | null | undefined>(undefined)
  const [lapsed, setLapsed] = useState(false), [stuck, setStuck] = useState(false)
  useEffect(() => onExpired(() => { setMe(null); setLapsed(true); const t = setTimeout(() => { if (!signIn()) setStuck(true) }, 2500); return () => clearTimeout(t) }), [])
  const [navOpen, setNavOpen] = useState(false)
  useEffect(() => {
    if (!navOpen) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setNavOpen(false) }
    document.addEventListener('keydown', onKey)
    document.body.classList.add('no-scroll')
    return () => { document.removeEventListener('keydown', onKey); document.body.classList.remove('no-scroll') }
  }, [navOpen])
  useEffect(() => applyTheme(theme), [theme])
  useEffect(() => (saved ? undefined : onOsChange(() => setSys(osTheme()))), [saved])
  const choose = (t: ThemeName) => { saveTheme(t); setSaved(t) }
  useEffect(() => {
    if (path === '/auth/callback') return
    fetchMe().then((m) => { if (m) markSignedIn(); else if (wasSignedIn()) markExpired(); setMe(m) }).catch(() => setMe(null))
  }, [path === '/auth/callback'])

  if (path === '/auth/callback') {
    return <Callback onDone={() => { setMe(undefined); const to = takeReturn(); window.history.replaceState({}, '', to); go(to) }} />
  }
  if (me === undefined) return <div className="center"><i className="fa-solid fa-spinner fa-spin" /></div>
  if (me === null) {
    return (
      <div className="public">
        <V6Notice />
        <header className="topbar public-top">
          <div className="brand">
            <img src="https://cdn.botofthespecter.com/logo.png" alt="" />
            <span>BotOfTheSpecter</span>
            <em>beta</em>
          </div>
          <div className="actions">
            <ThemeSwitch value={theme} onChange={choose} />
            <a className="btn" href={loginUrl()}><i className="fa-brands fa-twitch" /> Login with Twitch</a>
          </div>
        </header>
        <ActAsNote />
        {lapsed && <div className="notice notice-warn sess-note" role="alert"><i className="fas fa-clock-rotate-left" /> <span>Your session expired, please sign in again.{stuck ? ' Signing in did not stick, so try once more.' : ' Taking you to sign in.'}</span> <button type="button" className="btn" onClick={signInNow}><i className="fa-brands fa-twitch" /> Sign in</button></div>}
        <Landing />
      </div>
    )
  }

  const page = path === '/' ? <Home me={me} /> : path === '/bot' ? <Bot /> : path === '/profile' ? <Profile /> : path === '/premium' ? <Premium /> : path === '/media' ? <Media /> : path === '/emote-vault' ? <EmoteVault /> : path === '/custom-commands' ? <CustomCommands /> : path === '/manage-custom-user-commands' ? <UserCommands /> : path === '/builtin' ? <BuiltinCommands /> : path === '/timed-messages' ? <TimedMessages /> : path === '/known-users' ? <WelcomeMessages /> : path === '/alerts' ? <Alerts /> : path === '/media-player' ? <MediaPlayer /> : path === '/music' ? <Music /> : path === '/overlays' ? <Overlays /> : path === '/closed-captions' ? <ClosedCaptions /> : path === '/avatar' ? <Avatar /> : path === '/pet' ? <Pet /> : path === '/socials' ? <Socials /> : path === '/working-or-study' ? <WorkingStudy /> : path === '/tracked-games' ? <GameTracker /> : path === '/makers' ? <Makers /> : path === '/subathon' ? <Subathon /> : path === '/bot-points' ? <BotPoints /> : path === '/point-store' ? <PointStore /> : path === '/channel-rewards' ? <ChannelRewards /> : path === '/raffles' ? <Raffles /> : path === '/polls' ? <Polls /> : path === '/bingo' ? <Bingo /> : path === '/tanggle' ? <Tanggle /> : path === '/counters' ? <Counters /> : path === '/stream-streak' ? <StreamStreak /> : path === '/mod-channels' ? <ModChannels /> : path === '/followers' ? <Followers /> : path === '/subscribers' ? <Subscribers /> : path === '/mods' ? <Mods /> : path === '/vips' ? <Vips /> : path === '/moderation' ? <Moderation /> : path === '/birthdays' ? <Birthdays /> : path === '/raids' ? <Raids /> : path === '/logs' ? <Logs /> : path === '/notifications' ? <Notifications /> : path === '/schedule' ? <Schedule /> : path === '/videos' ? <Videos /> : path === '/streaming' ? <Streaming /> : path === '/integrations' ? <Integrations /> : path === '/discordbot' ? <DiscordBot /> : path === '/streamelements' ? <StreamElements /> : path === '/streamlabs' ? <StreamLabs /> : path === '/controllerapp' ? <ControllerApp /> : path === '/modules' ? <Modules /> : <NotFound title={navTitle(path) ?? (path === '/mod-channels' ? 'Mod Channels' : path === '/profile' ? 'Profile' : null)} />
  const name = me.display_name || me.username || ''

  return (
    <div className="shell">
      {navOpen && <div className="drawer-overlay" onClick={() => setNavOpen(false)} aria-hidden="true" />}
      <aside className={`sidebar${navOpen ? ' open' : ''}`} id="sidebar" aria-label="Main menu">
        <div className="brand">
          <img src="https://cdn.botofthespecter.com/logo.png" alt="" />
          <span>BotOfTheSpecter</span>
          <em>beta</em>
        </div>
        <nav>
          {NAV.map((n, i) => 'divider' in n ? <hr key={i} className="nav-div" />
            : 'children' in n ? <Group key={n.label} g={n} path={path} go={(p) => { setNavOpen(false); go(p) }} />
            : (
            <a key={n.path} href={n.path} className={path === n.path ? 'active' : ''}
              onClick={(e) => { e.preventDefault(); setNavOpen(false); go(n.path) }}>
              <i className={n.icon} /> <span>{n.label}</span>
            </a>
          ))}
        </nav>
        <div className="side-foot">
          <hr className="nav-div" />
          <a href="/mod-channels" className={path === '/mod-channels' ? 'active' : ''} onClick={(e) => { e.preventDefault(); setNavOpen(false); go('/mod-channels') }}>
            <i className="fas fa-user-shield" /> <span>Mod Channels</span>
          </a>
          {me.is_admin && (
            <a href="https://beta.admin.botofthespecter.com/" target="_blank" rel="noopener" title="Admin Panel">
              <i className="fas fa-shield-alt" /> <span>Admin Panel</span>
            </a>
          )}
          <a href="/profile" className={path === '/profile' ? 'active' : ''} onClick={(e) => { e.preventDefault(); setNavOpen(false); go('/profile') }}>
            <i className="fas fa-id-card" /> <span>Profile | {me.username}</span>
          </a>
          <a href="#logout" onClick={async (e) => { e.preventDefault(); await logout(); markSignedOut(); setMe(null) }}>
            <i className="fas fa-sign-out-alt" /> <span>Log Out</span>
          </a>
          <div className="version-row"><span className="version-badge">v{me.dashboard_version || '6.0.0'}</span><Copyright /></div>
        </div>
      </aside>
      <div className="main">
        <V6Notice />
        <header className="topbar">
          <button className="hamburger" onClick={() => setNavOpen(true)} aria-label="Open menu" aria-controls="sidebar" aria-expanded={navOpen}>
            <i className="fa-solid fa-bars" />
          </button>
          <div className="brand brand-mobile"><img src="https://cdn.botofthespecter.com/logo.png" alt="" /><span>BotOfTheSpecter</span><em>beta</em></div>
          <h1>{navTitle(path) ?? (path === '/mod-channels' ? 'Mod Channels' : path === '/profile' ? 'Profile' : 'Dashboard')}</h1>
          <div className="actions">
            <ThemeSwitch value={theme} onChange={choose} />
            <span className="user">
              {me.profile_image && <img src={me.profile_image} alt="" />}
              <span className="user-name">{name}</span>
            </span>
          </div>
        </header>
        {me.acting_as && <div className="notice notice-warn act-as-bar" role="status"><i className="fas fa-user-secret" /> <span>Viewing as <strong>{me.acting_as.display_name || me.acting_as.username || 'the selected user'}</strong></span> <a className="btn" href="/api/stop_act_as.php"><i className="fas fa-arrow-right-from-bracket" /> Stop Acting As</a></div>}
        <ActAsNote />
        <main className="content">{page}</main>
      </div>
    </div>
  )
}

function Group({ g, path, go }: { g: NavGroup; path: string; go: (p: string) => void }) {
  const has = g.children.some((c) => 'path' in c && c.path === path)
  const [open, setOpen] = useState(has)
  useEffect(() => { if (has) setOpen(true) }, [has])
  return (
    <div className={`nav-group${open ? ' open' : ''}`}>
      <button className={`nav-group-head${has ? ' has-active' : ''}`} onClick={() => setOpen(!open)} aria-expanded={open}>
        <i className={g.icon} /> <span>{g.label}</span> <i className="fa-solid fa-chevron-down caret" />
      </button>
      {open && (
        <div className="nav-sub">
          {g.children.map((c, i) => 'divider' in c ? <hr key={i} className="nav-div" /> : (
            <a key={c.path} href={c.path} className={path === c.path ? 'active' : ''}
              onClick={(e) => { e.preventDefault(); go(c.path) }}>
              <i className={c.icon} /> <span>{c.label}</span>
            </a>
          ))}
        </div>
      )}
    </div>
  )
}

// Result of an Act As hand-off from beta.admin (?act_as=...), shown once.
const ACT_AS_NOTES: Record<string, string> = {
  signin: 'Sign in to the beta dashboard first, then start Act As again from the admin panel.',
  invalid: 'That Act As link is invalid or has expired. Start it again from the admin panel.',
  denied: 'Act As was refused: the admin panel and this dashboard must be signed in as the same admin.',
  not_found: 'The selected user could not be found.',
  error: 'Act As could not be started. Try again from the admin panel.',
}
function ActAsNote() {
  const [q] = useState(() => new URLSearchParams(window.location.search).get('act_as') || '')
  useEffect(() => { if (q) window.history.replaceState({}, '', window.location.pathname) }, [q])
  const text = ACT_AS_NOTES[q]
  return text ? <div className="notice notice-error act-as-bar" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{text}</span></div> : null
}

// Always shown. The old session-dismiss key is cleared so earlier dismissals no longer hide it.
try { sessionStorage.removeItem('sp-v6-notice') } catch { /* ignore */ }
function V6Notice() {
  return (
    <div className="v6-notice" role="note">
      <i className="fa-solid fa-flask" aria-hidden="true" />
      <span>This is the Version 6 dashboard, built for the upcoming Version 6 bot. Most features will become available over time as the new dashboard is worked on.</span>
    </div>
  )
}
