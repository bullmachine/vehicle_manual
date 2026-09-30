import { ArrowLeft, ArrowRight, BookOpen, Check, ChevronDown, Clock3, KeyRound, Languages, LoaderCircle, LockKeyhole, Phone, ShieldCheck, UserRound } from 'lucide-react'
import type { ReactNode } from 'react'
import type { FormEvent } from 'react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage, formatDate } from '../lib/format'
import type { CustomerSession, ManualCard, Option, QrContext } from '../lib/types'
import { BrandMark } from '../components/BrandMark'
import { PhoneField } from '../components/PhoneField'
import { EmptyState, LoadingState } from '../components/States'

const steps = ['Vehicle', 'Details', 'Verify', 'Manuals']

export function CustomerPortalPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()
  const [context, setContext] = useState<QrContext | null>(null)
  const [loadedToken, setLoadedToken] = useState('')
  const [loadError, setLoadError] = useState('')
  const [step, setStep] = useState(0)
  const [selection, setSelection] = useState({ vehicle_variant_id: 0, engine_id: 0, language_id: 0 })
  const [engines, setEngines] = useState<Option[]>([])
  const [name, setName] = useState('')
  const [mobile, setMobile] = useState('')
  const [otp, setOtp] = useState('')
  const [debugOtp, setDebugOtp] = useState('')
  const [resendIn, setResendIn] = useState(0)
  const [submitting, setSubmitting] = useState(false)
  const [actionError, setActionError] = useState('')
  const [session, setSession] = useState<CustomerSession | null>(null)
  const [manuals, setManuals] = useState<ManualCard[]>([])
  const [manualsLoading, setManualsLoading] = useState(false)
  const loading = loadedToken !== token

  useEffect(() => {
    let active = true
    apiFetch<QrContext>(`/public/qr/${encodeURIComponent(token)}`)
      .then((data) => {
        if (!active) return
        setLoadError('')
        setContext(data)
        setSelection({
          vehicle_variant_id: data.selection.vehicle_variant_id ?? 0,
          engine_id: data.selection.engine_id ?? 0,
          language_id: data.selection.language_id ?? data.options.languages[0]?.id ?? 0,
        })
        setEngines(data.options.engines)
      })
      .catch((error) => active && setLoadError(errorMessage(error)))
      .finally(() => active && setLoadedToken(token))
    return () => { active = false }
  }, [token])

  useEffect(() => {
    if (!resendIn) return
    const timer = window.setInterval(() => setResendIn((value) => Math.max(0, value - 1)), 1000)
    return () => window.clearInterval(timer)
  }, [resendIn])

  useEffect(() => {
    if (step !== 3 || !session) return
    apiFetch<{ data: ManualCard[] }>('/customer/manuals')
      .then((data) => setManuals(data.data))
      .catch((error) => setActionError(errorMessage(error)))
      .finally(() => setManualsLoading(false))
  }, [session, step])

  const selectedVehicle = useMemo(() => context?.options.vehicle_variants.find((item) => item.id === selection.vehicle_variant_id), [context, selection.vehicle_variant_id])
  const selectedEngine = useMemo(() => engines.find((item) => item.id === selection.engine_id), [engines, selection.engine_id])
  const selectedLanguage = useMemo(() => context?.options.languages.find((item) => item.id === selection.language_id), [context, selection.language_id])

  async function loadEngines(vehicleId: number) {
    if (!vehicleId) return
    try {
      const data = await apiFetch<Option[]>(`/public/engines?vehicle_variant_id=${vehicleId}`)
      setEngines(data)
      setSelection((current) => ({ ...current, engine_id: data[0]?.id ?? 0 }))
    } catch (error) {
      setActionError(errorMessage(error))
    }
  }

  function continueVehicle() {
    setActionError('')
    if (context?.selection.vehicle_required && !selection.vehicle_variant_id) return setActionError('Select your vehicle variant to continue.')
    if (context?.selection.engine_required && !selection.engine_id) return setActionError('Select an engine type to continue.')
    if (!selection.language_id) return setActionError('Select a language to continue.')
    setStep(1)
  }

  async function submitDetails(event: FormEvent) {
    event.preventDefault()
    setActionError('')
    if (name.trim().length < 2) return setActionError('Enter your name.')
    if (mobile.replace(/\D/g, '').length < 6) return setActionError('Enter a valid mobile number.')
    setSubmitting(true)
    const payload = { qr_token: token, name: name.trim(), mobile: mobile.trim(), ...selection }
    try {
      if (context?.otp_enabled) {
        const result = await apiFetch<{ debug_otp?: string; resend_in: number }>('/public/customer/request-otp', jsonBody(payload))
        setDebugOtp(result.debug_otp ?? '')
        setResendIn(result.resend_in)
        setStep(2)
      } else {
        const result = await apiFetch<{ data: CustomerSession }>('/public/customer/session', jsonBody(payload))
        setManualsLoading(true)
        setSession(result.data)
        setStep(3)
      }
    } catch (error) {
      setActionError(errorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  async function verifyOtp(event: FormEvent) {
    event.preventDefault()
    setActionError('')
    if (!new RegExp(`^\\d{${otpLength}}$`).test(otp)) return setActionError(`Enter the ${otpLength}-digit OTP.`)
    setSubmitting(true)
    try {
      const result = await apiFetch<{ data: CustomerSession }>('/public/customer/verify-otp', jsonBody({ qr_token: token, name: name.trim(), mobile: mobile.trim(), otp, ...selection }))
      setSession(result.data)
      setStep(3)
    } catch (error) {
      setActionError(errorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  async function resendOtp() {
    if (resendIn > 0) return
    setSubmitting(true)
    try {
      const result = await apiFetch<{ debug_otp?: string; resend_in: number }>('/public/customer/request-otp', jsonBody({ qr_token: token, name: name.trim(), mobile: mobile.trim(), ...selection }))
      setDebugOtp(result.debug_otp ?? '')
      setResendIn(result.resend_in)
      setActionError('')
    } catch (error) {
      setActionError(errorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <div className="customer-app"><div className="shell-width"><LoadingState label="Finding your vehicle manual…" /></div></div>
  if (loadError || !context) return <div className="customer-app"><div className="shell-width narrow-state"><EmptyState title="QR code not found" message={loadError || 'This QR code is invalid or has been deactivated.'} action={<button className="button button-primary" onClick={() => navigate('/')}>Return to portal</button>} /></div></div>
  const otpLength = context.otp_length ?? 6

  return (
    <div className="customer-app portal-page">
      <header className="customer-header shell-width"><BrandMark /><div className="header-secure"><LockKeyhole size={15} /> Secure access</div></header>
      <main className="shell-width portal-main">
        <div className="portal-heading"><div><div className="eyebrow"><span className="eyebrow-line" /> VEHICLE MANUAL PORTAL</div><h1>{step === 3 ? 'Your user manuals' : 'Let’s get you connected.'}</h1><p>{step === 3 ? 'Choose a manual to read in the protected viewer.' : 'Confirm your vehicle details and we’ll prepare the right manual for you.'}</p></div><div className="portal-reference"><span>{context.qr.type?.name ?? 'Manual access'}</span><strong>{context.qr.reference}</strong></div></div>
        <StepIndicator current={step} />
        {actionError && <div className="inline-alert error-alert"><ShieldCheck size={17} />{actionError}</div>}
        {step === 0 && <section className="portal-card selection-card"><div className="card-heading"><div className="card-icon"><BookOpen size={21} /></div><div><h2>Vehicle configuration</h2><p>These details determine which manuals you can access.</p></div></div><div className="selection-grid"><SelectField label="Vehicle variant" icon={<span className="field-icon">▦</span>} value={selection.vehicle_variant_id} options={context.options.vehicle_variants} placeholder="Select vehicle" disabled={!context.selection.vehicle_required} onChange={(value) => { setSelection((current) => ({ ...current, vehicle_variant_id: value, engine_id: 0 })); if (context.selection.vehicle_required) void loadEngines(value) }} /><SelectField label="Engine type" icon={<span className="field-icon">◉</span>} value={selection.engine_id} options={engines} placeholder={selection.vehicle_variant_id ? 'Select engine' : 'Select a vehicle first'} disabled={!context.selection.engine_required || !selection.vehicle_variant_id} onChange={(value) => setSelection((current) => ({ ...current, engine_id: value }))} /><SelectField label="Language" icon={<Languages size={17} />} value={selection.language_id} options={context.options.languages} placeholder="Select language" disabled={!context.selection.language_required} onChange={(value) => setSelection((current) => ({ ...current, language_id: value }))} /></div><div className="selection-summary"><div><span>Vehicle</span><strong>{selectedVehicle?.name ?? 'Select above'}</strong></div><div><span>Engine</span><strong>{selectedEngine?.name ?? 'Select above'}</strong></div><div><span>Language</span><strong>{selectedLanguage?.name ?? 'Select above'}</strong></div></div><div className="card-actions"><span className="muted-note"><ShieldCheck size={15} /> {context.qr.type ? `${context.qr.type.name} access - choose the vehicle that matches your machine.` : 'Your QR may already identify some details.'}</span><button className="button button-primary" onClick={continueVehicle}>Continue <ArrowRight size={18} /></button></div></section>}
        {step === 1 && <section className="portal-card details-card"><div className="card-heading"><div className="card-icon green-icon"><UserRound size={21} /></div><div><h2>Tell us who you are</h2><p>We use these details to protect your manual access.</p></div></div><form onSubmit={submitDetails} className="details-form"><label>Full name<input value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Raj Kumar" autoComplete="name" /></label><label>Mobile number<PhoneField value={mobile} onChange={setMobile} /></label><div className="privacy-note"><LockKeyhole size={16} /><span>Your information is used for access verification and support. It is never placed inside a QR code.</span></div><div className="card-actions"><button type="button" className="button button-ghost" onClick={() => setStep(0)}><ArrowLeft size={17} /> Back</button><button className="button button-primary" disabled={submitting}>{submitting ? <LoaderCircle className="spin" size={18} /> : <Phone size={17} />} {context.otp_enabled ? 'Send OTP' : 'Continue to manuals'}</button></div></form></section>}
        {step === 2 && <section className="portal-card otp-card"><div className="otp-illustration"><KeyRound size={31} /></div><div className="otp-copy"><h2>Verify your mobile</h2><p>We sent a {otpLength}-digit code to <strong>{mobile}</strong>. Enter it below to continue.</p>{debugOtp && <div className="debug-otp">Development OTP: <strong>{debugOtp}</strong></div>}<form onSubmit={verifyOtp}><input className="otp-input" value={otp} onChange={(event) => setOtp(event.target.value.replace(/\D/g, '').slice(0, otpLength))} inputMode="numeric" autoComplete="one-time-code" placeholder={'0'.repeat(otpLength)} aria-label={`${otpLength} digit OTP`} /><button className="button button-primary button-wide" disabled={submitting}>{submitting ? <LoaderCircle className="spin" size={18} /> : <Check size={18} />} Verify and continue</button></form><button className="resend-button" onClick={resendOtp} disabled={resendIn > 0 || submitting}>{resendIn > 0 ? `Resend OTP in ${resendIn}s` : 'Didn’t receive OTP? Resend'}</button></div><div className="otp-side"><Clock3 size={19} /><strong>Secure verification</strong><span>Your code expires shortly and can only be used once.</span></div></section>}
        {step === 3 && <section className="manuals-section">{manualsLoading ? <LoadingState label="Loading your manuals…" /> : manuals.length === 0 ? <EmptyState title="Manual not available" message="We couldn’t find a user manual for this vehicle configuration. Please contact your dealer or support team." /> : <div className="manual-grid">{manuals.map((manual) => <ManualCardItem key={manual.id} manual={manual} onOpenVersion={(versionId) => navigate(`/manual/${manual.id}${versionId ? `?version=${versionId}` : ''}`)} />)}</div>}</section>}
        {session && <div className="session-bar"><span><LockKeyhole size={14} /> Access protected for {session.customer.name}</span><button onClick={() => { setSession(null); setManuals([]); setManualsLoading(false); setStep(0); setOtp(''); setName(''); setMobile('') }}>Start again</button></div>}
      </main>
      <footer className="customer-footer shell-width"><BrandMark /><span>Protected document delivery · BULL</span><Link className="footer-links" to={`/contact?qr=${encodeURIComponent(token)}${session ? `&session=${session.id}` : ''}`}>Need help? Contact support.</Link></footer>
    </div>
  )
}

function StepIndicator({ current }: { current: number }) {
  return <div className="step-indicator">{steps.map((label, index) => <div key={label} className={`step-item${index === current ? ' current' : ''}${index < current ? ' complete' : ''}`}><span>{index < current ? <Check size={14} /> : index + 1}</span><strong>{label}</strong>{index < steps.length - 1 && <i />}</div>)}</div>
}

function SelectField({ label, icon, value, options, placeholder, disabled, onChange }: { label: string; icon: ReactNode; value: number; options: Option[]; placeholder: string; disabled?: boolean; onChange: (value: number) => void }) {
  return <label className={`select-field${disabled ? ' is-fixed' : ''}`}><span>{label}</span><div className="select-wrap">{icon}<select value={value} disabled={disabled} onChange={(event) => onChange(Number(event.target.value))}><option value={0}>{placeholder}</option>{options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select><ChevronDown size={17} /></div>{disabled && <small>Identified from your QR code</small>}</label>
}

function ManualCardItem({ manual, onOpenVersion }: { manual: ManualCard; onOpenVersion: (versionId?: number) => void }) {
  const latest = manual.versions[0]
  const hasHistory = manual.versions.length > 1
  return <article className="manual-card"><div className="manual-card-top"><div className="manual-type-icon"><BookOpen size={22} /></div><span className="status-pill published">Available</span></div><h3>{manual.title}</h3><p className="manual-type">{manual.type}</p><div className="manual-meta"><span>{manual.vehicle}</span><i /> <span>{manual.engine}</span><i /> <span>{manual.language}</span></div>{hasHistory ? <div className="version-list"><span className="version-list-label">All versions · latest first</span>{manual.versions.map((item) => <button key={item.id} className={`version-option ${item.id === latest.id ? 'current' : ''}`} onClick={() => onOpenVersion(item.id)}><span className="version-option-name"><strong>v{item.version}</strong>{item.id === latest.id && <em>Latest</em>}</span><small>{item.published_at ? `Published ${formatDate(item.published_at)}` : 'Date unavailable'}{item.page_count ? ` · ${item.page_count} pages` : ''}</small></button>)}</div> : <div className="manual-version"><span>Version {latest?.version ?? '—'}</span><span>{latest?.page_count ? `${latest.page_count} pages` : 'Protected document'}</span></div>}<button className="button button-primary button-wide" onClick={() => onOpenVersion(latest?.id)}>Read manual <ArrowRight size={17} /></button></article>
}
