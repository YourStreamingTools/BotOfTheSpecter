import { useEffect, useRef, useState } from 'react'
import { getConsent, onConsentChange, setConsent } from './consent'

export default function ConsentBanner() {
  const [open, setOpen] = useState(() => getConsent() === null)
  const ref = useRef<HTMLButtonElement>(null)
  useEffect(() => onConsentChange((v) => setOpen(v === null)), [])
  useEffect(() => { if (open) ref.current?.focus({ preventScroll: true }) }, [open])
  if (!open) return null
  return (
    <div className="consent-box" role="region" aria-label="Cookie consent" aria-live="polite">
      <p><i className="fa-solid fa-cookie-bite" /> We use strictly necessary cookies to keep you signed in. With your consent we also remember optional preferences, such as which bot you last selected.</p>
      <p className="muted small"><a href="https://botofthespecter.com/privacy-policy.php" target="_blank" rel="noopener">Privacy Policy</a></p>
      <div className="consent-actions">
        <button ref={ref} className="btn btn-ok" onClick={() => setConsent('accepted', 7)}>Accept Cookies</button>
        <button className="btn btn-danger" onClick={() => setConsent('declined', 14)}>Decline Cookies</button>
      </div>
    </div>
  )
}
