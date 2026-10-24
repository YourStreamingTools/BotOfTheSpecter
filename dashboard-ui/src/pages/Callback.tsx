import { useEffect, useState } from 'react'
import { completeLogin, loginUrl } from '../auth'

export default function Callback({ onDone }: { onDone: () => void }) {
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    completeLogin().then((r) => (r.ok ? onDone() : setError(r.error ?? 'auth_failed')))
  }, [])
  return (
    <div className="center">
      <div className="card login">
        {error ? (
          <>
            <h2>Sign in failed</h2>
            <p className="muted">{error}</p>
            <a className="btn" href={loginUrl()}>Try again</a>
          </>
        ) : <i className="fa-solid fa-spinner fa-spin" />}
      </div>
    </div>
  )
}
