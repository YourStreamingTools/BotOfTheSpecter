export type SupportSession = {
  ok: boolean
  logged_in: boolean
  is_staff: boolean
  is_registered: boolean
  username: string | null
  display_name: string | null
  profile_image: string | null
  csrf_token: string | null
  dashboard_version: string
}

export type Ticket = {
  id: number
  ticket_number: string
  twitch_user_id: string
  username: string
  display_name: string
  category: string
  subject: string
  status: string
  priority: string
  meta: { program?: string; program_name?: string }
  created_at: string
  updated_at: string
}

export type TicketReply = {
  id: number
  author_twitch_id: string
  author_display_name: string
  is_staff: number | boolean
  message: string
  created_at: string
}

export type BetaProgram = {
  id: number
  slug: string
  name: string
  description: string | null
  is_active: number | boolean
}

async function parse(res: Response) {
  if (res.status === 401) {
    const returnTo = window.location.pathname + window.location.search + window.location.hash
    window.location.href = '/login.php?return_to=' + encodeURIComponent(returnTo)
    return new Promise(() => undefined)
  }
  const data = await res.json()
  return data
}

export async function fetchSession(): Promise<SupportSession> {
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

export function timeAgo(dt: string): string {
  const diff = Math.floor((Date.now() - new Date(dt).getTime()) / 1000)
  if (Number.isNaN(diff) || diff < 60) return 'just now'
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago'
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago'
  return Math.floor(diff / 86400) + 'd ago'
}

export function formatWhen(dt: string): string {
  const d = new Date(dt)
  if (Number.isNaN(d.getTime())) return dt
  return d.toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export const CAT_LABEL: Record<string, string> = {
  billing: 'Billing',
  technical: 'Technical',
  account: 'Account',
  general: 'General',
  feedback: 'Feedback',
  beta_request: 'Beta Program Request',
}

export const STATUS_LABEL: Record<string, string> = {
  open: 'Open',
  in_progress: 'In Progress',
  resolved: 'Resolved',
  closed: 'Closed',
}
