import { useState, type ReactNode } from 'react'
import { Confirm } from './insights/shared'
import type { Toast } from './commands/shared'

// Masked token with confirm-to-reveal and copy (StreamElements / StreamLabs).
export function TokenField({ id, label, value, what, toast, children }: { id: string; label: string; value: string; what: string; toast: (t: Toast) => void; children?: ReactNode }) {
  const [show, setShow] = useState(false), [ask, setAsk] = useState(false)
  const copy = () => navigator.clipboard?.writeText(value).then(() => toast({ kind: 'ok', text: 'Copied.' }), () => toast({ kind: 'err', text: 'Could not copy the token to the clipboard.' }))
  return <div className="fld fld-wide">
    <label htmlFor={id}>{label}</label>
    <div className="st-secret tk-secret">
      <input id={id} className="input mono" type={show ? 'text' : 'password'} readOnly autoComplete="off" value={value} />
      <button type="button" className="btn btn-ghost" aria-label={show ? `Hide ${what}` : `Show ${what}`} aria-pressed={show} onClick={() => show ? setShow(false) : setAsk(true)}><i className={show ? 'fas fa-eye-slash' : 'fas fa-eye'} /></button>
      <button type="button" className="btn btn-ghost" aria-label={`Copy ${what}`} onClick={copy}><i className="fas fa-copy" /></button>
    </div>
    {children}
    {ask && <Confirm title={`Reveal ${what}?`} yes="Show" onClose={() => setAsk(false)} onYes={() => { setAsk(false); setShow(true) }}><p>Are you sure you want to show your {what}? Keep it secret, and make sure this screen is not shared before you continue.</p></Confirm>}
  </div>
}
export const Pill = ({ k, children, title }: { k: string; children: ReactNode; title?: string }) => <span className={`pill ${k}`} title={title}>{children}</span>
