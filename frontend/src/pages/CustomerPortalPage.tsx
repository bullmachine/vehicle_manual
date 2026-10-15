import { ArrowLeft, ArrowRight, BookOpen, Check, ChevronDown, Hash, Languages, LoaderCircle, LockKeyhole, Search, ShieldCheck, UserRound } from 'lucide-react'
import type { ReactNode } from 'react'
import type { FormEvent } from 'react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage, formatDate } from '../lib/format'
import type { CustomerSession, MachineLookup, ManualCard, Option, QrContext } from '../lib/types'
import { BrandMark } from '../components/BrandMark'
import { PhoneField } from '../components/PhoneField'
import { EmptyState, LoadingState } from '../components/States'

const steps = ['Machine', 'Details', 'Manuals']

export function CustomerPortalPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()
  const [context, setContext] = useState<QrContext | null>(null)
  const [loadedToken, setLoadedToken] = useState('')
  const [loadError, setLoadError] = useState('')
  const [step, setStep] = useState(0)
  const [serialNo, setSerialNo] = useState('')
  const [lookup, setLookup] = useState<MachineLookup | null>(null)
  const [languageId, setLanguageId] = useState(0)
  const [name, setName] = useState('')
  const [mobile, setMobile] = useState('')
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
      })
      .catch((error) => active && setLoadError(errorMessage(error)))
      .finally(() => active && setLoadedToken(token))
    return () => { active = false }
  }, [token])

  useEffect(() => {
    if (step !== 2 || !session) return
    apiFetch<{ data: ManualCard[] }>('/customer/manuals')
      .then((data) => setManuals(data.data))
      .catch((error) => setActionError(errorMessage(error)))
      .finally(() => setManualsLoading(false))
  }, [session, step])

  const languages = lookup?.languages ?? []
  const selectedLanguage = languages.find((item) => item.id === languageId)

  async function findMachine(event: FormEvent) {
    event.preventDefault()
    setActionError('')
    if (!serialNo.trim()) return setActionError('Enter the machine serial number.')
    setSubmitting(true)
    try {
      const result = await apiFetch<{ data: MachineLookup }>('/public/machine', jsonBody({ qr_token: token, machine_serial_no: serialNo.trim() }))
      setLookup(result.data)
      setSerialNo(result.data.machine.serial_no)
      setLanguageId(result.data.languages[0]?.id ?? 0)
    } catch (error) {
      setLookup(null)
      setLanguageId(0)
      setActionError(errorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  function changeSerialNo(value: string) {
    setSerialNo(value)
    if (lookup) {
      setLookup(null)
      setLanguageId(0)
    }
  }

  function continueMachine() {
    setActionError('')
    if (!lookup) return setActionError('Find your machine to continue.')
    if (!languageId) return setActionError('Select a language to continue.')
    setStep(1)
  }

  async function submitDetails(event: FormEvent) {
    event.preventDefault()
    setActionError('')
    if (name.trim().length < 2) return setActionError('Enter your name.')
    if (mobile && mobile.replace(/\D/g, '').length < 6) return setActionError('Enter a valid mobile number or leave it blank.')
    setSubmitting(true)
    try {
      const result = await apiFetch<{ data: CustomerSession }>('/public/customer/session', jsonBody({
        qr_token: token,
        machine_serial_no: lookup?.machine.serial_no ?? serialNo.trim(),
        language_id: languageId,
        name: name.trim(),
        mobile: mobile.trim() || null,
      }))
      setManualsLoading(true)
      setSession(result.data)
      setStep(2)
    } catch (error) {
      setActionError(errorMessage(error))
    } finally {
      setSubmitting(false)
    }
  }

  function startAgain() {
    setSession(null)
    setManuals([])
    setManualsLoading(false)
    setStep(0)
    setSerialNo('')
    setLookup(null)
    setLanguageId(0)
    setName('')
    setMobile('')
  }

  if (loading) return <div className="customer-app"><div className="shell-width"><LoadingState label="Opening the manual portal…" /></div></div>
  if (loadError || !context) return <div className="customer-app"><div className="shell-width narrow-state"><EmptyState title="QR code not found" message={loadError || 'This QR code is invalid or has been deactivated.'} action={<button className="button button-primary" onClick={() => navigate('/')}>Return to portal</button>} /></div></div>

  return (
    <div className="customer-app portal-page">
      <header className="customer-header shell-width"><BrandMark /><div className="header-secure"><LockKeyhole size={15} /> Secure access</div></header>
      <main className="shell-width portal-main">
        <div className="portal-heading"><div><div className="eyebrow"><span className="eyebrow-line" /> VEHICLE MANUAL PORTAL</div><h1>{step === 2 ? 'Your user manuals' : 'Let’s get you connected.'}</h1><p>{step === 2 ? 'Choose a manual to read in the protected viewer.' : 'Enter your machine serial number and we’ll prepare the right manual for you.'}</p></div><div className="portal-reference"><span>{context.qr.type?.name ?? 'Manual access'}</span><strong>{context.qr.reference}</strong></div></div>
        <StepIndicator current={step} />
        {actionError && <div className="inline-alert error-alert"><ShieldCheck size={17} />{actionError}</div>}
        {step === 0 && <section className="portal-card selection-card">
          <div className="card-heading"><div className="card-icon"><BookOpen size={21} /></div><div><h2>Identify your machine</h2><p>The serial number (MSNO) is printed on your machine’s identification plate.</p></div></div>
          <form onSubmit={findMachine} className="details-form">
            <label>Machine serial number<div className="serial-lookup"><input value={serialNo} onChange={(event) => changeSerialNo(event.target.value)} placeholder="e.g. BS255" autoComplete="off" autoCapitalize="characters" spellCheck={false} maxLength={60} /><button className="button button-ghost" disabled={submitting}>{submitting ? <LoaderCircle className="spin" size={17} /> : <Search size={17} />} Find machine</button></div></label>
          </form>
          {lookup && <>
            <div className="selection-summary"><div><span>Machine</span><strong>{lookup.machine.description}</strong></div><div><span>Serial number</span><strong>{lookup.machine.serial_no}</strong></div><div><span>Model (FERT)</span><strong>{lookup.machine.fert.code}</strong></div></div>
            {languages.length === 0
              ? <div className="inline-alert error-alert"><ShieldCheck size={17} />No manuals have been published for this machine yet. Please contact your dealer or support team.</div>
              : <div className="selection-grid"><SelectField label="Language" icon={<Languages size={17} />} value={languageId} options={languages} placeholder="Select language" onChange={setLanguageId} /></div>}
          </>}
          <div className="card-actions"><span className="muted-note"><Hash size={15} /> {selectedLanguage ? `Manuals will open in ${selectedLanguage.name}.` : 'We only show manuals for your exact machine model.'}</span><button className="button button-primary" onClick={continueMachine} disabled={!lookup || !languageId}>Continue <ArrowRight size={18} /></button></div>
        </section>}
        {step === 1 && <section className="portal-card details-card"><div className="card-heading"><div className="card-icon green-icon"><UserRound size={21} /></div><div><h2>Tell us who you are</h2><p>We use these details to protect your manual access and to help if you contact support.</p></div></div><form onSubmit={submitDetails} className="details-form"><label>Full name<input value={name} onChange={(event) => setName(event.target.value)} placeholder="e.g. Raj Kumar" autoComplete="name" required /></label><label>Mobile number (optional)<PhoneField value={mobile} onChange={setMobile} /></label><div className="privacy-note"><LockKeyhole size={16} /><span>Your information is used for access records and support. It is never placed inside a QR code.</span></div><div className="card-actions"><button type="button" className="button button-ghost" onClick={() => setStep(0)}><ArrowLeft size={17} /> Back</button><button className="button button-primary" disabled={submitting}>{submitting ? <LoaderCircle className="spin" size={18} /> : <Check size={17} />} Continue to manuals</button></div></form></section>}
        {step === 2 && <section className="manuals-section">{manualsLoading ? <LoadingState label="Loading your manuals…" /> : manuals.length === 0 ? <EmptyState title="Manual not available" message="We couldn’t find a user manual for this machine in the selected language. Please contact your dealer or support team." /> : <div className="manual-grid">{manuals.map((manual) => <ManualCardItem key={manual.id} manual={manual} onOpenVersion={(versionId) => navigate(`/manual/${manual.id}${versionId ? `?version=${versionId}` : ''}`)} />)}</div>}</section>}
        {session && <div className="session-bar"><span><LockKeyhole size={14} /> Access protected for {session.customer.name}{session.machine.serial_no ? ` · ${session.machine.serial_no}` : ''}</span><button onClick={startAgain}>Start again</button></div>}
      </main>
      <footer className="customer-footer shell-width"><BrandMark /><span>Protected document delivery · BULL</span><Link className="footer-links" to={`/contact?qr=${encodeURIComponent(token)}${session ? `&session=${session.id}` : ''}`}>Need help? Contact support.</Link></footer>
    </div>
  )
}

function StepIndicator({ current }: { current: number }) {
  return <div className="step-indicator">{steps.map((label, index) => <div key={label} className={`step-item${index === current ? ' current' : ''}${index < current ? ' complete' : ''}`}><span>{index < current ? <Check size={14} /> : index + 1}</span><strong>{label}</strong>{index < steps.length - 1 && <i />}</div>)}</div>
}

function SelectField({ label, icon, value, options, placeholder, disabled, onChange }: { label: string; icon: ReactNode; value: number; options: Option[]; placeholder: string; disabled?: boolean; onChange: (value: number) => void }) {
  return <label className={`select-field${disabled ? ' is-fixed' : ''}`}><span>{label}</span><div className="select-wrap">{icon}<select value={value} disabled={disabled} onChange={(event) => onChange(Number(event.target.value))}><option value={0}>{placeholder}</option>{options.map((option) => <option key={option.id} value={option.id}>{option.native_name && option.native_name !== option.name ? `${option.name} · ${option.native_name}` : option.name}</option>)}</select><ChevronDown size={17} /></div></label>
}

function ManualCardItem({ manual, onOpenVersion }: { manual: ManualCard; onOpenVersion: (versionId?: number) => void }) {
  const latest = manual.versions[0]
  const hasHistory = manual.versions.length > 1
  return <article className="manual-card"><div className="manual-card-top"><div className="manual-type-icon"><BookOpen size={22} /></div><span className="status-pill published">Available</span></div><h3>{manual.title}</h3><p className="manual-type">{manual.type}</p><div className="manual-meta"><span>{manual.machine}</span><i /> <span>{manual.language}</span></div>{hasHistory ? <div className="version-list"><span className="version-list-label">All versions · latest first</span>{manual.versions.map((item) => <button key={item.id} className={`version-option ${item.id === latest.id ? 'current' : ''}`} onClick={() => onOpenVersion(item.id)}><span className="version-option-name"><strong>v{item.version}</strong>{item.id === latest.id && <em>Latest</em>}</span><small>{item.published_at ? `Published ${formatDate(item.published_at)}` : 'Date unavailable'}{item.page_count ? ` · ${item.page_count} pages` : ''}</small></button>)}</div> : <div className="manual-version"><span>Version {latest?.version ?? '—'}</span><span>{latest?.page_count ? `${latest.page_count} pages` : 'Protected document'}</span></div>}<button className="button button-primary button-wide" onClick={() => onOpenVersion(latest?.id)}>Read manual <ArrowRight size={17} /></button></article>
}
