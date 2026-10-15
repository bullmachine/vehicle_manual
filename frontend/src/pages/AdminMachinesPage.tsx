import { AlertTriangle, CheckCircle2, Cpu, LoaderCircle, RefreshCw, Search } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage, formatDate, formatDateTime } from '../lib/format'
import type { Paginated } from '../lib/types'
import { EmptyState, ErrorState, LoadingState } from '../components/States'

type MachineRow = { id: number; msno: string; fert: string; fert_name?: string | null; description?: string | null; invoice?: string | null; plant?: string | null; delivery_date?: string | null; source: string; synced_at?: string | null }
type SyncResult = { received: number; created: number; updated: number; skipped: number; ferts_added: number }
type LastSync = { at: string; status: 'success' | 'failed'; plants: string[]; from: string; to: string; result: SyncResult | null; error: string | null; by: string }
type MachineList = Paginated<MachineRow> & { summary: { total: number; from_sap: number; plants: string[]; configured_plants: string[]; sap_configured: boolean; last_sync: LastSync | null } }

const today = () => new Date().toISOString().slice(0, 10)
const daysAgo = (days: number) => new Date(Date.now() - days * 86400000).toISOString().slice(0, 10)

export function AdminMachinesPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [plant, setPlant] = useState('')
  const [page, setPage] = useState(1)
  const [from, setFrom] = useState(daysAgo(7))
  const [to, setTo] = useState(today())
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const query = useQuery({ queryKey: ['admin-machines', search, plant, page], queryFn: () => apiFetch<MachineList>(`/admin/machines?per_page=50&page=${page}&q=${encodeURIComponent(search)}${plant ? `&plant=${encodeURIComponent(plant)}` : ''}`) })
  const sync = useMutation({
    mutationFn: () => apiFetch<{ data: SyncResult }>('/admin/machines/sync', jsonBody({ from, to })),
    onSuccess: (result) => { setError(''); setNotice(`Sync complete: ${describe(result.data)}`); void queryClient.invalidateQueries({ queryKey: ['admin-machines'] }) },
    onError: (reason) => { setNotice(''); setError(errorMessage(reason)); void queryClient.invalidateQueries({ queryKey: ['admin-machines'] }) },
  })
  const summary = query.data?.summary
  const last = summary?.last_sync

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!from || !to) return setError('Choose a from and to date.')
    if (from > to) return setError('The from date must be on or before the to date.')
    setNotice('')
    setError('')
    sync.mutate()
  }

  return <div className="admin-page">
    <div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> SAP MACHINE DATA</span><h1>Machines</h1><p>Machine serial numbers synced from SAP. Customers are matched to manuals through each machine’s FERT.</p></div></div>
    <div className="qr-overview"><div className="qr-overview-icon"><Cpu size={23} /></div><div><strong>{summary ? `${summary.total} machines · ${summary.from_sap} from SAP` : 'Loading machines…'}</strong><span>{last ? <>Last sync {formatDateTime(last.at)} by {last.by} · plants {last.plants.join(', ')} · {formatDate(last.from)} to {formatDate(last.to)}{last.status === 'success' && last.result ? ` · ${describe(last.result)}` : ''}</> : 'No SAP sync has run yet. The nightly sync runs at 01:30 for the last 7 days.'}</span></div>{last && <span className={`qr-security${last.status === 'failed' ? ' sync-failed' : ''}`}>{last.status === 'success' ? <><CheckCircle2 size={15} /> Last sync OK</> : <><AlertTriangle size={15} /> Last sync failed</>}</span>}</div>
    {last?.status === 'failed' && last.error && <div className="inline-alert error-alert"><AlertTriangle size={16} />{last.error}</div>}
    <form className="toolbar" onSubmit={submit}><span className="form-label">Sync from SAP</span><div className="date-filters"><input type="date" value={from} onChange={(event) => setFrom(event.target.value)} aria-label="From date" /><span>to</span><input type="date" value={to} onChange={(event) => setTo(event.target.value)} aria-label="To date" /></div><span className="toolbar-count">Plants {summary?.configured_plants.join(', ') || '—'}</span><button className="button button-primary" disabled={sync.isPending || summary?.sap_configured === false}>{sync.isPending ? <LoaderCircle className="spin" size={17} /> : <RefreshCw size={17} />} {sync.isPending ? 'Syncing…' : 'Sync now'}</button></form>
    {summary?.sap_configured === false && <div className="inline-alert error-alert"><AlertTriangle size={16} />SAP is not configured. Set SAP_MACHINE_API_URL, SAP_MACHINE_API_USERNAME and SAP_MACHINE_API_PASSWORD in backend/.env.</div>}
    {notice && <div className="inline-alert success-alert"><CheckCircle2 size={16} />{notice}</div>}
    {error && <div className="inline-alert error-alert"><AlertTriangle size={16} />{error}</div>}
    <div className="toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Search serial no, FERT, description or invoice" /></div><select value={plant} onChange={(event) => { setPlant(event.target.value); setPage(1) }}><option value="">All plants</option>{summary?.plants.map((item) => <option key={item} value={item}>{item}</option>)}</select><span className="toolbar-count">{query.data?.total ?? 0} machines</span></div>
    {query.isLoading ? <LoadingState label="Loading machines…" /> : query.isError ? <ErrorState message="Unable to load machines." onRetry={() => void query.refetch()} /> : query.data?.data.length === 0 ? <EmptyState title={search || plant ? 'No machines match' : 'No machines yet'} message={search || plant ? 'Try a different serial number or FERT.' : 'Run a sync from SAP to load machine serial numbers.'} /> : <div className="table-card"><table className="data-table"><thead><tr><th>Serial no (MSNO)</th><th>FERT</th><th>Invoice</th><th>Plant</th><th>Delivery date</th><th>Source</th><th>Synced</th></tr></thead><tbody>{query.data!.data.map((row) => <tr key={row.id}><td><code className="code-label">{row.msno}</code></td><td><div className="table-stack"><strong>{row.fert}</strong><span>{row.fert_name ?? row.description ?? '—'}</span></div></td><td className="muted-cell">{row.invoice ?? '—'}</td><td className="muted-cell">{row.plant ?? '—'}</td><td className="muted-cell">{row.delivery_date ? formatDate(row.delivery_date) : '—'}</td><td><span className={`status-pill ${row.source === 'sap' ? 'published' : 'draft'}`}>{row.source === 'sap' ? 'SAP' : 'Dummy file'}</span></td><td className="muted-cell">{row.synced_at ? formatDateTime(row.synced_at) : '—'}</td></tr>)}</tbody></table><div className="table-footer"><span>{query.data!.total} machines · page {query.data!.current_page} of {query.data!.last_page}</span><div className="pagination"><button onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1}>Previous</button><button className="current">{query.data!.current_page}</button><button onClick={() => setPage((current) => current + 1)} disabled={page >= query.data!.last_page}>Next</button></div></div></div>}
  </div>
}

function describe(result: SyncResult): string {
  return `${result.received} received, ${result.created} new, ${result.updated} updated, ${result.skipped} skipped, ${result.ferts_added} new FERTs`
}
