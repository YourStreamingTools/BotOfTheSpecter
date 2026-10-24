import type { ReactNode } from 'react'

// Shared bits for the Community pages (followers, subscribers, VIPs, mods):
// the old dashboard's follower card (64px avatar or initial, then text lines).
export const API = '/api/community'
export const safeImg = (u?: string | null) => (u && /^https:\/\//i.test(u) ? u : '')
export const initial = (n: string) => (n ? n.charAt(0).toUpperCase() : '?')
export const FAIL = 'Something went wrong loading this. Refresh to try again.'

export function Avatar({ src, name, size = 64 }: { src?: string | null; name: string; size?: number }) {
  const u = safeImg(src)
  return u ? <img className="cm-av" src={u} alt="" width={size} height={size} loading="lazy" style={{ width: size, height: size }} />
    : <span className="cm-av cm-av-i" aria-hidden="true" style={{ width: size, height: size, fontSize: size / 2.6 }}>{initial(name)}</span>
}

export function UserCard({ img, name, children }: { img?: string | null; name: string; children: ReactNode }) {
  return <div className="card cm-card"><Avatar src={img} name={name} /><div className="cm-card-txt">{children}</div></div>
}

export function SkelCards({ n = 8 }: { n?: number }) {
  return <>{Array.from({ length: n }, (_, k) => <div key={k} className="card cm-card" aria-hidden="true"><span className="cm-av bp-skel" /><div className="cm-card-txt"><span className="bp-skel bp-skel-w" /><span className="bp-skel" /></div></div>)}</>
}

export const SkelRows = ({ cols, n = 5 }: { cols: number; n?: number }) => <>{Array.from({ length: n }, (_, k) => <tr key={k} aria-hidden="true">{Array.from({ length: cols }, (_, j) => <td key={j}><span className="bp-skel" style={{ width: `${40 + ((k + j) * 13) % 45}%` }} /></td>)}</tr>)}</>
