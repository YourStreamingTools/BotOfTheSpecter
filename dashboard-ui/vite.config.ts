import react from '@vitejs/plugin-react'
import { renameSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig, type Plugin } from 'vite'

// Beta dashboard: static SPA served by Caddy from /var/www/beta-dashboard
// on beta.dashboard.botofthespecter.com (try_files fallback to index.html).
// `--mode admin` builds the admin panel entry (admin.html, src/admin/) into
// ../beta-admin for beta.admin.botofthespecter.com, with its PHP API taken
// from public-admin/ instead of public/. Both share src/ components and CSS.
const ADMIN_OUT = resolve(import.meta.dirname, '../beta-admin')

// Caddy serves index.html as the SPA fallback, so the admin entry is renamed.
const adminIndex = (): Plugin => ({
  name: 'admin-index',
  apply: 'build',
  closeBundle() { renameSync(resolve(ADMIN_OUT, 'admin.html'), resolve(ADMIN_OUT, 'index.html')) },
})

export default defineConfig(({ mode }) => mode === 'admin'
  ? {
      plugins: [react(), adminIndex()],
      base: '/',
      publicDir: 'public-admin',
      build: { outDir: ADMIN_OUT, emptyOutDir: true, sourcemap: false, rollupOptions: { input: resolve(import.meta.dirname, 'admin.html') } },
    }
  : {
      plugins: [react()],
      base: '/',
      build: { outDir: '../beta-dashboard', emptyOutDir: true, sourcemap: false },
    })
