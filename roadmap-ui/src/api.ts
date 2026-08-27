export type RoadmapSession = {
  ok: boolean
  logged_in: boolean
  is_admin: boolean
  username: string | null
  display_name: string | null
  profile_image: string | null
  csrf_token: string
}

export type RoadmapItem = {
  id: number
  title: string
  description: string
  category: string
  subcategory: string
  priority: string
  website_type: string | null
  completed_date: string | null
  created_by: string | null
  created_at: string
  updated_at: string
  subcategories: string[]
  website_types: string[]
}

export type Subcat = { name: string; color: string }

export type ItemsPayload = {
  ok: boolean
  categories: string[]
  subcategories: Subcat[]
  subcat_colors: Record<string, string>
  items: RoadmapItem[]
  is_admin: boolean
}

async function parse(res: Response) {
  if (res.status === 401) {
    const returnTo = window.location.pathname + window.location.search
    window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo)
    return new Promise(() => undefined)
  }
  return res.json()
}

export async function fetchSession(): Promise<RoadmapSession> {
  const res = await fetch('/api/session.php', { credentials: 'same-origin' })
  return parse(res)
}

export async function apiGet(url: string) {
  const res = await fetch(url, { credentials: 'same-origin' })
  return parse(res)
}

export async function apiPost(url: string, body: Record<string, unknown>) {
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': String(body.csrf_token || ''),
    },
    body: JSON.stringify(body),
  })
  return parse(res)
}

export function sydneyDate(dt: string): string {
  if (!dt) return ''
  return new Date(dt).toLocaleDateString('en-AU', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'Australia/Sydney',
  })
}

export function renderMarkdown(src: string): string {
  const dirty = window.marked ? window.marked.parse(src || '') : (src || '')
  return window.DOMPurify ? window.DOMPurify.sanitize(dirty) : dirty
}

export function highlight(root: HTMLElement | null) {
  if (!root || !window.hljs) return
  root.querySelectorAll('pre code').forEach((el) => window.hljs!.highlightElement(el))
}

export function attachUrl(path: string): string {
  if (!path) return ''
  if (path.startsWith('http')) return path
  if (path.startsWith('../')) return '/' + path.replace(/^(\.\.\/)+/, '')
  if (path.startsWith('/')) return path
  return '/uploads/attachments/' + path
}

export const CAT_ICON: Record<string, string> = {
  REQUESTS: 'lightbulb',
  'IN PROGRESS': 'spinner',
  'BETA TESTING': 'flask',
  COMPLETED: 'circle-check',
  REJECTED: 'circle-xmark',
}

export const CAT_TAG: Record<string, string> = {
  REQUESTS: 'info',
  'IN PROGRESS': 'warning',
  'BETA TESTING': 'primary',
  COMPLETED: 'success',
  REJECTED: 'danger',
}

export const PRIO_TAG: Record<string, string> = {
  LOW: 'success',
  MEDIUM: 'info',
  HIGH: 'warning',
  CRITICAL: 'danger',
}

export function subTag(name: string, colors: Record<string, string>): string {
  return colors[name] || ({
    'TWITCH BOT': 'primary',
    'DISCORD BOT': 'info',
    'WEBSOCKET SERVER': 'success',
    'API SERVER': 'warning',
    WEBSITE: 'danger',
    OTHER: 'light',
  } as Record<string, string>)[name] || 'light'
}
