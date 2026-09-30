import { Activity, BookOpen, Download, Search, UserRound } from 'lucide-react'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch, apiUrl, getAdminToken } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { Paginated } from '../lib/types'
import { EmptyState, ErrorState, LoadingState } from '../components/States'

type Tab = 'scans' | 'usage' | 'customers'
type ScanRow = { id: number; created_at: string; qr_code?: { reference: string; type?: { name: string } }; vehicle_variant?: { name: string }; engine?: { name: string }; language?: { name: string }; ip_address?: string; device?: string }
type UsageRow = { id: number; title: string; manual_type?: { name: string }; vehicle_variant?: { name: string }; engine?: { name: string }; language?: { name: string }; total_views: number; unique_users: number; last_viewed_at?: string }
type CustomerRow = { id: number; name: string; mobile: string; last_accessed_at?: string; sessions?: { id: number; created_at: string; vehicle_variant?: { name: string }; engine?: { name: string }; language?: { name: string }; qr_code?: { reference: string } }[] }

export function AdminReportsPage() {
  const [tab, setTab] = useState<Tab>('scans')
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const queryString = new URLSearchParams({ per_page: '50' })
  if (search) queryString.set('q', search)
  if (from) queryString.set('from', from)
  if (to) queryString.set('to', to)
  const path = tab === 'scans' ? '/admin/reports/qr-scans' : tab === 'usage' ? '/admin/reports/manual-usage' : '/admin/reports/customers'
  const query = useQuery({ queryKey: ['admin-report', tab, search, from, to], queryFn: () => apiFetch<Paginated<unknown>>(`${path}?${queryString.toString()}`) })
  return <div className="admin-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> INSIGHTS</span><h1>Reports</h1><p>Understand QR adoption, manual engagement and customer access patterns.</p></div>{tab === 'scans' && <button className="button button-primary" onClick={() => void downloadExport(queryString)}><Download size={17} /> Export CSV</button>}</div><div className="report-tabs"><button className={tab === 'scans' ? 'active' : ''} onClick={() => setTab('scans')}><Activity size={17} /> QR scans</button><button className={tab === 'usage' ? 'active' : ''} onClick={() => setTab('usage')}><BookOpen size={17} /> Manual usage</button><button className={tab === 'customers' ? 'active' : ''} onClick={() => setTab('customers')}><UserRound size={17} /> Customers</button></div><div className="toolbar report-toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search report" /></div>{tab !== 'usage' && <div className="date-filters"><input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /><span>to</span><input type="date" value={to} onChange={(event) => setTo(event.target.value)} /></div>}<span className="toolbar-count">{query.data?.total ?? 0} records</span></div>{query.isLoading ? <LoadingState label="Running report…" /> : query.isError ? <ErrorState message="Unable to load this report." onRetry={() => void query.refetch()} /> : query.data?.data.length === 0 ? <EmptyState title="No report data" message="Activity will appear here once customers start using the portal." /> : tab === 'scans' ? <ScanTable rows={query.data!.data as ScanRow[]} /> : tab === 'usage' ? <UsageTable rows={query.data!.data as UsageRow[]} /> : <CustomerTable rows={query.data!.data as CustomerRow[]} />}</div>
}

async function downloadExport(params: URLSearchParams) {
  const response = await fetch(`${apiUrl}/admin/reports/qr-scans/export?${params.toString()}`, { headers: { Authorization: `Bearer ${getAdminToken()}`, Accept: 'text/csv' } })
  if (!response.ok) throw new Error('Export failed')
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'qr-scan-report.csv'
  link.click()
  URL.revokeObjectURL(url)
}

function ScanTable({ rows }: { rows: ScanRow[] }) { return <div className="table-card"><table className="data-table"><thead><tr><th>Date & time</th><th>QR reference</th><th>QR type</th><th>Configuration</th><th>IP / device</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td className="muted-cell">{formatDateTime(row.created_at)}</td><td><strong>{row.qr_code?.reference ?? '—'}</strong></td><td>{row.qr_code?.type?.name ?? '—'}</td><td><div className="table-stack"><span>{row.vehicle_variant?.name ?? '—'}</span><span>{row.engine?.name ?? '—'} · {row.language?.name ?? '—'}</span></div></td><td className="muted-cell"><div className="table-stack"><span>{row.ip_address ?? '—'}</span><span>{row.device ?? 'Not specified'}</span></div></td></tr>)}</tbody></table><div className="table-footer"><span>{rows.length} scans shown</span></div></div> }
function UsageTable({ rows }: { rows: UsageRow[] }) { return <div className="table-card"><table className="data-table"><thead><tr><th>Manual</th><th>Configuration</th><th>Total views</th><th>Unique customers</th><th>Last viewed</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td><div className="table-title"><div className="table-icon"><BookOpen size={16} /></div><div><strong>{row.title}</strong><span>{row.manual_type?.name}</span></div></div></td><td><div className="table-stack"><span>{row.vehicle_variant?.name}</span><span>{row.engine?.name} · {row.language?.name}</span></div></td><td><strong>{row.total_views}</strong></td><td><strong>{row.unique_users}</strong></td><td className="muted-cell">{row.last_viewed_at ? formatDateTime(row.last_viewed_at) : 'Never'}</td></tr>)}</tbody></table><div className="table-footer"><span>{rows.length} manuals shown</span></div></div> }
function CustomerTable({ rows }: { rows: CustomerRow[] }) { return <div className="table-card"><table className="data-table"><thead><tr><th>Customer</th><th>Last access</th><th>Latest configuration</th><th>Sessions</th></tr></thead><tbody>{rows.map((row) => { const latest = row.sessions?.[0]; return <tr key={row.id}><td><div className="table-title"><div className="table-icon small"><UserRound size={15} /></div><div><strong>{row.name}</strong><span>{row.mobile}</span></div></div></td><td className="muted-cell">{row.last_accessed_at ? formatDateTime(row.last_accessed_at) : 'Never'}</td><td><div className="table-stack"><span>{latest?.vehicle_variant?.name ?? '—'}</span><span>{latest?.engine?.name ?? '—'} · {latest?.language?.name ?? '—'}</span></div></td><td><strong>{row.sessions?.length ?? 0}</strong></td></tr> })}</tbody></table><div className="table-footer"><span>{rows.length} customers shown</span></div></div> }
