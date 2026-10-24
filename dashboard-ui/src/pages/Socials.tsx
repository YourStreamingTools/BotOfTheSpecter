import { useEffect, useState } from 'react'
import { UrlReveal } from '../components/UrlReveal'
import { get } from '../bot'
import { Switch, ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/socials.php (Social Roller). Data and saves go through
// public/api/socials/socials.php (scripts/sync-live-api.sh). No timers.
const API = '/api/socials/socials.php'
type Row = { platform: string; handle: string; active: number }
type Page = { success: boolean; has_key: boolean; socials: Row[] }
const NAMES: Record<string, string> = { twitter: 'X / Twitter', youtube: 'YouTube', tiktok: 'TikTok', linkedin: 'LinkedIn', github: 'GitHub', kofi: 'Ko-fi' }
const name = (p: string) => NAMES[p] ?? p.charAt(0).toUpperCase() + p.slice(1)
const same = (a: Row[], b: Row[]) => a.every((r, i) => r.handle === b[i].handle && r.active === b[i].active)

export default function Socials() {
  const [page, setPage] = useState<Page | null>(null)
  const [rows, setRows] = useState<Row[]>([])
  const [err, setErr] = useState('')
  const [toast, setToast] = useState<Toast>(null)
  const [saving, setSaving] = useState(false)
  useEffect(() => { get<Page>(API).then((r) => { if (!r.success) throw new Error(); setPage(r); setRows(r.socials) }).catch(() => setErr('The Social Roller page could not load. Refresh to try again.')) }, [])
  if (err) return <div className="page cmds mp so"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!page) return <div className="page cmds mp so"><section className="card"><p className="muted">Loading</p></section></div>
  const saved = page.socials
  const set = (i: number, r: Partial<Row>) => setRows(rows.map((x, j) => (j === i ? { ...x, ...r } : x)))
  const tooLong = rows.some((r) => r.handle.trim().length > 255)
  const dirty = !same(rows, saved)
  const shown = saved.filter((r) => r.active && r.handle.trim()).length
  async function save() {
    setSaving(true)
    try {
      const body: Record<string, string> = { save_socials: '1' }
      rows.forEach((r) => { body[`${r.platform}_handle`] = r.handle.trim(); if (r.active && r.handle.trim()) body[`${r.platform}_active`] = '1' })
      const r = await send<{ success: boolean; error?: string }>(API, body)
      if (!r.success) throw new Error(r.error || 'Could not save your socials. Try again.')
      const next = rows.map((x) => ({ ...x, handle: x.handle.trim(), active: x.active && x.handle.trim() ? 1 : 0 }))
      setPage({ ...page!, socials: next }); setRows(next)
      setToast({ kind: 'ok', text: 'Socials saved. Refresh the browser source in OBS to see them.' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  return (
    <div className="page cmds mp so">
      <section className="card" aria-label="Social Roller">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-share-alt" /> Social Roller <span className={`pill ${shown ? 'pill-ok' : 'pill-off'}`}>{shown} shown</span></h3></div>
        <div className="mp-rows">
          <div className="mp-row"><div className="mp-row-l"><span className="mp-lbl">Overlay URL</span><span className="muted">Add as an OBS browser source. It rolls through your shown accounts, 7 seconds each. It contains your channel key, so keep it out of screen shares.</span></div>
            <div className="mp-row-r mp-urlbox"><UrlReveal mask="https://overlay.botofthespecter.com/social-roller.php?code=••••••••••••" disabled={!page.has_key} toast={setToast}
              fetchUrl={async () => { const r = await get<{ success: boolean; url?: string; error?: string }>(`${API}?beta=url`); if (!r.success || !r.url) throw new Error(r.error || 'Could not get the overlay URL.'); return r.url }} /></div></div>
        </div>
      </section>
      <section className="card mp-settings" aria-label="Social accounts">
        <div className="card-title-row"><h3 className="card-h"><i className="fas fa-users" /> Social accounts</h3></div>
        <p className="mp-lhelp muted">Accounts roll in this order. A blank handle removes the platform; switch one off to keep its handle but hide it.</p>
        <div className="mp-rows">
          {rows.map((r, i) => { const has = !!r.handle.trim(), long = r.handle.trim().length > 255, id = `so-${r.platform}`
            return <div key={r.platform} className="mp-row so-row"><div className="mp-row-l"><label htmlFor={id}>{name(r.platform)}</label><span id={`${id}-h`} className={long ? 'mp-bad' : 'muted'}>{long ? 'Use 255 characters or fewer.' : !has ? 'Not set up.' : r.active ? 'Shown on the overlay.' : 'Hidden from the overlay.'}</span></div>
              <div className="mp-row-r so-ctl"><input id={id} className={`input mp-lname so-in${long ? ' is-bad' : ''}`} maxLength={300} placeholder="@handle or username" aria-describedby={`${id}-h`} aria-invalid={long} value={r.handle} onChange={(e) => set(i, { handle: e.target.value, active: e.target.value.trim() ? (r.handle.trim() ? r.active : 1) : 0 })} />
                <Switch on={has && !!r.active} busy={!has} label={`Show ${name(r.platform)} on the overlay`} onChange={(v) => set(i, { active: v ? 1 : 0 })} /></div></div> })}
        </div>
        <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
          <span className={`muted small mp-save-msg${tooLong ? ' is-bad' : ''}`}>{tooLong ? <span className="mp-bad">Fix the highlighted field to save.</span> : 'The overlay shows changes after its browser source reloads.'}</span>
          {dirty && <span className="pill pill-warn">Unsaved changes</span>}
          <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => setRows(saved)}><i className="fas fa-rotate-left" /> Discard</button>
          <button type="button" className="btn" disabled={!dirty || saving || tooLong} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Save'}</button>
        </div>
      </section>
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
