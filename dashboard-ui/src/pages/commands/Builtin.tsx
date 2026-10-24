import { useEffect, useMemo, useState } from 'react'
import Combobox from '../../components/Combobox'
import Modal from '../../components/Modal'
import { get } from '../../bot'
import { hasConsent } from '../../consent'
import { BUCKETS, PERM_OPTS, Switch, ToastBar, permLabel, send, type Toast, VarChips } from './shared'

// Port of dashboard/builtin.php via public/api/commands/builtin.php.
const API = '/api/commands/builtin.php'
type Chat = Record<string, { label?: string; vars?: string[]; default?: string }>
type Cmd = { command: string; permission: string; status: string; description: string; force_level: string | null; aliases: string[]; chat_messages: Chat | null }
type Page = { success: boolean; commands: Cmd[]; show_enabled: boolean; show_disabled: boolean; message?: string }

// Same cookies as the live page, written only after cookie consent.
function setPref(name: string, v: boolean) {
  if (!hasConsent()) return
  document.cookie = `${name}=${v ? 'true' : 'false'};expires=${new Date(Date.now() + 30 * 864e5).toUTCString()};path=/`
}
function readSearch() { try { return localStorage.getItem('searchQuery') ?? '' } catch { return '' } }

export default function BuiltinCommands() {
  const [data, setData] = useState<Page | null>(null)
  const [err, setErr] = useState('')
  const [q, setQ] = useState(readSearch)
  const [showOn, setShowOn] = useState(true)
  const [showOff, setShowOff] = useState(true)
  const [busy, setBusy] = useState<string | null>(null)
  const [open, setOpen] = useState<Cmd | null>(null)
  const [toast, setToast] = useState<Toast>(null)

  async function load(first = false) {
    try {
      const r = await get<Page>(API, 20000)
      if (!r.success) throw new Error(r.message)
      setData(r); setErr('')
      if (first) { setShowOn(r.show_enabled); setShowOff(r.show_disabled) }
    } catch (e) { setErr((e as Error).message || 'Could not load built-in commands.') }
  }
  useEffect(() => { load(true) }, [])
  function search(v: string) { setQ(v); try { localStorage.setItem('searchQuery', v) } catch { /* ignore */ } }

  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return (data?.commands ?? []).filter((c) => ((showOn && c.status === 'Enabled') || (showOff && c.status === 'Disabled')) &&
      (!n || [c.command, c.description, ...c.aliases, permLabel(c.permission)].some((v) => v.toLowerCase().includes(n))))
  }, [data, q, showOn, showOff])

  async function update(c: Cmd, body: Record<string, string>, patch: Partial<Cmd>) {
    setBusy(c.command)
    try {
      const r = await send(API, { command_name: c.command, ...body })
      if (r.success) setData((d) => d && { ...d, commands: d.commands.map((x) => (x.command === c.command ? { ...x, ...patch } : x)) })
      else setToast({ kind: 'err', text: r.message || 'That did not save.' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message || 'That did not save.' }) }
    setBusy(null)
  }

  return (
    <div className="page cmds">
      <div className="section-head"><h2><i className="fas fa-cubes" /> Built-in Commands</h2>{data && <span className="pill">{data.commands.length}</span>}</div>
      <ToastBar t={toast} onClose={() => setToast(null)} />
      <section className="card cmd-lib">
        <div className="cmd-tools">
          <label className="media-search"><i className="fa-solid fa-magnifying-glass" aria-hidden="true" /><input className="input" type="search" placeholder="Search commands" aria-label="Search built-in commands" value={q} onChange={(e) => search(e.target.value)} /></label>
          <label className="media-check-all"><input type="checkbox" checked={showOn} onChange={(e) => { setShowOn(e.target.checked); setPref('show_enabled_commands', e.target.checked) }} /> Show enabled</label>
          <label className="media-check-all"><input type="checkbox" checked={showOff} onChange={(e) => { setShowOff(e.target.checked); setPref('show_disabled_commands', e.target.checked) }} /> Show disabled</label>
        </div>
        {err && <div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div>}
        {!data && !err && <div className="cmd-list">{[0, 1, 2, 3].map((i) => <div key={i} className="cmd-row"><span className="skel skel-line" /></div>)}</div>}
        {data && !shown.length && <p className="muted media-empty">No commands found.</p>}
        {shown.length > 0 && (
          <ul className="cmd-list">
            {shown.map((c) => {
              const on = c.status === 'Enabled'
              return (
                <li key={c.command} className={`cmd-row bi-row${on ? '' : ' is-off'}`}>
                  <div className="cmd-main">
                    <div className="cmd-name"><code>!{c.command}</code>{c.aliases.length > 0 && <span className="muted small">Aliases: {c.aliases.map((a) => '!' + a).join(', ')}</span>}</div>
                    <p className="cmd-resp">{c.description}</p>
                  </div>
                  <div className="bi-perm">
                    {c.force_level ? <span className="pill pill-warn" title="Permission level is locked for this command"><i className="fas fa-lock" /> {permLabel(c.force_level)}</span>
                      : <Combobox label={`Usage level for !${c.command}`} searchable={false} options={PERM_OPTS} value={permLabel(c.permission)} onChange={(v) => update(c, { usage_level: v }, { permission: permToDb(v) })} />}
                  </div>
                  <div className="cmd-side">
                    <Switch on={on} busy={busy === c.command} label={`!${c.command} ${on ? 'enabled' : 'disabled'}`} onChange={(v) => update(c, { status: v ? 'Enabled' : 'Disabled' }, { status: v ? 'Enabled' : 'Disabled' })} />
                    <button type="button" className="icon-btn" aria-label={`Options for !${c.command}`} title="Options" onClick={() => setOpen(c)}><i className="fas fa-sliders" /></button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>
      {open && <OptionsModal cmd={open} onClose={() => setOpen(null)} onSaved={() => { setOpen(null); setToast({ kind: 'ok', text: `Options for !${open.command} saved.` }) }} />}
    </div>
  )
}

function permToDb(display: string) {
  return ({ Everyone: 'everyone', VIPs: 'vip', 'All Subscribers': 'all-subs', 'Tier 1 Subscriber': 't1-sub', 'Tier 2 Subscriber': 't2-sub', 'Tier 3 Subscriber': 't3-sub', Mods: 'mod', Broadcaster: 'broadcaster' } as Record<string, string>)[display] ?? 'everyone'
}

type Opts = Record<string, unknown>
function OptionsModal({ cmd, onClose, onSaved }: { cmd: Cmd; onClose: () => void; onSaved: () => void }) {
  const [o, setO] = useState<Opts | null>(null)
  const [msg, setMsg] = useState('')
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    send<{ success: boolean; options: Opts; message?: string }>(API, { action: 'get_command_options', command_name: cmd.command })
      .then((r) => {
        if (!r.success) { setMsg(r.message || 'Error loading command options.'); return }
        const x: Opts = { cooldown_rate: 1, cooldown_time: 15, cooldown_bucket: 'default', ...(Array.isArray(r.options) ? {} : r.options) }
        for (const [k, spec] of Object.entries(cmd.chat_messages ?? {})) { const v = x[k]; x[k] = typeof v === 'string' && v.trim() ? v : spec.default ?? '' }
        setO(x)
      })
      .catch(() => setMsg('Error loading command options.'))
  }, [cmd])
  const set = (k: string, v: unknown) => setO((x) => x && { ...x, [k]: v })
  async function save() {
    if (!o) return
    setSaving(true); setMsg('')
    const out: Opts = { cooldown_rate: parseInt(String(o.cooldown_rate), 10) || 1, cooldown_time: parseInt(String(o.cooldown_time), 10) || 15, cooldown_bucket: o.cooldown_bucket || 'default' }
    if (cmd.command === 'lurk' || cmd.command === 'unlurk') out.timer = !!o.timer
    for (const k of Object.keys(cmd.chat_messages ?? {})) out[k] = String(o[k] ?? '')
    try {
      const r = await send<{ success: boolean; message?: string }>(API, { action: 'save_command_options', command_name: cmd.command, options: JSON.stringify(out) })
      if (r.success) onSaved(); else setMsg('Error saving options: ' + (r.message ?? ''))
    } catch { setMsg('Error saving command options.') }
    setSaving(false)
  }
  return (
    <Modal title={`Options for !${cmd.command}`} icon="fas fa-sliders" onClose={onClose} wide>
      <p className="muted small">Cooldown options need bot version 5.5 or later.</p>
      {!o && !msg && <><span className="skel skel-line" /><span className="skel skel-line" /></>}
      {o && (
        <div className="cmd-form">
          <div className="fld"><label htmlFor="bo-rate">Cooldown rate</label><input id="bo-rate" className="input" type="number" min={1} value={String(o.cooldown_rate)} onChange={(e) => set('cooldown_rate', e.target.value)} /><small className="muted small">Uses allowed before the cooldown starts. 1 means wait after every use.</small></div>
          <div className="fld"><label htmlFor="bo-time">Cooldown time (seconds)</label><input id="bo-time" className="input" type="number" min={0} value={String(o.cooldown_time)} onChange={(e) => set('cooldown_time', e.target.value)} /><small className="muted small">How long until the command can be used again after the rate is reached.</small></div>
          <div className="fld fld-wide"><label>Cooldown bucket</label><Combobox label="Cooldown bucket" searchable={false} options={BUCKETS} value={String(o.cooldown_bucket)} onChange={(v) => set('cooldown_bucket', v)} /></div>
          {cmd.command === 'lurk' && <label className="media-check-all fld-wide"><input type="checkbox" checked={!!o.timer} onChange={(e) => set('timer', e.target.checked)} /> Lurk timer: show how long a viewer has been lurking when they use !lurk again</label>}
          {cmd.command === 'unlurk' && <label className="media-check-all fld-wide"><input type="checkbox" checked={!!o.timer} onChange={(e) => set('timer', e.target.checked)} /> Unlurk timer: reset the lurk timer instead of ending lurk tracking on !unlurk</label>}
          {Object.entries(cmd.chat_messages ?? {}).map(([k, spec]) => (
            <div key={k} className="fld fld-wide">
              <label htmlFor={`bo-${k}`}>{spec.label || 'Chat message'}</label>
              <textarea id={`bo-${k}`} className="input" rows={3} value={String(o[k] ?? '')} onChange={(e) => set(k, e.target.value)} />
              <small className="muted small">Sent to chat when this command succeeds. Uses the same variables as custom commands{spec.vars?.length ? ', including:' : '.'}</small>
              {spec.vars?.length ? <VarChips label={`Variables for ${spec.label || 'this message'}`} vars={spec.vars.map((v) => ({ v }))} /> : null}
            </div>
          ))}
        </div>
      )}
      {msg && <p className="counter over" role="status">{msg}</p>}
      <div className="modal-acts"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button type="button" className="btn" disabled={!o || saving} onClick={save}>{saving ? 'Saving' : 'Save changes'}</button></div>
    </Modal>
  )
}
