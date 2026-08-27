import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Static build lands under the live support docroot as /app/.
// PHP (login, tickets POST, session JSON) stays on the same host.
export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../support/app',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'https://support.botofthespecter.com',
      '/login.php': 'https://support.botofthespecter.com',
      '/logout.php': 'https://support.botofthespecter.com',
      '/css': 'https://support.botofthespecter.com',
    },
  },
})
