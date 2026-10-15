import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { z } from 'zod'
import { ApiError } from './api/client'

/**
 * Maps a 422 VALIDATION_FAILED response onto react-hook-form fields.
 * Returns a message for errors that do not belong to a single field.
 */
export function applyApiErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>): string | null {
  if (!(error instanceof ApiError)) return 'Terjadi kesalahan yang tidak terduga.'
  const entries = Object.entries(error.fields)
  if (error.code === 'VALIDATION_FAILED' && entries.length > 0) {
    for (const [field, messages] of entries) {
      setError(field as Path<T>, { type: 'server', message: messages[0] })
    }
    return null
  }
  return error.message
}

export function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Terjadi kesalahan yang tidak terduga.'
}

/** Password policy mirrored from the backend (min 12, upper + lower case, digit). */
export const passwordRule = {
  min: 12,
  test: (v: string) => v.length >= 12 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v),
  message: 'Minimal 12 karakter, mengandung huruf besar, huruf kecil, dan angka.',
}

/** Organization code as accepted by the API: 2–30 letters, digits, underscore or dash. */
export const organizationCodeRule = z
  .string()
  .trim()
  .regex(/^[A-Za-z0-9][A-Za-z0-9_-]{1,29}$/, '2–30 karakter: huruf, angka, garis bawah, atau strip.')

/** Suggested organization code from its name, e.g. "PT Maju Jaya" → "PTMAJUJAYA". */
export const suggestCode = (name: string) => name.toUpperCase().replace(/[^A-Z0-9]+/g, '').slice(0, 12)
