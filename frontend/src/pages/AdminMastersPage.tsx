import { Check, Database, Download, Edit3, FileUp, LoaderCircle, Plus, Search, Trash2, X } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import { useParams, NavLink } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, apiUrl, getAdminToken, jsonBody } from '../lib/api'
import { errorMessage } from '../lib/format'
import type { Paginated } from '../lib/types'
import { EmptyState, ErrorState, LoadingState } from '../components/States'

type QrTypeOption = { id: number; code: string; name: string }
type MasterRow = { id: number; code?: string; name?: string; native_name?: string; description?: string; status?: string; display_order?: number; updated_at?: string; qr_types?: QrTypeOption[]; [key: string]: unknown }
type MasterResult = Paginated<MasterRow>
type MasterForm = Record<string, string | number[]>

const resources: Record<string, { title: string; singular: string; description: string; fields: string[]; codeLabel: string; codePlaceholder: string; namePlaceholder: string }> = {
  ferts: { title: 'FERT (machine models)', singular: 'FERT', description: 'Machine serial numbers resolve to a FERT. Manuals are mapped to the FERTs they apply to.', fields: ['code', 'name', 'description', 'status', 'display_order'], codeLabel: 'FERT number', codePlaceholder: 'e.g. 8000001', namePlaceholder: 'e.g. Bull Smart Backhoe Loader' },
  languages: { title: 'Languages', singular: 'language', description: 'Manage the languages available for the customer manual portal.', fields: ['code', 'name', 'native_name', 'status', 'display_order'], codeLabel: 'Code', codePlaceholder: 'e.g. ta', namePlaceholder: 'e.g. Tamil' },
  'manual-types': { title: 'Manual types', singular: 'manual type', description: 'Define the categories of manuals that can be published.', fields: ['code', 'name', 'status', 'display_order'], codeLabel: 'Code', codePlaceholder: 'e.g. operator', namePlaceholder: 'e.g. Operator Manual' },
  'qr-types': { title: 'QR types', singular: 'QR type', description: 'Categorise the QR access labels you generate.', fields: ['code', 'name', 'status', 'display_order'], codeLabel: 'Code', codePlaceholder: 'e.g. domestic', namePlaceholder: 'e.g. Domestic' },
}

export function AdminMastersPage() {
  const { resource = 'ferts' } = useParams()
  const key = resources[resource] ? resource : 'ferts'
  const config = resources[key]
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState('')
  const [exporting, setExporting] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing] = useState<MasterRow | null>(null)
  const [error, setError] = useState('')
  const [qrFilter, setQrFilter] = useState('')
  const isFert = key === 'ferts'
  const query = useQuery({ queryKey: ['admin-master', key, search, page, isFert ? qrFilter : ''], queryFn: () => apiFetch<MasterResult>(`/admin/${key}?per_page=50&page=${page}&q=${encodeURIComponent(search)}${isFert && qrFilter ? `&qr_type_id=${qrFilter}` : ''}`) })
  const qrTypes = useQuery({ queryKey: ['qr-types', 'fert-mapping'], queryFn: () => apiFetch<Paginated<QrTypeOption>>('/admin/qr-types?per_page=100'), enabled: isFert })
  const save = useMutation({ mutationFn: ({ id, body }: { id?: number; body: MasterForm }) => apiFetch(`/admin/${key}${id ? `/${id}` : ''}`, jsonBody(body, id ? 'PUT' : 'POST')), onSuccess: () => { setShowModal(false); setEditing(null); setError(''); void queryClient.invalidateQueries({ queryKey: ['admin-master', key] }) }, onError: (reason) => setError(errorMessage(reason)) })
  const importFile = useMutation({ mutationFn: (file: File) => { const body = new FormData(); body.append('file', file); return apiFetch<{ data: { created: number; updated: number; skipped: number; total: number } }>('/admin/ferts/import', { method: 'POST', body }) }, onSuccess: (result) => { setError(''); setNotice(`Imported ${result.data.total} rows: ${result.data.created} new, ${result.data.updated} updated, ${result.data.skipped} skipped.`); void queryClient.invalidateQueries({ queryKey: ['admin-master', key] }) }, onError: (reason) => { setNotice(''); setError(errorMessage(reason)) } })
  const remove = useMutation({ mutationFn: (id: number) => apiFetch(`/admin/${key}/${id}`, { method: 'DELETE' }), onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin-master', key] }), onError: (reason) => setError(errorMessage(reason)) })

  async function exportFerts() {
    setExporting(true)
    setError('')
    try {
      const response = await fetch(`${apiUrl}/admin/ferts/export?q=${encodeURIComponent(search)}`, { headers: { Authorization: `Bearer ${getAdminToken()}`, Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } })
      if (!response.ok) throw new Error(`Export failed (HTTP ${response.status}).`)
      const url = URL.createObjectURL(await response.blob())
      const link = document.createElement('a')
      link.href = url
      link.download = `fert-master-${new Date().toISOString().slice(0, 10)}.xlsx`
      link.click()
      URL.revokeObjectURL(url)
    } catch (reason) {
      setError(errorMessage(reason))
    } finally {
      setExporting(false)
    }
  }

  function openCreate() { setEditing(null); setError(''); setShowModal(true) }
  function openEdit(row: MasterRow) { setEditing(row); setError(''); setShowModal(true) }

  return <div className="admin-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> MASTER DATA</span><h1>{config.title}</h1><p>{config.description}</p></div><div className="page-actions">{key === 'ferts' && <button type="button" className="button button-ghost" onClick={() => void exportFerts()} disabled={exporting}>{exporting ? <LoaderCircle className="spin" size={17} /> : <Download size={17} />} Export Excel</button>}{key === 'ferts' && <label className="button button-ghost">{importFile.isPending ? <LoaderCircle className="spin" size={17} /> : <FileUp size={17} />} Import JSON / CSV<input type="file" accept=".json,.csv,application/json,text/csv" hidden onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ''; if (file) importFile.mutate(file) }} /></label>}<button className="button button-primary" onClick={openCreate}><Plus size={18} /> Add {config.singular}</button></div></div><nav className="master-tabs">{Object.entries(resources).map(([tab, item]) => <NavLink key={tab} to={`/admin/masters/${tab}`} onClick={() => { setPage(1); setSearch(''); setNotice(''); setQrFilter('') }} className={({ isActive }) => `master-tab${isActive ? ' active' : ''}`}>{item.title}</NavLink>)}</nav><div className="master-context"><div className="master-context-icon"><Database size={19} /></div><div><strong>Reference data</strong><span>Changes apply across machine lookup, manual matching and customer access.</span></div><span className="master-count">{query.data?.total ?? 0} records</span></div><div className="toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder={`Search ${config.title.toLowerCase()}`} /></div>{isFert && <select value={qrFilter} onChange={(event) => { setQrFilter(event.target.value); setPage(1) }} aria-label="Filter by QR type"><option value="">All QR types</option><option value="none">Not mapped to a QR type</option>{qrTypes.data?.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}</div>{key === 'ferts' && <p className="muted-cell">A machine opens only from QR codes of the types its FERT is mapped to; a FERT with no QR type opens from every QR code. Import accepts a CSV with FERT, DESCRIPTION and an optional QR_TYPE column (e.g. domestic, or domestic;export, or all), or the machine JSON. New FERTs are added automatically by the SAP sync.</p>}{notice && <div className="inline-alert success-alert">{notice}</div>}{error && <div className="inline-alert error-alert">{error}</div>}{query.isLoading ? <LoadingState label={`Loading ${config.title.toLowerCase()}…`} /> : query.isError ? <ErrorState message="Unable to load master data." onRetry={() => void query.refetch()} /> : query.data?.data.length === 0 ? <EmptyState title={`No ${config.title.toLowerCase()} yet`} message="Add your first record to configure the portal." action={<button className="button button-primary" onClick={openCreate}><Plus size={17} /> Add {config.singular}</button>} /> : <div className="table-card"><table className="data-table"><thead><tr><th>{config.codeLabel}</th><th>Name</th>{isFert && <th>QR types</th>}<th>Status</th><th>Order</th><th>Updated</th><th /></tr></thead><tbody>{query.data!.data.map((row) => <tr key={row.id}><td><code className="code-label">{row.code}</code></td><td><div className="table-title"><div className="table-icon small"><Check size={15} /></div><div><strong>{row.name}</strong>{row.native_name && <span>{row.native_name}</span>}</div></div></td>{isFert && <td>{row.qr_types?.length ? <div className="qr-type-tags">{row.qr_types.map((type) => <span key={type.id} className="qr-type-tag">{type.name}</span>)}</div> : <span className="muted-cell">All QR types</span>}</td>}<td><span className={`status-pill ${row.status}`}>{row.status}</span></td><td className="muted-cell">{row.display_order ?? '—'}</td><td className="muted-cell">{row.updated_at ? new Date(row.updated_at).toLocaleDateString() : '—'}</td><td><div className="row-actions"><button className="icon-button" onClick={() => openEdit(row)} aria-label="Edit"><Edit3 size={16} /></button><button className="icon-button danger" onClick={() => { if (window.confirm('Delete this record?')) remove.mutate(row.id) }} aria-label="Delete"><Trash2 size={16} /></button></div></td></tr>)}</tbody></table><div className="table-footer"><span>{query.data!.total} records · page {query.data!.current_page} of {query.data!.last_page}</span><div className="pagination"><button onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1}>Previous</button><button className="current">{query.data!.current_page}</button><button onClick={() => setPage((current) => current + 1)} disabled={page >= query.data!.last_page}>Next</button></div></div></div>}{showModal && <MasterModal config={config} key={`${key}-${editing?.id ?? 'new'}`} row={editing} qrTypes={isFert ? qrTypes.data?.data ?? [] : null} error={error} saving={save.isPending} onClose={() => setShowModal(false)} onSave={(body) => save.mutate({ id: editing?.id, body })} />}</div>
}

function MasterModal({ config, row, qrTypes, error, saving, onClose, onSave }: { config: typeof resources[string]; row: MasterRow | null; qrTypes: QrTypeOption[] | null; error: string; saving: boolean; onClose: () => void; onSave: (body: MasterForm) => void }) {
  const [form, setForm] = useState<Record<string, string>>(() => row ? Object.fromEntries(config.fields.map((field) => [field, String(row[field] ?? '')])) : { code: '', name: '', description: '', status: 'active', display_order: '' })
  const [qrTypeIds, setQrTypeIds] = useState<number[]>(() => row?.qr_types?.map((type) => type.id) ?? [])
  function set(field: string, value: string) { setForm((current) => ({ ...current, [field]: value })) }
  function toggleQrType(id: number, checked: boolean) { setQrTypeIds((current) => checked ? [...current, id] : current.filter((item) => item !== id)) }
  function submit(event: FormEvent) { event.preventDefault(); const body: MasterForm = Object.fromEntries(config.fields.map((field) => [field, form[field] ?? ''])); if (qrTypes) body.qr_type_ids = qrTypeIds; onSave(body) }
  return <div className="modal-backdrop"><div className="modal-card"><div className="modal-header"><div><span className="eyebrow"><span className="eyebrow-line" /> {row ? 'EDIT RECORD' : 'NEW RECORD'}</span><h2>{row ? 'Update' : 'Add'} {config.singular}</h2><p>Keep this reference data accurate for reliable manual matching.</p></div><button className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><form className="modal-form" onSubmit={submit}><div className="form-grid"><label>{config.codeLabel}<input value={form.code ?? ''} onChange={(event) => set('code', event.target.value)} placeholder={config.codePlaceholder} required /></label><label>Name<input value={form.name ?? ''} onChange={(event) => set('name', event.target.value)} placeholder={config.namePlaceholder} required /></label>{config.fields.includes('native_name') && <label>Native name<input value={form.native_name ?? ''} onChange={(event) => set('native_name', event.target.value)} placeholder="Local language name" /></label>}{config.fields.includes('display_order') && <label>Display order<input type="number" min="0" value={form.display_order ?? ''} onChange={(event) => set('display_order', event.target.value)} placeholder="0" /></label>}{config.fields.includes('description') && <label className="span-2">Description<textarea value={form.description ?? ''} onChange={(event) => set('description', event.target.value)} rows={3} /></label>}{config.fields.includes('status') && <label>Status<select value={form.status ?? 'active'} onChange={(event) => set('status', event.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option></select></label>}</div>{qrTypes && <div className="form-section"><span className="form-label">QR types · machines of this FERT open only from these QR codes</span><div className="check-grid">{qrTypes.map((type) => <label className="check-option" key={type.id}><input type="checkbox" checked={qrTypeIds.includes(type.id)} onChange={(event) => toggleQrType(type.id, event.target.checked)} /><span>{type.name}</span></label>)}</div><small className="muted-cell">{qrTypeIds.length === 0 ? 'None selected: this FERT opens from every QR code.' : `${qrTypeIds.length} selected.`}</small></div>}{error && <div className="form-error">{error}</div>}<div className="modal-actions"><button type="button" className="button button-ghost" onClick={onClose}>Cancel</button><button className="button button-primary" disabled={saving}>{saving && <LoaderCircle className="spin" size={17} />} Save changes</button></div></form></div></div>
}
