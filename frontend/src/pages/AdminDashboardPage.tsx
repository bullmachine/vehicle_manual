import { Activity, ArrowUpRight, BookOpen, CarFront, ChevronRight, CircleUserRound, Eye, QrCode } from 'lucide-react'
import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { apiFetch } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { ErrorState, LoadingState } from '../components/States'

type DashboardData = {
  cards: Record<string, number>
  charts: {
    scan_trend: { date: string; scans: number }[]
    qr_type_distribution: { name: string; value: number }[]
    language_usage: { name: string; value: number }[]
    top_manuals: { id: number; title: string; access_logs_count: number }[]
  }
  recent_activity: { id: number; created_at: string; qr_code?: { reference: string }; vehicle_variant?: { name: string } }[]
}

const colors = ['#2f6fed', '#f59e0b', '#16a394', '#8b5cf6', '#e05252']

export function AdminDashboardPage() {
  const query = useQuery({ queryKey: ['admin-dashboard'], queryFn: () => apiFetch<DashboardData>('/admin/dashboard') })
  if (query.isLoading) return <LoadingState label="Loading dashboard…" />
  if (query.isError || !query.data) return <ErrorState message="Dashboard data is unavailable." onRetry={() => void query.refetch()} />
  const { cards, charts, recent_activity } = query.data
  return <div className="dashboard-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> OVERVIEW</span><h1>Good morning, team.</h1><p>Here’s what’s happening across your manual portal today.</p></div><div className="heading-actions"><button className="button button-ghost"><Activity size={17} /> Live activity</button><button className="button button-primary" onClick={() => window.location.reload()}><ArrowUpRight size={17} /> Refresh data</button></div></div><div className="stat-grid"><StatCard label="Total vehicles" value={cards.total_vehicles} icon={<CarFront size={19} />} trend="Configured variants" tone="blue" /><StatCard label="Active QR codes" value={cards.active_qr_codes} icon={<QrCode size={19} />} trend="Ready to scan" tone="green" /><StatCard label="Published manuals" value={cards.published_manuals} icon={<BookOpen size={19} />} trend={`${cards.total_manuals} total manuals`} tone="amber" /><StatCard label="Manual views" value={cards.total_manual_views} icon={<Eye size={19} />} trend="All customer sessions" tone="purple" /></div><div className="dashboard-charts"><section className="panel chart-panel chart-wide"><PanelHeader title="QR scan trend" subtitle="Last 7 days" action="View report" /><ResponsiveContainer width="100%" height={245}><LineChart data={charts.scan_trend} margin={{ top: 8, right: 8, bottom: 0, left: -22 }}><CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e8edf4" /><XAxis dataKey="date" tickFormatter={(value: string) => value.slice(5)} tick={{ fontSize: 11, fill: '#8290a5' }} axisLine={false} tickLine={false} /><YAxis allowDecimals={false} tick={{ fontSize: 11, fill: '#8290a5' }} axisLine={false} tickLine={false} /><Tooltip /><Line type="monotone" dataKey="scans" stroke="#2f6fed" strokeWidth={3} dot={{ r: 4, fill: '#fff', strokeWidth: 3 }} /></LineChart></ResponsiveContainer></section><section className="panel chart-panel"><PanelHeader title="QR type distribution" subtitle="Active codes" /><ResponsiveContainer width="100%" height={245}><PieChart><Pie data={charts.qr_type_distribution} dataKey="value" nameKey="name" innerRadius={62} outerRadius={88} paddingAngle={4}>{charts.qr_type_distribution.map((entry, index) => <Cell key={entry.name} fill={colors[index % colors.length]} />)}</Pie><Tooltip /><Legend verticalAlign="bottom" iconType="circle" wrapperStyle={{ fontSize: 12 }} /></PieChart></ResponsiveContainer></section><section className="panel chart-panel"><PanelHeader title="Language usage" subtitle="Manual catalogue" /><ResponsiveContainer width="100%" height={245}><BarChart data={charts.language_usage} layout="vertical" margin={{ top: 0, right: 10, left: 8, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#e8edf4" /><XAxis type="number" hide /><YAxis type="category" dataKey="name" width={68} tick={{ fontSize: 11, fill: '#526176' }} axisLine={false} tickLine={false} /><Tooltip /><Bar dataKey="value" fill="#2f6fed" radius={[0, 5, 5, 0]} barSize={15} /></BarChart></ResponsiveContainer></section><section className="panel top-manuals-panel"><PanelHeader title="Most viewed manuals" subtitle="All time" action="View all" />{charts.top_manuals.length === 0 ? <p className="panel-empty">No manual views yet.</p> : <div className="top-manual-list">{charts.top_manuals.map((manual, index) => <div className="top-manual" key={manual.id}><span className="rank">0{index + 1}</span><div className="top-manual-icon"><BookOpen size={17} /></div><div className="top-manual-copy"><strong>{manual.title}</strong><span>{manual.access_logs_count} page sessions</span></div><ChevronRight size={16} /></div>)}</div>}</section></div><section className="panel activity-panel"><PanelHeader title="Recent activity" subtitle="Latest portal events" action="Audit log" />{recent_activity.length === 0 ? <p className="panel-empty">No activity recorded yet.</p> : <div className="activity-list">{recent_activity.map((item) => <div className="activity-item" key={item.id}><div className="activity-icon"><QrCode size={16} /></div><div><strong>QR scanned <span>· {item.qr_code?.reference ?? 'Unknown code'}</span></strong><p>{item.vehicle_variant?.name ?? 'Vehicle configuration'} · {formatDateTime(item.created_at)}</p></div><CircleUserRound size={16} className="activity-user" /></div>)}</div>}</section></div>
}

function StatCard({ label, value, icon, trend, tone }: { label: string; value: number; icon: ReactNode; trend: string; tone: string }) {
  return <div className="stat-card"><div className={`stat-icon ${tone}`}>{icon}</div><div className="stat-copy"><span>{label}</span><strong>{value.toLocaleString()}</strong><small>{trend}</small></div><ArrowUpRight size={16} className="stat-arrow" /></div>
}

function PanelHeader({ title, subtitle, action }: { title: string; subtitle: string; action?: string }) {
  return <div className="panel-header"><div><h2>{title}</h2><span>{subtitle}</span></div>{action && <button className="panel-action">{action}</button>}</div>
}
