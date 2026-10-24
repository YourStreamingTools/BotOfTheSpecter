import { useEffect, useMemo, useRef, useState } from 'react'
import Combobox from '../../components/Combobox'
import Modal from '../../components/Modal'
import { get } from '../../bot'
import { BUCKETS, Counter, PERM_OPTS, Switch, ToastBar, bucketShort, cleanName, permLabel, send, type Toast, VarsHelp } from './shared'

// Port of dashboard/custom_commands.php via public/api/commands/custom.php.
const API = '/api/commands/custom.php'
const MAX = 500
const PICK = /\(random\.(?:pick|piack)\)/i

type Cmd = { command: string; response: string; status: string; cooldown: number | string; cooldown_bucket: string | null; permission: string; aliases: string | null }
type Page = { success: boolean; username: string; builtin: string[]; now: string }
type Form = { name: string; response: string; cooldown: string; bucket: string; perm: string; aliases: string }
const EMPTY: Form = { name: '', response: '', cooldown: '15', bucket: 'default', perm: 'Everyone', aliases: '' }
const aliasList = (a: string | null) => (a ?? '').split(',').map((x) => x.trim()).filter(Boolean)

export default function CustomCommands() {
  const [cmds, setCmds] = useState<Cmd[] | null>(null)
  const [page, setPage] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [edit, setEdit] = useState<Cmd | null>(null)
  const [removing, setRemoving] = useState<string | null>(null)
  const [importing, setImporting] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)

  async function load() {
    try {
      const r = await get<{ success: boolean; commands: Cmd[] }>(`${API}?ajax_action=list`)
      if (!r.success) throw new Error()
      setCmds(r.commands.sort((a, b) => a.command.localeCompare(b.command))); setErr('')
    } catch { setErr('Could not load your custom commands. Try again in a moment.') }
  }
  useEffect(() => { load(); get<Page>(API).then(setPage).catch(() => {}) }, [])

  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return (cmds ?? []).filter((c) => !n || [c.command, c.response, c.aliases ?? '', permLabel(c.permission)].some((v) => v.toLowerCase().includes(n)))
  }, [cmds, q])

  async function toggle(c: Cmd, on: boolean) {
    setBusy(c.command)
    try {
      const r = await send(API, { command: c.command, status: on ? 'Enabled' : 'Disabled' }, true)
      if (r.success) setCmds((l) => l?.map((x) => (x.command === c.command ? { ...x, status: on ? 'Enabled' : 'Disabled' } : x)) ?? l)
      else setToast({ kind: 'err', text: r.message || 'No rows were updated.' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'Could not update the status.' }) }
    setBusy(null)
  }
  async function remove(name: string) {
    setRemoving(null)
    try {
      const r = await send(API, { remove_command: name })
      setToast({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? 'Command removed.' : 'Could not remove the command.') })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    load()
  }

  return (
    <div className="page cmds">
      <div className="section-head print-hide"><h2><i className="fas fa-code" /> Custom Commands</h2></div>
      <VarsHelp>
        <ol className="cmd-howto">
          <li>Skip the "!" when naming a command. It is added automatically.</li>
          <li>You can also add commands in chat with <code>!addcommand [command] [message]</code>, for example <code>!addcommand mycommand This is my custom command</code>.</li>
        </ol>
      </VarsHelp>
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card print-hide">
        <h3 className="card-h"><i className="fas fa-plus" /> Add a custom command</h3>
        <CmdForm builtin={page?.builtin ?? []} existing={cmds ?? []} submitLabel="Add command" onDone={(t) => { setToast(t); load() }} />
      </section>

      <section className="card cmd-lib">
        <div className="card-title-row print-hide">
          <h3 className="card-h"><i className="fas fa-terminal" /> Your commands {cmds && <span className="pill">{cmds.length}</span>}</h3>
          <div className="cmd-acts">
            <button type="button" className="btn btn-ghost" onClick={() => setImporting(true)}><i className="fas fa-file-import" /> Import</button>
            <a className="btn btn-ghost" href={`${API}?ajax_action=export`}><i className="fas fa-file-export" /> Export</a>
            <button type="button" className="btn btn-ghost" onClick={() => window.print()}><i className="fas fa-print" /> Print</button>
          </div>
        </div>
        {page && <p className="print-only">Custom commands for {page.username}, {page.now}</p>}
        {cmds && cmds.length > 0 && (
          <table className="print-only print-table">
            <thead><tr><th>Command</th><th>Aliases</th><th>Response</th><th>Permission</th><th>Cooldown</th><th>Status</th></tr></thead>
            <tbody>
              {cmds.map((c) => (
                <tr key={c.command}>
                  <td>!{c.command}</td>
                  <td>{aliasList(c.aliases).map((a) => '!' + a).join(', ')}</td>
                  <td>{c.response}</td>
                  <td>{permLabel(c.permission)}</td>
                  <td>{parseInt(String(c.cooldown), 10) || 0}s {bucketShort(c.cooldown_bucket ?? 'default')}</td>
                  <td>{c.status === 'Enabled' ? 'Enabled' : 'Disabled'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {cmds && cmds.length > 0 && <label className="media-search print-hide"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search commands" aria-label="Search commands" value={q} onChange={(e) => setQ(e.target.value)} /></label>}
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!cmds && !err && <div className="cmd-list">{[0, 1, 2].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {cmds && !cmds.length && <p className="muted media-empty">No custom commands yet. Add your first one above.</p>}
        {cmds && cmds.length > 0 && !shown.length && <p className="muted media-empty">No commands match.</p>}
        {shown.length > 0 && (
          <ul className="cmd-list">
            {shown.map((c) => {
              const on = c.status === 'Enabled'
              const al = aliasList(c.aliases)
              return (
                <li key={c.command} className={`cmd-row${on ? '' : ' is-off'}`}>
                  <div className="cmd-main">
                    <div className="cmd-name"><code>!{c.command}</code>{al.length > 0 && <span className="muted small"><i className="fas fa-tags" /> {al.map((a) => '!' + a).join(', ')}</span>}</div>
                    <p className="cmd-resp">{c.response}</p>
                    <div className="cmd-meta">
                      <span className="pill">{permLabel(c.permission)}</span>
                      <span className="pill"><i className="fas fa-clock" /> {parseInt(String(c.cooldown), 10) || 0}s {bucketShort(c.cooldown_bucket ?? 'default')}</span>
                      <span className={`pill ${on ? 'pill-ok' : 'pill-off'}`}>{on ? 'Enabled' : 'Disabled'}</span>
                    </div>
                  </div>
                  <div className="cmd-side print-hide">
                    <Switch on={on} busy={busy === c.command} label={`!${c.command} ${on ? 'enabled' : 'disabled'}`} onChange={(v) => toggle(c, v)} />
                    <button type="button" className="icon-btn" aria-label={`Edit !${c.command}`} title="Edit" onClick={() => setEdit(c)}><i className="fas fa-pencil" /></button>
                    <button type="button" className="icon-btn icon-err" aria-label={`Remove !${c.command}`} title="Remove" onClick={() => setRemoving(c.command)}><i className="fas fa-trash" /></button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {edit && (
        <Modal title={`Edit !${edit.command}`} icon="fas fa-pencil" onClose={() => setEdit(null)} wide>
          <CmdForm edit={edit} builtin={page?.builtin ?? []} existing={cmds ?? []} submitLabel="Update command" onDone={(t) => { setToast(t); if (t?.kind === 'ok') setEdit(null); load() }} />
        </Modal>
      )}
      {removing && (
        <Modal title="Remove command?" icon="fas fa-trash" onClose={() => setRemoving(null)}>
          <p>This will permanently remove <b>!{removing}</b>.</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" data-autofocus onClick={() => setRemoving(null)}>Cancel</button><button type="button" className="btn btn-danger" onClick={() => remove(removing)}><i className="fas fa-trash" /> Yes, remove it</button></div>
        </Modal>
      )}
      {importing && <ImportModal onClose={() => setImporting(false)} onDone={load} />}
    </div>
  )
}

function CmdForm({ edit, builtin, existing, submitLabel, onDone }: { edit?: Cmd; builtin: string[]; existing: Cmd[]; submitLabel: string; onDone: (t: Toast) => void }) {
  const [f, setF] = useState<Form>(edit ? { name: edit.command, response: edit.response, cooldown: String(edit.cooldown ?? 15), bucket: edit.cooldown_bucket === 'mods' ? 'mod' : edit.cooldown_bucket || 'default', perm: permLabel(edit.permission), aliases: edit.aliases ?? '' } : EMPTY)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState('')
  const [many, setMany] = useState<{ name: string; auto: boolean } | null>(null)
  const [ask, setAsk] = useState(false)
  const asked = useRef('')
  const set = (k: keyof Form) => (v: string) => setF((x) => ({ ...x, [k]: v }))
  const name = cleanName(f.name)
  const clash = name && builtin.includes(name) ? 'That name matches a built-in command.' : name && name !== edit?.command && existing.some((c) => c.command === name) ? `!${name} already exists.` : ''
  const hasPick = PICK.test(f.response)

  function blurResponse() {
    if (!hasPick || !name) return
    const sig = name + '|' + f.response
    if (asked.current === sig) return
    asked.current = sig
    setAsk(true)
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (clash || !name || !f.response.trim() || f.response.length > MAX) return
    setSaving(true); setMsg('')
    const body: Record<string, string> = edit
      ? { command_to_edit: edit.command, new_command_name: name, command_response: f.response, cooldown_response: f.cooldown, cooldown_bucket_response: f.bucket, permission_response: f.perm, aliases: f.aliases }
      : { command: name, response: f.response, cooldown: f.cooldown, cooldown_bucket: f.bucket, permission: f.perm, aliases: f.aliases }
    try {
      const r = await send(API, body)
      if (r.success && !edit) setF(EMPTY)
      if (!r.success) setMsg(r.message || 'That did not save.')
      onDone({ kind: r.success ? 'ok' : 'err', text: r.message || (r.success ? 'Saved.' : 'That did not save.') })
    } catch (e2) { setMsg((e2 as Error).message || 'That did not save.') }
    setSaving(false)
  }
  const id = edit ? 'e' : 'a'
  return (
    <form className="cmd-form" onSubmit={submit}>
      <div className="fld">
        <label htmlFor={`${id}-name`}>{edit ? 'Command name' : 'Command'}</label>
        <div className="bang"><span aria-hidden="true">!</span><input id={`${id}-name`} className="input" required placeholder="e.g. hello" value={f.name} onChange={(e) => set('name')(e.target.value)} autoComplete="off" /></div>
        {clash ? <small className="counter over">{clash}</small> : f.name && name !== f.name ? <small className="muted small">Saved as !{name}</small> : null}
      </div>
      <div className="fld fld-wide">
        <label htmlFor={`${id}-resp`}>Response</label>
        <textarea id={`${id}-resp`} className="input" required rows={2} placeholder="Bot reply when the command is used" value={f.response} onChange={(e) => set('response')(e.target.value)} onBlur={blurResponse} />
        <div className="fld-foot"><Counter n={f.response.length} max={MAX} />{hasPick && <button type="button" className="btn btn-quiet sm" disabled={!name} onClick={() => setMany({ name, auto: false })}><i className="fas fa-list" /> Manage options</button>}</div>
      </div>
      <div className="fld"><label htmlFor={`${id}-cd`}>Cooldown (seconds)</label><input id={`${id}-cd`} className="input" type="number" min={1} max={86400} required value={f.cooldown} onChange={(e) => set('cooldown')(e.target.value)} /></div>
      <div className="fld"><label>Cooldown bucket</label><Combobox label="Cooldown bucket" searchable={false} options={BUCKETS} value={f.bucket} onChange={set('bucket')} /></div>
      <div className="fld"><label>Permission level</label><Combobox label="Permission level" searchable={false} options={PERM_OPTS} value={f.perm} onChange={set('perm')} /></div>
      <div className="fld"><label htmlFor={`${id}-al`}>Aliases</label><input id={`${id}-al`} className="input" placeholder="e.g. book, bk" value={f.aliases} onChange={(e) => set('aliases')(e.target.value)} autoComplete="off" /></div>
      <p className="muted small fld-wide">Default applies the cooldown to everyone, User tracks it per chatter, and Mod only cools down moderators. Aliases are comma-separated alternate names that share this command's cooldown.</p>
      {msg && <p className="counter over fld-wide" role="status">{msg}</p>}
      <div className="modal-acts fld-wide"><button className="btn" disabled={saving || !!clash || f.response.length > MAX}><i className={edit ? 'fas fa-floppy-disk' : 'fas fa-plus'} /> {saving ? 'Saving' : submitLabel}</button></div>
      {ask && (
        <Modal title="Use many options?" icon="fas fa-list" onClose={() => setAsk(false)}>
          <p>You used <code>(random.pick)</code>. Do you want to manage a long option list here instead of writing it inline?</p>
          <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={() => setAsk(false)}>No, keep inline syntax</button><button type="button" className="btn" data-autofocus onClick={() => { setAsk(false); setMany({ name, auto: true }) }}>Yes, use many options</button></div>
        </Modal>
      )}
      {many && <ManyOptions command={many.name} auto={many.auto} response={f.response} onClose={() => setMany(null)} onSaved={(enabled) => { if (enabled) set('response')(f.response.replace(/\(random\.(?:pick|piack)\.[^)]*\)/gi, '(random.pick)').replace(/\(random\.piack\)/gi, '(random.pick)')) }} />}
    </form>
  )
}

function ManyOptions({ command, auto, response, onClose, onSaved }: { command: string; auto: boolean; response: string; onClose: () => void; onSaved: (enabled: boolean) => void }) {
  const [state, setState] = useState<{ enabled: boolean; text: string } | null>(null)
  const [msg, setMsg] = useState('')
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    const inline = (response.match(/\(random\.(?:pick|piack)\.([^)]+)\)/i)?.[1] ?? '').split('.').map((s) => s.trim()).filter(Boolean)
    send<{ success: boolean; many_options_enabled: boolean; options: string[]; message?: string }>(API, { action: 'get_random_pick_options', command_name: command })
      .then((r) => {
        if (!r.success) { setMsg(r.message || 'Unable to load options.'); return }
        const opts = r.options.length ? r.options : inline
        setState({ enabled: auto || r.many_options_enabled || inline.length > 0, text: opts.join('\n') })
      })
      .catch(() => setMsg('Unable to load options. Please try again.'))
  }, [command, response, auto])
  async function save() {
    if (!state) return
    setSaving(true); setMsg('')
    const options = state.text.split(/\r?\n/).map((s) => s.trim()).filter(Boolean)
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'save_random_pick_options', command_name: command, many_options_enabled: state.enabled ? '1' : '0', options: JSON.stringify(options) })
      if (!r.success) { setMsg(r.message || 'Unable to save options.'); setSaving(false); return }
      onSaved(state.enabled); onClose()
    } catch { setMsg('Unable to save options. Please try again.'); setSaving(false) }
  }
  const count = state ? state.text.split(/\r?\n/).filter((s) => s.trim()).length : 0
  return (
    <Modal title={`Options for !${command}`} icon="fas fa-list" onClose={onClose} wide>
      {!state && !msg && <span className="skel skel-line" />}
      {state && (
        <>
          <label className="media-check-all"><input type="checkbox" checked={state.enabled} onChange={(e) => setState({ ...state, enabled: e.target.checked })} /> Use these options for <code>(random.pick)</code></label>
          <label className="fld-label" htmlFor="many-list">Options, one per line</label>
          <textarea id="many-list" className="input" rows={10} placeholder={'Item 1\nItem 2\nItem 3'} value={state.text} onChange={(e) => setState({ ...state, text: e.target.value })} />
          <p className="muted small">{count} {count === 1 ? 'option' : 'options'}. No limit on the count, and empty lines are ignored.</p>
        </>
      )}
      {msg && <p className="counter over" role="status">{msg}</p>}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="button" className="btn" disabled={!state || saving} onClick={save}>{saving ? 'Saving' : 'Save options'}</button></div>
    </Modal>
  )
}

type ImportResult = { success: boolean; message?: string; imported?: number; skipped_existing?: string[]; skipped_builtin?: string[]; skipped_invalid?: string[]; alias_warnings?: string[]; truncated?: boolean; max_rows?: number }
const COLS: [string, string, string][] = [
  ['command', 'Yes', 'Name without the !, letters and numbers only.'], ['response', 'Yes', 'Bot reply, up to 500 characters.'],
  ['cooldown', 'No', 'Seconds. Defaults to 15.'], ['cooldown_bucket', 'No', 'default, user or mod. Defaults to default.'],
  ['permission', 'No', 'everyone, vip, all-subs, t1-sub, t2-sub, t3-sub, mod or broadcaster. Defaults to everyone.'],
  ['aliases', 'No', 'Comma-separated alternate names.'], ['status', 'No', 'Enabled or Disabled. Defaults to Enabled.'],
]
function ImportModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [drag, setDrag] = useState(false)
  const [busy, setBusy] = useState(false)
  const [res, setRes] = useState<ImportResult | null>(null)
  const input = useRef<HTMLInputElement>(null)
  async function go() {
    if (!file) return
    setBusy(true); setRes(null)
    const fd = new FormData(); fd.append('action', 'import_commands'); fd.append('import_file', file, file.name)
    try {
      const r = await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })
      setRes(r.status === 401 ? { success: false, message: 'Your session expired. Sign in again.' } : await r.json() as ImportResult)
      onDone()
    } catch { setRes({ success: false, message: 'Import failed. Please check the file and try again.' }) }
    setBusy(false)
  }
  const issues: [string, string[]][] = res ? [['Skipped because they already exist', (res.skipped_existing ?? []).map((c) => '!' + c)], ['Skipped because they match a built-in command', (res.skipped_builtin ?? []).map((c) => '!' + c)], ['Skipped invalid rows', res.skipped_invalid ?? []], ['Alias warnings', res.alias_warnings ?? []]] : []
  return (
    <Modal title="Import custom commands" icon="fas fa-file-import" onClose={onClose} wide>
      <p>Upload a CSV file to add many Twitch chat custom commands at once. Existing names and built-in commands are skipped, so nothing is overwritten.</p>
      <ol className="cmd-howto"><li>Download the template.</li><li>Add one command per row in Excel, Google Sheets or a text editor. Wrap a response in double quotes if it contains a comma.</li><li>Save as CSV (UTF-8). Semicolon-separated files from Excel also work.</li><li>Choose the file and import.</li></ol>
      <div className="imp-cols">{COLS.map(([c, req, note]) => <div key={c} className="imp-col"><code>{c}</code><span className={`pill ${req === 'Yes' ? 'pill-info' : 'pill-off'}`}>{req === 'Yes' ? 'Required' : 'Optional'}</span><small className="muted">{note}</small></div>)}</div>
      <a className="btn btn-ghost imp-tpl" href={`${API}?ajax_action=import_template`}><i className="fas fa-download" /> Download template</a>
      <div className={`drop${drag ? ' is-drag' : ''}`} onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={() => setDrag(false)} onDrop={(e) => { e.preventDefault(); setDrag(false); const f = e.dataTransfer.files[0]; if (f) setFile(f) }}>
        <i className="fa-solid fa-file-csv" aria-hidden="true" />
        <p>{file ? <b>{file.name}</b> : <><b>Drag a CSV here</b> or</>}</p>
        <button type="button" className="btn btn-ghost" onClick={() => input.current?.click()}>{file ? 'Choose another' : 'Choose file'}</button>
        <input ref={input} type="file" accept=".csv,text/csv" hidden onChange={(e) => { setFile(e.target.files?.[0] ?? null); e.target.value = '' }} />
        <small className="muted">CSV only, up to 1 MB (1000 commands).</small>
      </div>
      {res && (
        <div className={`notice notice-${res.success && (res.imported ?? 0) > 0 && issues.every(([, l]) => !l.length) ? 'ok' : res.success ? 'warn' : 'err'} imp-res`} role="status">
          <div>
            <b>{!res.success ? res.message || 'Import failed.' : res.imported ? `${res.imported} ${res.imported === 1 ? 'command' : 'commands'} imported.` : 'No new commands were imported.'}</b>
            {res.truncated && <p>Only the first {res.max_rows} rows were processed. Split larger files and upload again.</p>}
            {issues.filter(([, l]) => l.length).map(([h, l]) => <div key={h}><p>{h}:</p><ul>{l.map((x) => <li key={x}>{x}</li>)}</ul></div>)}
          </div>
        </div>
      )}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Close</button><button type="button" className="btn" disabled={!file || busy} onClick={go}><i className="fas fa-file-import" /> {busy ? 'Importing' : 'Import'}</button></div>
    </Modal>
  )
}
