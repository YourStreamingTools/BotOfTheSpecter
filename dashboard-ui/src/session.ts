// Session expiry, handled once for the whole app. Any 401 from a beta API
// (fetch or XHR) clears the signed-in state, shows the expired notice and
// sends the user to sign in, then back to the page they were on.
import { loginUrl as dashboardLogin } from './auth'

// The admin panel signs in through its own endpoint, so it swaps this in.
let loginUrl: () => string = dashboardLogin
export const setLoginUrl = (fn: () => string) => { loginUrl = fn }

const RETURN = 'sp-return', LAST = 'sp-expired-at', SIGNED = 'sp-signed-in'
let fired = false
const listeners = new Set<() => void>()
export const onExpired = (fn: () => void) => { listeners.add(fn); return () => { listeners.delete(fn) } }
export const markSignedIn = () => { localStorage.setItem(SIGNED, '1'); fired = false }
export const markSignedOut = () => localStorage.removeItem(SIGNED)
export const wasSignedIn = () => localStorage.getItem(SIGNED) === '1'

// Only same-site paths, never the auth pages themselves.
const safe = (p: string | null) => (p && p.startsWith('/') && !p.startsWith('//') && !p.startsWith('/auth') ? p : null)
export const takeReturn = () => { const p = safe(sessionStorage.getItem(RETURN)); sessionStorage.removeItem(RETURN); return p ?? '/' }
export const rememberHere = () => { const p = safe(window.location.pathname + window.location.search + window.location.hash); if (p && p !== '/') sessionStorage.setItem(RETURN, p) }

// A second expiry within a minute of the last redirect means sign-in is not
// sticking, so stop redirecting and leave the notice up with a button.
export function signIn() {
  rememberHere()
  const last = Number(sessionStorage.getItem(LAST) || 0)
  if (Date.now() - last < 60000) return false
  sessionStorage.setItem(LAST, String(Date.now()))
  window.location.assign(loginUrl())
  return true
}
export const signInNow = () => { rememberHere(); sessionStorage.setItem(LAST, String(Date.now())); window.location.assign(loginUrl()) }

// A page that must keep running (Closed Captions) takes over expiry while it is
// open: no redirect, no sign-out, it shows its own notice instead.
let holder: (() => void) | null = null
export const holdExpiry = (fn: () => void) => { holder = fn; return () => { if (holder === fn) holder = null } }

export function expired() {
  if (holder) { holder(); return }
  if (fired) return
  fired = true
  markSignedOut()
  rememberHere()
  listeners.forEach((fn) => fn())
}
// me.php answers 401 for "not signed in" on load; App decides what that means.
const watched = (u: string) => { try { const x = new URL(u, window.location.href); return x.origin === window.location.origin && x.pathname.startsWith('/api/') && !/\/api\/(me|callback|logout|public)\.php$/.test(x.pathname) } catch { return false } }

export function installSessionGuard() {
  const f = window.fetch.bind(window)
  window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
    const r = await f(input, init)
    if (r.status === 401 && watched(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url)) expired()
    return r
  }
  const open = XMLHttpRequest.prototype.open
  XMLHttpRequest.prototype.open = function (this: XMLHttpRequest & { __u?: string }, method: string, url: string | URL, ...rest: unknown[]) {
    this.__u = String(url)
    this.addEventListener('load', () => { if (this.status === 401 && watched(this.__u!)) expired() })
    return (open as (...a: unknown[]) => void).call(this, method, url, ...rest)
  } as typeof XMLHttpRequest.prototype.open
}
