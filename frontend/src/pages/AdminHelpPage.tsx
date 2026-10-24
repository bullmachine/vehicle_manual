import { ArrowRight, BarChart3, BookOpen, CircleHelp, ClipboardList, Cpu, Lightbulb, MessageSquare, QrCode, Route as RouteIcon, Search, Settings, ShieldCheck, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

type HelpSection = {
  id: string
  title: string
  icon: LucideIcon
  summary: string
  link?: { to: string; label: string }
  steps?: string[]
  points?: { term: string; text: string }[]
  tips?: string[]
}

const sections: HelpSection[] = [
  {
    id: 'overview',
    title: 'How the portal works',
    icon: RouteIcon,
    summary: 'Customers open their machine’s user manual by scanning the QR label and entering the machine serial number. The serial number tells the portal which machine model (FERT) it is, and the portal shows only the manuals mapped to that FERT.',
    steps: [
      'The customer scans the QR label on the machine. It opens the portal on their phone.',
      'They type the machine serial number (MSNO) printed on the machine plate, for example HDSS8503, and tap Find machine.',
      'The portal looks the serial number up in the Machines list (synced from SAP) and finds its FERT.',
      'The customer picks a language. Only languages that have a published manual for that FERT are offered.',
      'They enter their name. The mobile number is optional, and any country is accepted with the + country code.',
      'The portal shows the published manuals for that FERT and language. Pages open in the protected viewer with the customer’s name and serial number as a watermark.',
    ],
    tips: [
      'A customer sees a manual only when three things are true: the serial number is in Machines, its FERT is active, and a published manual is mapped to that FERT in their language.',
    ],
  },
  {
    id: 'setup',
    title: 'Getting started checklist',
    icon: Lightbulb,
    summary: 'Do these once, in this order, so customers can find their manuals.',
    steps: [
      'Machines: check that machines have been synced from SAP. If the list is empty, run Sync now for the date range you need.',
      'Master data → FERT: check the machine models. New FERTs are added automatically by the SAP sync.',
      'Master data → Languages and Manual types: keep only the ones you use active.',
      'Manuals: upload each manual PDF, choose its language and type, and map it to every FERT it applies to. Set it to Published.',
      'QR management: generate QR labels and print them for the machines.',
      'Test: scan a label with a phone, enter a real serial number and open the manual.',
    ],
  },
  {
    id: 'machines',
    title: 'Machines and the SAP sync',
    icon: Cpu,
    summary: 'The list of machine serial numbers with their FERT, invoice, plant and delivery date. It is copied from SAP; customers can only find machines that are in this list.',
    link: { to: '/admin/machines', label: 'Open Machines' },
    steps: [
      'Open Machines. The header shows the total count and the time and result of the last sync.',
      'To pull machines now, choose a from and to date under Sync from SAP and click Sync now. One sync from this page can cover up to one year.',
      'Use the search box to find a machine by serial number, FERT, description or invoice.',
    ],
    points: [
      { term: 'Automatic sync', text: 'SAP is synced automatically every Monday and Thursday at 07:30 for the last few days, so newly invoiced machines appear on their own.' },
      { term: 'Source', text: 'SAP means the record came from SAP. File means it came from the sample data file used for testing.' },
      { term: 'Serial clean-up', text: 'SAP item numbers such as “10.” are removed, and one invoice line listing several serial numbers is split into separate machines.' },
    ],
    tips: [
      'If a customer says their serial number is not found, search for it here. If it is missing, sync the date range when the machine was invoiced.',
      'A red “Last sync failed” message usually means the server could not reach SAP. Try again later or contact IT.',
    ],
  },
  {
    id: 'ferts',
    title: 'FERT master (machine models)',
    icon: ClipboardList,
    summary: 'A FERT is the SAP material number of a machine model. Every machine serial number belongs to one FERT, and manuals are mapped to FERTs.',
    link: { to: '/admin/masters/ferts', label: 'Open FERT master' },
    steps: [
      'Open Master data → FERT (machine models).',
      'New FERTs are created automatically when a machine with an unknown FERT is synced from SAP. Their name is taken from the SAP description.',
      'Set which QR codes each FERT works with: edit the FERT and tick its QR types (Domestic, Export, Export (Non-BULL)). A machine then opens only from a QR code of those types.',
      'To add or map many FERTs at once, click Import JSON / CSV. A CSV needs FERT and DESCRIPTION columns, and can have a QR_TYPE column such as domestic, domestic;export or all.',
      'Click Export Excel to download the FERT list, for example to plan which manuals each model needs.',
      'Use the edit button to correct a name, or set a FERT to Inactive to hide its manuals from customers.',
    ],
    tips: [
      'Do not delete a FERT that machines still use. Set it to Inactive instead.',
      'A FERT with no QR type ticked opens from every QR code. Use the QR type filter → “Not mapped to a QR type” to find the FERTs still to map. New FERTs from the SAP sync start unmapped.',
    ],
  },
  {
    id: 'manuals',
    title: 'Manuals',
    icon: BookOpen,
    summary: 'Upload manual PDFs, keep versions, map them to FERTs and control what customers see.',
    link: { to: '/admin/manuals', label: 'Open Manuals' },
    steps: [
      'Click Upload manual. Enter the title, manual type, version (for example 1.0), language and effective date.',
      'Under Machine models (FERT), search by FERT number or name and tick every model this manual applies to.',
      'Choose the initial status and the PDF file (up to 200 MB), then save.',
      'To change which models a manual covers later, click Map FERTs on its row.',
      'When a manual is revised, click Add version and upload the new PDF. Customers see the newest published version first and can still open older ones.',
      'Use Publish and Unpublish to show or hide the whole manual. Use the history button to archive a single old version.',
    ],
    points: [
      { term: 'Draft', text: 'Saved but not visible to customers.' },
      { term: 'Pending review', text: 'Waiting for approval; not visible to customers.' },
      { term: 'Published', text: 'Visible to customers whose machine has a mapped FERT.' },
      { term: 'Unpublished', text: 'Hidden from customers; can be published again.' },
      { term: 'Archived', text: 'Retired; kept for records only.' },
    ],
    tips: [
      'One manual has one language. Upload a separate manual for each language and map each to the same FERTs.',
      'If a customer sees “No manuals have been published for this machine yet”, the FERT has no published manual in any language. Map one with Map FERTs.',
    ],
  },
  {
    id: 'qr',
    title: 'QR codes',
    icon: QrCode,
    summary: 'QR labels are the entry point to the portal. One QR code can be used on many machines, because the customer identifies the machine by its serial number.',
    link: { to: '/admin/qr-codes', label: 'Open QR management' },
    steps: [
      'Click Generate QR code, choose the QR type, give it a label (for example “Front dashboard label”) and an expiry date if needed.',
      'Open the code to see the QR image and its secure link. Use Copy link to share or test it.',
      'Deactivate a code to stop it working. Activate turns it back on.',
      'Regenerate creates a new link and stops the old one immediately. Only use it if a label was misused, because printed labels with the old code will stop working.',
    ],
    tips: [
      'Scans shows how many times a code was opened, and Last scanned shows when.',
    ],
  },
  {
    id: 'reports',
    title: 'Reports',
    icon: BarChart3,
    summary: 'See how customers use the portal.',
    link: { to: '/admin/reports', label: 'Open Reports' },
    points: [
      { term: 'QR scans', text: 'Every scan with date, QR code, machine serial number, FERT and language. Filter by date and use Export CSV to download it.' },
      { term: 'Manual usage', text: 'Total views and unique customers for each manual.' },
      { term: 'Customers', text: 'Customers who opened manuals, with their last access and sessions.' },
    ],
  },
  {
    id: 'enquiries',
    title: 'Support requests',
    icon: MessageSquare,
    summary: 'Messages customers send from the Contact support form on the portal.',
    link: { to: '/admin/enquiries', label: 'Open Support requests' },
    steps: [
      'Open Support requests to see new messages. Context shows the customer’s machine serial number and model when they were signed in.',
      'Change the status to In progress while you handle it and to Closed when it is resolved.',
    ],
  },
  {
    id: 'settings',
    title: 'Settings and audit log',
    icon: Settings,
    summary: 'Portal-wide settings and a record of every admin action.',
    link: { to: '/admin/settings', label: 'Open Settings' },
    points: [
      { term: 'Maximum manual file size', text: 'The largest PDF that can be uploaded.' },
      { term: 'Support contact', text: 'The email or phone number shown to customers for help.' },
      { term: 'Audit logs', text: 'Who created, changed, published, deleted or synced what, and when. Use it to trace any change.' },
    ],
  },
  {
    id: 'roles',
    title: 'Roles and permissions',
    icon: Users,
    summary: 'What each admin role can do.',
    points: [
      { term: 'Super Admin', text: 'Everything, including settings, audit logs and admin users.' },
      { term: 'Admin', text: 'Dashboard, master data, machines, manuals, QR codes, reports and support requests.' },
      { term: 'Viewer', text: 'Dashboard, reports and support requests (read only).' },
    ],
    tips: [
      'Menus you do not have permission for show an error when opened. Ask a Super Admin to change your role.',
      'Nobody can change their own role or give the Super Admin role to others, for security.',
    ],
  },
  {
    id: 'faq',
    title: 'Common customer problems',
    icon: CircleHelp,
    summary: 'Quick answers when a customer calls for help.',
    points: [
      { term: '“We could not find a machine with that serial number”', text: 'Search the serial number in Machines. If it is missing, sync the month the machine was invoiced. Check the customer is typing the number from the machine plate.' },
      { term: '“Manuals for this machine are not available yet”', text: 'The machine’s FERT is set to Inactive in the FERT master, or the machine has no FERT in SAP.' },
      { term: '“No manuals have been published for this machine yet”', text: 'No published manual is mapped to the machine’s FERT. Map one in Manuals → Map FERTs.' },
      { term: '“Manual not available” after choosing a language', text: 'There is no published manual in that language for this FERT. Upload one, or ask the customer to choose another language.' },
      { term: '“This machine is not available on this QR code”', text: 'The customer scanned a QR code of a type (for example Export) that the machine’s FERT is not mapped to. Check the label on the machine, or correct the FERT’s QR types in the FERT master.' },
      { term: '“QR code not found”', text: 'The code was deactivated, expired or regenerated. Check it in QR management.' },
      { term: 'Mobile number rejected', text: 'Numbers outside India must start with + and the country code, for example +971 50 123 4567. The mobile number is optional, so the customer can also leave it empty.' },
    ],
  },
]

export function AdminHelpPage() {
  const [search, setSearch] = useState('')
  const term = search.trim().toLowerCase()
  const visible = term ? sections.filter((section) => sectionText(section).includes(term)) : sections

  return <div className="admin-page help-page"><div className="page-heading"><div><span className="eyebrow"><span className="eyebrow-line" /> USER GUIDE</span><h1>Help</h1><p>How the portal works and how to use each admin screen, step by step.</p></div></div><div className="toolbar"><div className="search-box"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search the guide, e.g. sync, FERT, QR" /></div><span className="toolbar-count">{visible.length} topics</span></div><div className="help-layout"><nav className="help-toc" aria-label="Guide topics">{visible.map((section) => <a key={section.id} href={`#help-${section.id}`}><section.icon size={15} />{section.title}</a>)}</nav><div className="help-sections">{visible.length === 0 ? <div className="panel help-empty"><strong>No topics match “{search}”.</strong><span>Try another word, such as machine, manual or QR.</span></div> : visible.map((section) => <HelpCard key={section.id} section={section} />)}</div></div></div>
}

function HelpCard({ section }: { section: HelpSection }) {
  const Icon = section.icon
  return <section id={`help-${section.id}`} className="panel settings-panel help-card"><div className="settings-panel-heading"><div className="settings-heading-icon"><Icon size={19} /></div><div><h2>{section.title}</h2><p>{section.summary}</p></div></div>{section.steps && <ol className="help-steps">{section.steps.map((step) => <li key={step}>{step}</li>)}</ol>}{section.points && <dl className="help-points">{section.points.map((point) => <div key={point.term}><dt>{point.term}</dt><dd>{point.text}</dd></div>)}</dl>}{section.tips && <div className="delivery-note help-tips"><ShieldCheck size={17} /><div>{section.tips.map((tip) => <span key={tip}>{tip}</span>)}</div></div>}{section.link && <Link className="table-action help-link" to={section.link.to}>{section.link.label} <ArrowRight size={14} /></Link>}</section>
}

function sectionText(section: HelpSection): string {
  return [section.title, section.summary, ...(section.steps ?? []), ...(section.tips ?? []), ...(section.points ?? []).flatMap((point) => [point.term, point.text])].join(' ').toLowerCase()
}
