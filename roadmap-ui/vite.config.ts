import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../roadmap/app',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'https://roadmap.botofthespecter.com',
      '/admin': 'https://roadmap.botofthespecter.com',
      '/login.php': 'https://roadmap.botofthespecter.com',
      '/logout.php': 'https://roadmap.botofthespecter.com',
      '/css': 'https://roadmap.botofthespecter.com',
      '/uploads': 'https://roadmap.botofthespecter.com',
    },
  },
})
