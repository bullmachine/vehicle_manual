import { useId } from 'react'

type PhoneFieldProps = {
  value: string
  onChange: (value: string) => void
  required?: boolean
  autoComplete?: string
  disabled?: boolean
  id?: string
}

/**
 * Single mobile number input with no country selector.
 *
 * Visitors type the number as they know it, with or without a country code
 * ("9876543210" or "+971 50 123 4567"). The server normalises it and decides
 * whether it is valid; a number without "+" is read as the default country.
 */
export function PhoneField({ value, onChange, required, autoComplete = 'tel', disabled, id }: PhoneFieldProps) {
  const inputId = useId()

  return (
    <input
      id={id ?? inputId}
      type="tel"
      value={value}
      // Keep only characters that appear in phone numbers.
      onChange={(event) => onChange(event.target.value.replace(/[^\d+\s()-]/g, '').slice(0, 20))}
      placeholder="e.g. 9876543210 or +971 50 123 4567"
      inputMode="tel"
      autoComplete={autoComplete}
      required={required}
      disabled={disabled}
    />
  )
}
