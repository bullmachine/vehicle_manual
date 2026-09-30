import { ChevronDown, ChevronRight, Filter, LoaderCircle, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, jsonBody } from '../lib/api'
import { formatDateTime, errorMessage } from '../lib/format'
import type { Paginated, SupportEnquiry } from '../lib/types'
import { EmptyState, ErrorState, LoadingState } from '../components/States'

const STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'new', label: 'New' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'closed', label: 'Closed' },
]

export function AdminEnquiryPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [expanded, setExpanded] = useState<number | null>(null)
  const [error, setError] = useState('')
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['admin-enquiries', search, status], queryFn: () => apiFetch<Paginated<SupportEnquiry>>(`/admin/support-enquiries?per_page=50&q=${encodeURIComponent(search)}${status ? `&status=${status}` : ''}`) })
  const update = useMutation({ mutationFn: ({ id, status: next }: { id: number; status: string }) => apiFetch(`/admin/support-enquiries/${id}`, jsonBody({ status: next }, 'PUT')), onSuccess: () => { setError(''); void queryClient.invalidateQueries({ queryKey: ['admin-enquiries'] }) }, onError: (reason) => setError(errorMessage(reason)) })
  const destroy = useMutation({ mutationFn: (id: number) => apiFetch(`/admin/support-enquiries/${id}`, { method: 'DELETE' }), onSuccess: () => { setError(''); void queryClient.invalidateQueries({ queryKey: ['admin-enquiries'] }) }, onError: (reason) => setError(errorMessage(reason)) })

  function remove(row: SupportEnquiry) {
    if (window.confirm(`Delete the enquiry from ${row.name}? This cannot be undone.`)) destroy.mutate(row.id)
  }

  return <div className="admin-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> SUPPORT DESK</span><h1>Support requests</h1><p>Messages submitted from the customer portal contact form.</p></div></div><div className="toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, mobile, email or subject" /></div><div className="filter-select"><Filter size={16} /><select value={status} onChange={(event) => setStatus(event.target.value)}>{STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></div><span className="toolbar-count">{query.data?.total ?? 0} requests</span></div>{error && <div className="inline-alert error-alert">{error}</div>}{query.isLoading ? <LoadingState label="Loading support requests…" /> : query.isError ? <ErrorState message="Unable to load support requests." onRetry={() => void query.refetch()} /> : query.data?.data.length === 0 ? <EmptyState title="No support requests" message="Messages sent from the customer portal will appear here." /> : <div className="table-card"><table className="data-table"><thead><tr><th>From</th><th>Subject</th><th>Context</th><th>Status</th><th>Received</th><th /></tr></thead><tbody>{query.data!.data.map((row) => <EnquiryRow key={row.id} row={row} expanded={expanded === row.id} busy={update.isPending || destroy.isPending} onToggle={() => setExpanded(expanded === row.id ? null : row.id)} onStatus={(next) => update.mutate({ id: row.id, status: next })} onDelete={() => remove(row)} />)}</tbody></table><div className="table-footer"><span>{query.data!.total} support requests</span></div></div>}</div>
}

function EnquiryRow({ row, expanded, busy, onToggle, onStatus, onDelete }: { row: SupportEnquiry; expanded: boolean; busy: boolean; onToggle: () => void; onStatus: (status: string) => void; onDelete: () => void }) {
  return <><tr className={expanded ? 'audit-row-expanded' : ''}><td><div className="table-stack"><strong>{row.name}</strong><span>{row.mobile_display ?? row.mobile}</span>{row.email && <span>{row.email}</span>}</div></td><td><div className="table-stack"><span>{row.subject}</span>{row.vehicle && <span>{row.vehicle}</span>}</div></td><td>{row.qr ? <div className="table-stack"><span>{row.qr.reference}</span>{row.qr.label && <span>{row.qr.label}</span>}</div> : <span className="muted-cell">No QR context</span>}</td><td><span className={`status-pill ${row.status}`}>{row.status.replace('_', ' ')}</span></td><td className="muted-cell">{formatDateTime(row.created_at)}</td><td><div className="row-actions"><button className="icon-button" onClick={onToggle} aria-label="Show message">{expanded ? <ChevronDown size={17} /> : <ChevronRight size={17} />}</button><button className="icon-button danger-text" onClick={onDelete} disabled={busy} aria-label={`Delete enquiry from ${row.name}`}><Trash2 size={16} /></button></div></td></tr>{expanded && <tr className="audit-detail-row"><td colSpan={6}><div className="audit-detail"><div className="enquiry-message"><span>Message</span><p>{row.message}</p></div><div className="enquiry-controls"><label>Status<select value={row.status} onChange={(event) => onStatus(event.target.value)} disabled={busy}>{STATUSES.slice(1).map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label><div><span>Resolved</span><small>{row.resolved_at ? formatDateTime(row.resolved_at) : 'Not yet resolved'}</small></div><div><span>Source</span><small>{row.source.replace(/_/g, ' ')}</small></div>{busy && <div className="enquiry-busy"><LoaderCircle className="spin" size={16} /> Saving</div>}</div></div></td></tr>}</>
}
