// StreamersConnect sign-in, same request as dashboard/login.php. The callback
// params are verified server-side by /api/callback.php with the API key.
const SC_BASE = 'https://streamersconnect.com/'
const SCOPES = 'openid channel:bot moderator:manage:chat_messages user:read:moderated_channels moderator:read:blocked_terms moderator:read:chat_settings moderator:read:vips moderator:read:moderators moderator:read:unban_requests moderator:read:banned_users moderator:read:chat_messages moderator:read:warnings user:bot channel:read:goals channel:moderate channel:manage:moderators user:edit:broadcast channel:manage:redemptions channel:manage:polls channel:manage:predictions moderator:manage:automod moderator:read:suspicious_users channel:read:hype_train channel:manage:broadcast channel:manage:raids channel:read:charity user:read:email user:read:chat user:write:chat user:read:follows chat:read chat:edit moderation:read moderator:read:followers channel:read:redemptions channel:read:vips channel:manage:vips user:read:subscriptions channel:read:subscriptions moderator:read:chatters bits:read channel:manage:ads channel:read:ads channel:manage:schedule channel:manage:clips editor:manage:clips clips:edit moderator:manage:announcements moderator:manage:banned_users moderator:manage:chat_messages moderator:read:shoutouts moderator:manage:shoutouts user:read:blocked_users user:manage:blocked_users'

export type Me = {
  user_id: number | null
  username: string | null
  display_name: string | null
  profile_image: string | null
  twitch_user_id: string
  is_admin?: boolean
  dashboard_version?: string
  acting_as?: { user_id: number; username: string; display_name: string } | null
}

export function loginUrl(): string {
  const params = new URLSearchParams({
    service: 'twitch',
    login: window.location.host,
    scopes: SCOPES,
    return_url: `${window.location.origin}/auth/callback`,
  })
  return `${SC_BASE}?${params.toString()}`
}

export async function fetchMe(): Promise<Me | null> {
  const r = await fetch('/api/me.php', { credentials: 'same-origin' })
  if (r.status === 401) return null
  if (!r.ok) throw new Error(`me: ${r.status}`)
  return (await r.json()) as Me
}

// server_token is single-use, so the exchange must run once even if React
// mounts the callback twice (StrictMode).
let pending: Promise<{ ok: boolean; error?: string }> | null = null
export function completeLogin(): Promise<{ ok: boolean; error?: string }> {
  if (!pending) pending = doCompleteLogin()
  return pending
}

async function doCompleteLogin(): Promise<{ ok: boolean; error?: string }> {
  const q = new URLSearchParams(window.location.search)
  if (q.get('error')) return { ok: false, error: q.get('error')! }
  const body = { auth_data_sig: q.get('auth_data_sig'), server_token: q.get('server_token') }
  if (!body.auth_data_sig && !body.server_token) return { ok: false, error: 'missing_auth' }
  const r = await fetch('/api/callback.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const j = await r.json().catch(() => ({}))
  return r.ok && j.ok ? { ok: true } : { ok: false, error: j.error ?? `http_${r.status}` }
}

export async function logout(): Promise<void> {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' })
}
