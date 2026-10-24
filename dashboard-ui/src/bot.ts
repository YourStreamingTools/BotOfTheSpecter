// Beta mirrors of the live Bot Control endpoints (public/api/bot/*.php).
export type BotType = 'stable' | 'beta' | 'v6' | 'kick'

export type Overview = {
  is_technical: boolean; is_acting_as: boolean
  has_verified_custom_bot: boolean; custom_bot_username: string | null
  kick_connected: boolean; kick_channel_name: string
  use_custom: number; use_self: number; use_custom_module: number
}
export type ListStatus = {
  success: boolean; beta_access: boolean; tier: string
  bot_is_mod: boolean; bot_mod_unknown?: boolean; bot_is_banned: boolean; bot_ban_reason: string; bot_mod_message: string
  stream: { db: string | null; ssh: string | null; twitch: string | null; final: string | null }
  stream_labels: Record<'db' | 'ssh' | 'twitch' | 'final', string>
  tooltips: Record<string, string>
}
export type BotStatus = {
  success: boolean; running: boolean; pid: number; version: string; latestVersion: string
  updateAvailable: boolean; codeUpdateAvailable: boolean; versionOutdated: boolean
  lastModified: string; lastRun: string; customModuleAvailable: boolean; message?: string
}
export type Limit = { requests_remaining: number | null; requests_limit: number; last_updated: string | null }
export type Limits = Record<'shazam' | 'exchangerate' | 'weather', Limit>
export type Service = { status: string; message?: string; latency_ms?: number; pid?: number; pid_running?: boolean }

export async function get<T>(url: string, timeoutMs = 12000): Promise<T> {
  const ctrl = new AbortController()
  const t = setTimeout(() => ctrl.abort(), timeoutMs)
  try {
    const r = await fetch(url, { credentials: 'same-origin', signal: ctrl.signal })
    if (!r.ok && r.status !== 200) throw new Error(`http_${r.status}`)
    return (await r.json()) as T
  } finally { clearTimeout(t) }
}

export async function post<T>(url: string, body: Record<string, string>, timeoutMs = 15000): Promise<T> {
  const ctrl = new AbortController()
  const t = setTimeout(() => ctrl.abort(), timeoutMs)
  try {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body).toString(),
    })
    return (await r.json()) as T
  } finally { clearTimeout(t) }
}

export const fetchOverview = () => get<Overview>('/api/bot/overview.php')
export const fetchList = () => get<ListStatus>('/api/bot/overview.php?ajax_action=list', 20000)
export const fetchBotStatus = (bot: BotType) => get<BotStatus>(`/api/bot/check_bot_status.php?bot=${bot}&_t=${Date.now()}`)
export const fetchLimits = () => get<Limits>('/api/bot/api_limits.php')
export const fetchService = (svc: string) => get<Service>(`/api/bot/api_status.php?service=${svc}`)

export function botAction(action: 'run' | 'stop', bot: BotType, opts: { useCustom: boolean; useSelf: boolean; loadModule: boolean }) {
  return post<{ success: boolean; message?: string }>('/api/bot/bot_action.php', {
    action, bot,
    use_custom_bot: opts.useCustom ? '1' : '0',
    use_self: opts.useSelf ? '1' : '0',
    load_custom_module: opts.loadModule ? '1' : '0',
  })
}

export const saveUseCustom = (body: Record<string, string>) =>
  post<{ success: boolean; use_self?: number; use_custom?: number; message?: string }>('/api/bot/update_use_custom.php', body)
export const saveUseCustomModule = (on: boolean) =>
  post<{ success: boolean; message?: string }>('/api/bot/update_use_custom_module.php', { use_custom_module: on ? '1' : '0' })
