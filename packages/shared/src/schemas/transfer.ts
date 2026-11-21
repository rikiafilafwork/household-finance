import { z } from 'zod';
import { amountSchema, clientIdSchema, idSchema, localDateSchema, noteSchema } from './common';

const transferFields = z.object({
  from_wallet_id: idSchema,
  to_wallet_id: idSchema,
  amount: amountSchema,
  occurred_on: localDateSchema,
  note: noteSchema,
  client_id: clientIdSchema,
});

const differentWallets = (value: { from_wallet_id?: number; to_wallet_id?: number }) =>
  value.from_wallet_id === undefined || value.to_wallet_id === undefined || value.from_wallet_id !== value.to_wallet_id;

/** Padanan StoreTransferRequest. */
export const createTransferSchema = transferFields.refine(differentWallets, {
  message: 'Wallet asal dan tujuan harus berbeda.',
  path: ['to_wallet_id'],
});

/** Padanan UpdateTransferRequest (PATCH parsial). */
export const updateTransferSchema = transferFields
  .omit({ client_id: true })
  .partial()
  .strict()
  .refine(differentWallets, { message: 'Wallet asal dan tujuan harus berbeda.', path: ['to_wallet_id'] });

export type CreateTransferInput = z.infer<typeof createTransferSchema>;
export type UpdateTransferInput = z.infer<typeof updateTransferSchema>;
