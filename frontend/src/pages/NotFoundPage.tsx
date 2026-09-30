import { Link } from 'react-router-dom'
import { BrandMark } from '../components/BrandMark'

export function NotFoundPage() {
  return <div className="customer-app"><header className="customer-header shell-width"><BrandMark /></header><main className="shell-width narrow-state"><div className="not-found-code">404</div><h1>Page not found</h1><p>The page you requested does not exist.</p><Link className="button button-primary" to="/">Return to portal</Link></main></div>
}
