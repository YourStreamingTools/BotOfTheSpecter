import { useEffect, useState } from 'react'

// Topbar "Dev Stream Online" pill, as on the live dashboard: shown while twitch.tv/gfaundead is live.
// Checked after first paint and every 5 minutes; any failure just keeps it hidden.
export default function DevStreamPill() {
  const [online, setOnline] = useState(false)
  useEffect(() => {
    let alive = true
    const check = () => fetch('/api/dev-stream/status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : null)).then((d) => { if (alive) setOnline(!!d?.online) }).catch(() => {})
    check()
    const t = window.setInterval(check, 5 * 60 * 1000)
    return () => { alive = false; window.clearInterval(t) }
  }, [])
  if (!online) return null
  return (
    <a className="dev-pill" href="https://twitch.tv/gfaundead" target="_blank" rel="noopener noreferrer" title="Watch the dev stream on twitch.tv/gfaundead">
      <i className="fas fa-video" /> <span>Dev Stream Online</span><span className="dev-pill-ch"> &mdash; twitch.tv/gfaundead</span>
    </a>
  )
}
