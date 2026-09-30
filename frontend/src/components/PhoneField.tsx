import { useId, useState } from 'react'
import { ChevronDown } from 'lucide-react'
import { composeE164, dialFor, nationalDigits, placeholderFor, usePhoneCountries } from '../lib/phone'

type PhoneFieldProps = {
  value: string
  onChange: (e164: string) => void
  required?: boolean
  autoComplete?: string
  disabled?: boolean
  id?: string
}

/**
 * Mobile number input with a country selector.
 *
 * Emits canonical E.164 so the caller can post it directly. The server stays the
 * authority on whether a number is genuinely valid for the chosen country.
 */
export function PhoneField({ value, onChange, required, autoComplete = 'tel', disabled, id }: PhoneFieldProps) {
  const countries = usePhoneCountries()
  const inputId = useId()
  const [chosen, setChosen] = useState<string | null>(null)

  // Follow the server default until the visitor picks a country, then leave their choice alone.
  const region = chosen ?? countries.default
  const dial = dialFor(countries, region)

  const handleDigits = (raw: string) => {
    const digits = nationalDigits(raw)
    onChange(digits ? composeE164(dial, digits) : '')
  }

  // `dial` already carries the leading plus, e.g. "+971".
  const national = value.startsWith(dial) ? value.slice(dial.length) : nationalDigits(value)

  return (
    <div className="input-with-prefix phone-field">
      <span className="phone-field-dial">
        <select
          aria-label="Country code"
          value={region}
          disabled={disabled || !countries.ready}
          onChange={(event) => {
            setChosen(event.target.value)
            onChange('')
          }}
        >
          {countries.countries.map((country) => (
            <option key={country.code} value={country.code}>
              {country.dial} {country.name}
            </option>
          ))}
        </select>
        <ChevronDown className="phone-field-chevron" size={14} aria-hidden="true" />
      </span>
      <input
        id={id ?? inputId}
        value={national}
        onChange={(event) => handleDigits(event.target.value)}
        placeholder={placeholderFor(region)}
        inputMode="tel"
        autoComplete={autoComplete}
        required={required}
        disabled={disabled}
      />
    </div>
  )
}
