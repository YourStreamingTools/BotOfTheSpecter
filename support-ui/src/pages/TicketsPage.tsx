import { useEffect, useState, type FormEvent } from 'react'
import {
  apiGet,
  apiPost,
  CAT_LABEL,
  STATUS_LABEL,
  formatWhen,
  timeAgo,
  type SupportSession,
  type Ticket,
  type TicketReply,
} from '../api'

function statusBadge(s: string) {
  return <span className={'sp-badge sp-status-' + s}>{STATUS_LABEL[s] || s}</span>
}
function prioBadge(p: string) {
  const icons: Record<string, string> = { low: 'fa-arrow-down', normal: 'fa-minus', high: 'fa-arrow-up' }
  return (
    <span className={'sp-badge sp-prio-' + p}>
      <i className={'fa-solid ' + (icons[p] || 'fa-minus')} /> {p.charAt(0).toUpperCase() + p.slice(1)}
    </span>
  )
}

export default function TicketsPage({ session }: { session: SupportSession }) {
  const params = new URLSearchParams(window.location.search)
  const id = params.get('id')
  const action = params.get('action')
  const queue = params.get('view') === 'queue' && session.is_staff
  if (id) return <Thread ticketNumber={id} session={session} />
  if (action === 'new') return <NewTicket session={session} />
  return <TicketList queue={queue} session={session} />
}

function TicketList({ queue, session }: { queue: boolean; session: SupportSession }) {
  const params = new URLSearchParams(window.location.search)
  const [status, setStatus] = useState(params.get('status') || 'all')
  const [priority, setPriority] = useState(params.get('priority') || 'all')
  const [tickets, setTickets] = useState<Ticket[] | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    const q = new URLSearchParams()
    if (queue) q.set('view', 'queue')
    if (status !== 'all') q.set('status', status)
    if (queue && priority !== 'all') q.set('priority', priority)
    apiGet('/api/tickets.php?' + q.toString())
      .then((data: { ok?: boolean; tickets?: Ticket[]; error?: string }) => {
        if (!data?.ok) {
          setError(data?.error || 'Could not load tickets.')
          setTickets([])
          return
        }
        setTickets(data.tickets || [])
      })
      .catch(() => {
        setError('Could not load tickets.')
        setTickets([])
      })
  }, [queue, status, priority])

  function filterTo(nextStatus: string, nextPrio: string) {
    setStatus(nextStatus)
    setPriority(nextPrio)
    const q = new URLSearchParams()
    if (queue) q.set('view', 'queue')
    if (nextStatus !== 'all') q.set('status', nextStatus)
    if (queue && nextPrio !== 'all') q.set('priority', nextPrio)
    const qs = q.toString()
    history.replaceState(null, '', '/tickets.php' + (qs ? '?' + qs : ''))
  }

  return (
    <>
      {!session.is_registered && (
        <div className="sp-alert sp-alert-warning">
          <i className="fa-solid fa-triangle-exclamation" />
          <span>This support system is only for users of <strong>BotOfTheSpecter</strong> the Twitch and Discord bot.
            You don't appear to have a BotOfTheSpecter account. Please <a href="https://botofthespecter.com" target="_blank" rel="noopener">sign up at botofthespecter.com</a> before submitting a ticket.</span>
        </div>
      )}
      <div className="sp-page-header">
        <div>
          <h1>{queue ? <><i className="fa-solid fa-shield-halved" /> Staff Queue</> : 'My Support Tickets'}</h1>
          <p style={{ color: 'var(--text-secondary)' }}>{tickets ? `${tickets.length} ticket${tickets.length !== 1 ? 's' : ''}` : 'Loading…'}</p>
        </div>
        <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
          {session.is_staff && !queue && <a href="/tickets.php?view=queue" className="sp-btn sp-btn-secondary"><i className="fa-solid fa-shield-halved" /> Staff Queue</a>}
          {session.is_registered
            ? <a href="/tickets.php?action=new" className="sp-btn sp-btn-primary"><i className="fa-solid fa-plus" /> New Ticket</a>
            : <button className="sp-btn sp-btn-primary" disabled type="button"><i className="fa-solid fa-plus" /> New Ticket</button>}
        </div>
      </div>
      <form className="sp-filters" onSubmit={(e) => e.preventDefault()}>
        <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'center' }}>
          <select className="sp-select sp-select-sm" value={status} onChange={(e) => filterTo(e.target.value, priority)}>
            <option value="all">All Statuses</option>
            {['open', 'in_progress', 'resolved', 'closed'].map((st) => (
              <option key={st} value={st}>{STATUS_LABEL[st]}</option>
            ))}
          </select>
          {queue && (
            <select className="sp-select sp-select-sm" value={priority} onChange={(e) => filterTo(status, e.target.value)}>
              <option value="all">All Priorities</option>
              {['high', 'normal', 'low'].map((pr) => (
                <option key={pr} value={pr}>{pr.charAt(0).toUpperCase() + pr.slice(1)}</option>
              ))}
            </select>
          )}
        </div>
      </form>
      {error && <div className="sp-alert sp-alert-danger"><i className="fa-solid fa-circle-xmark" /><span>{error}</span></div>}
      {tickets && tickets.length === 0 && (
        <div className="sp-empty-state">
          <div className="sp-empty-icon"><i className="fa-solid fa-ticket" /></div>
          <h3>{queue ? 'No tickets match your filter' : 'No tickets yet'}</h3>
          <p>{queue ? 'Try changing the status or priority filter.' : 'Submit your first support ticket if you need help.'}</p>
          {!queue && session.is_registered && <a href="/tickets.php?action=new" className="sp-btn sp-btn-primary sp-mt-2"><i className="fa-solid fa-plus" /> Submit a Ticket</a>}
        </div>
      )}
      {tickets && tickets.length > 0 && (
        <div className="sp-table-wrap">
          <table className="sp-table">
            <thead>
              <tr>
                <th>Ticket #</th>
                <th>Subject</th>
                {queue && <th>From</th>}
                <th>Category</th>
                <th>Priority</th>
                <th>Status</th>
                <th>{queue ? 'Opened' : 'Last Updated'}</th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((t) => (
                <tr key={t.ticket_number}>
                  <td><a href={'/tickets.php?id=' + encodeURIComponent(t.ticket_number)} style={{ fontFamily: 'monospace', whiteSpace: 'nowrap' }}>{t.ticket_number}</a></td>
                  <td><a href={'/tickets.php?id=' + encodeURIComponent(t.ticket_number)}>{t.subject}</a></td>
                  {queue && <td>{t.display_name || t.username}</td>}
                  <td>{CAT_LABEL[t.category] || t.category}</td>
                  <td>{prioBadge(t.priority)}</td>
                  <td>{statusBadge(t.status)}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{timeAgo(queue ? t.created_at : t.updated_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function NewTicket({ session }: { session: SupportSession }) {
  const params = new URLSearchParams(window.location.search)
  const [category, setCategory] = useState(params.get('category') || 'general')
  const [program, setProgram] = useState(params.get('program') || '')
  const [priority, setPriority] = useState('normal')
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  const [programs, setPrograms] = useState<{ slug: string; name: string }[]>([])
  const [errors, setErrors] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const isFeedback = category === 'feedback'
  const isBeta = category === 'beta_request'

  useEffect(() => {
    apiGet('/api/tickets.php').then((data: { beta_programs?: { slug: string; name: string }[] }) => {
      setPrograms(data?.beta_programs || [])
    })
  }, [])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErrors([])
    const data = await apiPost('/api/tickets.php', {
      _action: 'new_ticket',
      csrf_token: session.csrf_token,
      category,
      program_slug: program,
      priority,
      subject,
      message,
    })
    setBusy(false)
    if (data?.ok && data.ticket_number) {
      window.location.href = '/tickets.php?id=' + encodeURIComponent(data.ticket_number)
      return
    }
    setErrors(data?.errors || [data?.error || 'Could not submit ticket.'])
  }

  return (
    <>
      <div className="sp-page-header">
        <div>
          <a href="/tickets.php" className="sp-back-link"><i className="fa-solid fa-arrow-left" /> My Tickets</a>
          <h1>Submit a Support Ticket</h1>
        </div>
      </div>
      {!session.is_registered && (
        <div className="sp-alert sp-alert-warning">
          <i className="fa-solid fa-triangle-exclamation" />
          <span>This support system is only for users of <strong>BotOfTheSpecter</strong>. Please <a href="https://botofthespecter.com" target="_blank" rel="noopener">sign up</a> first.</span>
        </div>
      )}
      {errors.map((err) => (
        <div key={err} className="sp-alert sp-alert-danger"><i className="fa-solid fa-circle-xmark" /><span>{err}</span></div>
      ))}
      <div className="sp-card" style={{ maxWidth: 640, ...(session.is_registered ? {} : { opacity: 0.5, pointerEvents: 'none' as const }) }}>
        <div className="sp-card-header"><i className="fa-solid fa-ticket" /> New Ticket</div>
        <div className="sp-card-body">
          <form onSubmit={submit}>
            <div className="sp-form-row">
              <div className="sp-form-group">
                <label className="sp-label" htmlFor="ticket_category">Category</label>
                <select id="ticket_category" className="sp-select" value={category} onChange={(e) => setCategory(e.target.value)}>
                  <option value="general">General</option>
                  <option value="technical">Technical</option>
                  <option value="account">Account</option>
                  <option value="billing">Billing</option>
                  <option value="feedback">Feedback</option>
                  {programs.length > 0 && <option value="beta_request">Beta Program Request</option>}
                </select>
              </div>
              {!isFeedback && !isBeta && (
                <div className="sp-form-group">
                  <label className="sp-label" htmlFor="ticket_priority">Priority</label>
                  <select id="ticket_priority" className="sp-select" value={priority} onChange={(e) => setPriority(e.target.value)}>
                    <option value="normal">Normal</option>
                    <option value="low">Low</option>
                    {session.is_staff && <option value="high">High</option>}
                  </select>
                </div>
              )}
            </div>
            {isBeta && (
              <div className="sp-form-group">
                <label className="sp-label" htmlFor="ticket_program">Beta Program <span className="sp-req">*</span></label>
                <select id="ticket_program" className="sp-select" value={program} onChange={(e) => setProgram(e.target.value)}>
                  <option value="">— Select a program —</option>
                  {programs.map((bp) => <option key={bp.slug} value={bp.slug}>{bp.name}</option>)}
                </select>
              </div>
            )}
            {!isFeedback && !isBeta && (
              <div className="sp-form-group">
                <label className="sp-label" htmlFor="ticket_subject">Subject <span className="sp-req">*</span></label>
                <input id="ticket_subject" className="sp-input" maxLength={255} placeholder="Briefly describe your issue" value={subject} onChange={(e) => setSubject(e.target.value)} />
              </div>
            )}
            <div className="sp-form-group">
              <label className="sp-label" htmlFor="ticket_message">
                {isFeedback ? 'Your Feedback' : isBeta ? 'Why do you want access?' : 'Description'} <span className="sp-req">*</span>
              </label>
              <textarea
                id="ticket_message"
                className="sp-textarea"
                rows={7}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder={isFeedback
                  ? "Tell us what you think — what's working, what's not, or what you'd like to see improved."
                  : isBeta
                    ? "Tell us a little about yourself and why you'd like to join this beta program."
                    : 'Please describe the issue in detail - what happened, what you expected, and any error messages you saw.'}
              />
              <div className={'sp-char-counter' + (message.length > 0 && message.length < 20 ? ' warn' : message.length >= 20 ? ' ok' : '')}>
                {message.length} chars (min 20)
              </div>
            </div>
            <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
              <button type="submit" className="sp-btn sp-btn-primary" disabled={busy}>
                <i className="fa-solid fa-paper-plane" /> {busy ? 'Submitting…' : isFeedback ? 'Submit Feedback' : isBeta ? 'Submit Request' : 'Submit Ticket'}
              </button>
              <a href="/tickets.php" className="sp-btn sp-btn-ghost">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </>
  )
}

function Thread({ ticketNumber, session }: { ticketNumber: string; session: SupportSession }) {
  const [ticket, setTicket] = useState<Ticket | null>(null)
  const [replies, setReplies] = useState<TicketReply[]>([])
  const [missing, setMissing] = useState(false)
  const [message, setMessage] = useState('')
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [declineOpen, setDeclineOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  function load() {
    apiGet('/api/tickets.php?id=' + encodeURIComponent(ticketNumber)).then((data: { ok?: boolean; ticket?: Ticket; replies?: TicketReply[] }) => {
      if (!data?.ok || !data.ticket) {
        setMissing(true)
        return
      }
      setTicket(data.ticket)
      setReplies(data.replies || [])
      setStatus(data.ticket.status)
      setPriority(data.ticket.priority)
    })
  }
  useEffect(load, [ticketNumber])

  async function reply(e: FormEvent) {
    e.preventDefault()
    if (!ticket) return
    setBusy(true)
    const data = await apiPost('/api/tickets.php', {
      _action: 'reply',
      csrf_token: session.csrf_token,
      ticket_id: ticket.id,
      message,
    })
    setBusy(false)
    if (data?.ok) {
      setMessage('')
      load()
      return
    }
    setError(data?.error || (data?.errors && data.errors[0]) || 'Reply failed.')
  }

  async function staffUpdate(e: FormEvent) {
    e.preventDefault()
    if (!ticket) return
    await apiPost('/api/tickets.php', {
      _action: 'staff_update',
      csrf_token: session.csrf_token,
      ticket_id: ticket.id,
      status,
      priority,
    })
    load()
  }

  async function betaAction(kind: 'approve_beta' | 'decline_beta') {
    if (!ticket) return
    setBusy(true)
    await apiPost('/api/tickets.php', {
      _action: kind,
      csrf_token: session.csrf_token,
      ticket_id: ticket.id,
      reason,
    })
    setBusy(false)
    load()
  }

  if (missing) {
    return (
      <div className="sp-empty-state">
        <div className="sp-empty-icon"><i className="fa-solid fa-circle-xmark" /></div>
        <h3>Ticket Not Found</h3>
        <p>That ticket doesn't exist or you don't have permission to view it.</p>
        <a href="/tickets.php" className="sp-btn sp-btn-primary sp-mt-2">Back to My Tickets</a>
      </div>
    )
  }
  if (!ticket) return <p style={{ color: 'var(--text-secondary)' }}>Loading ticket…</p>

  const progName = ticket.meta?.program_name || ticket.meta?.program || 'Unknown'
  const openish = ticket.status === 'open' || ticket.status === 'in_progress'

  return (
    <>
      {error && <div className="sp-alert sp-alert-danger"><i className="fa-solid fa-circle-xmark" /><span>{error}</span></div>}
      <div className="sp-page-header">
        <div>
          <a href="/tickets.php" className="sp-back-link"><i className="fa-solid fa-arrow-left" /> {session.is_staff ? 'Staff Queue' : 'My Tickets'}</a>
          <h1>{ticket.subject}</h1>
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.5rem', alignItems: 'center' }}>
            <span style={{ fontFamily: 'monospace', fontSize: '0.85rem', color: 'var(--text-muted)' }}>{ticket.ticket_number}</span>
            {statusBadge(ticket.status)}
            {prioBadge(ticket.priority)}
            <span className="sp-badge sp-cat">{CAT_LABEL[ticket.category] || ticket.category}</span>
          </div>
        </div>
        {session.is_staff && (
          <form onSubmit={staffUpdate} style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
            <select className="sp-select sp-select-sm" value={status} onChange={(e) => setStatus(e.target.value)}>
              {['open', 'in_progress', 'resolved', 'closed'].map((st) => <option key={st} value={st}>{STATUS_LABEL[st]}</option>)}
            </select>
            <select className="sp-select sp-select-sm" value={priority} onChange={(e) => setPriority(e.target.value)}>
              {['low', 'normal', 'high'].map((pr) => <option key={pr} value={pr}>{pr.charAt(0).toUpperCase() + pr.slice(1)}</option>)}
            </select>
            <button type="submit" className="sp-btn sp-btn-secondary sp-btn-sm"><i className="fa-solid fa-check" /> Update</button>
          </form>
        )}
      </div>
      <div className="sp-ticket-meta">
        <span><i className="fa-regular fa-user" /> Opened by <strong>{ticket.display_name || ticket.username}</strong></span>
        <span><i className="fa-regular fa-clock" /> {formatWhen(ticket.created_at)}</span>
        <span><i className="fa-regular fa-rotate" /> Updated {timeAgo(ticket.updated_at)}</span>
        {ticket.category === 'beta_request' && <span><i className="fa-solid fa-flask" /> Program: <strong>{progName}</strong></span>}
      </div>
      {session.is_staff && ticket.category === 'beta_request' && openish && (
        <div className="sp-card sp-mt-3" style={{ borderLeft: '3px solid var(--accent,#7c3aed)' }}>
          <div className="sp-card-header"><i className="fa-solid fa-flask" /> Beta Request — {progName}</div>
          <div className="sp-card-body">
            <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
              <button type="button" className="sp-btn sp-btn-primary" disabled={busy} onClick={() => betaAction('approve_beta')}>
                <i className="fa-solid fa-circle-check" /> Approve
              </button>
              <div>
                <button type="button" className="sp-btn sp-btn-danger" onClick={() => setDeclineOpen(true)}>
                  <i className="fa-solid fa-circle-xmark" /> Decline
                </button>
                {declineOpen && (
                  <div style={{ marginTop: '0.75rem' }}>
                    <div className="sp-form-group">
                      <textarea className="sp-textarea" rows={3} placeholder="Optional: reason for declining…" value={reason} onChange={(e) => setReason(e.target.value)} />
                    </div>
                    <button type="button" className="sp-btn sp-btn-danger" disabled={busy} onClick={() => betaAction('decline_beta')}>
                      <i className="fa-solid fa-circle-xmark" /> Confirm Decline
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
      <div className="sp-ticket-thread">
        {replies.map((r) => {
          const isStaffMsg = Boolean(r.is_staff)
          return (
            <div key={r.id} className={isStaffMsg ? 'sp-msg sp-msg-staff' : 'sp-msg sp-msg-user'}>
              <div className="sp-msg-header">
                <span className="sp-msg-author">
                  {isStaffMsg && <span className="sp-badge sp-staff-badge"><i className="fa-solid fa-shield-halved" /> Staff </span>}
                  {r.author_display_name}
                </span>
                <span className="sp-msg-time">{formatWhen(r.created_at)}</span>
              </div>
              <div className="sp-msg-body" style={{ whiteSpace: 'pre-wrap' }}>{r.message}</div>
            </div>
          )
        })}
      </div>
      {openish ? (
        <div className="sp-card sp-mt-3">
          <div className="sp-card-header"><i className="fa-solid fa-reply" /> Reply</div>
          <div className="sp-card-body">
            <form onSubmit={reply}>
              <div className="sp-form-group">
                <label className="sp-label" htmlFor="reply_msg">Message</label>
                <textarea id="reply_msg" className="sp-textarea" rows={5} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Write your reply here…" />
              </div>
              <button type="submit" className="sp-btn sp-btn-primary" disabled={busy}><i className="fa-solid fa-paper-plane" /> Send Reply</button>
            </form>
          </div>
        </div>
      ) : (
        <div className="sp-alert sp-alert-info sp-mt-3">
          <i className="fa-solid fa-lock" />
          <span>This ticket is <strong>{STATUS_LABEL[ticket.status] || ticket.status}</strong>. {!session.is_staff && 'Replying will automatically reopen it.'}</span>
          {!session.is_staff && (
            <form onSubmit={reply} style={{ marginTop: '0.75rem' }}>
              <div className="sp-form-group">
                <textarea className="sp-textarea" rows={4} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Write a follow-up…" />
              </div>
              <button type="submit" className="sp-btn sp-btn-secondary" disabled={busy}><i className="fa-solid fa-paper-plane" /> Reopen &amp; Reply</button>
            </form>
          )}
        </div>
      )}
    </>
  )
}
