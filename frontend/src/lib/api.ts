export type ApiErrorShape = {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  status: number
  code?: string
  errors?: Record<string, string[]>

  constructor(status: number, payload: ApiErrorShape) {
    super(payload.message ?? 'Something went wrong')
    this.name = 'ApiError'
    this.status = status
    this.code = payload.code
    this.errors = payload.errors
  }
}

type RuntimeConfig = {
  apiUrl?: string
}

const runtimeConfig = (globalThis as { __BULL_CONFIG__?: RuntimeConfig }).__BULL_CONFIG__ ?? {}
const runtimeConfigPresent = runtimeConfig.apiUrl !== undefined

const devOrigins = ['http://localhost:5173', 'http://localhost:4173', 'http://127.0.0.1:5173', 'http://127.0.0.1:4173']

export function runtimeConfigDiagnostic(): string {
  if (runtimeConfigPresent || typeof window === 'undefined') return ''
  if (devOrigins.includes(window.location.origin)) return ''

  return `runtime-config.js did not load, so API calls fall back to the same origin (${window.location.origin}/api). Serve runtime-config.js with the build or set an absolute apiUrl.`
}

function resolveApiUrl(): string {
  const candidates = [runtimeConfig.apiUrl, import.meta.env.VITE_API_URL, '/api']

  for (const candidate of candidates) {
    const value = typeof candidate === 'string' ? candidate.trim().replace(/\/+$/, '') : ''
    if (value) return value
  }

  return '/api'
}

export const apiUrl = resolveApiUrl()

const adminTokenKey = 'bull_admin_token'

export function getAdminToken(): string | null {
  return localStorage.getItem(adminTokenKey)
}

export function setAdminToken(token: string): void {
  localStorage.setItem(adminTokenKey, token)
}

export function clearAdminToken(): void {
  localStorage.removeItem(adminTokenKey)
}

function htmlHint(body: string): string {
  if (/apache/i.test(body)) return 'Apache served this page, so the request never reached the Laravel API.'
  if (/<!doctype html|<html/i.test(body)) return 'The static web server returned a page, so the request never reached the Laravel API.'

  return 'The server returned a page instead of an API response.'
}

function describeFailure(status: number, target: string, contentType: string, body: string): string {
  if (status === 401) {
    return 'Your session has expired. Please sign in again.'
  }

  if (contentType.includes('html')) {
    if (status === 404 || status === 405) {
      return `${status} from ${target}. ${htmlHint(body)} The API URL is not set for this build, so the frontend called the static web server. Check that runtime-config.js loads and sets apiUrl.`
    }

    if (status >= 500) {
      return `${status} from ${target}. ${htmlHint(body)} The API responded with an error page. Check the Laravel log.`
    }

    return `${status} from ${target}. ${htmlHint(body)}`
  }

  return `Request to ${target} failed with status ${status}.`
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  const isFormData = options.body instanceof FormData
  if (!isFormData && options.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getAdminToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const target = `${apiUrl}${path}`
  const response = await fetch(target, {
    ...options,
    headers,
    credentials: 'include',
  })
  const contentType = response.headers.get('content-type') ?? ''
  const isJson = contentType.includes('json')
  const payload = isJson ? await response.json() : await response.text()
  const body = typeof payload === 'string' ? payload : ''

  if (!response.ok) {
    if (response.status === 401 && token) {
      clearAdminToken()
    }

    const normalized = isJson && typeof payload === 'object' && payload !== null
      ? payload as ApiErrorShape
      : { message: describeFailure(response.status, target, contentType, body) }

    throw new ApiError(response.status, normalized)
  }

  if (!isJson) {
    throw new ApiError(response.status, {
      message: `${target} returned ${contentType || 'an empty body'} instead of JSON. ${htmlHint(body)}`,
    })
  }

  return payload as T
}

export function jsonBody(value: unknown, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'POST'): RequestInit {
  return {
    method,
    body: JSON.stringify(value),
  }
}
