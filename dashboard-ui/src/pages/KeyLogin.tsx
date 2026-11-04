import { useState } from 'react'

// Hidden sign-in at /login/key (nothing links here): an admin API key with
// Dashboard access and its password sign in as the key's demo account.
export default function KeyLogin({ onDone }: { onDone: () => void }) {
  const [key, setKey] = useState(''), [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false), [error, setError] = useState('')
  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true); setError('')
    try {
      const r = await fetch('/api/key_login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ api_key: key.trim(), password }),
      })
      const j = await r.json().catch(() => ({}))
      if (r.ok && j.ok) return onDone()
      setError(r.status === 401 ? 'That key and password did not match.' : 'Signing in failed. Try again.')
    } catch { setError('Signing in failed. Try again.') } finally { setBusy(false) }
  }
  return (
    <div className="center">
      <form className="card login adm-form key-login" onSubmit={submit}>
        <h2>Sign in with a key</h2>
        <label className="fld"><span className="fld-label">API key</span>
          <input className="input" required autoComplete="username" spellCheck={false} value={key} onChange={(e) => setKey(e.target.value)} /></label>
        <label className="fld"><span className="fld-label">Password</span>
          <input className="input" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} /></label>
        {error && <div className="notice notice-error" role="alert">{error}</div>}
        <button type="submit" className="btn" disabled={busy}>{busy ? <i className="fa-solid fa-spinner fa-spin" /> : <i className="fas fa-right-to-bracket" />} Sign in</button>
      </form>
    </div>
  )
}
