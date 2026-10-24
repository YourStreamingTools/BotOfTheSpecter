import { StrictMode } from 'react'
import '../index.css'
import { createRoot } from 'react-dom/client'
import AdminApp from './AdminApp'
import { installSessionGuard, setLoginUrl } from '../session'

// Sign in (and back in after expiry) through the admin SSO handoff, returning here.
setLoginUrl(() => `/api/login.php?return=${encodeURIComponent(window.location.pathname + window.location.search)}`)
installSessionGuard()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AdminApp />
  </StrictMode>,
)
