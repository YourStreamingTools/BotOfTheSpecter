import type { ThemeName } from '../theme'

// Light / Dark switch shared by the dashboard and the admin panel.
export default function ThemeSwitch({ value, onChange }: { value: ThemeName; onChange: (t: ThemeName) => void }) {
  const opts: Array<[ThemeName, string, string]> = [['light', 'fa-sun', 'Light'], ['dark', 'fa-moon', 'Dark']]
  return (
    <div className="seg theme-switch" role="radiogroup" aria-label="Theme">
      {opts.map(([v, icon, label]) => (
        <button key={v} role="radio" aria-checked={value === v} className={value === v ? 'on' : ''}
          title={label} onClick={() => onChange(v)}>
          <i className={`fa-solid ${icon}`} aria-hidden="true" /><span className="lbl">{label}</span>
        </button>
      ))}
    </div>
  )
}
