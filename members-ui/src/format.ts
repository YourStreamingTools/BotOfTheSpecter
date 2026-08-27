export function formatWatchTime(seconds: number): string {
  const n = Number(seconds) || 0
  if (n <= 0) return 'Not recorded'
  const units: Array<[string, number]> = [
    ['year', 31536000],
    ['month', 2592000],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ]
  const parts: string[] = []
  let left = n
  for (const [name, div] of units) {
    const q = Math.floor(left / div)
    if (q > 0) {
      parts.push(q + ' ' + name + (q !== 1 ? 's' : ''))
      left -= q * div
    }
  }
  return parts.slice(0, 2).join(', ') || 'Less than a minute'
}

export function formatLurkDuration(startTime: string): string {
  const start = new Date(startTime)
  if (Number.isNaN(start.getTime())) return 'Invalid Date'
  const diff = Date.now() - start.getTime()
  if (diff < 60000) return 'Less than a minute'
  const years = Math.floor(diff / (1000 * 60 * 60 * 24 * 365))
  const months = Math.floor((diff % (1000 * 60 * 60 * 24 * 365)) / (1000 * 60 * 60 * 24 * 30))
  const days = Math.floor((diff % (1000 * 60 * 60 * 24 * 30)) / (1000 * 60 * 60 * 24))
  const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
  const parts: string[] = []
  if (years > 0) parts.push(years + ' year(s)')
  if (months > 0) parts.push(months + ' month(s)')
  if (days > 0) parts.push(days + ' day(s)')
  if (hours > 0) parts.push(hours + ' hour(s)')
  if (minutes > 0) parts.push(minutes + ' minute(s)')
  return parts.join(' ') || 'Less than a minute'
}

export function formatDateTime(dateTime: string): string {
  const date = new Date(dateTime)
  if (Number.isNaN(date.getTime())) return dateTime
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
  })
}

export function formatMonthYear(dateTime: string): string {
  const date = new Date(dateTime)
  if (Number.isNaN(date.getTime())) return dateTime
  return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
}

export function formatGameDate(datetime: string | undefined): string {
  if (!datetime) return 'Unknown'
  const ts = Date.parse(datetime)
  if (Number.isNaN(ts)) return 'Unknown'
  return new Date(ts).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

export const PERMISSION_LABELS: Record<string, [string, string]> = {
  everyone: ['Everyone', '#2ecc71'],
  vip: ['VIPs', '#a855f7'],
  'all-subs': ['All Subscribers', '#ffd700'],
  't1-sub': ['Tier 1 Subscriber', '#c0c0c0'],
  't2-sub': ['Tier 2 Subscriber', '#cd7f32'],
  't3-sub': ['Tier 3 Subscriber', '#ffd700'],
  mod: ['Mods', '#f5a623'],
  broadcaster: ['Broadcaster', '#e74c3c'],
}

export function cooldownColor(seconds: number): string {
  if (seconds <= 15) return '#2ecc71'
  if (seconds <= 60) return '#f5a623'
  return '#e74c3c'
}

export const STORE_TYPE_ICONS: Record<string, string> = {
  sound_alert: 'fa-volume-high',
  video_alert: 'fa-film',
  tts: 'fa-comment-dots',
  chat_message: 'fa-message',
}

export const STORE_TYPE_LABELS: Record<string, string> = {
  sound_alert: 'Sound',
  video_alert: 'Video',
  tts: 'TTS',
  chat_message: 'Chat',
}
