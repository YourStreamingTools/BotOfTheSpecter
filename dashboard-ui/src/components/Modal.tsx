import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

const stack: object[] = []

// Portal dialog with a focus trap, Esc to close and focus returned on close.
export default function Modal({ title, icon, onClose, children, wide }: { title: string; icon?: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  const box = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)
  close.current = onClose
  useEffect(() => {
    const me = {}
    stack.push(me)
    const prev = document.activeElement as HTMLElement | null
    const first = box.current?.querySelector<HTMLElement>('[data-autofocus]') ?? box.current?.querySelector<HTMLElement>('button')
    first?.focus({ preventScroll: true })
    const key = (e: KeyboardEvent) => {
      if (stack[stack.length - 1] !== me) return
      if (e.key === 'Escape') { e.preventDefault(); close.current() }
      if (e.key === 'Tab' && box.current) {
        const f = [...box.current.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], input, [tabindex="0"], video, audio')]
        if (!f.length) return
        const a = f[0], z = f[f.length - 1]
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus() }
        else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus() }
      }
    }
    document.addEventListener('keydown', key)
    return () => { stack.splice(stack.indexOf(me), 1); document.removeEventListener('keydown', key); prev?.focus({ preventScroll: true }) }
  }, [])
  return createPortal(
    <div className="modal-scrim" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div ref={box} className={`modal card${wide ? ' modal-wide' : ''}`} role="dialog" aria-modal="true" aria-label={title}>
        <div className="modal-head"><h3>{icon && <i className={icon} />} {title}</h3>
          <button type="button" className="combo-x" aria-label="Close" onClick={onClose}><i className="fa-solid fa-xmark" /></button></div>
        {children}
      </div>
    </div>, document.body)
}
