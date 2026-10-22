import { BarChart3, BookOpen, ChevronDown, CircleHelp, ClipboardList, Cpu, LayoutDashboard, LogOut, Menu, MessageSquare, QrCode, Settings, ShieldCheck, X } from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { apiFetch, clearAdminToken, runtimeConfigDiagnostic } from '../lib/api'
import { BrandMark } from './BrandMark'

const navigation = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/admin/manuals', label: 'Manuals', icon: BookOpen },
  { to: '/admin/machines', label: 'Machines', icon: Cpu },
  { to: '/admin/qr-codes', label: 'QR management', icon: QrCode },
  { to: '/admin/masters/ferts', label: 'Master data', icon: ClipboardList },
  { to: '/admin/reports', label: 'Reports', icon: BarChart3 },
  { to: '/admin/enquiries', label: 'Support requests', icon: MessageSquare },
  { to: '/admin/settings', label: 'Settings', icon: Settings },
  { to: '/admin/audit', label: 'Audit logs', icon: ShieldCheck },
  { to: '/admin/help', label: 'Help', icon: CircleHelp },
]

export function AdminLayout() {
  const [open, setOpen] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  const configDiagnostic = runtimeConfigDiagnostic()

  async function logout() {
    try {
      await apiFetch('/admin/logout', { method: 'POST' })
    } catch {
      clearAdminToken()
    }
    clearAdminToken()
    navigate('/admin/login', { replace: true })
  }

  return (
    <div className="admin-app">
      <aside className={`admin-sidebar${open ? ' is-open' : ''}`}>
        <div className="admin-sidebar-head">
          <BrandMark light />
          <button className="icon-button sidebar-close" onClick={() => setOpen(false)} aria-label="Close navigation"><X size={20} /></button>
        </div>
        <div className="admin-sidebar-caption">CONTROL CENTER</div>
        <nav className="admin-nav">
          {navigation.map(({ to, label, icon: Icon, end }) => (
            <NavLink key={to} to={to} end={end} onClick={() => setOpen(false)} className={({ isActive }) => `admin-nav-link${isActive ? ' active' : ''}`}>
              <Icon size={18} strokeWidth={1.8} />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>
        <div className="sidebar-footer">
          <div className="security-note"><ShieldCheck size={18} /><div><strong>Protected workspace</strong><span>Private document delivery enabled</span></div></div>
          <button className="sidebar-logout" onClick={logout}><LogOut size={17} /> Sign out</button>
        </div>
      </aside>
      {open && <button className="sidebar-scrim" onClick={() => setOpen(false)} aria-label="Close navigation" />}
      <main className="admin-main">
        <header className="admin-topbar">
          <button className="icon-button mobile-menu" onClick={() => setOpen(true)} aria-label="Open navigation"><Menu size={21} /></button>
          <div className="breadcrumb"><span>Admin portal</span><b>/</b><strong>{getPageName(location.pathname)}</strong></div>
          <div className="topbar-actions">
            <div className="topbar-status"><span className="status-dot" /> System operational</div>
            <div className="profile-wrap">
              <button className="profile-button" onClick={() => setProfileOpen((value) => !value)}><span className="avatar">BA</span><span className="profile-name">BULL Admin</span><ChevronDown size={15} /></button>
              {profileOpen && <div className="profile-menu"><button onClick={logout}><LogOut size={15} /> Sign out</button></div>}
            </div>
          </div>
        </header>
        <div className="admin-content">{configDiagnostic && <div className="inline-alert error-alert config-banner">{configDiagnostic}</div>}<Outlet /></div>
      </main>
    </div>
  )
}

function getPageName(path: string): string {
  if (path.includes('manuals')) return 'Manuals'
  if (path.includes('machines')) return 'Machines'
  if (path.includes('qr-codes')) return 'QR management'
  if (path.includes('masters')) return 'Master data'
  if (path.includes('reports')) return 'Reports'
  if (path.includes('enquiries')) return 'Support requests'
  if (path.includes('settings')) return 'Settings'
  if (path.includes('audit')) return 'Audit logs'
  if (path.includes('help')) return 'Help'
  return 'Dashboard'
}
