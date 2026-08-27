export type MembersSession = {
  ok: boolean
  logged_in: boolean
  username: string | null
  display_name: string | null
  profile_image: string | null
  twitch_user_id: string | null
  store_csrf: string | null
  dashboard_version: string
}

export type ChannelStatus = 'ok' | 'not_found' | 'restricted' | 'deceased' | 'invalid'

export type ChannelCommand = {
  command: string
  response: string
  status: string
  cooldown: string | number
  permission: string
}

export type ChannelLurker = {
  user_id: string
  start_time: string
  display_name?: string
}

export type MemorialData = {
  lurkers: Array<{ user_id: string; start_time: string; display_name: string }>
  typos: Array<{ username: string; typo_count: number }>
  deaths: Array<{ game_name: string; death_count: number }>
  hugs: Array<{ username: string; hug_count: number }>
  watchtime: Array<{ username: string; total_watch_time_live: number }>
}

export type ChannelData = {
  ok: boolean
  channel: string
  status: ChannelStatus
  display_name: string
  profile_image: string | null
  viewer_display_name: string
  memorial?: MemorialData
  commands?: ChannelCommand[]
  lurkers?: ChannelLurker[]
  typos?: Array<{ username: string; typo_count: number }>
  game_deaths?: Array<{ game_name: string; death_count: number }>
  hug_counts?: Array<{ username: string; hug_count: number }>
  kiss_counts?: Array<{ username: string; kiss_count: number }>
  highfive_counts?: Array<{ username: string; highfive_count: number }>
  custom_counts?: Array<{ command: string; count: number }>
  user_counts?: Array<{ command: string; user: string; count: number }>
  reward_counts?: Array<{ reward_id?: string; user: string; count: number; reward_title: string }>
  watch_time?: Array<{ username: string; total_watch_time_live: number; total_watch_time_offline: number }>
  quotes?: Array<{ id: number; quote: string }>
  todos?: Array<{
    id: number
    objective: string
    category: string | number
    completed: string | number
    created_at: string
    updated_at: string
  }>
  todo_categories?: Array<{ id: number; category: string }>
}

export type FreeGame = {
  game_title: string
  game_org?: string
  game_price?: string
  game_description?: string
  game_thumbnail?: string
  game_url?: string
  received_at?: string
}

export type StoreItem = {
  id: number
  title: string
  slug: string
  description: string | null
  cost: number
  item_type: string
  cooldown_seconds: number
  stock: number | null
  max_per_stream: number | null
}

export type StoreData = {
  ok: boolean
  channel: string
  status: 'ok' | 'not_found' | 'unavailable' | 'invalid'
  display_name: string
  profile_image: string | null
  csrf: string
  store_ready?: boolean
  point_name?: string
  balance?: number
  settings?: { enabled: number; paused: number; stream_online_only: number }
  stream_online?: boolean
  items?: StoreItem[]
  recent?: Array<{ item_title: string; cost: number; created_at: string }>
}

export type SuggestItem = {
  username: string
  display_name: string
  avatar: string
}

async function parse(res: Response) {
  if (res.status === 401) {
    const returnTo = window.location.pathname + window.location.search + window.location.hash
    window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo)
    return new Promise(() => undefined)
  }
  return res.json()
}

export async function fetchSession(): Promise<MembersSession> {
  const res = await fetch('/api/session.php', { credentials: 'same-origin' })
  return parse(res)
}

export async function fetchChannel(user: string): Promise<ChannelData> {
  const res = await fetch('/api/channel.php?user=' + encodeURIComponent(user), { credentials: 'same-origin' })
  return parse(res)
}

export async function fetchFreeGames(): Promise<{ ok: boolean; games: FreeGame[]; count: number; error?: string }> {
  const res = await fetch('/api/freegames.php', { credentials: 'same-origin' })
  return parse(res)
}

export async function fetchStore(channel: string): Promise<StoreData> {
  const res = await fetch('/api/store.php?user=' + encodeURIComponent(channel), { credentials: 'same-origin' })
  return parse(res)
}

export async function buyStoreItem(channel: string, itemId: number, csrf: string) {
  const body = new FormData()
  body.append('action', 'buy')
  body.append('item_id', String(itemId))
  body.append('csrf', csrf)
  const res = await fetch('/' + encodeURIComponent(channel) + '/store', {
    method: 'POST',
    body,
    credentials: 'same-origin',
  })
  return res.json()
}

export async function fetchSuggestions(q: string): Promise<SuggestItem[]> {
  const res = await fetch('/autocomplete.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
  if (res.status === 401) {
    const returnTo = window.location.pathname + window.location.search
    window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo)
    return []
  }
  const data = await res.json()
  return Array.isArray(data) ? data : []
}
