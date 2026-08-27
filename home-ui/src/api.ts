export type HomeSession = {
  ok: boolean
  logged_in: boolean
  username: string | null
  display_name: string | null
  dashboard_version: string
}

const empty: HomeSession = {
  ok: false,
  logged_in: false,
  username: null,
  display_name: null,
  dashboard_version: '',
}

export async function fetchSession(): Promise<HomeSession> {
  const res = await fetch('/api/session.php', { credentials: 'same-origin', cache: 'no-store' })
  if (!res.ok) return empty
  const data = (await res.json()) as HomeSession
  return { ...empty, ...data }
}

export type HostStatus = {
  id: string
  label: string
  status: string
  ping: number
}

export type StatusMetric = {
  server_name: string
  cpu_percent: number | string | null
  ram_percent: number | string | null
  ram_used: number | string | null
  ram_total: number | string | null
  disk_percent: number | string | null
  disk_used: number | string | null
  disk_total: number | string | null
  net_sent: number | string | null
  net_recv: number | string | null
}

export type RunningBots = {
  total: number
  totals?: {
    stable?: number
    beta?: number
    v6?: number
    custom?: number
    kick?: number
  }
}

export type YearCount = { year: number | string; count: number | string }

export type StatusPayload = {
  maintenanceMode?: boolean
  maintenanceMessage?: string
  hostStatuses?: HostStatus[]
  serverDisplayNames?: Record<string, string>
  apiServiceStatus?: { status: string; ping: number }
  databaseServiceStatus?: { status: string; ping: number }
  notificationServiceStatus?: { status: string; ping: number }
  botServerStatus?: { status: string; ping: number }
  web1Status?: { status: string; ping: number }
  betaVersion?: string | null
  stableVersion?: string | null
  discordVersion?: string | null
  songRequestsRemaining?: number | null
  exchangeRateRequestsRemaining?: number | null
  weatherRequestsRemaining?: number | null
  metrics?: StatusMetric[]
  botMessageCounts?: Record<string, number>
  generatedAt?: number
  runningBots?: RunningBots | null
  totalUsers?: number | string | null
  usersByYear?: YearCount[]
  betaUsers?: string[]
}

export async function fetchStatus(): Promise<StatusPayload> {
  const res = await fetch('/status.php?ajax=1&_=' + Date.now(), { cache: 'no-store' })
  if (!res.ok) throw new Error('HTTP ' + res.status)
  return (await res.json()) as StatusPayload
}
