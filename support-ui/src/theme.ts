export type ThemeName = 'light' | 'dark'

export function readTheme(): ThemeName {
  try {
    const stored = localStorage.getItem('sp-theme')
    if (stored === 'light' || stored === 'dark') return stored
  } catch {
    /* ignore */
  }
  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
    return 'light'
  }
  return 'dark'
}

export function applyTheme(theme: ThemeName, persist: boolean): void {
  document.documentElement.setAttribute('data-theme', theme)
  document.documentElement.className = theme === 'light' ? 'light-theme' : 'dark-theme'
  if (persist) {
    try {
      localStorage.setItem('sp-theme', theme)
    } catch {
      /* ignore */
    }
  }
}
