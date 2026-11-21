import { z } from 'zod';
import { amountSchema, clientIdSchema, idSchema, localDateSchema, noteSchema } from './common';

/** Padanan StoreTransactionRequest. Hanya income/expense; transfer & adjustment punya endpoint sendiri. */
export const createTransactionSchema = z.object({
  wallet_id: idSchema,
  type: z.enum(['income', 'expense']),
  amount: amountSchema,
  occurred_on: localDateSchema,
  category_id: idSchema.nullable().optional(),
  note: noteSchema,
  // Wajib (Keputusan #11): web membuatnya per submit, mobile saat item masuk antrean.
  client_id: clientIdSchema,
});

/** Padanan UpdateTransactionRequest (PATCH parsial). client_id & status tidak bisa diubah. */
export const updateTransactionSchema = createTransactionSchema.omit({ client_id: true }).partial().strict();

export type CreateTransactionInput = z.infer<typeof createTransactionSchema>;
export type UpdateTransactionInput = z.infer<typeof updateTransactionSchema>;
