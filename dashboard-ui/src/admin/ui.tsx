import { useEffect, useState, type ReactNode } from 'react'
import Modal from '../components/Modal'
export { Switch, ToastBar, type Toast } from '../pages/commands/shared'

// Admin endpoints answer JSON from public-admin/api (all behind _admin.php).
export type Res = { success: boolean; message?: string; [k: string]: unknown }

export async function post<T = Res>(url: string, body: Record<string, string | number>): Promise<T> {
  const fd = new URLSearchParams()
  Object.entries(body).forEach(([k, v]) => fd.append(k, String(v)))
  const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: fd.toString() })
  if (r.status === 401) throw new Error('Your session expired. Sign in again.')
  if (r.status === 403) throw new Error('Admins only.')
  const t = await r.text()
  try { return JSON.parse(t) as T } catch { throw new Error(`Unexpected response (${r.status}).`) }
}

export async function getJson<T>(url: string): Promise<T> {
  const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
  if (!r.ok) throw new Error(r.status === 403 ? 'Admins only.' : `Could not load (${r.status}).`)
  return (await r.json()) as T
}

// Load once, with reload(). `data` stays null until the first answer.
export function useLoad<T>(url: string): { data: T | null; err: string; reload: () => Promise<void>; setData: (fn: (d: T) => T) => void } {
  const [data, set] = useState<T | null>(null)
  const [err, setErr] = useState('')
  const reload = async () => { try { set(await getJson<T>(url)); setErr('') } catch (e) { setErr((e as Error).message) } }
  useEffect(() => { void reload() }, [url]) // eslint-disable-line react-hooks/exhaustive-deps
  return { data, err, reload, setData: (fn) => set((d) => (d ? fn(d) : d)) }
}

// Confirm before any destructive action.
export type Ask = { title: string; text: ReactNode; ok: string; danger?: boolean; run: () => void | Promise<void> } | null
export function Confirm({ ask, onClose }: { ask: Ask; onClose: () => void }) {
  const [busy, setBusy] = useState(false)
  if (!ask) return null
  return (
    <Modal title={ask.title} icon={ask.danger ? 'fas fa-triangle-exclamation' : 'fas fa-circle-question'} onClose={onClose}>
      <div>{ask.text}</div>
      <div className="modal-acts">
        <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
        <button type="button" data-autofocus className={`btn${ask.danger ? ' btn-danger' : ''}`} disabled={busy} onClick={() => { const run = ask.run; setBusy(true); onClose(); setBusy(false); Promise.resolve().then(run).catch(() => {}) }}>{ask.ok}</button>
      </div>
    </Modal>
  )
}

export const Loading = () => <div className="card"><span className="bp-skel" /> <span className="bp-skel bp-skel-w" /></div>
export const LoadErr = ({ err, retry }: { err: string; retry: () => void }) => <div className="notice notice-error" role="alert"><i className="fas fa-triangle-exclamation" /> <span>{err}</span> <button type="button" className="btn btn-ghost" onClick={retry}>Retry</button></div>
