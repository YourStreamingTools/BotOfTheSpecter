import { useEffect, useMemo, useState } from 'react'
import Modal from '../../components/Modal'
import { get } from '../../bot'
import { Counter, ToastBar, cleanName, send, type Toast } from './shared'
import TwitchUserPicker, { cleanLogin } from '../../components/TwitchUserPicker'

// Port of dashboard/manage_custom_user_commands.php via public/api/commands/user.php.
const API = '/api/commands/user.php'
const MAX = 255
type Cmd = { command: string; response: string; status: string; cooldown: number | string; user_id: string }

function readSearch() { try { return localStorage.getItem('searchTerm') ?? '' } catch { return '' } }

export default function UserCommands() {
  const [cmds, setCmds] = useState<Cmd[] | null>(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState(readSearch)
  const [toast, setToast] = useState<Toast>(null)
  const [edit, setEdit] = useState<Cmd | null>(null)
  const [del, setDel] = useState<string | null>(null)

  async function load() {
    try {
      const r = await get<{ success: boolean; commands: Cmd[] }>(`${API}?ajax_action=list`)
      if (!r.success) throw new Error()
      setCmds(r.commands); setErr('')
    } catch { setErr('Could not load user commands. Try again in a moment.') }
  }
  useEffect(() => { load() }, [])
  // Same search memory as the live page (localStorage key "searchTerm").
  function search(v: string) { setQ(v); try { localStorage.setItem('searchTerm', v) } catch { /* ignore */ } }
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return (cmds ?? []).filter((c) => !n || [c.command, c.response, c.user_id, String(c.cooldown), c.status].some((v) => String(v).toLowerCase().includes(n)))
  }, [cmds, q])
  const pending = (cmds ?? []).filter((c) => c.status !== 'Enabled').length

  async function act(body: Record<string, string>) {
    try {
      const r = await send(API, body)
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || 'Done.' })
      return r.success
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'That did not save.' }); return false } finally { load() }
  }

  return (
    <div className="page cmds">
      <div className="section-head"><h2><i className="fas fa-user-cog" /> User Commands</h2>{pending > 0 && <span className="pill pill-warn">{pending} waiting for approval</span>}</div>
      <p className="muted">Commands that belong to one viewer. Only the named user can run their command. Review, approve, edit and delete them here.</p>
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card">
        <h3 className="card-h"><i className="fas fa-plus" /> Add a user command</h3>
        <UserForm onSave={(b) => act(b)} existing={cmds ?? []} />
      </section>
      <section className="card cmd-lib">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-list" /> All user commands {cmds && <span className="pill">{cmds.length}</span>}</h3></div>
        {cmds && cmds.length > 0 && <label className="media-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search commands or users" aria-label="Search user commands" value={q} onChange={(e) => search(e.target.value)} /></label>}
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!cmds && !err && <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {cmds && !cmds.length && <p className="muted media-empty">No user commands yet.</p>}
        {cmds && cmds.length > 0 && !shown.length && <p className="muted media-empty">No commands match.</p>}
        {shown.length > 0 && (
          <ul className="cmd-list">
            {shown.map((c) => {
              const on = c.status === 'Enabled'
              return (
                <li key={c.command} className={`cmd-row${on ? '' : ' is-off'}`}>
                  <div className="cmd-main">
                    <div className="cmd-name"><code>!{c.command}</code><span className="muted small"><i className="fas fa-user" /> {c.user_id}</span></div>
                    <p className="cmd-resp">{c.response}</p>
                    <div className="cmd-meta"><span className="pill"><i className="fas fa-clock" /> {c.cooldown}s</span><span className={`pill ${on ? 'pill-ok' : 'pill-warn'}`}>{on ? 'Enabled' : 'Disabled'}</span></div>
                  </div>
                  <div className="cmd-side">
                    {!on && <button type="button" className="icon-btn icon-ok" aria-label={`Approve !${c.command}`} title="Enable this command" onClick={() => act({ approve_command: c.command })}><i className="fas fa-check" /></button>}
                    <button type="button" className="icon-btn" aria-label={`Edit !${c.command}`} title="Edit" onClick={() => setEdit(c)}><i className="fas fa-pencil" /></button>
                    <button type="button" className="icon-btn icon-err" aria-label={`Delete !${c.command}`} title="Delete" onClick={() => setDel(c.command)}><i className="fas fa-trash" /></button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>
      {edit && <Modal title={`Edit !${edit.command}`} icon="fas fa-pencil" onClose={() => setEdit(null)} wide><UserForm edit={edit} existing={cmds ?? []} onSave={async (b) => { const ok = await act(b); if (ok) setEdit(null); return ok }} /></Modal>}
      {del && (
        <Modal title="Delete command?" icon="fas fa-trash" onClose={() => setDel(null)}>
          <p>Are you sure you want to delete <b>!{del}</b>?</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setDel(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => { act({ delete_command: del }); setDel(null) }}><i className="fas fa-trash" /> Delete command</button></div>
        </Modal>
      )}
    </div>
  )
}

function UserForm({ edit, existing, onSave }: { edit?: Cmd; existing: Cmd[]; onSave: (b: Record<string, string>) => Promise<boolean> }) {
  const [name, setName] = useState(edit?.command ?? '')
  const [resp, setResp] = useState(edit?.response ?? '')
  const [user, setUser] = useState(edit?.user_id ?? '')
  const [cd, setCd] = useState(String(edit?.cooldown ?? 15))
  const [busy, setBusy] = useState(false)
  const clean = cleanName(name)
  const clash = clean && clean !== edit?.command && existing.some((c) => c.command === clean) ? `!${clean} already exists. Choose a different name.` : ''
  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!clean || clash || !resp.trim() || resp.length > MAX || !cleanLogin(user)) return
    setBusy(true)
    const ok = await onSave(edit ? { command_to_edit: edit.command, new_command_name: clean, command_response: resp, cooldown_response: cd, user_id_response: cleanLogin(user) } : { command: clean, response: resp, cooldown: cd, user_id: cleanLogin(user) })
    if (ok && !edit) { setName(''); setResp(''); setUser(''); setCd('15') }
    setBusy(false)
  }
  const id = edit ? 'ue' : 'ua'
  return (
    <form className="cmd-form" onSubmit={submit}>
      <div className="fld"><label htmlFor={`${id}-n`}>Command</label><div className="bang"><span aria-hidden="true">!</span><input id={`${id}-n`} className="input" required maxLength={MAX} placeholder="e.g. hello" value={name} onChange={(e) => setName(e.target.value)} autoComplete="off" /></div>{clash && <small className="counter over">{clash}</small>}</div>
      <div className="fld"><label htmlFor={`${id}-u`}>Twitch user</label><TwitchUserPicker id={`${id}-u`} label="Twitch user" required value={user} onChange={setUser} placeholder="Search Twitch users" /><small className="muted small">Only this user can run the command.</small></div>
      <div className="fld fld-wide"><label htmlFor={`${id}-r`}>Response</label><textarea id={`${id}-r`} className="input" rows={2} required placeholder="Bot reply when the command is used" value={resp} onChange={(e) => setResp(e.target.value)} /><Counter n={resp.length} max={MAX} /></div>
      <div className="fld"><label htmlFor={`${id}-c`}>Cooldown (seconds)</label><input id={`${id}-c`} className="input" type="number" min={1} max={86400} required value={cd} onChange={(e) => setCd(e.target.value)} /></div>
      <div className="modal-acts fld-wide"><button className="btn" disabled={busy || !!clash || resp.length > MAX}><i className={edit ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {busy ? 'Saving' : edit ? 'Update command' : 'Add command'}</button></div>
    </form>
  )
}
