import { CheckCircle2, LoaderCircle, Save, Settings2, ShieldCheck, SlidersHorizontal } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage } from '../lib/format'
import { ErrorState, LoadingState } from '../components/States'

type Settings = { manual_max_file_size: number; support_contact: string }
type SettingsResult = { data: Settings }

export function AdminSettingsPage() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['admin-settings'], queryFn: () => apiFetch<SettingsResult>('/admin/settings') })
  if (query.isLoading) return <LoadingState label="Loading settings…" />
  if (query.isError || !query.data) return <ErrorState message="Unable to load portal settings." onRetry={() => void query.refetch()} />
  return <SettingsForm key={JSON.stringify(query.data.data)} initial={query.data.data} onSaved={() => queryClient.invalidateQueries({ queryKey: ['admin-settings'] })} />
}

function SettingsForm({ initial, onSaved }: { initial: Settings; onSaved: () => void }) {
  const [form, setForm] = useState(initial)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')
  const save = useMutation({ mutationFn: () => apiFetch('/admin/settings', jsonBody(form, 'PUT')), onSuccess: () => { setSaved(true); setError(''); onSaved(); window.setTimeout(() => setSaved(false), 3000) }, onError: (reason) => setError(errorMessage(reason)) })
  function set(key: keyof Settings, value: string | number) { setForm((current) => ({ ...current, [key]: value })); setSaved(false) }
  function submit(event: FormEvent) { event.preventDefault(); save.mutate() }
  return <div className="admin-page settings-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> PLATFORM CONFIGURATION</span><h1>Settings</h1><p>Configure document limits and support preferences.</p></div><div className="settings-status">{saved ? <><CheckCircle2 size={15} /> Changes saved</> : <><ShieldCheck size={15} /> Secure defaults active</>}</div></div><form onSubmit={submit} className="settings-layout"><section className="panel settings-panel"><div className="settings-panel-heading"><div className="settings-heading-icon amber"><SlidersHorizontal size={19} /></div><div><h2>Document delivery</h2><p>Keep uploaded files private and within a predictable storage limit.</p></div></div><div className="settings-form-grid"><label>Maximum manual file size<div className="input-suffix"><input type="number" min="1" step="0.1" value={(form.manual_max_file_size / 1024 / 1024).toFixed(1)} onChange={(event) => set('manual_max_file_size', Math.round(Number(event.target.value) * 1024 * 1024))} /><span>MB</span></div></label><label>Support contact<input value={form.support_contact} onChange={(event) => set('support_contact', event.target.value)} placeholder="support@example.com or +91 98765 43210" /></label></div><div className="delivery-note"><ShieldCheck size={18} /><div><strong>Private storage enabled</strong><span>Uploaded PDFs are stored outside the public directory. Customers receive watermarked page images only after session and manual authorization checks.</span></div></div></section><section className="panel settings-panel security-panel"><div className="settings-panel-heading"><div className="settings-heading-icon purple"><Settings2 size={19} /></div><div><h2>Security controls</h2><p>These protections are active for every public customer request.</p></div></div><div className="security-check-list"><div><CheckCircle2 size={17} /><span>Opaque QR tokens are stored hashed and encrypted for authorized retrieval.</span></div><div><CheckCircle2 size={17} /><span>Customer sessions are random, hashed, HttpOnly cookies with configurable expiry.</span></div><div><CheckCircle2 size={17} /><span>Every manual page request is checked against the machine’s FERT and the chosen language.</span></div><div><CheckCircle2 size={17} /><span>Machine lookups and session creation are rate limited per visitor.</span></div></div></section>{error && <div className="form-error settings-error">{error}</div>}<div className="settings-save"><span>Changes apply to new requests and are audited.</span><button className="button button-primary" disabled={save.isPending}>{save.isPending ? <LoaderCircle className="spin" size={17} /> : <Save size={17} />} Save settings</button></div></form></div>
}
