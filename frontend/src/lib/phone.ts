import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'

export type PhoneCountry = {
  code: string
  dial: string
  name: string
}

export type PhoneCountries = {
  default: string
  countries: PhoneCountry[]
  /**
   * False while the real list is still in flight, so the caller can hold the
   * country selector shut rather than letting someone pick from the fallback.
   */
  ready: boolean
}

/**
 * Keeps the field usable if the country endpoint is unreachable. India only is exactly
 * what the portal accepted before international numbers were introduced, so the worst
 * case is the old behaviour rather than a broken field.
 */
const FALLBACK: PhoneCountries = {
  default: 'IN',
  countries: [{ code: 'IN', dial: '+91', name: 'India' }],
  ready: false,
}

export function usePhoneCountries(): PhoneCountries {
  const { data, isSuccess } = useQuery({
    queryKey: ['phone-countries'],
    queryFn: () => apiFetch<{ data: Omit<PhoneCountries, 'ready'> }>('/public/phone-countries'),
    staleTime: 1000 * 60 * 60,
  })

  return data?.data ? { ...data.data, ready: isSuccess } : FALLBACK
}

export function dialFor(countries: PhoneCountries, code: string): string {
  return countries.countries.find((country) => country.code === code)?.dial ?? `+${countries.default}`
}

/** National part of a number, capped at the E.164 maximum of 15 digits. */
export function nationalDigits(value: string): string {
  return value.replace(/\D/g, '').slice(0, 15)
}

/** Canonical E.164, which is what the API stores and what the API expects back. */
export function composeE164(dial: string, national: string): string {
  return `${dial}${nationalDigits(national)}`
}

export function placeholderFor(code: string): string {
  return code === 'IN' ? '98765 43210' : 'Mobile number'
}
