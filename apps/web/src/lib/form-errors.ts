import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { ApiError } from '@/api/client';

/**
 * 422 dari Laravel → pesan per field di react-hook-form. Field yang tidak ada di form
 * (mis. `client_id`, `category`) dikembalikan sebagai pesan umum untuk ditampilkan di atas form.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  knownFields: readonly string[],
): string | null {
  if (!(error instanceof ApiError) || !error.isValidation) {
    return error instanceof ApiError ? null : 'Terjadi kesalahan. Coba lagi.';
  }

  const general: string[] = [];
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    const message = messages[0];
    if (!message) continue;
    if (knownFields.includes(field)) {
      setError(field as Path<T>, { type: 'server', message });
    } else {
      general.push(message);
    }
  }

  return general.length > 0 ? general.join(' ') : Object.keys(error.fieldErrors).length === 0 ? error.message : null;
}
