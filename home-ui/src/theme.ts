// Theme: Light or Dark, same logic as the dashboard. With nothing saved, start from
// prefers-color-scheme and follow live OS changes; once the user picks one it is saved.
// index.html applies the same logic inline before first paint.
export type ThemeName = 'light' | 'dark'
const KEY = 'sp-theme'
const mq = () => window.matchMedia('(prefers-color-scheme: light)')

export function readSaved(): ThemeName | null {
  try {
    const v = localStorage.getItem(KEY)
    if (v === 'light' || v === 'dark') return v
  } catch {
    /* ignore */
  }
  return null
}

export function osTheme(): ThemeName {
  return mq().matches ? 'light' : 'dark'
}

export function readTheme(): ThemeName {
  return readSaved() ?? osTheme()
}

export function applyTheme(theme: ThemeName): void {
  const el = document.documentElement
  el.setAttribute('data-theme', theme)
  el.className = theme === 'light' ? 'light-theme' : 'dark-theme'
  el.style.colorScheme = theme
}

export function saveTheme(theme: ThemeName): void {
  try {
    localStorage.setItem(KEY, theme)
  } catch {
    /* ignore */
  }
}

export function onOsChange(cb: () => void): () => void {
  if (!window.matchMedia) return () => {}
  const m = mq()
  m.addEventListener('change', cb)
  return () => m.removeEventListener('change', cb)
}
