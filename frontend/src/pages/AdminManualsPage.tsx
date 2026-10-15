import { Archive, BookOpen, CheckCircle2, FileUp, History, Link2, LoaderCircle, Plus, Search, UploadCloud, X } from 'lucide-react'
import type { FormEvent } from 'react'
import { Fragment, useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage, formatDate } from '../lib/format'
import type { AdminManualDetail, AdminManualVersion, Paginated } from '../lib/types'
import { EmptyState, ErrorState, LoadingState } from '../components/States'

type ManualRow = { id: number; title: string; status: string; ferts?: MasterOption[]; language?: { name: string }; manual_type?: { name: string }; updated_at?: string; versions?: AdminManualVersion[] }
type MasterOption = { id: number; name: string; code: string }
type MasterList = { data: MasterOption[] }

export function AdminManualsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [showUpload, setShowUpload] = useState(false)
  const [versionFor, setVersionFor] = useState<ManualRow | null>(null)
  const [mappingFor, setMappingFor] = useState<ManualRow | null>(null)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [error, setError] = useState('')
  const manuals = useQuery({ queryKey: ['admin-manuals', search, status], queryFn: () => apiFetch<Paginated<ManualRow>>(`/admin/manuals?per_page=50&q=${encodeURIComponent(search)}${status ? `&status=${status}` : ''}`) })
  const publish = useMutation({ mutationFn: (id: number) => apiFetch(`/admin/manuals/${id}/publish`, { method: 'POST' }), onSuccess: () => { setError(''); void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }) }, onError: (reason) => setError(errorMessage(reason)) })
  const unpublish = useMutation({ mutationFn: (id: number) => apiFetch(`/admin/manuals/${id}/unpublish`, { method: 'POST' }), onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }), onError: (reason) => setError(errorMessage(reason)) })
  const detail = useQuery({ queryKey: ['admin-manual', expanded], queryFn: () => apiFetch<{ data: AdminManualDetail }>(`/admin/manuals/${expanded}`), enabled: expanded !== null })
  const archiveVersion = useMutation({ mutationFn: (input: { manualId: number; versionId: number }) => apiFetch(`/admin/manuals/${input.manualId}/versions/${input.versionId}/archive`, { method: 'POST' }), onSuccess: () => { setError(''); void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }); void queryClient.invalidateQueries({ queryKey: ['admin-manual'] }) }, onError: (reason) => setError(errorMessage(reason)) })

  return <div className="admin-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> CONTENT LIBRARY</span><h1>User manuals</h1><p>Upload, version and publish approved vehicle documentation.</p></div><button className="button button-primary" onClick={() => setShowUpload(true)}><Plus size={18} /> Upload manual</button></div><div className="toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search manuals" /></div><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option><option value="published">Published</option><option value="draft">Draft</option><option value="pending_review">Pending review</option><option value="unpublished">Unpublished</option><option value="archived">Archived</option></select><span className="toolbar-count">{manuals.data?.total ?? 0} manuals</span></div>{error && <div className="inline-alert error-alert">{error}</div>}{manuals.isLoading ? <LoadingState label="Loading manuals…" /> : manuals.isError ? <ErrorState message="Unable to load manuals." onRetry={() => void manuals.refetch()} /> : manuals.data?.data.length === 0 ? <EmptyState title="No manuals found" message="Upload the first approved manual to start your library." action={<button className="button button-primary" onClick={() => setShowUpload(true)}><UploadCloud size={17} /> Upload manual</button>} /> : <div className="table-card"><table className="data-table"><thead><tr><th>Manual</th><th>Machine models (FERT)</th><th>Version</th><th>Status</th><th>Updated</th><th /></tr></thead><tbody>{manuals.data!.data.map((manual) => { const latest = manual.versions?.[0]; return <Fragment key={manual.id}><tr><td><div className="table-title"><div className="table-icon"><BookOpen size={17} /></div><div><strong>{manual.title}</strong><span>{manual.manual_type?.name}</span></div></div></td><td><div className="table-stack"><span>{fertSummary(manual.ferts)}</span><span>{manual.language?.name}</span></div></td><td><span className="version-stack"><span className="version-label">v{latest?.version ?? '—'}</span>{(manual.versions?.length ?? 0) > 1 && <span className="version-count">{manual.versions!.length} versions</span>}</span></td><td><span className={`status-pill ${manual.status}`}>{manual.status.replace('_', ' ')}</span></td><td className="muted-cell">{formatDate(manual.updated_at)}</td><td><div className="row-actions">{manual.status === 'published' ? <button className="table-action" onClick={() => unpublish.mutate(manual.id)} disabled={unpublish.isPending}>Unpublish</button> : <button className="table-action success" onClick={() => publish.mutate(manual.id)} disabled={publish.isPending}><CheckCircle2 size={14} /> Publish</button>}<button className="table-action" onClick={() => setVersionFor(manual)}><UploadCloud size={14} /> Add version</button><button className="icon-button" onClick={() => setExpanded(expanded === manual.id ? null : manual.id)} aria-label="Version history" aria-expanded={expanded === manual.id}><History size={18} /></button><button className="table-action" onClick={() => setMappingFor(manual)}><Link2 size={14} /> Map FERTs</button></div></td></tr>{expanded === manual.id && <tr className="version-history-row"><td colSpan={6}>{detail.isLoading ? <div className="version-history-loading">Loading version history…</div> : detail.isError ? <div className="form-error">Unable to load version history.</div> : <div className="version-history"><div className="version-history-head"><span>Version history</span><small>Newest first</small></div>{detail.data?.data.versions.length === 0 ? <div className="version-history-empty">No versions yet.</div> : detail.data?.data.versions.map((item) => <div key={item.id} className="version-history-item"><div className="version-history-name"><strong>v{item.version}</strong><span className={`status-pill ${item.status}`}>{item.status.replace('_', ' ')}</span></div><small>{item.published_at ? `Published ${formatDate(item.published_at)}` : 'Not published'}{item.effective_date ? ` · Effective ${formatDate(item.effective_date)}` : ''}{item.page_count ? ` · ${item.page_count} pages` : ''}</small>{item.status !== 'archived' && <button className="table-action danger" onClick={() => archiveVersion.mutate({ manualId: manual.id, versionId: item.id })} disabled={archiveVersion.isPending}><Archive size={14} /> Archive</button>}</div>)}</div>}</td></tr>}</Fragment> })}</tbody></table><div className="table-footer"><span>Showing {manuals.data!.data.length} of {manuals.data!.total}</span><div className="pagination"><button disabled>Previous</button><button className="current">1</button><button disabled>Next</button></div></div></div>}{showUpload && <UploadModal onClose={() => setShowUpload(false)} onSaved={() => { setShowUpload(false); void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }) }} />}{versionFor && <VersionModal manual={versionFor} onClose={() => setVersionFor(null)} onSaved={() => { setVersionFor(null); void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }) }} />}{mappingFor && <FertMappingModal manual={mappingFor} onClose={() => setMappingFor(null)} onSaved={() => { setMappingFor(null); void queryClient.invalidateQueries({ queryKey: ['admin-manuals'] }) }} />}</div>
}

function UploadModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [form, setForm] = useState({ title: '', manual_type_id: '', language_id: '', version: '', effective_date: '', description: '', status: 'draft' })
  const [ferts, setFerts] = useState<MasterOption[]>([])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const types = useQuery({ queryKey: ['manual-types'], queryFn: () => apiFetch<MasterList>('/admin/manual-types?per_page=100') })
  const languages = useQuery({ queryKey: ['languages'], queryFn: () => apiFetch<MasterList>('/admin/languages?per_page=100') })

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!file) return setError('Choose a PDF file.')
    if (ferts.length === 0) return setError('Select at least one FERT.')
    setSaving(true)
    setError('')
    const body = new FormData()
    Object.entries(form).forEach(([key, value]) => body.append(key, value))
    ferts.forEach((fert) => body.append('fert_ids[]', String(fert.id)))
    body.append('pdf', file)
    try {
      await apiFetch('/admin/manuals', { method: 'POST', body })
      onSaved()
    } catch (reason) {
      setError(errorMessage(reason))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><div className="modal-card upload-modal"><div className="modal-header"><div><span className="eyebrow"><span className="eyebrow-line" /> NEW CONTENT</span><h2>Upload a manual</h2><p>Files are stored privately and delivered through the protected viewer.</p></div><button className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><form onSubmit={submit} className="modal-form"><div className="form-grid"><label className="span-2">Manual title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} placeholder="e.g. Bull Smart Backhoe Loader Operator Manual" required /></label><label>Manual type<select value={form.manual_type_id} onChange={(event) => setForm({ ...form, manual_type_id: event.target.value })} required><option value="">Select type</option>{types.data?.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Version<input value={form.version} onChange={(event) => setForm({ ...form, version: event.target.value })} placeholder="1.0" required /></label><label>Language<select value={form.language_id} onChange={(event) => setForm({ ...form, language_id: event.target.value })} required><option value="">Select language</option>{languages.data?.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Effective date<input type="date" value={form.effective_date} onChange={(event) => setForm({ ...form, effective_date: event.target.value })} /></label><label>Initial status<select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}><option value="draft">Save as draft</option><option value="pending_review">Pending review</option><option value="published">Publish immediately</option></select></label><label className="span-2">Description<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="What does this version include?" rows={3} /></label></div><FertPicker value={ferts} onChange={setFerts} /><label className="file-drop"><input type="file" accept="application/pdf,.pdf" onChange={(event) => setFile(event.target.files?.[0] ?? null)} /><FileUp size={25} /><strong>{file ? file.name : 'Choose a PDF manual'}</strong><span>{file ? `${(file.size / 1024 / 1024).toFixed(1)} MB selected` : 'PDF only · maximum 50 MB'}</span></label>{error && <div className="form-error">{error}</div>}<div className="modal-actions"><button type="button" className="button button-ghost" onClick={onClose}>Cancel</button><button className="button button-primary" disabled={saving}>{saving ? <LoaderCircle className="spin" size={17} /> : <UploadCloud size={17} />} Save manual</button></div></form></div></div>
}

function VersionModal({ manual, onClose, onSaved }: { manual: ManualRow; onClose: () => void; onSaved: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [form, setForm] = useState({ version: '', effective_date: '', description: '', status: 'draft' })
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const existing = manual.versions?.map((item) => item.version) ?? []

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!file) return setError('Choose a PDF file.')
    setSaving(true)
    setError('')
    const body = new FormData()
    Object.entries(form).forEach(([key, value]) => body.append(key, value))
    body.append('pdf', file)
    try {
      await apiFetch(`/admin/manuals/${manual.id}/versions`, { method: 'POST', body })
      onSaved()
    } catch (reason) {
      setError(errorMessage(reason))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><div className="modal-card upload-modal"><div className="modal-header"><div><span className="eyebrow"><span className="eyebrow-line" /> NEW VERSION</span><h2>Add a version</h2><p>Earlier versions stay available to customers and remain listed below the latest.</p></div><button className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><form onSubmit={submit} className="modal-form"><div className="context-note"><BookOpen size={16} /><div><strong>{manual.title}</strong><span>{manual.manual_type?.name} · {fertSummary(manual.ferts)} · {manual.language?.name}</span></div></div><div className="form-grid"><label>Version<input value={form.version} onChange={(event) => setForm({ ...form, version: event.target.value })} placeholder="4.0" list="existing-manual-versions" required /></label><label>Effective date<input type="date" value={form.effective_date} onChange={(event) => setForm({ ...form, effective_date: event.target.value })} /></label><label className="span-2">Initial status<select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}><option value="draft">Save as draft</option><option value="pending_review">Pending review</option><option value="published">Publish immediately</option></select></label><label className="span-2">Description<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="What changed in this version?" rows={3} /></label></div><label className="file-drop"><input type="file" accept="application/pdf,.pdf" onChange={(event) => setFile(event.target.files?.[0] ?? null)} /><FileUp size={25} /><strong>{file ? file.name : 'Choose a PDF manual'}</strong><span>{file ? `${(file.size / 1024 / 1024).toFixed(1)} MB selected` : 'PDF only · maximum 50 MB'}</span></label>{error && <div className="form-error">{error}</div>}<div className="modal-actions"><button type="button" className="button button-ghost" onClick={onClose}>Cancel</button><button className="button button-primary" disabled={saving}>{saving ? <LoaderCircle className="spin" size={17} /> : <UploadCloud size={17} />} Add version</button></div></form><datalist id="existing-manual-versions">{existing.map((value) => <option key={value} value={value} />)}</datalist></div></div>
}

function fertSummary(ferts?: MasterOption[]): string {
  if (!ferts?.length) return 'No FERT mapped'
  return ferts.length <= 2 ? ferts.map((fert) => fert.code).join(', ') : `${ferts[0].code} +${ferts.length - 1} more`
}

function FertPicker({ value, onChange }: { value: MasterOption[]; onChange: (ferts: MasterOption[]) => void }) {
  const [filter, setFilter] = useState('')
  const [term, setTerm] = useState('')
  useEffect(() => {
    const timer = window.setTimeout(() => setTerm(filter.trim()), 250)
    return () => window.clearTimeout(timer)
  }, [filter])
  const results = useQuery({ queryKey: ['fert-search', term], queryFn: () => apiFetch<Paginated<MasterOption>>(`/admin/ferts?per_page=20&status=active&q=${encodeURIComponent(term)}`) })
  const selectedIds = new Set(value.map((fert) => fert.id))
  const options = (results.data?.data ?? []).filter((item) => !selectedIds.has(item.id))
  const total = results.data?.total ?? 0
  return <div className="form-section"><span className="form-label">Machine models (FERT) · {value.length} selected</span>{value.length > 0 && <div className="check-grid fert-selected">{value.map((item) => <button type="button" className="check-option fert-chip" key={item.id} onClick={() => onChange(value.filter((fert) => fert.id !== item.id))} aria-label={`Remove ${item.code}`}><strong>{item.code}</strong> {item.name}<X size={12} /></button>)}</div>}<div className="search-box"><Search size={16} /><input value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Search FERT number or name" /></div><div className="check-grid">{results.isLoading ? <span className="muted-cell">Searching FERTs…</span> : options.length === 0 ? <span className="muted-cell">{term ? 'No FERTs match.' : 'No more FERTs. Add or import them under Master data.'}</span> : options.map((item) => <label className="check-option" key={item.id}><input type="checkbox" checked={false} onChange={() => onChange([...value, item])} /><span><strong>{item.code}</strong> {item.name}</span></label>)}</div>{total > 20 && <small className="muted-cell">Showing the first 20 of {total} matches. Type more of the FERT number to narrow the list.</small>}</div>
}

function FertMappingModal({ manual, onClose, onSaved }: { manual: ManualRow; onClose: () => void; onSaved: () => void }) {
  const [ferts, setFerts] = useState<MasterOption[]>(() => manual.ferts ?? [])
  const [error, setError] = useState('')
  const save = useMutation({ mutationFn: () => apiFetch(`/admin/manuals/${manual.id}`, jsonBody({ fert_ids: ferts.map((fert) => fert.id) }, 'PUT')), onSuccess: onSaved, onError: (reason) => setError(errorMessage(reason)) })
  function submit(event: FormEvent) {
    event.preventDefault()
    if (ferts.length === 0) return setError('Select at least one FERT.')
    setError('')
    save.mutate()
  }
  return <div className="modal-backdrop"><div className="modal-card upload-modal"><div className="modal-header"><div><span className="eyebrow"><span className="eyebrow-line" /> FERT MAPPING</span><h2>Map machine models</h2><p>Customers whose machine serial number resolves to one of these FERTs will see this manual.</p></div><button className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><form onSubmit={submit} className="modal-form"><div className="context-note"><BookOpen size={16} /><div><strong>{manual.title}</strong><span>{manual.manual_type?.name} · {manual.language?.name}</span></div></div><FertPicker value={ferts} onChange={setFerts} />{error && <div className="form-error">{error}</div>}<div className="modal-actions"><button type="button" className="button button-ghost" onClick={onClose}>Cancel</button><button className="button button-primary" disabled={save.isPending}>{save.isPending ? <LoaderCircle className="spin" size={17} /> : <CheckCircle2 size={17} />} Save mapping</button></div></form></div></div>
}
