import { ArrowLeft, ChevronLeft, ChevronRight, Expand, LoaderCircle, Minus, Plus, ShieldCheck, X } from 'lucide-react'
import type { CSSProperties } from 'react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import * as pdfjsLib from 'pdfjs-dist'
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { apiFetch, apiUrl, jsonBody } from '../lib/api'
import { errorMessage } from '../lib/format'
import type { CustomerSession, ManualVersion } from '../lib/types'
import { ErrorState, LoadingState } from '../components/States'

pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc

type ManualDetail = {
  id: number
  title: string
  type?: string
  vehicle?: string
  engine?: string
  language?: string
  version: ManualVersion
}

export function ManualViewerPage() {
  const { manualId = '' } = useParams()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [detail, setDetail] = useState<ManualDetail | null>(null)
  const [session, setSession] = useState<CustomerSession | null>(null)
  const [page, setPage] = useState(1)
  const [pageCount, setPageCount] = useState(1)
  const [zoom, setZoom] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [pageLoading, setPageLoading] = useState(true)
  const [feedback, setFeedback] = useState('')
  const [feedbackSent, setFeedbackSent] = useState(false)
  const [pdfDoc, setPdfDoc] = useState<pdfjsLib.PDFDocumentProxy | null>(null)
  const canvasRef = useRef<HTMLCanvasElement | null>(null)

  const versionId = params.get('version') ?? ''

  useEffect(() => {
    Promise.all([
      apiFetch<{ data: ManualDetail }>(`/customer/manuals/${manualId}${versionId ? `?version_id=${versionId}` : ''}`),
      apiFetch<{ data: CustomerSession }>('/customer/session'),
    ]).then(([manualResult, sessionResult]) => {
      setDetail(manualResult.data)
      setSession(sessionResult.data)
      setPage(1)
    }).catch((reason) => setError(errorMessage(reason))).finally(() => setLoading(false))
  }, [manualId, versionId])

  useEffect(() => {
    if (!detail) return
    const fileUrl = `${apiUrl}/customer/manuals/${manualId}/file${versionId ? `?version_id=${versionId}` : ''}`
    const task = pdfjsLib.getDocument({ url: fileUrl, withCredentials: true })
    task.promise
      .then((document) => {
        setPdfDoc(document)
        setPageCount(document.numPages)
        setPage(1)
      })
      .catch((reason) => setError(errorMessage(reason)))

    return () => {
      setPdfDoc(null)
      void task.destroy()
    }
  }, [detail, manualId, versionId])

  useEffect(() => {
    if (!pdfDoc) return
    const canvas = canvasRef.current
    if (!canvas) return
    let cancelled = false
    setPageLoading(true)
    pdfDoc.getPage(page)
      .then((pdfPage) => {
        if (cancelled) return
        const deviceScale = Math.min(window.devicePixelRatio || 1, 2)
        const viewport = pdfPage.getViewport({ scale: 2 * deviceScale })
        canvas.width = viewport.width
        canvas.height = viewport.height
        return pdfPage.render({ canvas, viewport })
      })
      .then(() => {
        if (!cancelled) setPageLoading(false)
      })
      .catch((reason) => {
        if (cancelled) return
        setPageLoading(false)
        setError(errorMessage(reason))
      })

    return () => { cancelled = true }
  }, [pdfDoc, page])

  useEffect(() => {
    const prevent = (event: Event) => event.preventDefault()
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && ['p', 's', 'u'].includes(event.key.toLowerCase())) event.preventDefault()
      if (event.key === 'ArrowLeft') setPage((value) => Math.max(1, value - 1))
      if (event.key === 'ArrowRight') setPage((value) => Math.min(pageCount, value + 1))
    }
    document.addEventListener('contextmenu', prevent)
    document.addEventListener('dragstart', prevent)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('contextmenu', prevent); document.removeEventListener('dragstart', prevent); document.removeEventListener('keydown', onKey) }
  }, [pageCount])

  async function openFullscreen() {
    const element = document.documentElement
    if (!document.fullscreenElement) await element.requestFullscreen()
    else await document.exitFullscreen()
  }

  async function sendFeedback(rating: 'yes' | 'no') {
    setFeedback(rating)
    setFeedbackSent(false)
  }

  async function submitFeedback() {
    try {
      await apiFetch(`/customer/manuals/${manualId}/feedback`, jsonBody({ rating: feedback }))
      setFeedbackSent(true)
    } catch (reason) {
      setError(errorMessage(reason))
    }
  }

  if (loading) return <div className="viewer-page"><LoadingState label="Preparing protected viewer…" /></div>
  if (error || !detail) return <div className="viewer-page"><div className="viewer-error"><ErrorState message={error || 'Manual not found.'} onRetry={() => navigate(-1)} /><button className="button button-ghost" onClick={() => navigate(-1)}><ArrowLeft size={17} /> Back to manuals</button></div></div>

  const pageCount = Math.max(1, detail.version.page_count)
  const watermark = session ? `${session.customer.name} · ${session.customer.mobile} · ${session.id}` : 'Protected customer session'
  return <div className="viewer-page" onContextMenu={(event) => event.preventDefault()}>
    <header className="viewer-header"><button className="viewer-back" onClick={() => navigate(-1)}><ArrowLeft size={18} /><span>Manuals</span></button><div className="viewer-title"><strong>{detail.title}</strong><span>Version {detail.version.version} · {detail.language}</span></div><div className="viewer-actions"><span className="viewer-protected"><ShieldCheck size={15} /> Protected</span><button className="icon-button" onClick={openFullscreen} aria-label="Full screen"><Expand size={19} /></button></div></header>
    <main className="viewer-main"><div className="viewer-stage" style={{ '--viewer-zoom': zoom } as CSSProperties}><div className="page-shadow" /><canvas ref={canvasRef} className="manual-page-canvas" /><div className="watermark-layer" aria-hidden="true"><span>{watermark}</span></div>{(!pdfDoc || pageLoading) && <div className="page-loader"><LoaderCircle className="spin" size={30} /><span>Loading protected page…</span></div>}</div></main>
    <footer className="viewer-controls"><div className="zoom-controls"><button className="icon-button" onClick={() => setZoom((value) => Math.max(0.7, Number((value - 0.1).toFixed(1))))} aria-label="Zoom out"><Minus size={19} /></button><span>{Math.round(zoom * 100)}%</span><button className="icon-button" onClick={() => setZoom((value) => Math.min(1.8, Number((value + 0.1).toFixed(1))))} aria-label="Zoom in"><Plus size={19} /></button></div><div className="page-controls"><button className="icon-button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} aria-label="Previous page"><ChevronLeft size={21} /></button><span><strong>{page}</strong> / {pageCount}</span><button className="icon-button" disabled={page >= pageCount} onClick={() => setPage((value) => Math.min(pageCount, value + 1))} aria-label="Next page"><ChevronRight size={21} /></button></div><div className="viewer-security-note"><ShieldCheck size={15} /> Watermarked for you</div></footer>
    <section className="viewer-feedback"><div><strong>Was this manual helpful?</strong><span>Your feedback helps us improve the operator experience.</span></div>{feedbackSent ? <span className="feedback-thanks"><ShieldCheck size={16} /> Thank you</span> : <div className="feedback-actions"><button className={feedback === 'yes' ? 'selected' : ''} onClick={() => void sendFeedback('yes')}>Yes, helpful</button><button className={feedback === 'no' ? 'selected' : ''} onClick={() => void sendFeedback('no')}>Not quite</button>{feedback && <button className="feedback-submit" onClick={() => void submitFeedback()}>Send feedback</button>}<button className="feedback-close" onClick={() => setFeedback('')} aria-label="Close feedback"><X size={15} /></button></div>}</section>
  </div>
}
