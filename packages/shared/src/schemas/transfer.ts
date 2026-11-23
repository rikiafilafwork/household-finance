import { z } from 'zod';
import { amountSchema, clientIdSchema, idSchema, localDateSchema, noteSchema } from './common';

const transferFields = z.object({
  from_account_id: idSchema,
  to_account_id: idSchema,
  amount: amountSchema,
  occurred_on: localDateSchema,
  note: noteSchema,
  client_id: clientIdSchema,
});

const differentAccounts = (value: { from_account_id?: number; to_account_id?: number }) =>
  value.from_account_id === undefined || value.to_account_id === undefined || value.from_account_id !== value.to_account_id;

/** Padanan StoreTransferRequest. */
export const createTransferSchema = transferFields.refine(differentAccounts, {
  message: 'Akun asal dan tujuan harus berbeda.',
  path: ['to_account_id'],
});

/** Padanan UpdateTransferRequest (PATCH parsial). */
export const updateTransferSchema = transferFields
  .omit({ client_id: true })
  .partial()
  .strict()
  .refine(differentAccounts, { message: 'Akun asal dan tujuan harus berbeda.', path: ['to_account_id'] });

export type CreateTransferInput = z.infer<typeof createTransferSchema>;
export type UpdateTransferInput = z.infer<typeof updateTransferSchema>;
