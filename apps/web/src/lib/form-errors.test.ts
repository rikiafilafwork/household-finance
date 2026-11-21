import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { applyServerErrors } from './form-errors';

describe('applyServerErrors', () => {
  it('maps known fields to the form and returns the rest as a general message', () => {
    const setError = vi.fn();
    const error = new ApiError(422, 'invalid', {
      amount: ['Nominal harus angka bulat.'],
      client_id: ['client_id sudah dipakai. Buat client_id baru.'],
    });

    const general = applyServerErrors(error, setError, ['amount', 'wallet_id']);

    expect(setError).toHaveBeenCalledWith('amount', { type: 'server', message: 'Nominal harus angka bulat.' });
    expect(setError).toHaveBeenCalledTimes(1);
    expect(general).toBe('client_id sudah dipakai. Buat client_id baru.');
  });

  it('returns null for non-validation API errors (already toasted)', () => {
    expect(applyServerErrors(new ApiError(403, 'x'), vi.fn(), [])).toBeNull();
  });

  it('returns a generic message for unknown errors', () => {
    expect(applyServerErrors(new Error('boom'), vi.fn(), [])).toBe('Terjadi kesalahan. Coba lagi.');
  });
});
