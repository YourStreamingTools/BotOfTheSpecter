import { useEffect, useState } from 'react'
import { get } from '../bot'

type Status = { success: boolean; beta_access: boolean; checked: boolean; subscribed: boolean; tier: string | null; is_gift: boolean; error: string | null }
type Feature = { text: string; tip: string }
type Plan = { key: string; name: string; price: string; icon: string; mod: string; storage: number; storageTip: string; sounds: number; features: Feature[]; note?: string; footer?: string }

const SUB_URL = 'https://www.twitch.tv/subs/gfaundead'

// Same plans, allowances and wording as the live dashboard/premium.php (lang/en.php).
const PLANS: Plan[] = [
  { key: 'free', name: 'Free Plan', price: '$0 USD', icon: 'fas fa-rocket', mod: 'free', storage: 20, sounds: 10,
    storageTip: 'Upload your own sound effects and music files to use for alerts and walk-on sounds.',
    features: [
      { text: 'Core Bot Features', tip: 'Access to essential bot commands and features.' },
      { text: 'Limited Support', tip: 'Receive community-based support for basic issues.' },
      { text: 'Shared Bot (BotOfTheSpecter)', tip: "The bot's username will be the same across all channels." },
      { text: 'Custom Bot Name', tip: 'Run the bot under your own verified bot account name. Set it up on your Profile page.' },
    ],
    note: '90-95% of the bot is FREE!' },
  { key: '1000', name: 'Twitch Tier 1/Prime', price: '$5.99 USD', icon: 'fas fa-star', mod: 't1', storage: 50, sounds: 20,
    storageTip: 'Store your own sound effects and music files for alerts and walk-on sounds.',
    features: [
      { text: '!song Command', tip: 'Use this command to view what song is playing in chat. (NOT REQUIRED FOR SPOTIFY)' },
      { text: 'Full Support', tip: 'Get priority assistance and support for any bot-related issues.' },
      { text: 'Exclusive Beta Features', tip: 'Early access to new and experimental bot features.' },
    ] },
  { key: '2000', name: 'Twitch Tier 2', price: '$9.99 USD', icon: 'fas fa-crown', mod: 't2', storage: 100, sounds: 30,
    storageTip: 'Store more of your own sound effects and music files for alerts and walk-on sounds.',
    features: [
      { text: 'Everything From Tier 1', tip: 'Includes all the benefits of the Tier 1 subscription.' },
      { text: 'Personalized Support', tip: 'Receive dedicated one-on-one support for your bot needs.' },
      { text: 'AI Features & Conversations', tip: 'Enjoy advanced AI-powered features and interactive conversations in chat.' },
    ] },
  { key: '3000', name: 'Twitch Tier 3', price: '$24.99 USD', icon: 'fas fa-gem', mod: 't3', storage: 200, sounds: 40,
    storageTip: 'Store an even larger collection of custom sound effects and music files.',
    features: [{ text: 'Everything from Tier 2', tip: 'Includes all the benefits of the Tier 2 subscription.' }] },
]
const BETA_PLAN: Plan = { key: 'beta', name: 'Exclusive Beta Plan', price: 'Free Access Forever!', icon: 'fas fa-flask', mod: 'beta', storage: 500, sounds: 40,
  storageTip: 'Upload your own sound effects and music files to use for alerts and walk-on sounds.',
  features: [{ text: 'All Features FOREVER', tip: 'Enjoy all bot features for free, forever!' }],
  footer: 'Thank you for helping make BotOfTheSpecter!' }

const TIER_LABEL: Record<string, string> = { '1000': 'Tier 1', '2000': 'Tier 2', '3000': 'Tier 3' }

function Action({ plan, current, beta }: { plan: Plan; current: string; beta: boolean }) {
  const isCurrent = current === plan.key
  if (beta) return isCurrent
    ? <span className="btn btn-ok btn-block static"><i className="fas fa-check-circle" /> Current Plan + Beta</span>
    : <span className="btn btn-block static btn-quiet"><i className="fas fa-flask" /> Beta Access</span>
  if (isCurrent) return <span className="btn btn-ok btn-block static"><i className="fas fa-check-circle" /> Current Plan</span>
  if (plan.key === 'free') return null
  let icon = 'fas fa-plus-circle', label = 'Subscribe'
  if (current !== 'free') {
    const up = parseInt(current, 10) < parseInt(plan.key, 10)
    icon = up ? 'fas fa-arrow-up' : 'fas fa-arrow-down'; label = up ? 'Upgrade' : 'Downgrade'
  }
  return <a className="btn btn-block" href={SUB_URL} target="_blank" rel="noopener"><i className={icon} /> {label}</a>
}

function Card({ plan, current, beta, status }: { plan: Plan; current: string; beta: boolean; status: Status | null }) {
  const isCurrent = plan.key === 'beta' || (status !== null && current === plan.key)
  return (
    <section className={`card plan plan-${plan.mod}${plan.key === 'beta' ? ' plan-banner' : ''}${isCurrent ? ' is-current' : ''}`}>
      <div className="plan-top">
        <span className="plan-icon"><i className={plan.icon} /></span>
        {isCurrent && <span className="pill pill-ok"><i className="fas fa-check" /> Current</span>}
      </div>
      <h3>{plan.name}</h3>
      <p className="plan-price">{plan.price}</p>
      <div className="plan-stats">
        <div><b>{plan.storage} MB</b><span>Storage</span></div>
        <div><b>{plan.sounds}</b><span>Sounds a day</span></div>
      </div>
      <ul className="plan-feats">
        {plan.features.map((f) => <li key={f.text}><i className="fas fa-check" /><span><b>{f.text}</b><small>{f.tip}</small></span></li>)}
      </ul>
      {plan.note && <p className="plan-note"><strong>{plan.note}</strong></p>}
      {plan.footer && <p className="plan-note muted">{plan.footer}</p>}
      {plan.key !== 'beta' && (
        <div className="plan-action">{status === null ? <span className="skel" /> : <Action plan={plan} current={current} beta={beta} />}</div>
      )}
    </section>
  )
}

export default function Premium() {
  const [status, setStatus] = useState<Status | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    get<Status>('/api/premium/status.php', 15000).then(setStatus).catch(() => {
      setFailed(true)
      setStatus({ success: false, beta_access: false, checked: false, subscribed: false, tier: null, is_gift: false, error: null })
    })
  }, [])

  const beta = !!status?.beta_access
  const current = status?.subscribed && status.tier && PLANS.some((p) => p.key === status.tier) ? status.tier : 'free'
  let info = '', warn = ''
  if (status) {
    if (status.checked && status.subscribed) {
      if (beta) info = `You have beta access and are also subscribed at ${TIER_LABEL[status.tier ?? ''] ?? `Tier ${status.tier}`}.`
    } else if (status.checked) {
      if (beta) info = 'You have beta access but are not currently subscribed on Twitch.'
      else warn = "You are not subscribed or we couldn't find a subscription on Twitch."
    } else if (!beta) {
      warn = 'Unable to determine your subscription status.' + (status.error && !failed ? ` Details: ${status.error}` : '')
    }
  }

  return (
    <div className="page premium">
      <div className="section-head">
        <h2><i className="fas fa-crown" /> Premium Features</h2>
        {status === null && <span className="skel skel-pill" />}
        {info && <span className="pill pill-info"><i className="fas fa-crown" /> {info}</span>}
        {status?.is_gift && status.subscribed && <span className="pill">Gifted sub</span>}
      </div>
      <p className="muted plan-explain">Storage holds your own sound effects and music for alerts and walk-on sounds. Sounds a day is how many sounds you can have created on the Alerts page in any 24 hours.</p>
      {warn && <div className="notice notice-warn"><i className="fas fa-exclamation-triangle" /> <span>{warn}</span></div>}
      {beta && <Card plan={BETA_PLAN} current={current} beta={beta} status={status} />}
      <div className="plan-grid">
        {PLANS.map((p) => <Card key={p.key} plan={p} current={current} beta={beta} status={status} />)}
      </div>
    </div>
  )
}
