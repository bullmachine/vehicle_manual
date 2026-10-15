import { Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, ShieldCheck } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { apiFetch, jsonBody, setAdminToken } from '../lib/api'
import { errorMessage } from '../lib/format'
import type { AdminUser } from '../lib/types'
import { BrandMark } from '../components/BrandMark'

export function AdminLoginPage() {
  const navigate = useNavigate()
  const [login, setLogin] = useState('')
  const [password, setPassword] = useState('')
  const [otp, setOtp] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [twoFactor, setTwoFactor] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent) {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      const result = await apiFetch<{ token?: string; user: AdminUser; two_factor_required?: boolean; message?: string }>('/admin/login', jsonBody({ login, password, otp: otp || undefined }))
      if (result.two_factor_required) {
        if (otp) setError(result.message ?? 'The two-factor code is incorrect.')
        setTwoFactor(true)
        return
      }
      if (!result.token) throw new Error('Login response did not include a session token.')
      setAdminToken(result.token)
      navigate('/admin', { replace: true })
    } catch (reason) {
      setError(errorMessage(reason))
    } finally {
      setLoading(false)
    }
  }

  return <div className="login-page"><div className="login-decoration login-decoration-one" /><div className="login-decoration login-decoration-two" /><div className="login-card"><div className="login-brand"><BrandMark /><span className="login-badge"><ShieldCheck size={14} /> Secure admin</span></div><div className="login-heading"><span className="eyebrow"><span className="eyebrow-line" /> CONTROL CENTER</span><h1>Welcome back.</h1><p>Sign in to manage vehicle manuals, QR codes and customer access.</p></div><form className="login-form" onSubmit={submit}><label>Email or username<input value={login} onChange={(event) => setLogin(event.target.value)} placeholder="admin@example.com" autoComplete="username" required /></label><label>Password<div className="password-field"><input type={showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" autoComplete="current-password" required /><button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></div></label>{twoFactor && <label>Authentication code<div className="input-with-icon"><KeyRound size={17} /><input value={otp} onChange={(event) => setOtp(event.target.value.replace(/\D/g, '').slice(0, 6))} placeholder="6-digit code" inputMode="numeric" autoComplete="one-time-code" /></div></label>}{error && <div className="form-error">{error}</div>}<button className="button button-primary button-wide login-submit" disabled={loading}>{loading ? <LoaderCircle className="spin" size={18} /> : <LockKeyhole size={17} />}{twoFactor ? 'Verify and sign in' : 'Sign in to control center'}</button></form><div className="login-footer"><LockKeyhole size={14} /> Protected by secure session authentication</div></div><p className="login-copyright">BULL Vehicle Manual Portal · Authorized personnel only</p></div>
}
