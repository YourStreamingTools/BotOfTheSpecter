import { useEffect, useState } from 'react'
import { fetchFreeGames, type FreeGame } from '../api'
import { formatGameDate } from '../format'

export default function FreeGamesPage() {
  const [games, setGames] = useState<FreeGame[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchFreeGames()
      .then((data) => {
        if (!data?.ok) {
          setError(data?.error || 'Unable to fetch data from API')
          setGames([])
          return
        }
        setGames(data.games || [])
      })
      .catch(() => {
        setError('Unable to fetch data from API')
        setGames([])
      })
  }, [])

  if (games === null) {
    return <p style={{ color: 'var(--text-secondary)' }}>Loading…</p>
  }

  if (error) {
    return (
      <>
        <Header />
        <div className="sp-alert sp-alert-warning">
          <strong>Notice:</strong> {error}
        </div>
      </>
    )
  }

  if (games.length === 0) {
    return (
      <>
        <Header />
        <div className="sp-empty">
          <i className="fa-solid fa-gamepad" />
          <h3>No Games Found</h3>
          <p>No recent free games were found.</p>
        </div>
      </>
    )
  }

  const latest = games[0]

  return (
    <>
      <Header />
      <div className="ms-game-featured">
        {latest.game_thumbnail && (
          <img className="ms-game-featured-img" src={latest.game_thumbnail} alt={latest.game_title} />
        )}
        <div className="ms-game-featured-body">
          <div className="ms-game-featured-badge">Latest Free Game</div>
          <div className="ms-game-featured-title">{latest.game_title}</div>
          <div className="ms-game-featured-meta">
            <strong>{latest.game_org}</strong> &middot; {latest.game_price} &middot; Received: {formatGameDate(latest.received_at)}
          </div>
          <div className="ms-game-featured-desc">{(latest.game_description || '').slice(0, 400)}</div>
          <div className="ms-game-featured-actions">
            {latest.game_url && (
              <a href={latest.game_url} target="_blank" rel="noopener" className="sp-btn sp-btn-primary">
                <i className="fa-solid fa-arrow-up-right-from-square" /> Claim / View
              </a>
            )}
            <a href="#all-games" className="sp-btn sp-btn-secondary">View All Recent Games</a>
          </div>
        </div>
      </div>
      <div id="all-games" className="ms-games-grid">
        {games.map((game) => (
          <div className="ms-game-card" key={game.game_title + (game.received_at || '')}>
            {game.game_thumbnail && (
              <div className="ms-game-card-img">
                <img src={game.game_thumbnail} alt={game.game_title} />
              </div>
            )}
            <div className="ms-game-card-body">
              <div className="ms-game-card-title">{game.game_title}</div>
              <div className="ms-game-card-meta"><strong>{game.game_org}</strong> &middot; {game.game_price}</div>
              <div className="ms-game-card-desc">{(game.game_description || '').slice(0, 300)}</div>
            </div>
            <div className="ms-game-card-footer">
              {game.game_url ? (
                <a href={game.game_url} target="_blank" rel="noopener" className="sp-btn sp-btn-primary sp-btn-sm">Claim / View</a>
              ) : (
                <span className="sp-badge">No Link</span>
              )}
              <span className="ms-game-card-date">Received: {formatGameDate(game.received_at)}</span>
            </div>
          </div>
        ))}
      </div>
    </>
  )
}

function Header() {
  return (
    <div className="sp-page-header">
      <div>
        <h1>FreeStuff — System Announcements</h1>
        <p>System-wide FreeStuff announcements used by the Discord and Twitch bots.</p>
      </div>
    </div>
  )
}
