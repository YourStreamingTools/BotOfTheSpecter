import { useEffect, useState } from 'react'
import { holdExpiry } from '../../session'

// Closed Captions can't be stopped for a sign-in, so while the page is open:
// - the session-expired redirect is held and shown as a notice instead;
// - the beta session is kept alive with a ping every 10 minutes;
// - the Twitch token is validated on load and hourly (Twitch's rule), and the
//   server refreshes it on a 401 or when under 15 minutes are left.
// Call recheck() after any Twitch 401 to refresh straight away.
const TOKEN = '/api/twitch/token.php'
export type Upkeep = { notice: '' | 'signin' | 'session'; recheck: () => void }
let recheckNow: () => void = () => {}

export function useTokenUpkeep(): Upkeep {
  const [notice, setNotice] = useState<Upkeep['notice']>('')
  useEffect(() => {
    let timer = 0, live = true, running = false
    const release = holdExpiry(() => { if (live) setNotice('session') })
    const later = (s: number) => { clearTimeout(timer); timer = window.setTimeout(check, s * 1000) }
    async function check() {
      if (running) return
      running = true
      try {
        const r = await fetch(`${TOKEN}?op=check`, { credentials: 'same-origin' })
        if (!live) return
        if (r.status === 401) { setNotice('session'); later(600); return }
        const j = await r.json().catch(() => ({})) as { success?: boolean; next_check?: number; retry?: number; signin?: boolean }
        if (j.success) { setNotice(''); later(j.next_check ?? 3600) }
        else if (j.signin) { setNotice('signin'); later(3600) }
        else later(j.retry ?? 60)
      } catch { if (live) later(60) } finally { running = false }
    }
    recheckNow = () => { void check() }
    check()
    const ping = window.setInterval(() => { fetch(`${TOKEN}?op=ping`, { credentials: 'same-origin' }).catch(() => {}) }, 600000)
    return () => { live = false; clearTimeout(timer); clearInterval(ping); release(); recheckNow = () => {} }
  }, [])
  return { notice, recheck: () => recheckNow() }
}
