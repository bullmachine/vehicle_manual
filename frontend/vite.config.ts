import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

const backend = 'http://127.0.0.1:8000'

const proxy = {
  '/api': { target: backend, changeOrigin: true },
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')

  return {
    base: env.VITE_BASE_PATH || '/',
    plugins: [react()],
    server: {
      port: 5173,
      proxy,
    },
    preview: {
      port: 4173,
      proxy,
    },
  }
})
