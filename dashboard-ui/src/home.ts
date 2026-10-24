export type Live = { online: boolean; uptime_seconds: number; viewer_count: number; title: string | null; game: string | null } | null
export type Summary = { lifetime: Record<string, number>; window: Record<string, number>; window_days: number } | null
export type Boards = {
  top_commands: Array<{ command: string; count: number }>
  top_rewards: Array<{ reward_title: string; count: number }>
  chat_leaders: Array<{ username: string; messages: number }>
  top_songs: Array<{ song: string; artist: string; count: number }>
  deaths_by_game: Array<{ game: string; deaths: number }>
  streaks: Array<{ username: string; current: number; highest: number }>
} | null
export type Activity = { items: Array<{ type: string; actor: string; detail: string; at: string | null }> } | null
export type HomeData = { window: string; live: Live; summary: Summary; leaderboards: Boards; activity: Activity; dashboard_version: string | null }

export async function fetchHome(window: string): Promise<HomeData> {
  const r = await fetch(`/api/home.php?window=${encodeURIComponent(window)}`, { credentials: 'same-origin' })
  if (r.status === 401) throw new Error('unauthorized')
  if (!r.ok) throw new Error(`http_${r.status}`)
  return (await r.json()) as HomeData
}
