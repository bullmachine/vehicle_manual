import { existsSync, readdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
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

// pdf.js ships its web worker as pdf.worker.min.mjs. Apache on the LAN serves
// .mjs fine, but IIS (the public host) has no MIME type for it and the
// staticContent section that declares extra types is locked, so the worker
// answers 404 and the viewer never starts. Rename emitted .mjs assets to .js,
// a type both servers already serve, and point every referring file at the new
// name. The rename is exact-basename, so no unrelated strings are touched.
const assetsDir = resolve(process.cwd(), 'dist', 'assets')
if (existsSync(assetsDir)) {
  const renamedMap = new Map()
  for (const name of readdirSync(assetsDir)) {
    if (!name.endsWith('.mjs')) continue
    const next = name.slice(0, -4) + '.js'
    renameSync(resolve(assetsDir, name), resolve(assetsDir, next))
    renamedMap.set(name, next)
    console.log(`worker asset renamed ${name} -> ${next}`)
  }
  if (renamedMap.size > 0) {
    const targets = [
      ...readdirSync(assetsDir).map((name) => resolve(assetsDir, name)),
      resolve(process.cwd(), 'dist', 'index.html'),
    ]
    for (const file of targets) {
      if (!/\.(js|html)$/.test(file)) continue
      const before = readFileSync(file, 'utf8')
      let after = before
      for (const [from, to] of renamedMap) after = after.split(from).join(to)
      if (after !== before) writeFileSync(file, after)
    }
  }
}
