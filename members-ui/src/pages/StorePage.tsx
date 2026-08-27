import { useEffect, useState } from 'react'
import { buyStoreItem, fetchStore, type StoreData, type StoreItem } from '../api'
import { STORE_TYPE_ICONS, STORE_TYPE_LABELS } from '../format'

export default function StorePage({ channel }: { channel: string }) {
  const [data, setData] = useState<StoreData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [toast, setToast] = useState<{ msg: string; ok: boolean } | null>(null)
  const [balance, setBalance] = useState(0)
  const [buying, setBuying] = useState<number | null>(null)

  useEffect(() => {
    setData(null)
    setError(null)
    fetchStore(channel)
      .then((d) => {
        setData(d)
        setBalance(d.balance || 0)
      })
      .catch(() => setError('Unable to load this store.'))
  }, [channel])

  useEffect(() => {
    if (!toast) return
    const t = window.setTimeout(() => setToast(null), 5000)
    return () => window.clearTimeout(t)
  }, [toast])

  if (error) {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-triangle-exclamation" />
        <h3>Unable to load</h3>
        <p>{error}</p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Search channels</a>
      </div>
    )
  }

  if (!data) {
    return <p style={{ color: 'var(--text-secondary)' }}>Loading…</p>
  }

  if (data.status === 'not_found' || data.status === 'invalid') {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-circle-question" />
        <h3>Channel not found</h3>
        <p>We couldn&rsquo;t find <strong>{channel}</strong> on BotOfTheSpecter.</p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Back to Search</a>
      </div>
    )
  }

  if (data.status === 'unavailable') {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-store-slash" />
        <h3>Store unavailable</h3>
        <p>This channel&rsquo;s store cannot be viewed.</p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Back to Search</a>
      </div>
    )
  }

  const pointName = data.point_name || 'Points'
  const settings = data.settings || { enabled: 0, paused: 0, stream_online_only: 0 }
  const items = data.items || []
  const storeOpen = !!data.store_ready && !!settings.enabled && !settings.paused
  const liveOnlyBlocked = storeOpen && !!settings.stream_online_only && !data.stream_online
  const buyEnabled = storeOpen && !liveOnlyBlocked

  async function onBuy(item: StoreItem) {
    if (!buyEnabled || buying !== null) return
    if (!window.confirm('Buy "' + item.title + '"?')) return
    setBuying(item.id)
    try {
      const res = await buyStoreItem(channel, item.id, data!.csrf)
      if (res && res.success) {
        setToast({ msg: res.message || 'Purchased!', ok: true })
        if (typeof res.balance === 'number') setBalance(res.balance)
      } else {
        setToast({ msg: (res && res.message) || 'Purchase failed.', ok: false })
      }
    } catch {
      setToast({ msg: 'Network error. Try again.', ok: false })
    } finally {
      setBuying(null)
    }
  }

  return (
    <>
      <div className="sp-page-header ms-store-header">
        <div className="ms-store-channel">
          {data.profile_image && <img className="ms-store-avatar" src={data.profile_image} alt="" />}
          <div>
            <h1>{data.display_name} Store</h1>
            <p className="ms-store-sub">Spend your {pointName} on streamer-approved rewards.</p>
          </div>
        </div>
        <div className="ms-store-balance-card" id="store-balance-card">
          <div className="ms-store-balance-label">Your balance</div>
          <div className="ms-store-balance-value">
            <span id="store-balance">{balance}</span>
            <span className="ms-store-balance-unit">{pointName}</span>
          </div>
        </div>
      </div>

      {toast && (
        <div className={'sp-alert ' + (toast.ok ? 'sp-alert-success' : 'sp-alert-danger')} style={{ marginBottom: '1rem' }}>
          {toast.msg}
        </div>
      )}

      {!data.store_ready ? (
        <div className="sp-empty">
          <i className="fa-solid fa-store" />
          <h3>Store not set up</h3>
          <p>This channel hasn&rsquo;t configured a Point Store yet.</p>
        </div>
      ) : !settings.enabled ? (
        <div className="sp-empty">
          <i className="fa-solid fa-store-slash" />
          <h3>Store closed</h3>
          <p>The streamer has not enabled their Point Store.</p>
        </div>
      ) : settings.paused ? (
        <div className="sp-empty">
          <i className="fa-solid fa-pause" />
          <h3>Store paused</h3>
          <p>Purchases are temporarily paused. Check back soon.</p>
        </div>
      ) : (
        <>
          {liveOnlyBlocked && (
            <div className="sp-alert sp-alert-warning" style={{ marginBottom: '1rem' }}>
              <i className="fa-solid fa-broadcast-tower" /> This store only accepts purchases while the stream is live.
            </div>
          )}
          {items.length === 0 ? (
            <div className="sp-empty">
              <i className="fa-solid fa-box-open" />
              <h3>{liveOnlyBlocked ? 'No items' : 'No items yet'}</h3>
              <p>{liveOnlyBlocked ? 'No store items are listed yet.' : 'The streamer hasn\'t added any store items.'}</p>
            </div>
          ) : (
            <div className={'ms-store-grid' + (liveOnlyBlocked ? ' ms-store-grid--disabled' : '')}>
              {items.map((item) => {
                const canAfford = balance >= item.cost
                const outOfStock = item.stock !== null && item.stock <= 0
                const canBuy = canAfford && !outOfStock && buyEnabled
                return (
                  <div
                    key={item.id}
                    className={'ms-store-card' + (canBuy || liveOnlyBlocked ? '' : ' is-locked')}
                    data-item-id={item.id}
                    data-cost={item.cost}
                  >
                    <div className="ms-store-card-icon">
                      <i className={'fa-solid ' + (STORE_TYPE_ICONS[item.item_type] || 'fa-gift')} />
                    </div>
                    <div className="ms-store-card-body">
                      <div className="ms-store-card-type">{STORE_TYPE_LABELS[item.item_type] || item.item_type}</div>
                      <h3 className="ms-store-card-title">{item.title}</h3>
                      {item.description && <p className="ms-store-card-desc">{item.description}</p>}
                      <div className="ms-store-card-cost">{item.cost} {pointName}</div>
                      {!liveOnlyBlocked && outOfStock && <span className="sp-badge sp-badge-closed">Out of stock</span>}
                      {!liveOnlyBlocked && !outOfStock && !canAfford && (
                        <span className="sp-badge sp-badge-closed">Need more {pointName}</span>
                      )}
                    </div>
                    {!liveOnlyBlocked && (
                      <div className="ms-store-card-actions">
                        <button
                          type="button"
                          className="sp-btn sp-btn-primary sp-btn-sm store-buy-btn"
                          disabled={!canBuy || buying === item.id}
                          onClick={() => onBuy(item)}
                        >
                          <i className="fa-solid fa-cart-shopping" /> Buy
                        </button>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </>
      )}

      {data.recent && data.recent.length > 0 && (
        <div className="sp-card" style={{ marginTop: '1.5rem' }}>
          <div className="sp-card-header">
            <span className="sp-card-title"><i className="fa-solid fa-receipt" /> Your recent purchases</span>
          </div>
          <div className="sp-card-body">
            <ul className="ms-store-recent">
              {data.recent.map((r, i) => (
                <li key={i}>
                  <strong>{r.item_title}</strong>
                  <span>{r.cost} {pointName}</span>
                  <span className="ms-store-recent-time">{r.created_at}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </>
  )
}
