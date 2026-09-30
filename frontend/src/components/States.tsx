import type { ReactNode } from 'react'
import { AlertCircle, LoaderCircle } from 'lucide-react'

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return <div className="loading-state"><LoaderCircle className="spin" size={22} /><span>{label}</span></div>
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return <div className="error-state"><AlertCircle size={21} /><div><strong>Something went wrong</strong><p>{message}</p>{onRetry && <button className="text-button" onClick={onRetry}>Try again</button>}</div></div>
}

export function EmptyState({ title, message, action }: { title: string; message: string; action?: ReactNode }) {
  return <div className="empty-state"><div className="empty-icon"><AlertCircle size={22} /></div><h3>{title}</h3><p>{message}</p>{action}</div>
}
