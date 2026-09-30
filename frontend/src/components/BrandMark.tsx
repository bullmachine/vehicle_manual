import bullLogo from '../assets/bul_logo.png'

export function BrandMark({ light = false }: { light?: boolean }) {
  return (
    <div className={`brand-mark${light ? ' brand-mark-light' : ''}`}>
      <img className="brand-logo" src={bullLogo} alt="BULL" />
    </div>
  )
}
