import { z } from 'zod';
import { CLIENT_ID_PATTERN } from '../client-id';

/** Batas aman uang, sama dengan StoreWalletRequest::MAX_AMOUNT di Laravel. */
export const MAX_AMOUNT = 1_000_000_000_000_000;

/** Magnitudo uang: integer rupiah positif (tanda diberikan server sesuai tipe). */
export const amountSchema = z
  .number({ error: 'Nominal wajib diisi.' })
  .int('Nominal harus bilangan bulat rupiah.')
  .min(1, 'Nominal harus lebih dari 0.')
  .max(MAX_AMOUNT, 'Nominal terlalu besar.');

/** Saldo boleh negatif (kartu kredit / overdraft). */
export const balanceSchema = z
  .number()
  .int('Saldo harus bilangan bulat rupiah.')
  .min(-MAX_AMOUNT)
  .max(MAX_AMOUNT);

/** Tanggal lokal household, YYYY-MM-DD (Keputusan #7). */
export const localDateSchema = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}$/, 'Format tanggal YYYY-MM-DD.')
  .refine((value) => {
    const [y, m, d] = value.split('-').map(Number) as [number, number, number];
    const date = new Date(Date.UTC(y, m - 1, d));
    return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
  }, 'Tanggal tidak valid.');

export const clientIdSchema = z.string().toLowerCase().regex(CLIENT_ID_PATTERN, 'client_id harus UUID v4.');

export const idSchema = z.number().int().positive();

export const noteSchema = z.string().max(255, 'Catatan maksimal 255 karakter.').nullable().optional();
