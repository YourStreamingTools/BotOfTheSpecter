import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  base: '/app/',
  build: {
    outDir: '../YourStreamingTools/app',
    emptyOutDir: true,
    sourcemap: false,
  },
})
