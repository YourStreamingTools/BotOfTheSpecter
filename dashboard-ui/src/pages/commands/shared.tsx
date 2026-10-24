import { useEffect } from 'react'
import type { ComboOption } from '../../components/Combobox'

// Display name (what the live forms post) to the value stored in the user DB.
export const PERMS: [string, string][] = [
  ['Everyone', 'everyone'], ['VIPs', 'vip'], ['All Subscribers', 'all-subs'], ['Tier 1 Subscriber', 't1-sub'],
  ['Tier 2 Subscriber', 't2-sub'], ['Tier 3 Subscriber', 't3-sub'], ['Mods', 'mod'], ['Broadcaster', 'broadcaster'],
]
export const PERM_OPTS: ComboOption[] = PERMS.map(([d]) => ({ value: d, label: d }))
export const permLabel = (db: string) => PERMS.find(([, v]) => v === db)?.[0] ?? 'Everyone'
export const BUCKETS: ComboOption[] = [
  { value: 'default', label: 'Default (all users)' }, { value: 'user', label: 'User (per-user cooldown)' }, { value: 'mod', label: 'Mod (only cooldown for mods)' },
]
export const bucketShort = (b: string) => (b === 'user' ? 'per user' : b === 'mod' || b === 'mods' ? 'mods only' : 'shared')
export const cleanName = (s: string) => s.toLowerCase().replace(/\s+/g, '').replace(/[^a-z0-9]/g, '')

export async function send<T = { success: boolean; message?: string }>(url: string, body: Record<string, string>, ajax = false): Promise<T> {
  const r = await fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...(ajax ? { 'X-Requested-With': 'XMLHttpRequest' } : {}) },
    body: new URLSearchParams(body).toString(),
  })
  if (r.status === 401) throw new Error('Your session expired. Sign in again.')
  return (await r.json()) as T
}

export function Switch({ on, label, busy, onChange }: { on: boolean; label: string; busy?: boolean; onChange: (v: boolean) => void }) {
  return (
    <button type="button" role="switch" aria-checked={on} aria-label={label} className={`switch${on ? ' on' : ''}`} disabled={busy} onClick={() => onChange(!on)}>
      <span className="switch-knob" />
    </button>
  )
}

export function Counter({ n, max }: { n: number; max: number }) {
  return <small className={`counter${n > max ? ' over' : ''}`}>{n}/{max} characters{n > max ? ' - exceeds limit' : ''}</small>
}

export type Toast = { kind: 'ok' | 'err'; text: string } | null
// Floats over the page so showing it never moves content. Success hides after 3.5s, errors stay until closed.
export function ToastBar({ t, onClose }: { t: Toast; onClose: () => void }) {
  useEffect(() => { if (t?.kind !== 'ok') return; const h = setTimeout(onClose, 3500); return () => clearTimeout(h) }, [t]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!t) return null
  return <div className={`notice notice-${t.kind} cmd-toast`} role="status"><i className={t.kind === 'ok' ? 'fas fa-check' : 'fas fa-triangle-exclamation'} /> <span>{t.text}</span> <button type="button" className="combo-x" aria-label="Dismiss" onClick={onClose}><i className="fa-solid fa-xmark" /></button></div>
}

// Themed variable chips: the token in accent, an optional short meaning after it.
export function VarChips({ vars, label = 'Variables' }: { vars: { v: string; d?: string }[]; label?: string }) {
  return (
    <ul className="var-chips" aria-label={label}>
      {vars.map(({ v, d }) => <li key={v} className="var-chip"><code>{v}</code>{d && <span>{d}</span>}</li>)}
    </ul>
  )
}

// The custom variables help box used on custom commands and timed messages.
export function VarsHelp({ where = 'response', children }: { where?: string; children?: React.ReactNode }) {
  return (
    <section className="card notice-card print-hide">
      {children}
      <p className="muted">Custom variables add dynamic parts to a {where}, and only work in the {where}.</p>
      <a className="btn btn-quiet cmd-vars" href="https://support.botofthespecter.com/index.php#variables" target="_blank" rel="noopener"><i className="fas fa-code" /> View custom variables</a>
    </section>
  )
}
