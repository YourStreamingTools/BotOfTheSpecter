import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../home/app',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    proxy: {
      '/api': 'https://botofthespecter.com',
      '/status.php': 'https://botofthespecter.com',
      '/login.php': 'https://botofthespecter.com',
      '/logout.php': 'https://botofthespecter.com',
      '/style.css': 'https://botofthespecter.com',
    },
  },
})
