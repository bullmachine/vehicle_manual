export type Option = {
  id: number
  code: string
  name: string
  native_name?: string | null
}

export type QrContext = {
  qr: {
    reference: string
    type: { id: number; code: string; name: string }
    label: string
  }
  selection: {
    vehicle_variant_id?: number
    vehicle_variant?: Option
    engine_id?: number
    engine?: Option
    language_id?: number
    language?: Option
    vehicle_required: boolean
    engine_required: boolean
    language_required: boolean
  }
  options: {
    vehicle_variants: Option[]
    engines: Option[]
    languages: Option[]
  }
  otp_enabled: boolean
  otp_length: number
}

export type CustomerSession = {
  id: number
  customer: { id: number; name: string; mobile: string }
  vehicle_variant: { id: number; name: string }
  engine: { id: number; name: string }
  language: { id: number; name: string }
  expires_at: string
}

export type ManualVersion = {
  id: number
  version: string
  published_at?: string | null
  effective_date?: string | null
  page_count: number
}

export type ManualCard = {
  id: number
  title: string
  type?: string | null
  vehicle?: string | null
  engine?: string | null
  language?: string | null
  updated_at?: string | null
  versions: ManualVersion[]
}

export type AdminManualVersion = {
  id: number
  version: string
  status: string
  effective_date?: string | null
  published_at?: string | null
  page_count?: number | null
  file_size?: number | null
  original_name?: string | null
  created_at?: string | null
}

export type AdminManualDetail = {
  id: number
  title: string
  status: string
  vehicle_variant?: { name: string } | null
  engine?: { name: string } | null
  language?: { name: string } | null
  manual_type?: { name: string } | null
  updated_at?: string | null
  versions: AdminManualVersion[]
  qr_types?: { id: number; name: string }[]
}

export type AdminUser = {
  id: number
  name: string
  email: string
  username?: string | null
  role?: { id: number; name: string; slug: string } | null
  two_factor_enabled?: boolean
}

export type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type SupportEnquiry = {
  id: number
  name: string
    mobile: string
    mobile_display: string | null
    mobile_region: string | null
  email: string | null
  subject: string
  message: string
  status: 'new' | 'in_progress' | 'closed'
  source: string
  created_at: string
  resolved_at: string | null
  qr: { id: number; reference: string; label: string | null } | null
  vehicle: string | null
}
