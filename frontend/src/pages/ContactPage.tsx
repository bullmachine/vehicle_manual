import { ArrowLeft, CheckCircle2, LoaderCircle, LockKeyhole, MessageSquare, Send } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { BrandMark } from '../components/BrandMark'
import { PhoneField } from '../components/PhoneField'
import { apiFetch, jsonBody } from '../lib/api'
import { errorMessage } from '../lib/format'

const SUBJECTS = ['Manual not opening', 'Wrong manual shown', 'OTP or access problem', 'Document is unclear', 'Something else']

export function ContactPage() {
  const [params] = useSearchParams()
  const [form, setForm] = useState({ name: '', mobile: '', email: '', subject: SUBJECTS[0], message: '' })
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)
  const [reference, setReference] = useState<number | null>(null)

  function set(key: string, value: string) { setForm((current) => ({ ...current, [key]: value })) }
  const mobileLength = form.mobile.replace(/\D/g, '').length

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setSending(true)
    setError('')
    try {
      const session = params.get('session')
      const result = await apiFetch<{ data: { id: number } }>('/public/support/enquiries', jsonBody({
        name: form.name.trim(),
        mobile: form.mobile.trim(),
        email: form.email.trim() || null,
        subject: form.subject,
        message: form.message.trim(),
        qr_token: params.get('qr') || null,
        customer_session_id: session ? Number(session) : null,
      }))
      setReference(result.data.id)
    } catch (reason) {
      setError(errorMessage(reason))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="customer-app portal-page">
      <header className="customer-header shell-width"><BrandMark /><div className="header-secure"><LockKeyhole size={15} /> Secure support channel</div></header>
      <main className="shell-width portal-main">
        <div className="portal-heading"><div><div className="eyebrow"><span className="eyebrow-line" /> SUPPORT</div><h1>Contact us</h1><p>Tell us what went wrong and our support team will get back to you.</p></div></div>

        {reference !== null ? (
          <section className="portal-card details-card">
            <div className="card-heading"><div className="card-icon green-icon"><CheckCircle2 size={21} /></div><div><h2>Message received</h2><p>Our support team will review your request shortly.</p></div></div>
            <div className="selection-summary"><div><span>Reference</span><strong>#{reference}</strong></div><div><span>Subject</span><strong>{form.subject}</strong></div><div><span>Contact</span><strong>{form.mobile.trim()}{form.email.trim() ? ` · ${form.email.trim()}` : ''}</strong></div></div>
            <div className="card-actions"><Link className="button button-ghost" to="/"><ArrowLeft size={17} /> Back to portal</Link><button className="button button-primary" onClick={() => { setReference(null); setForm({ name: '', mobile: '', email: '', subject: SUBJECTS[0], message: '' }) }}><MessageSquare size={17} /> Send another</button></div>
          </section>
        ) : (
          <section className="portal-card details-card">
            <div className="card-heading"><div className="card-icon"><MessageSquare size={21} /></div><div><h2>Send us a message</h2><p>Fields marked with an asterisk are required.</p></div></div>
            <form onSubmit={submit} className="details-form">
              <label>Full name *<input value={form.name} onChange={(event) => set('name', event.target.value)} placeholder="e.g. Raj Kumar" autoComplete="name" required maxLength={120} /></label>
              <label>Mobile number *<PhoneField value={form.mobile} onChange={(value) => set('mobile', value)} required /></label>
              <label>Email address<span className="field-optional">Optional</span><input type="email" value={form.email} onChange={(event) => set('email', event.target.value)} placeholder="you@example.com" autoComplete="email" /></label>
              <label>Subject *<select value={form.subject} onChange={(event) => set('subject', event.target.value)}>{SUBJECTS.map((subject) => <option key={subject} value={subject}>{subject}</option>)}</select></label>
              <label>Message *<textarea value={form.message} onChange={(event) => set('message', event.target.value)} placeholder="Describe the problem you faced, including the vehicle and manual if relevant." rows={5} minLength={10} maxLength={4000} required /></label>
              {error && <div className="form-error">{error}</div>}
              <div className="privacy-note"><LockKeyhole size={16} /><span>Your details are used only to answer this support request. We never place them inside a QR code.</span></div>
              <div className="card-actions"><Link className="button button-ghost" to="/"><ArrowLeft size={17} /> Back</Link><button className="button button-primary" disabled={sending || mobileLength < 6}>{sending ? <LoaderCircle className="spin" size={18} /> : <Send size={17} />} Send message</button></div>
            </form>
          </section>
        )}
      </main>
      <footer className="customer-footer shell-width"><BrandMark /><span>Protected document delivery · BULL</span><span className="footer-links">BULL support portal</span></footer>
    </div>
  )
}
