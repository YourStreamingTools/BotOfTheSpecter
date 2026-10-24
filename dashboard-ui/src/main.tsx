import { StrictMode } from 'react'
import './index.css'
import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import ConsentBanner from './ConsentBanner'
import { installSessionGuard } from './session'

installSessionGuard()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
    <ConsentBanner />
  </StrictMode>,
)
