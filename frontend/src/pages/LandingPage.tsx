import { ArrowRight, BookOpen, Languages, ScanLine, ShieldCheck, Smartphone, Wrench } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { BrandMark } from '../components/BrandMark'

export function LandingPage() {
  const navigate = useNavigate()
  const [token, setToken] = useState('')

  function openManual(event: FormEvent) {
    event.preventDefault()
    const clean = token.trim().replace(/^\/q\//, '')
    if (clean) navigate(`/q/${encodeURIComponent(clean)}`)
  }

  return (
    <div className="customer-app landing-page">
      <header className="customer-header shell-width">
        <BrandMark />
        <div className="header-support"><span className="status-dot" /> Secure manual access</div>
      </header>
      <main className="shell-width landing-main">
        <section className="landing-hero">
          <div className="hero-copy">
            <div className="eyebrow"><span className="eyebrow-line" /> BULL VEHICLE CARE</div>
            <h1>Your vehicle.<br /><em>Always within reach.</em></h1>
            <p className="hero-lede">Find the right operator manual for your BULL machine in seconds. Scan the QR code on your vehicle or enter its secure access code below.</p>
            <form className="access-form" onSubmit={openManual}>
              <label htmlFor="access-code">Manual access code</label>
              <div className="access-input-row"><ScanLine size={20} /><input id="access-code" value={token} onChange={(event) => setToken(event.target.value)} placeholder="Enter code from your QR label" autoComplete="off" /><button className="button button-primary" type="submit">Continue <ArrowRight size={18} /></button></div>
              <span className="form-hint">The access code is safe and does not contain personal vehicle information.</span>
            </form>
            <div className="hero-trust"><span><ShieldCheck size={16} /> Private delivery</span><span><Smartphone size={16} /> Mobile ready</span><span><Languages size={16} /> Multilingual</span></div>
          </div>
          <div className="hero-visual" aria-label="Vehicle manual illustration">
            <div className="visual-glow" />
            <div className="manual-illustration">
              <div className="manual-shadow" />
              <div className="manual-cover"><div className="cover-top"><span className="cover-brand">BULL</span><span className="cover-tag">USER MANUAL</span></div><div className="cover-machine"><Wrench size={82} strokeWidth={1.1} /></div><div className="cover-bottom"><span>OPERATOR SERIES</span><strong>50 HP</strong></div></div>
              <div className="visual-float-card float-card-top"><BookOpen size={17} /><span><strong>Always current</strong><small>Latest approved version</small></span></div>
              <div className="visual-float-card float-card-bottom"><ShieldCheck size={17} /><span><strong>Secure viewer</strong><small>Protected page access</small></span></div>
            </div>
          </div>
        </section>
        <section className="feature-grid" aria-label="Portal benefits">
          <div className="feature-card"><div className="feature-icon blue"><ScanLine size={21} /></div><div><h3>Scan and go</h3><p>Your QR label takes you directly to the correct vehicle configuration.</p></div></div>
          <div className="feature-card"><div className="feature-icon amber"><Languages size={21} /></div><div><h3>Your language</h3><p>Choose from the languages configured for your vehicle and engine.</p></div></div>
          <div className="feature-card"><div className="feature-icon green"><ShieldCheck size={21} /></div><div><h3>Read with confidence</h3><p>Manuals are delivered page by page through a protected viewer with your personal watermark.</p></div></div>
        </section>
        <section className="how-it-works"><div><div className="eyebrow"><span className="eyebrow-line" /> HOW IT WORKS</div><h2>From machine to manual<br /><em>in three simple steps.</em></h2></div><div className="steps-row"><div className="step-item"><b>01</b><strong>Scan your QR</strong><span>Find the label on your vehicle.</span></div><div className="step-item"><b>02</b><strong>Confirm your details</strong><span>Select language and verify your mobile.</span></div><div className="step-item"><b>03</b><strong>Read your manual</strong><span>Open the latest approved version securely.</span></div></div></section>
      </main>
      <footer className="customer-footer shell-width"><BrandMark /><span>© {new Date().getFullYear()} BULL. Vehicle user manual portal.</span><span className="footer-links">Need help? Contact your dealer.</span></footer>
    </div>
  )
}
