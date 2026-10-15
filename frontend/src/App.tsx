import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { lazy, Suspense, useEffect, useState } from 'react'
import { BrowserRouter, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { AdminLayout } from './components/AdminLayout'
import { BrandMark } from './components/BrandMark'
import { getAdminToken, apiFetch, clearAdminToken } from './lib/api'
import type { AdminUser } from './lib/types'

const AdminAuditPage = lazy(() => import('./pages/AdminAuditPage').then(({ AdminAuditPage }) => ({ default: AdminAuditPage })))
const AdminDashboardPage = lazy(() => import('./pages/AdminDashboardPage').then(({ AdminDashboardPage }) => ({ default: AdminDashboardPage })))
const AdminEnquiryPage = lazy(() => import('./pages/AdminEnquiryPage').then(({ AdminEnquiryPage }) => ({ default: AdminEnquiryPage })))
const AdminLoginPage = lazy(() => import('./pages/AdminLoginPage').then(({ AdminLoginPage }) => ({ default: AdminLoginPage })))
const AdminMachinesPage = lazy(() => import('./pages/AdminMachinesPage').then(({ AdminMachinesPage }) => ({ default: AdminMachinesPage })))
const AdminManualsPage = lazy(() => import('./pages/AdminManualsPage').then(({ AdminManualsPage }) => ({ default: AdminManualsPage })))
const AdminMastersPage = lazy(() => import('./pages/AdminMastersPage').then(({ AdminMastersPage }) => ({ default: AdminMastersPage })))
const AdminQrPage = lazy(() => import('./pages/AdminQrPage').then(({ AdminQrPage }) => ({ default: AdminQrPage })))
const AdminReportsPage = lazy(() => import('./pages/AdminReportsPage').then(({ AdminReportsPage }) => ({ default: AdminReportsPage })))
const AdminSettingsPage = lazy(() => import('./pages/AdminSettingsPage').then(({ AdminSettingsPage }) => ({ default: AdminSettingsPage })))
const CustomerPortalPage = lazy(() => import('./pages/CustomerPortalPage').then(({ CustomerPortalPage }) => ({ default: CustomerPortalPage })))
const ContactPage = lazy(() => import('./pages/ContactPage').then(({ ContactPage }) => ({ default: ContactPage })))
const LandingPage = lazy(() => import('./pages/LandingPage').then(({ LandingPage }) => ({ default: LandingPage })))
const ManualViewerPage = lazy(() => import('./pages/ManualViewerPage').then(({ ManualViewerPage }) => ({ default: ManualViewerPage })))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage').then(({ NotFoundPage }) => ({ default: NotFoundPage })))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

function ProtectedAdmin() {
  const navigate = useNavigate()
  const [checking, setChecking] = useState(true)

  useEffect(() => {
    if (!getAdminToken()) {
      navigate('/admin/login', { replace: true })
      return
    }
    apiFetch<{ user: AdminUser }>('/admin/me')
      .catch(() => {
        clearAdminToken()
        navigate('/admin/login', { replace: true })
      })
      .finally(() => setChecking(false))
  }, [navigate])

  if (checking) return <div className="app-loading"><BrandMark /><span>Loading secure workspace…</span></div>
  return <Outlet />
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Suspense fallback={<div className="app-loading"><BrandMark /><span>Loading portal…</span></div>}>
          <Routes>
          <Route path="/" element={<LandingPage />} />
          <Route path="/q/:token" element={<CustomerPortalPage />} />
          <Route path="/manual/:manualId" element={<ManualViewerPage />} />
        <Route path="/contact" element={<ContactPage />} />
          <Route path="/admin/login" element={<AdminLoginPage />} />
          <Route path="/admin" element={<ProtectedAdmin />}>
            <Route element={<AdminLayout />}>
              <Route index element={<AdminDashboardPage />} />
              <Route path="manuals" element={<AdminManualsPage />} />
              <Route path="machines" element={<AdminMachinesPage />} />
              <Route path="qr-codes" element={<AdminQrPage />} />
              <Route path="masters/:resource" element={<AdminMastersPage />} />
              <Route path="reports" element={<AdminReportsPage />} />
              <Route path="settings" element={<AdminSettingsPage />} />
              <Route path="audit" element={<AdminAuditPage />} />
        <Route path="enquiries" element={<AdminEnquiryPage />} />
            </Route>
          </Route>
          <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </QueryClientProvider>
  )
}

export default App
