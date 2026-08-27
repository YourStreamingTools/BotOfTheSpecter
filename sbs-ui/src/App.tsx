import { useEffect, useState } from 'react'
import { applyTheme, readTheme, type ThemeName } from './theme'

type Ping = { status: string; ping: number }

type Metric = {
  server_name: string
  cpu_percent: number | string
  ram_percent: number | string
  ram_used: number | string
  ram_total: number | string
  disk_percent: number | string
  disk_used: number | string
  disk_total: number | string
  net_sent: number | string
  net_recv: number | string
}

type YearCount = { year: number | string; count: number | string }

type Payload = {
  apiServiceStatus?: Ping
  databaseServiceStatus?: Ping
  notificationServiceStatus?: Ping
  botServerStatus?: Ping
  web1Status?: Ping
  betaVersion?: string | null
  stableVersion?: string | null
  discordVersion?: string | null
  songRequestsRemaining?: number | null
  exchangeRateRequestsRemaining?: number | null
  weatherRequestsRemaining?: number | null
  metrics?: Metric[]
  betaUsersLeft?: string[]
  betaUsersRight?: string[]
  totalUsers?: number | string
  usersByYear?: YearCount[]
}

const NAMES: Record<string, string> = {
  web1: 'Web Server 1',
  sql: 'Database Service',
  api: 'API Service',
  websocket: 'WebSocket Service',
  bots: 'Bot Server',
}

const SERVICES: { key: keyof Payload; label: string }[] = [
  { key: 'web1Status', label: 'Web Server 1' },
  { key: 'databaseServiceStatus', label: 'Database Service' },
  { key: 'apiServiceStatus', label: 'API Service' },
  { key: 'notificationServiceStatus', label: 'WebSocket Service' },
  { key: 'botServerStatus', label: 'Bot Server' },
]

function formatSpeed(mbPerSec: number | string | null | undefined): string {
  const n = Number(mbPerSec)
  if (!Number.isFinite(n)) return '—'
  const bytesPerSec = n * 1000000
  if (bytesPerSec >= 1000000) return n.toFixed(2) + ' MB/s'
  if (bytesPerSec >= 1000) return (bytesPerSec / 1000).toFixed(2) + ' KB/s'
  return bytesPerSec.toFixed(2) + ' B/s'
}

function ServiceItem({ name, status }: { name: string; status?: Ping }) {
  if (status && status.status === 'OK') {
    return (
      <div className="status-item">
        <span className="has-text-weight-bold">{name}:</span> {status.ping}ms <span className="heartbeat beating">❤️</span>
      </div>
    )
  }
  if (status && status.status === 'DISABLED') {
    return (
      <div className="status-item">
        <span className="has-text-weight-bold">{name}:</span> Disabled <span>⏸️</span>
      </div>
    )
  }
  return (
    <div className="status-item">
      <span className="has-text-weight-bold">{name}:</span> Down <span>💀</span>
    </div>
  )
}

export default function App() {
  const [theme, setTheme] = useState<ThemeName>(() => readTheme())
  const [data, setData] = useState<Payload | null>(null)
  const [updated, setUpdated] = useState('Just now')

  useEffect(() => {
    applyTheme(theme, false)
    document.documentElement.classList.add('sbs-status')
    document.body.classList.add('sbs-status')
    return () => {
      document.documentElement.classList.remove('sbs-status')
      document.body.classList.remove('sbs-status')
    }
  }, [theme])

  useEffect(() => {
    const onStorage = (e: StorageEvent) => {
      if (e.key === 'sp-theme' && (e.newValue === 'light' || e.newValue === 'dark')) {
        setTheme(e.newValue)
      }
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  useEffect(() => {
    let cancelled = false
    const poll = () => {
      fetch('/index.php?ajax=1&metrics=1&_=' + Date.now(), { cache: 'no-store' })
        .then((res) => res.json())
        .then((payload: Payload) => {
          if (cancelled) return
          setData(payload)
          setUpdated(new Date().toLocaleTimeString())
        })
        .catch(() => {
          /* keep last good / skeleton */
        })
    }
    poll()
    const id = window.setInterval(poll, 60000)
    return () => {
      cancelled = true
      window.clearInterval(id)
    }
  }, [])

  const na = (v: string | number | null | undefined) => (v === null || v === undefined ? 'N/A' : String(v))
  const left = data?.betaUsersLeft
  const right = data?.betaUsersRight
  const friendsReady = left !== undefined && right !== undefined

  return (
    <div className="container">
      <div className="title-row">
        <h1>BotOfTheSpecter System Status</h1>
        <div className="last-updated" id="last-updated">
          Last updated: <span id="update-time">{updated}</span>
        </div>
        <button
          className="sa-theme-toggle"
          type="button"
          aria-label="Toggle light or dark theme"
          title="Toggle theme"
          onClick={() => {
            const next = theme === 'light' ? 'dark' : 'light'
            applyTheme(next, true)
            setTheme(next)
          }}
        >
          <i className={theme === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon'} />
        </button>
      </div>

      <div className="section">
        <div className="status-grid" id="service-status">
          {SERVICES.map((svc) => (
            <ServiceItem key={svc.key} name={svc.label} status={data?.[svc.key] as Ping | undefined} />
          ))}
        </div>
      </div>

      <div className="columns">
        <div className="column is-one-quarter">
          <div className="section">
            <h2>System Versions</h2>
            <div id="version-info">
              <div className="info-item">
                <span className="has-text-weight-bold">Chat Bot Stable:</span> <span>{na(data?.stableVersion)}</span>
              </div>
              <div className="info-item">
                <span className="has-text-weight-bold">Chat Bot Beta:</span> <span>{na(data?.betaVersion)}</span>
              </div>
              <div className="info-item">
                <span className="has-text-weight-bold">Discord Bot:</span> <span>{na(data?.discordVersion)}</span>
              </div>
            </div>
          </div>
        </div>
        <div className="column is-one-quarter">
          <div className="section">
            <h2>Public API Requests</h2>
            <div id="api-limits">
              <div className="info-item">
                <span className="has-text-weight-bold">Song Identification Remaing:</span>{' '}
                <span>{na(data?.songRequestsRemaining)}</span>
              </div>
              <div className="info-item">
                <span className="has-text-weight-bold">Exchange Rate Remaing:</span>{' '}
                <span>{na(data?.exchangeRateRequestsRemaining)}</span>
              </div>
              <div className="info-item">
                <span className="has-text-weight-bold">Weather Remaing:</span> <span>{na(data?.weatherRequestsRemaining)}</span>
              </div>
            </div>
          </div>
        </div>
        <div className="column is-one-quarter" id="signups-column">
          <div className="section" id="signups-section">
            <h2>Number of Signups:</h2>
            <div>
              <div className="info-item">
                <span className="has-text-weight-bold">Total:</span> <span>{na(data?.totalUsers)}</span>
              </div>
              <h2>Signups by Year:</h2>
              <div className="columns is-mobile">
                <div className="column is-half">
                  {data?.usersByYear?.[0] && (
                    <div className="info-item">
                      <span className="has-text-weight-bold">{data.usersByYear[0].year}:</span>{' '}
                      <span>{data.usersByYear[0].count}</span>
                    </div>
                  )}
                </div>
                <div className="column is-half">
                  {data?.usersByYear?.[1] && (
                    <div className="info-item">
                      <span className="has-text-weight-bold">{data.usersByYear[1].year}:</span>{' '}
                      <span>{data.usersByYear[1].count}</span>
                    </div>
                  )}
                </div>
              </div>
              <div className="columns is-mobile">
                <div className="column is-half">
                  {data?.usersByYear?.[2] && (
                    <div className="info-item">
                      <span className="has-text-weight-bold">{data.usersByYear[2].year}:</span>{' '}
                      <span>{data.usersByYear[2].count}</span>
                    </div>
                  )}
                </div>
                <div className="column is-half">
                  {data?.usersByYear?.[3] && (
                    <div className="info-item">
                      <span className="has-text-weight-bold">{data.usersByYear[3].year}:</span>{' '}
                      <span>{data.usersByYear[3].count}</span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
        <div className="column is-one-quarter">
          <div className="section">
            <div>
              <div className="info-item" />
            </div>
          </div>
        </div>
      </div>

      <div className="columns bottom-row">
        <div className="column is-half">
          <div className="section">
            <h2>System Metrics</h2>
            <div id="system-metrics">
              {(data?.metrics || []).map((metric) => (
                <div className="status-item" key={metric.server_name}>
                  <div className="metric-header">
                    <span className="has-text-weight-bold">Server: {NAMES[metric.server_name] || metric.server_name}</span>
                  </div>
                  <div>
                    CPU: {Number(metric.cpu_percent).toFixed(1)}% | RAM: {Number(metric.ram_percent).toFixed(1)}% (
                    {Number(metric.ram_used).toFixed(1)}GB / {Number(metric.ram_total).toFixed(1)}GB)
                    <br />
                    Disk: {Number(metric.disk_percent).toFixed(1)}% ({Number(metric.disk_used).toFixed(1)}GB /{' '}
                    {Number(metric.disk_total).toFixed(1)}GB) | Net: ↑ {formatSpeed(metric.net_sent)} ↓{' '}
                    {formatSpeed(metric.net_recv)}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="column is-half">
          <div className="section">
            <h2>Friends that use BotOfTheSpecter</h2>
            <div className={'beta-users columns' + (friendsReady ? '' : '')} id="beta-users" aria-busy={!friendsReady}>
              {friendsReady ? (
                [left || [], right || []].map((column, i) => (
                  <div className="column is-half" key={i}>
                    <div className="user-list">
                      {column.map((user) => (
                        <div className="info-item" key={user}>
                          <span>{user}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                ))
              ) : (
                <>
                  <div className="column is-half">
                    <div className="user-list sp-skeleton-stack" aria-hidden="true" style={{ gap: '0.45rem' }}>
                      <span className="sp-skeleton sp-skeleton-line w-70" />
                      <span className="sp-skeleton sp-skeleton-line w-55" />
                      <span className="sp-skeleton sp-skeleton-line w-80" />
                      <span className="sp-skeleton sp-skeleton-line w-60" />
                      <span className="sp-skeleton sp-skeleton-line w-45" />
                      <span className="sp-skeleton sp-skeleton-line w-70" />
                      <span className="sp-skeleton sp-skeleton-line w-50" />
                      <span className="sp-skeleton sp-skeleton-line w-65" />
                    </div>
                  </div>
                  <div className="column is-half">
                    <div className="user-list sp-skeleton-stack" aria-hidden="true" style={{ gap: '0.45rem' }}>
                      <span className="sp-skeleton sp-skeleton-line w-60" />
                      <span className="sp-skeleton sp-skeleton-line w-75" />
                      <span className="sp-skeleton sp-skeleton-line w-50" />
                      <span className="sp-skeleton sp-skeleton-line w-70" />
                      <span className="sp-skeleton sp-skeleton-line w-55" />
                      <span className="sp-skeleton sp-skeleton-line w-80" />
                      <span className="sp-skeleton sp-skeleton-line w-45" />
                      <span className="sp-skeleton sp-skeleton-line w-65" />
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
