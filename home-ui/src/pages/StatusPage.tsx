import { useEffect, useRef, useState } from 'react'
import { fetchStatus, type HostStatus, type StatusMetric, type StatusPayload } from '../api'

const DEFAULT_NAMES: Record<string, string> = {
  web1: 'Web Server 1',
  web2: 'Web Server 2',
  sql: 'Database Service',
  api: 'API Service',
  websocket: 'WebSocket Service',
  bots: 'Bot Server',
}

const MSG_RATE_WINDOW_MS = 5 * 60 * 1000

function formatSpeed(mbPerSec: number | string | null | undefined): string {
  const n = Number(mbPerSec)
  if (!Number.isFinite(n) || n < 0) return '—'
  const bytesPerSec = n * 1000000
  if (bytesPerSec >= 1000000) return n.toFixed(2) + ' MB/s'
  if (bytesPerSec >= 1000) return (bytesPerSec / 1000).toFixed(2) + ' KB/s'
  return bytesPerSec.toFixed(2) + ' B/s'
}

function formatNumber(n: number | string | null | undefined): string {
  if (n === null || n === undefined) return 'N/A'
  if (typeof n === 'number' || !isNaN(Number(n))) return Number(n).toLocaleString()
  return String(n)
}

function formatMetricNum(n: number | string | null | undefined, digits = 1): string {
  const v = Number(n)
  return Number.isFinite(v) ? v.toFixed(digits) : '—'
}

type Sample = { tMs: number; total: number; generatedAt: number }

function ServiceItem({ name, status, ping }: { name: string; status: string; ping: number }) {
  if (status === 'OK') {
    return (
      <div className="status-item">
        <span className="has-text-weight-bold">{name}:</span> {ping}ms{' '}
        <span className="heartbeat beating" role="img" aria-label="Online">
          ❤️
        </span>
      </div>
    )
  }
  if (status === 'DISABLED') {
    return (
      <div className="status-item">
        <span className="has-text-weight-bold">{name}:</span> Disabled <span aria-hidden="true">⏸️</span>
      </div>
    )
  }
  return (
    <div className="status-item">
      <span className="has-text-weight-bold">{name}:</span> Down <span aria-hidden="true">💀</span>
    </div>
  )
}

function SkeletonLine({ width }: { width: string }) {
  return (
    <span
      className={'sp-skeleton-line ' + width}
      style={{ display: 'inline-block', verticalAlign: 'middle' }}
      aria-hidden="true"
    />
  )
}

export default function StatusPage() {
  const [data, setData] = useState<StatusPayload | null>(null)
  const [clock, setClock] = useState(() => new Date().toLocaleTimeString())
  const [updated, setUpdated] = useState<string | null>(null)
  const [error, setError] = useState(false)
  const [msgRate, setMsgRate] = useState('—')
  const samples = useRef<Sample[]>([])
  const lastRate = useRef('—')
  const loaded = useRef(false)

  useEffect(() => {
    document.documentElement.classList.add('status-page')
    document.body.classList.add('status-page')
    return () => {
      document.documentElement.classList.remove('status-page')
      document.body.classList.remove('status-page')
    }
  }, [])

  useEffect(() => {
    const id = window.setInterval(() => setClock(new Date().toLocaleTimeString()), 1000)
    return () => window.clearInterval(id)
  }, [])

  useEffect(() => {
    let cancelled = false
    const poll = () => {
      fetchStatus()
        .then((payload) => {
          if (cancelled) return
          setError(false)
          setData(payload)
          setUpdated(new Date().toLocaleTimeString())
          loaded.current = true

          const counts = payload.botMessageCounts
          if (!counts) return
          const nowMs = Date.now()
          const generatedAt = Number(payload.generatedAt) || 0
          const sampleMs = generatedAt > 0 ? generatedAt * 1000 : nowMs
          const keys = ['discordbot', 'twitch_stable', 'twitch_beta', 'twitch_custom']
          let totalNow = 0
          keys.forEach((key) => {
            totalNow += Math.max(0, Number(counts[key]) || 0)
          })
          if (totalNow <= 0) {
            samples.current = []
            lastRate.current = 'N/A'
            setMsgRate('N/A')
            return
          }
          let last: Sample | undefined = samples.current[samples.current.length - 1]
          if (last && totalNow < last.total) {
            samples.current = []
            last = undefined
            lastRate.current = '—'
          }
          const isNewSample =
            !last ||
            (generatedAt > 0 && last.generatedAt !== generatedAt) ||
            (generatedAt <= 0 && totalNow !== last.total)
          if (isNewSample) {
            samples.current.push({
              tMs: sampleMs,
              total: totalNow,
              generatedAt: generatedAt || sampleMs,
            })
          }
          const cutoff = sampleMs - MSG_RATE_WINDOW_MS
          samples.current = samples.current.filter((s, i, arr) => s.tMs >= cutoff || i === arr.length - 1)
          if (samples.current.length >= 2) {
            const oldest = samples.current[0]
            const newest = samples.current[samples.current.length - 1]
            const elapsedMin = (newest.tMs - oldest.tMs) / 60000
            const delta = newest.total - oldest.total
            if (elapsedMin >= 0.05 && delta >= 0) {
              lastRate.current = (delta / elapsedMin).toFixed(1) + '/min'
            }
          } else {
            lastRate.current = '—'
          }
          setMsgRate(lastRate.current)
        })
        .catch(() => {
          if (cancelled) return
          setError(true)
          if (!loaded.current) setUpdated('update failed - retrying')
        })
    }
    poll()
    const id = window.setInterval(poll, 60000)
    return () => {
      cancelled = true
      window.clearInterval(id)
    }
  }, [])

  const names = { ...DEFAULT_NAMES, ...(data?.serverDisplayNames || {}) }
  const fallbackHosts: HostStatus[] = []
  const fallback: { id: string; label: string; key: keyof StatusPayload }[] = [
    { id: 'web1', label: 'Web Server 1', key: 'web1Status' },
    { id: 'sql', label: 'Database Service', key: 'databaseServiceStatus' },
    { id: 'api', label: 'API Service', key: 'apiServiceStatus' },
    { id: 'websocket', label: 'WebSocket Service', key: 'notificationServiceStatus' },
    { id: 'bots', label: 'Bot Server', key: 'botServerStatus' },
  ]
  for (const svc of fallback) {
    const st = data?.[svc.key]
    if (st && typeof st === 'object' && 'status' in st && 'ping' in st) {
      fallbackHosts.push({ id: svc.id, label: svc.label, status: String(st.status), ping: Number(st.ping) })
    }
  }
  const hosts: HostStatus[] =
    Array.isArray(data?.hostStatuses) && data.hostStatuses.length ? data.hostStatuses : fallbackHosts

  const running = data?.runningBots
  const totals = running?.totals || {}
  const breakdown = (
    [
      ['Stable', totals.stable],
      ['Beta', totals.beta],
      ['V6', totals.v6],
      ['Custom', totals.custom],
      ['Kick', totals.kick],
    ] as const
  )
    .filter(([, n]) => Number(n) > 0)
    .map(([label, n]) => label + ': ' + formatNumber(n))

  const years = data?.usersByYear || []
  const metrics: StatusMetric[] = data?.metrics || []
  const beta = data?.betaUsers
  const msg = data?.botMessageCounts || {}

  const msgText = (key: string) => {
    const count = Math.max(0, Number(msg[key]) || 0)
    return count === 0 ? 'Not Counting Yet' : formatNumber(count)
  }

  return (
    <>
      <img
        className="status-brand-logo"
        src="https://cdn.botofthespecter.com/logo.png"
        alt="BotOfTheSpecter"
        width={160}
        height={160}
        decoding="async"
      />
      <div className="container">
        <div className="maintenance-banner" id="maintenance-banner" hidden={!data?.maintenanceMode}>
          <span className="icon" aria-hidden="true">
            🛠️
          </span>
          <span id="maintenance-banner-text">{data?.maintenanceMessage || ''}</span>
        </div>
        <div className="title-row">
          <h1>BotOfTheSpecter System Status</h1>
          <div className="last-updated" id="last-updated">
            Time right now: <span id="current-time">{clock}</span>
            &nbsp;|&nbsp; Last updated:{' '}
            <span id="update-time">
              {updated || <SkeletonLine width="w-40" />}
              {error && loaded.current ? ' (retrying)' : ''}
            </span>
          </div>
        </div>

        <div className="section">
          <div className="status-grid" id="service-status" aria-busy={!data}>
            {data ? (
              hosts.map((h) => (
                <ServiceItem key={h.id} name={h.label || names[h.id] || h.id} status={h.status} ping={h.ping} />
              ))
            ) : (
              <>
                <div className="status-item" aria-hidden="true">
                  <span className="sp-skeleton sp-skeleton-line w-60" />
                  <span className="sp-skeleton sp-skeleton-line w-25" />
                </div>
                <div className="status-item" aria-hidden="true">
                  <span className="sp-skeleton sp-skeleton-line w-55" />
                  <span className="sp-skeleton sp-skeleton-line w-25" />
                </div>
                <div className="status-item" aria-hidden="true">
                  <span className="sp-skeleton sp-skeleton-line w-50" />
                  <span className="sp-skeleton sp-skeleton-line w-20" />
                </div>
                <div className="status-item" aria-hidden="true">
                  <span className="sp-skeleton sp-skeleton-line w-70" />
                  <span className="sp-skeleton sp-skeleton-line w-25" />
                </div>
                <div className="status-item" aria-hidden="true">
                  <span className="sp-skeleton sp-skeleton-line w-45" />
                  <span className="sp-skeleton sp-skeleton-line w-20" />
                </div>
              </>
            )}
          </div>
        </div>

        <div className="columns">
          <div className="column is-one-quarter">
            <div className="section">
              <h2>System Versions</h2>
              <div id="version-info" aria-busy={!data}>
                <div className="info-item">
                  <span className="has-text-weight-bold">Chat Bot Stable:</span>{' '}
                  <span>{data ? (data.stableVersion ?? 'N/A') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Chat Bot Beta:</span>{' '}
                  <span>{data ? (data.betaVersion ?? 'N/A') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Discord Bot:</span>{' '}
                  <span>{data ? (data.discordVersion ?? 'N/A') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Running Bots:</span>{' '}
                  <span>
                    {data ? (running && typeof running.total === 'number' ? formatNumber(running.total) : 'N/A') : <SkeletonLine width="w-25" />}
                  </span>
                </div>
                {breakdown.length > 0 && (
                  <div className="info-item" id="running-bots-breakdown">
                    {breakdown.join(' · ')}
                  </div>
                )}
              </div>
            </div>
          </div>
          <div className="column is-one-quarter">
            <div className="section">
              <h2>Public API Requests</h2>
              <div id="api-limits" aria-busy={!data}>
                <div className="info-item">
                  <span className="has-text-weight-bold">Song Identification Remaining:</span>{' '}
                  <span>{data ? formatNumber(data.songRequestsRemaining) : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Exchange Rate Remaining:</span>{' '}
                  <span>{data ? formatNumber(data.exchangeRateRequestsRemaining) : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Weather Remaining:</span>{' '}
                  <span>{data ? formatNumber(data.weatherRequestsRemaining) : <SkeletonLine width="w-40" />}</span>
                </div>
              </div>
            </div>
          </div>
          <div className="column is-one-quarter" id="signups-column">
            <div className="section" id="signups-section">
              <h2>Number of Signups</h2>
              <div id="signups-body" aria-busy={!data}>
                <div className="info-item">
                  <span className="has-text-weight-bold">Total:</span>{' '}
                  <span>{data ? formatNumber(data.totalUsers) : <SkeletonLine width="w-40" />}</span>
                </div>
                <h3>Signups by Year</h3>
                <div className="columns is-mobile">
                  <div className="column is-half">
                    {years[0] && (
                      <div className="info-item">
                        <span className="has-text-weight-bold">{years[0].year}:</span> {formatNumber(years[0].count)}
                      </div>
                    )}
                  </div>
                  <div className="column is-half">
                    {years[1] && (
                      <div className="info-item">
                        <span className="has-text-weight-bold">{years[1].year}:</span> {formatNumber(years[1].count)}
                      </div>
                    )}
                  </div>
                </div>
                <div className="columns is-mobile">
                  <div className="column is-half">
                    {years[2] && (
                      <div className="info-item">
                        <span className="has-text-weight-bold">{years[2].year}:</span> {formatNumber(years[2].count)}
                      </div>
                    )}
                  </div>
                  <div className="column is-half">
                    {years[3] && (
                      <div className="info-item">
                        <span className="has-text-weight-bold">{years[3].year}:</span> {formatNumber(years[3].count)}
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="column is-one-quarter">
            <div className="section">
              <h2>Messages Sent</h2>
              <div id="message-counts" aria-busy={!data}>
                <div className="info-item">
                  <span className="has-text-weight-bold">Discord Bot:</span>{' '}
                  <span>{data ? msgText('discordbot') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Chat Bot Stable:</span>{' '}
                  <span>{data ? msgText('twitch_stable') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Chat Bot Beta:</span>{' '}
                  <span>{data ? msgText('twitch_beta') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Chat Bot Custom:</span>{' '}
                  <span>{data ? msgText('twitch_custom') : <SkeletonLine width="w-40" />}</span>
                </div>
                <div className="info-item">
                  <span className="has-text-weight-bold">Messages/min:</span> <span>{data ? msgRate : '—'}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="columns bottom-row">
          <div className="column is-half">
            <div className="section">
              <h2>System Metrics</h2>
              <div id="system-metrics" aria-busy={!data}>
                {!data ? (
                  <>
                    <div className="status-item" aria-hidden="true">
                      <div className="metric-header">
                        <span className="sp-skeleton sp-skeleton-line w-50" />
                      </div>
                      <div className="metric-body sp-skeleton-stack">
                        <span className="sp-skeleton sp-skeleton-line w-70" />
                        <span className="sp-skeleton sp-skeleton-line w-80" />
                        <span className="sp-skeleton sp-skeleton-line w-60" />
                        <span className="sp-skeleton sp-skeleton-line w-90" />
                      </div>
                    </div>
                    <div className="status-item" aria-hidden="true">
                      <div className="metric-header">
                        <span className="sp-skeleton sp-skeleton-line w-45" />
                      </div>
                      <div className="metric-body sp-skeleton-stack">
                        <span className="sp-skeleton sp-skeleton-line w-70" />
                        <span className="sp-skeleton sp-skeleton-line w-80" />
                        <span className="sp-skeleton sp-skeleton-line w-55" />
                        <span className="sp-skeleton sp-skeleton-line w-90" />
                      </div>
                    </div>
                  </>
                ) : metrics.length === 0 ? (
                  <div className="status-item">Metrics unavailable — services may still be deploying /health/metrics</div>
                ) : (
                  metrics.map((metric) => {
                    const ramPct = Number(metric.ram_percent)
                    const ramUsed = Number(metric.ram_used)
                    const ramTotal = Number(metric.ram_total)
                    const diskPct = Number(metric.disk_percent)
                    const diskUsed = Number(metric.disk_used)
                    const diskTotal = Number(metric.disk_total)
                    const ramTxt = Number.isFinite(ramPct)
                      ? ramPct.toFixed(1) +
                        '% (' +
                        (Number.isFinite(ramUsed) ? ramUsed.toFixed(1) : '—') +
                        'GB / ' +
                        (Number.isFinite(ramTotal) ? ramTotal.toFixed(1) : '—') +
                        'GB)'
                      : '—'
                    const diskTxt = Number.isFinite(diskPct)
                      ? diskPct.toFixed(1) +
                        '% (' +
                        (Number.isFinite(diskUsed) ? diskUsed.toFixed(1) : '—') +
                        'GB / ' +
                        (Number.isFinite(diskTotal) ? diskTotal.toFixed(1) : '—') +
                        'GB)'
                      : '—'
                    return (
                      <div className="status-item" key={metric.server_name}>
                        <div className="metric-header">
                          <span className="has-text-weight-bold">
                            Server: {names[metric.server_name] || metric.server_name}
                          </span>
                        </div>
                        <div className="metric-body">
                          <span className="metric-line">
                            CPU:{' '}
                            {Number.isFinite(Number(metric.cpu_percent)) ? formatMetricNum(metric.cpu_percent) + '%' : '—'}
                          </span>
                          <span className="metric-line">RAM: {ramTxt}</span>
                          <span className="metric-line">DISK: {diskTxt}</span>
                          <span className="metric-line">
                            NETWORK: ↑ {formatSpeed(metric.net_sent)} ↓ {formatSpeed(metric.net_recv)}
                          </span>
                        </div>
                      </div>
                    )
                  })
                )}
              </div>
            </div>
          </div>
          <div className="column is-half">
            <div className="section">
              <h2>Friends that use BotOfTheSpecter</h2>
              <div className="beta-users user-list" id="beta-users" aria-busy={beta === undefined}>
                {beta === undefined ? (
                  <>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-60" />
                    </div>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-50" />
                    </div>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-70" />
                    </div>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-45" />
                    </div>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-55" />
                    </div>
                    <div className="info-item" aria-hidden="true">
                      <span className="sp-skeleton sp-skeleton-line w-40" />
                    </div>
                  </>
                ) : (
                  beta.map((user) => (
                    <div className="info-item" key={user}>
                      <span>{user}</span>
                    </div>
                  ))
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
