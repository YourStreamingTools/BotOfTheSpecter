import { useEffect, useState } from 'react'
import { get } from '../bot'
import { ToastBar, send, type Toast } from './commands/shared'

// Port of dashboard/subathon.php: the work-in-progress notice with the chat command,
// then one card with Starting minutes and the four time-per-event fields.
// Data and saves go through public/api/subathon/subathon.php. No timers.
const API = '/api/subathon/subathon.php'
type Set = { starting_minutes: number; cheer_add: number; sub_add_1: number; sub_add_2: number; sub_add_3: number }
type K = keyof Set
const ADDS: [K, string, number, string][] = [
  ['cheer_add', 'Cheers (Bits)', 5, 'Minutes per 100 bits cheered.'],
  ['sub_add_1', 'Tier 1 Subscription', 10, 'Minutes per Tier 1 sub.'],
  ['sub_add_2', 'Tier 2 Subscription', 20, 'Minutes per Tier 2 sub.'],
  ['sub_add_3', 'Tier 3 Subscription', 30, 'Minutes per Tier 3 sub.'],
]
const LIM: Record<K, [number, number]> = { starting_minutes: [1, 10080], cheer_add: [0, 1440], sub_add_1: [0, 1440], sub_add_2: [0, 1440], sub_add_3: [0, 1440] }

export default function Subathon() {
  const [saved, setSaved] = useState<Set | null>(null), [f, setF] = useState<Record<K, string> | null>(null)
  const [err, setErr] = useState(''), [toast, setToast] = useState<Toast>(null), [saving, setSaving] = useState(false)
  const strs = (s: Set) => Object.fromEntries(Object.entries(s).map(([k, v]) => [k, String(v)])) as Record<K, string>
  useEffect(() => { get<{ success: boolean; settings: Set }>(API).then((r) => { if (!r.success) throw new Error(); setSaved(r.settings); setF(strs(r.settings)) }).catch(() => setErr('The Subathon page could not load. Refresh to try again.')) }, [])
  if (err) return <div className="page cmds mp sa"><div className="notice notice-err"><i className="fas fa-triangle-exclamation" /> <span>{err}</span></div></div>
  if (!saved || !f) return <div className="page cmds mp sa"><section className="card"><p className="muted"><i className="fas fa-spinner fa-spin" /> Loading</p></section></div>
  const bad = (k: K) => { const v = f[k], [lo, hi] = LIM[k]; return !/^\d+$/.test(v) || +v < lo || +v > hi }
  const anyBad = (Object.keys(LIM) as K[]).some(bad)
  const dirty = (Object.keys(LIM) as K[]).some((k) => f[k] !== String(saved[k]))
  async function save() {
    setSaving(true)
    try {
      const r = await send<{ success: boolean; error?: string; settings?: Set }>(API, { update_settings: '1', ...f! })
      if (!r.success || !r.settings) throw new Error(r.error || 'Could not save the settings. Try again.')
      setSaved(r.settings); setF(strs(r.settings)); setToast({ kind: 'ok', text: 'Settings updated successfully!' })
    } catch (e) { setToast({ kind: 'err', text: (e as Error).message }) }
    setSaving(false)
  }
  const field = (k: K, label: string, def: number, help: string) => (
    <div className="fld sa-fld" key={k}>
      <label htmlFor={`sa-${k}`} className="sa-lbl"><span>{label}</span><span className="muted small">Default: {def}</span></label>
      <input id={`sa-${k}`} className={`input${bad(k) ? ' is-bad' : ''}`} type="number" inputMode="numeric" min={LIM[k][0]} max={LIM[k][1]} value={f[k]} aria-invalid={bad(k)} aria-describedby={`sa-${k}-h`} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
      <span id={`sa-${k}-h`} className={bad(k) ? 'mp-bad small' : 'muted small'}>{bad(k) ? `Use a whole number from ${LIM[k][0]} to ${LIM[k][1]}.` : help}</span>
    </div>)
  return (
    <div className="page cmds mp sa">
      <div className="pg-head"><h2><i className="fas fa-hourglass-half" /> Subathon Settings</h2></div>
      <div className="notice notice-warn sa-wip"><i className="fas fa-hourglass-half" />
        <div><b>Subathon Time: Work in Progress!</b>
          <p>We're still fine-tuning the donation feature for adding time to your subathon. In the meantime, add time with <code>!subathon addtime [minutes]</code> in chat. For example: <code>!subathon addtime 10</code> adds 10 minutes.</p></div></div>
      <section className="card sa-card" aria-label="Subathon settings">
        {field('starting_minutes', 'Starting Minutes', 60, 'The timer starts with this many minutes, before any time is added.')}
        <div className="sa-grid">{ADDS.map(([k, l, d, h]) => field(k, l, d, h))}</div>
        <div className={`mp-save${dirty ? ' is-dirty' : ''}`}>
          <span className="muted small">{anyBad ? <span className="mp-bad">Fix the highlighted field to save.</span> : 'Time is added while a subathon is running.'}</span>
          {dirty && <span className="pill pill-warn">Unsaved changes</span>}
          <button type="button" className="btn btn-ghost" disabled={!dirty || saving} onClick={() => setF(strs(saved))}><i className="fas fa-rotate-left" /> Discard</button>
          <button type="button" className="btn" disabled={!dirty || saving || anyBad} onClick={save}><i className={saving ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'} /> {saving ? 'Saving' : 'Update Settings'}</button>
        </div>
      </section>
      <ToastBar t={toast} onClose={() => setToast(null)} />
    </div>
  )
}
