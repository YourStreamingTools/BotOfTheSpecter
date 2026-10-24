// Theme: Light or Dark. With nothing saved, start from prefers-color-scheme and
// follow live OS changes; once the user picks one it is saved and used from then on.
// index.html applies the same logic inline before first paint.
export type ThemeName = 'light' | 'dark'
const KEY = 'sp-theme'
const mq = () => window.matchMedia('(prefers-color-scheme: light)')

export function readSaved(): ThemeName | null {
  try {
    const v = localStorage.getItem(KEY)
    if (v === 'light' || v === 'dark') return v
  } catch { /* ignore */ }
  return null
}

export function osTheme(): ThemeName {
  return mq().matches ? 'light' : 'dark'
}

export function applyTheme(t: ThemeName): void {
  const el = document.documentElement
  el.setAttribute('data-theme', t)
  el.className = t === 'light' ? 'light-theme' : 'dark-theme'
  el.style.colorScheme = t
}

export function saveTheme(t: ThemeName): void {
  try { localStorage.setItem(KEY, t) } catch { /* ignore */ }
}

export function onOsChange(cb: () => void): () => void {
  const m = mq()
  m.addEventListener('change', cb)
  return () => m.removeEventListener('change', cb)
}
