import { useEffect, useMemo, useState } from 'react'
import { fetchChannel, type ChannelData } from '../api'
import MemorialPage from './MemorialPage'
import { cooldownColor, formatDateTime, formatLurkDuration, formatWatchTime, PERMISSION_LABELS } from '../format'

type TabId =
  | 'customCommands'
  | 'lurkers'
  | 'typos'
  | 'deaths'
  | 'hugs'
  | 'kisses'
  | 'highfives'
  | 'custom'
  | 'userCounts'
  | 'rewardCounts'
  | 'watchTime'
  | 'quotes'
  | 'todos'

const TABS: Array<{ id: TabId; icon: string; label: string }> = [
  { id: 'customCommands', icon: 'fa-terminal', label: 'Custom Commands' },
  { id: 'lurkers', icon: 'fa-eye-slash', label: 'Lurkers' },
  { id: 'typos', icon: 'fa-keyboard', label: 'Typo Counts' },
  { id: 'deaths', icon: 'fa-skull', label: 'Deaths' },
  { id: 'hugs', icon: 'fa-heart', label: 'Hugs' },
  { id: 'kisses', icon: 'fa-kiss', label: 'Kisses' },
  { id: 'highfives', icon: 'fa-hand', label: 'High-Fives' },
  { id: 'custom', icon: 'fa-hashtag', label: 'Custom Counts' },
  { id: 'userCounts', icon: 'fa-users', label: 'User Counts' },
  { id: 'rewardCounts', icon: 'fa-gift', label: 'Rewards' },
  { id: 'watchTime', icon: 'fa-clock', label: 'Watch Time' },
  { id: 'quotes', icon: 'fa-quote-left', label: 'Quotes' },
  { id: 'todos', icon: 'fa-check-square', label: 'To-Do' },
]

export default function ChannelPage({ username }: { username: string }) {
  const [data, setData] = useState<ChannelData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<TabId>('customCommands')
  const [rewardFilter, setRewardFilter] = useState('All')

  useEffect(() => {
    setData(null)
    setError(null)
    setTab('customCommands')
    setRewardFilter('All')
    fetchChannel(username)
      .then((d) => setData(d))
      .catch(() => setError('Unable to load this channel.'))
  }, [username])

  if (error) {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-triangle-exclamation" />
        <h3>Unable to load</h3>
        <p>{error}</p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Search Again</a>
      </div>
    )
  }

  if (!data) {
    return <p style={{ color: 'var(--text-secondary)' }}>Loading…</p>
  }

  if (data.status === 'not_found' || data.status === 'invalid') {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-magnifying-glass" />
        <h3>Channel Not Found</h3>
        <p>
          We couldn&rsquo;t find a channel named <strong>{username}</strong> on BotOfTheSpecter.
          <br />
          The channel may not have signed up yet, or the username may be spelled incorrectly.
        </p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Search Again</a>
      </div>
    )
  }

  if (data.status === 'restricted') {
    return (
      <div className="sp-empty">
        <i className="fa-solid fa-user-lock" style={{ color: 'var(--amber)' }} />
        <h3>Channel Restricted</h3>
        <p>
          The channel <strong>{username}</strong> is currently restricted and cannot be viewed. Access has been suspended by an administrator.
        </p>
        <a href="/" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-arrow-left" /> Back to Search</a>
      </div>
    )
  }

  if (data.status === 'deceased') {
    return (
      <MemorialPage
        username={username}
        displayName={data.display_name || username}
        profileImage={data.profile_image}
        memorial={data.memorial || { lurkers: [], typos: [], deaths: [], hugs: [], watchtime: [] }}
      />
    )
  }

  return (
    <>
      <div className="sp-alert sp-alert-info" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem' }}>
        <span>
          Welcome {data.viewer_display_name}. You&rsquo;re viewing information for: {data.channel}
        </span>
        <a href={'/' + encodeURIComponent(username) + '/store'} className="sp-btn sp-btn-primary sp-btn-sm">
          <i className="fa-solid fa-store" /> Point Store
        </a>
      </div>
      <div className="ms-tabs-container">
        <div className="ms-tabs-wrap">
          <div className="data-tabs">
            {TABS.map((t) => (
              <div
                key={t.id}
                className={'tab-item' + (tab === t.id ? ' active' : '')}
                onClick={() => {
                  setTab(t.id)
                  setRewardFilter('All')
                }}
              >
                <i className={'fas ' + t.icon} />
                <span>{t.label}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <ChannelTable data={data} tab={tab} rewardFilter={rewardFilter} onRewardFilter={setRewardFilter} />
    </>
  )
}

function ChannelTable({
  data,
  tab,
  rewardFilter,
  onRewardFilter,
}: {
  data: ChannelData
  tab: TabId
  rewardFilter: string
  onRewardFilter: (v: string) => void
}) {
  const view = useMemo(() => buildTable(data, tab, rewardFilter), [data, tab, rewardFilter])
  const uniqueRewards = tab === 'rewardCounts'
    ? [...new Set((data.reward_counts || []).map((r) => r.reward_title).filter(Boolean))]
    : []

  return (
    <div className="sp-card">
      <h3 id="table-title">{view.title}</h3>
      {view.totals && <p id="command-totals">{view.totals}</p>}
      {tab === 'rewardCounts' && uniqueRewards.length > 0 && (
        <div className="reward-filters">
          <button
            type="button"
            className={'reward-filter-btn' + (rewardFilter === 'All' ? ' active' : '')}
            onClick={() => onRewardFilter('All')}
          >
            All
          </button>
          {uniqueRewards.map((name) => (
            <button
              key={name}
              type="button"
              className={'reward-filter-btn' + (rewardFilter === name ? ' active' : '')}
              onClick={() => onRewardFilter(name)}
            >
              {name}
            </button>
          ))}
        </div>
      )}
      <div className="sp-table-wrap" id="members-table-wrap">
        <table className="sp-table">
          <thead>
            <tr>
              {view.headers.map((h) => (
                <th key={h} style={h === 'Reward Name' && rewardFilter !== 'All' ? { display: 'none' } : undefined}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {view.rows.length === 0 ? (
              <tr><td colSpan={view.headers.length}>No rows to display.</td></tr>
            ) : (
              view.rows.map((row, i) => (
                <tr key={i}>
                  {row.map((cell, j) => (
                    <td
                      key={j}
                      className={cellClass(tab, j, cell)}
                      style={{
                        ...(j === 0 && tab === 'rewardCounts' && rewardFilter !== 'All' ? { display: 'none' } : {}),
                        ...cellStyle(tab, j, cell),
                      }}
                    >
                      {cell}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

type TableView = { title: string; headers: string[]; rows: Array<Array<string | number>>; totals?: string }

function buildTable(data: ChannelData, tab: TabId, rewardFilter: string): TableView {
  switch (tab) {
    case 'customCommands': {
      const all = data.commands || []
      const enabled = all.filter((c) => c.status === 'Enabled')
      const disabledCount = all.length - enabled.length
      return {
        title: 'Custom Commands',
        totals: `Command Totals: ${enabled.length} Enabled / ${disabledCount} Disabled`,
        headers: ['Command', 'Response', 'Status', 'Cooldown', 'Permission'],
        rows: enabled.map((item) => {
          const [permLabel] = PERMISSION_LABELS[item.permission] || [item.permission, '']
          return ['!' + item.command, item.response, item.status, String(item.cooldown) + 's', permLabel]
        }),
      }
    }
    case 'lurkers': {
      const rows = [...(data.lurkers || [])].sort(
        (a, b) => new Date(a.start_time).getTime() - new Date(b.start_time).getTime(),
      )
      return {
        title: 'Currently Lurking Users',
        headers: ['Username', 'Time'],
        rows: rows.map((item) => [item.display_name || item.user_id, formatLurkDuration(item.start_time)]),
      }
    }
    case 'typos':
      return {
        title: 'Typo Counts',
        headers: ['Username', 'Typo Count'],
        rows: (data.typos || []).map((i) => [i.username, i.typo_count]),
      }
    case 'deaths':
      return {
        title: 'Deaths Overview',
        headers: ['Game', 'Death Count'],
        rows: (data.game_deaths || []).map((i) => [i.game_name, i.death_count]),
      }
    case 'hugs':
      return {
        title: 'Hug Counts',
        headers: ['Username', 'Hug Count'],
        rows: (data.hug_counts || []).map((i) => [i.username, i.hug_count]),
      }
    case 'kisses':
      return {
        title: 'Kiss Counts',
        headers: ['Username', 'Kiss Count'],
        rows: (data.kiss_counts || []).map((i) => [i.username, i.kiss_count]),
      }
    case 'highfives':
      return {
        title: 'High-Five Counts',
        headers: ['Username', 'High-Five Count'],
        rows: (data.highfive_counts || []).map((i) => [i.username, i.highfive_count]),
      }
    case 'custom':
      return {
        title: 'Custom Counts',
        headers: ['Command', 'Used'],
        rows: (data.custom_counts || []).map((i) => [i.command, i.count]),
      }
    case 'userCounts':
      return {
        title: 'User Counts for Commands',
        headers: ['User', 'Command', 'Count'],
        rows: (data.user_counts || []).map((i) => [i.user, i.command, i.count]),
      }
    case 'rewardCounts': {
      const all = data.reward_counts || []
      const filtered = rewardFilter === 'All' ? all : all.filter((i) => i.reward_title === rewardFilter)
      return {
        title: 'Reward Counts',
        headers: ['Reward Name', 'Username', 'Count'],
        rows: filtered.map((i) => [i.reward_title, i.user, i.count]),
      }
    }
    case 'watchTime': {
      const rows = [...(data.watch_time || [])].sort(
        (a, b) => (b.total_watch_time_live - a.total_watch_time_live) || (b.total_watch_time_offline - a.total_watch_time_offline),
      )
      return {
        title: 'Watch Time',
        headers: ['Username', 'Online Watch Time', 'Offline Watch Time'],
        rows: rows.map((i) => [i.username, formatWatchTime(Number(i.total_watch_time_live)), formatWatchTime(Number(i.total_watch_time_offline))]),
      }
    }
    case 'quotes':
      return {
        title: 'Quotes',
        headers: ['ID', 'What was said'],
        rows: (data.quotes || []).map((i) => [i.id, i.quote]),
      }
    case 'todos': {
      const cats = data.todo_categories || []
      return {
        title: 'To-Do Items',
        headers: ['ID', 'Task', 'Category', 'Completed', 'Created At', 'Updated At'],
        rows: (data.todos || []).map((item) => {
          const cat = cats.find((c) => c.id === parseInt(String(item.category), 10))
          return [
            item.id,
            item.objective,
            cat?.category || String(item.category),
            String(item.completed),
            formatDateTime(item.created_at),
            formatDateTime(item.updated_at),
          ]
        }),
      }
    }
  }
}

function cellClass(tab: TabId, col: number, value: string | number): string | undefined {
  if (tab === 'customCommands' && col === 2) return String(value) === 'Enabled' ? 'text-success' : 'text-danger'
  if (tab === 'lurkers' && col === 1) return 'text-success'
  if (['typos', 'deaths', 'hugs', 'kisses', 'highfives', 'custom'].includes(tab) && col === 1) return 'text-success'
  if (tab === 'userCounts' && col >= 1) return 'text-success'
  if (tab === 'rewardCounts' && col === 2) return 'text-success'
  if (tab === 'quotes' && col === 1) return 'text-success'
  return undefined
}

function cellStyle(tab: TabId, col: number, value: string | number): { color?: string; fontWeight?: number } {
  if (tab === 'customCommands' && col === 3) {
    const n = parseInt(String(value), 10)
    return { color: cooldownColor(Number.isNaN(n) ? 0 : n), fontWeight: 600 }
  }
  if (tab === 'customCommands' && col === 4) {
    const found = Object.values(PERMISSION_LABELS).find(([label]) => label === String(value))
    return found ? { color: found[1], fontWeight: 600 } : {}
  }
  return {}
}
