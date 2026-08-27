import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Static build lands under the live members docroot as /app/.
// PHP (login, store POST, autocomplete, session JSON) stays on the same host.
export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../members/app',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'https://members.botofthespecter.com',
      '/login.php': 'https://members.botofthespecter.com',
      '/logout.php': 'https://members.botofthespecter.com',
      '/autocomplete.php': 'https://members.botofthespecter.com',
      '/style.css': 'https://members.botofthespecter.com',
    },
  },
})
