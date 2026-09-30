import { existsSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { loadEnv } from 'vite'

const mode = process.env.MODE || 'production'
const env = loadEnv(mode, process.cwd(), 'VITE_')
const apiUrl = (env.VITE_API_URL || '').trim()
const target = resolve(process.cwd(), 'dist', 'runtime-config.js')
const contents = `window.__BULL_CONFIG__ = {\n  apiUrl: ${JSON.stringify(apiUrl)},\n}\n`

if (!existsSync(target)) {
  console.error('runtime-config.js was not emitted into dist; the app will fall back to same-origin /api')
  process.exit(1)
}

if (mode === 'production' && !apiUrl && process.env.ALLOW_SAME_ORIGIN_API !== '1') {
  console.error('VITE_API_URL is empty, so the production build would call the static web server instead of the API.')
  console.error('Set VITE_API_URL (for example http://localhost:8000/api), or pass ALLOW_SAME_ORIGIN_API=1 if the same origin really serves /api.')
  process.exit(1)
}

writeFileSync(target, contents)
console.log(`runtime-config.js written with apiUrl=${apiUrl || '(same origin)'}`)
