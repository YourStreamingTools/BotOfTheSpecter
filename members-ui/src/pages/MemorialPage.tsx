import { useEffect, useState, type ReactNode } from 'react'
import type { MemorialData } from '../api'
import { HELPLINES, HELPLINES_BY_CC, type Helpline } from '../helplines'
import { formatMonthYear, formatWatchTime } from '../format'

type Props = {
  username: string
  displayName: string
  profileImage: string | null
  memorial: MemorialData
}

export default function MemorialPage({ username, displayName, profileImage, memorial }: Props) {
  const [open, setOpen] = useState(false)
  const [local, setLocal] = useState<Helpline | null>(null)

  useEffect(() => {
    fetch('https://ipapi.co/json/')
      .then((r) => r.json())
      .then((data: { country_code?: string }) => {
        const code = String(data.country_code || '').trim().toUpperCase()
        if (HELPLINES_BY_CC[code]) setLocal(HELPLINES_BY_CC[code])
      })
      .catch(() => undefined)
  }, [])

  return (
    <div className="memorial-page">
      <div className="memorial-stars-bg" aria-hidden="true" />
      <div className="memorial-candles" aria-hidden="true">
        <Candle />
        <Candle />
        <Candle />
      </div>
      <div className="memorial-dove-icon">
        <i className="fas fa-dove fa-3x" />
      </div>
      <h1 className="memorial-title">In Memoriam</h1>
      {profileImage && (
        <div className="memorial-profile-wrap">
          <img
            className="memorial-profile-img"
            src={profileImage}
            alt={'Profile image of ' + displayName}
            onError={(e) => { (e.target as HTMLImageElement).src = 'https://cdn.botofthespecter.com/logo.png' }}
          />
        </div>
      )}
      <p className="memorial-name">{displayName}</p>
      <p className="memorial-username">@{username}</p>
      <div className="memorial-divider" aria-hidden="true"><span>✦</span></div>
      <div className="memorial-message">
        <p className="preserved-note">This channel has been preserved as a permanent memorial.</p>
        <p>The account holder has passed away. Their channel, community, and memories remain here as a tribute to the person they were and the community they built.</p>
      </div>
      <div className="memorial-divider" aria-hidden="true"><span>&#10022;</span></div>
      <div className="memorial-stats">
        <p className="memorial-stats-heading">Community Highlights</p>
        <div className="memorial-stats-grid">
          <StatCard icon="fa-eye-slash" title="Top Lurkers" empty={memorial.lurkers.length === 0}>
            {memorial.lurkers.map((row, i) => (
              <StatRow key={row.user_id + i} rank={i + 1} name={row.display_name} value={'since ' + formatMonthYear(row.start_time)} />
            ))}
          </StatCard>
          <StatCard icon="fa-keyboard" title="Top Typos" empty={memorial.typos.length === 0}>
            {memorial.typos.map((row, i) => (
              <StatRow key={row.username + i} rank={i + 1} name={row.username} value={String(row.typo_count) + ' typos'} />
            ))}
          </StatCard>
          <StatCard icon="fa-skull" title="Top Deaths" empty={memorial.deaths.length === 0}>
            {memorial.deaths.map((row, i) => (
              <StatRow key={row.game_name + i} rank={i + 1} name={row.game_name} value={String(row.death_count) + ' deaths'} />
            ))}
          </StatCard>
          <StatCard icon="fa-heart" title="Top Hugs" empty={memorial.hugs.length === 0}>
            {memorial.hugs.map((row, i) => (
              <StatRow key={row.username + i} rank={i + 1} name={row.username} value={String(row.hug_count) + ' hugs'} />
            ))}
          </StatCard>
          <StatCard icon="fa-clock" title="Top Watchers" empty={memorial.watchtime.length === 0}>
            {memorial.watchtime.map((row, i) => (
              <StatRow key={row.username + i} rank={i + 1} name={row.username} value={formatWatchTime(Number(row.total_watch_time_live))} />
            ))}
          </StatCard>
        </div>
      </div>
      <div className="memorial-footer-stars" aria-hidden="true">✦ &nbsp; ✦ &nbsp; ✦</div>
      <div className="memorial-helplines">
        {local && (
          <div className="memorial-local-helpline">
            <div className="memorial-local-helpline-label">
              <i className="fas fa-location-dot" />
              <span>Your local helpline</span>
            </div>
            <div className="memorial-local-helpline-body">
              <span className="memorial-local-country">{local.country}</span>
              <span className="memorial-local-name">{local.name}</span>
              <span className="memorial-local-number">{local.number}</span>
            </div>
          </div>
        )}
        <p className="memorial-helplines-note">We share these resources simply because we care about you — no assumptions, no judgement.</p>
        <button
          className={'memorial-helplines-toggle' + (open ? ' is-open' : '')}
          type="button"
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
        >
          <i className="fas fa-hands-holding-heart" />
          <span>Help is always available — view all crisis helplines</span>
          <span className="toggle-arrow"><i className="fas fa-chevron-down" /></span>
        </button>
        <p className={'memorial-helplines-sub' + (open ? ' is-visible' : '')}>
          These numbers are here for anyone who simply needs someone to talk to — for any reason at all. Reaching out is always okay.
        </p>
        <div className={'memorial-helplines-grid' + (open ? ' is-open' : '')}>
          {HELPLINES.map((h) => (
            <div className="memorial-helpline-entry" key={h.country + h.number}>
              <span className="memorial-helpline-country">{h.country}</span>
              <span className="memorial-helpline-name">{h.name}</span>
              <span className="memorial-helpline-number">{h.number}</span>
            </div>
          ))}
        </div>
      </div>
      <div className="memorial-actions">
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Back to Search</a>
      </div>
    </div>
  )
}

function Candle() {
  return (
    <div className="candle">
      <div className="candle-flame" />
      <div className="candle-wick" />
      <div className="candle-body" />
      <div className="candle-base" />
    </div>
  )
}

function StatCard({ icon, title, empty, children }: { icon: string; title: string; empty: boolean; children: ReactNode }) {
  return (
    <div className="memorial-stat-card">
      <div className="memorial-stat-card-header">
        <i className={'fas ' + icon} />
        <span className="memorial-stat-card-title">{title}</span>
      </div>
      {empty ? <p className="memorial-stat-empty">No data recorded</p> : children}
    </div>
  )
}

function StatRow({ rank, name, value }: { rank: number; name: string; value: string }) {
  return (
    <div className="memorial-stat-row">
      <span className={'memorial-stat-rank' + (rank < 4 ? ' rank-' + rank : '')}>{rank}</span>
      <div className="memorial-stat-name-wrap">
        <span className="memorial-stat-name">{name}</span>
        <span className="memorial-stat-value">{value}</span>
      </div>
    </div>
  )
}
