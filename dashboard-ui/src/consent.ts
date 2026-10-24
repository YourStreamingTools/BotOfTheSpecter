// Cookie consent, matching the old dashboard: cookie "cookie_consent" = "accepted" | "declined", path=/.
// Written for .botofthespecter.com so the main dashboard sees the same choice.
export type Consent = 'accepted' | 'declined' | null
const NAME = 'cookie_consent'
const EVENT = 'sp-consent-change'
// Optional preference storage that the old site only keeps with consent (old: selectedBot, weather_location_valid).
const OPTIONAL_LOCAL = ['sp-beta-bot', 'alertsSoundJob']
const OPTIONAL_COOKIES = ['selectedBot', 'weather_location_valid', 'show_enabled_commands', 'show_disabled_commands', 'tm_filter', 'ku_filter', 'al_filter']

function domainAttr(): string {
  return /(^|\.)botofthespecter\.com$/.test(location.hostname) ? ';domain=.botofthespecter.com' : ''
}

export function getConsent(): Consent {
  const m = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]+)/)
  const v = m ? decodeURIComponent(m[1]) : ''
  return v === 'accepted' || v === 'declined' ? v : null
}

// days: banner uses 7 (accept) / 14 (decline) like the old banner; Profile uses no expiry like the old Profile page.
export function setConsent(v: 'accepted' | 'declined', days?: number) {
  const exp = days ? new Date(Date.now() + days * 864e5).toUTCString() : 'Fri, 31 Dec 9999 23:59:59 GMT'
  document.cookie = `${NAME}=${v};expires=${exp};path=/;SameSite=Lax;Secure${domainAttr()}`
  if (v === 'declined') clearOptional()
  window.dispatchEvent(new CustomEvent(EVENT, { detail: v }))
}

export function hasConsent(): boolean { return getConsent() === 'accepted' }

function clearOptional() {
  for (const k of OPTIONAL_LOCAL) { try { localStorage.removeItem(k) } catch { /* ignore */ } }
  for (const c of OPTIONAL_COOKIES) {
    document.cookie = `${c}=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/`
    document.cookie = `${c}=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/${domainAttr()}`
  }
}

export function onConsentChange(fn: (v: Consent) => void): () => void {
  const h = () => fn(getConsent())
  window.addEventListener(EVENT, h)
  return () => window.removeEventListener(EVENT, h)
}
