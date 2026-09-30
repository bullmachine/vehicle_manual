export function formatDate(value?: string | null, fallback = '—'): string {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }).format(date)
}

export function formatDateTime(value?: string | null, fallback = '—'): string {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

export function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message
  return 'Something went wrong. Please try again.'
}
