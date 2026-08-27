import { useEffect, useState, type FormEvent } from 'react'
import { apiGet, apiPost, formatWhen, type BetaProgram, type SupportSession } from '../api'

type PendingReq = {
  ticket_number: string
  username: string
  display_name: string
  program: string
  program_name: string
  created_at: string
}

export default function BetaPage({ session }: { session: SupportSession }) {
  const [programs, setPrograms] = useState<BetaProgram[]>([])
  const [enrolled, setEnrolled] = useState<string[]>([])
  const [pending, setPending] = useState<string[]>([])
  const [requests, setRequests] = useState<PendingReq[]>([])
  const [flash, setFlash] = useState<{ type: string; msg: string } | null>(null)
  const [editId, setEditId] = useState(0)
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [desc, setDesc] = useState('')
  const [busy, setBusy] = useState(false)

  function load() {
    apiGet('/api/beta.php').then((data: {
      ok?: boolean
      programs?: BetaProgram[]
      enrolled?: string[]
      pending?: string[]
      pending_requests?: PendingReq[]
    }) => {
      setPrograms(data?.programs || [])
      setEnrolled(data?.enrolled || [])
      setPending(data?.pending || [])
      setRequests(data?.pending_requests || [])
    })
  }
  useEffect(load, [])

  async function staff(body: Record<string, unknown>) {
    setBusy(true)
    const data = await apiPost('/api/beta.php', { ...body, csrf_token: session.csrf_token })
    setBusy(false)
    if (data?.ok) {
      setFlash({ type: 'success', msg: data.message || 'Saved.' })
      resetForm()
      load()
      return
    }
    setFlash({ type: 'danger', msg: data?.error || (data?.errors && data.errors[0]) || 'Request failed.' })
  }

  function resetForm() {
    setEditId(0)
    setSlug('')
    setName('')
    setDesc('')
  }

  function openEdit(p: BetaProgram) {
    setEditId(p.id)
    setName(p.name)
    setDesc(p.description || '')
    document.getElementById('program-card')?.scrollIntoView({ behavior: 'smooth' })
  }

  function saveProgram(e: FormEvent) {
    e.preventDefault()
    staff({ _action: 'save_program', edit_id: editId, slug, name, description: desc })
  }

  return (
    <>
      {flash && (
        <div className={'sp-alert sp-alert-' + flash.type}>
          <i className={'fa-solid ' + (flash.type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark')} />
          <span>{flash.msg}</span>
        </div>
      )}
      <div className="sp-page-header">
        <div>
          <h1><i className="fa-solid fa-flask" /> Beta Programs</h1>
          <p style={{ color: 'var(--text-secondary)' }}>Request early access to features currently in testing.</p>
        </div>
      </div>
      {programs.length === 0 ? (
        <div className="sp-empty-state">
          <div className="sp-empty-icon"><i className="fa-solid fa-flask" /></div>
          <h3>No Beta Programs Available</h3>
          <p>There are no beta programs open right now. Check back later.</p>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: '1rem', marginBottom: '2rem' }}>
          {programs.map((prog) => {
            const isEnrolled = enrolled.includes(prog.slug)
            const isPending = pending.includes(prog.slug)
            const isInactive = !prog.is_active
            return (
              <div key={prog.slug} className="sp-card" style={isInactive ? { opacity: 0.55 } : undefined}>
                <div className="sp-card-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.5rem', flexWrap: 'wrap' }}>
                  <span>{prog.name}</span>
                  <div style={{ display: 'flex', gap: '0.3rem', alignItems: 'center' }}>
                    {isInactive && <span className="sp-badge" style={{ background: 'var(--text-muted)', color: '#fff', fontSize: '0.7rem' }}>Inactive</span>}
                    {isEnrolled && <span className="sp-badge sp-badge-green"><i className="fa-solid fa-circle-check" /> Enrolled</span>}
                    {isPending && <span className="sp-badge sp-badge-amber"><i className="fa-solid fa-clock" /> Pending</span>}
                  </div>
                </div>
                <div className="sp-card-body">
                  {prog.description && <p style={{ color: 'var(--text-secondary)', marginBottom: '1rem', fontSize: '0.9rem', whiteSpace: 'pre-wrap' }}>{prog.description}</p>}
                  <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
                    <code style={{ fontSize: '0.72rem', color: 'var(--text-muted)' }}>{prog.slug}</code>
                    {!isInactive && !isEnrolled && !isPending && (
                      <a href={'/tickets.php?action=new&category=beta_request&program=' + encodeURIComponent(prog.slug)} className="sp-btn sp-btn-primary sp-btn-sm" style={{ marginLeft: 'auto' }}>
                        <i className="fa-solid fa-paper-plane" /> Request Access
                      </a>
                    )}
                    {session.is_staff && (
                      <div style={{ marginLeft: 'auto', display: 'flex', gap: '0.25rem' }}>
                        <button type="button" className="sp-btn sp-btn-sm" title="Edit" onClick={() => openEdit(prog)}>
                          <i className="fa-solid fa-pen" />
                        </button>
                        <button type="button" className="sp-btn sp-btn-sm" title={isInactive ? 'Activate' : 'Deactivate'} disabled={busy} onClick={() => staff({ _action: 'toggle_program', program_id: prog.id })}>
                          <i className={'fa-solid ' + (isInactive ? 'fa-eye' : 'fa-eye-slash')} />
                        </button>
                        <button type="button" className="sp-btn sp-btn-danger sp-btn-sm" title="Delete" disabled={busy} onClick={() => {
                          if (confirm('Delete this program? This cannot be undone.')) {
                            staff({ _action: 'delete_program', program_id: prog.id })
                          }
                        }}>
                          <i className="fa-solid fa-trash" />
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}
      {session.is_staff && (
        <>
          <div className="sp-card sp-mt-3" style={{ maxWidth: 560 }} id="program-card">
            <div className="sp-card-header"><i className="fa-solid fa-plus" /> <span>{editId ? 'Edit Beta Program' : 'Create Beta Program'}</span></div>
            <div className="sp-card-body">
              <form onSubmit={saveProgram}>
                {editId === 0 && (
                  <div className="sp-form-group">
                    <label className="sp-label" htmlFor="prog_slug">
                      Slug <span className="sp-req">*</span>
                      <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}> — this becomes the program key (lowercase, no spaces)</span>
                    </label>
                    <input id="prog_slug" className="sp-input" value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="e.g. streaming" maxLength={50} pattern="[a-z0-9_-]+" title="Lowercase letters, numbers, hyphens and underscores only" />
                  </div>
                )}
                <div className="sp-form-group">
                  <label className="sp-label" htmlFor="prog_name">Name <span className="sp-req">*</span></label>
                  <input id="prog_name" className="sp-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Streaming Beta" maxLength={100} />
                </div>
                <div className="sp-form-group">
                  <label className="sp-label" htmlFor="prog_desc">Description</label>
                  <textarea id="prog_desc" className="sp-textarea" rows={3} value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="What does this beta program test?" />
                </div>
                <div style={{ display: 'flex', gap: '0.5rem' }}>
                  <button type="submit" className="sp-btn sp-btn-primary" disabled={busy}>
                    <i className="fa-solid fa-floppy-disk" /> {editId ? 'Save Changes' : 'Save Program'}
                  </button>
                  {editId > 0 && <button type="button" className="sp-btn sp-btn-ghost" onClick={resetForm}>Cancel</button>}
                </div>
              </form>
            </div>
          </div>
          {requests.length > 0 && (
            <div className="sp-card sp-mt-3">
              <div className="sp-card-header">
                <i className="fa-solid fa-clock" /> Pending Requests
                <span className="sp-badge sp-badge-amber" style={{ marginLeft: '0.5rem' }}>{requests.length}</span>
              </div>
              <div className="sp-table-wrap">
                <table className="sp-table">
                  <thead>
                    <tr>
                      <th>Ticket</th>
                      <th>User</th>
                      <th>Program</th>
                      <th>Submitted</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {requests.map((req) => (
                      <tr key={req.ticket_number}>
                        <td><a href={'/tickets.php?id=' + encodeURIComponent(req.ticket_number)} style={{ fontFamily: 'monospace', whiteSpace: 'nowrap' }}>{req.ticket_number}</a></td>
                        <td>{req.display_name || req.username}</td>
                        <td><span className="sp-badge sp-badge-blue">{req.program_name}</span></td>
                        <td style={{ whiteSpace: 'nowrap' }}>{formatWhen(req.created_at)}</td>
                        <td>
                          <a href={'/tickets.php?id=' + encodeURIComponent(req.ticket_number)} className="sp-btn sp-btn-sm sp-btn-secondary">
                            <i className="fa-solid fa-eye" /> Review
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </>
  )
}
