import { useEffect, useRef } from 'react'

// Runs fn every ms while the tab is visible: paused when hidden, run once on return,
// stopped on unmount. fn should be one short request (no long-poll).
export function useVisibleInterval(fn: () => void, ms: number, on = true) {
  const f = useRef(fn); f.current = fn
  useEffect(() => {
    if (!on) return
    let h = 0
    const start = () => { clearInterval(h); h = window.setInterval(() => f.current(), ms) }
    const vis = () => { if (document.hidden) clearInterval(h); else { f.current(); start() } }
    if (!document.hidden) start()
    document.addEventListener('visibilitychange', vis)
    return () => { clearInterval(h); document.removeEventListener('visibilitychange', vis) }
  }, [ms, on])
}
