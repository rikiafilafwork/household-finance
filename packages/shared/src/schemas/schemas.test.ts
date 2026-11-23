import { describe, expect, it } from 'vitest';
import { newClientId } from '../client-id';
import { createTransactionSchema, createTransferSchema, updateTransactionSchema, updateTransferSchema } from './index';

const tx = () => ({
  account_id: 1,
  type: 'expense' as const,
  amount: 25_000,
  occurred_on: '2026-11-21',
  category_id: 3,
  note: 'Kopi',
  client_id: newClientId(),
});

describe('createTransactionSchema', () => {
  it('accepts a valid payload', () => {
    expect(createTransactionSchema.safeParse(tx()).success).toBe(true);
  });

  it('requires a UUID v4 client_id and lowercases it', () => {
    const upper = newClientId().toUpperCase();
    expect(createTransactionSchema.parse({ ...tx(), client_id: upper }).client_id).toBe(upper.toLowerCase());
    expect(createTransactionSchema.safeParse({ ...tx(), client_id: undefined }).success).toBe(false);
    expect(createTransactionSchema.safeParse({ ...tx(), client_id: 'bukan-uuid' }).success).toBe(false);
  });

  // Sama dengan kasus yang ditolak Laravel (TransactionActionsTest).
  it.each([
    ['negative amount', { amount: -25_000 }],
    ['zero amount', { amount: 0 }],
    ['float amount', { amount: 25_000.5 }],
    ['string amount', { amount: '25.000' }],
    ['transfer type', { type: 'transfer' }],
    ['adjustment type', { type: 'adjustment' }],
    ['bad date format', { occurred_on: '21/11/2026' }],
    ['impossible date', { occurred_on: '2026-02-30' }],
    ['note too long', { note: 'x'.repeat(256) }],
  ])('rejects %s', (_case, override) => {
    expect(createTransactionSchema.safeParse({ ...tx(), ...override }).success).toBe(false);
  });

  it('accepts leap day only in leap years', () => {
    expect(createTransactionSchema.safeParse({ ...tx(), occurred_on: '2028-02-29' }).success).toBe(true);
    expect(createTransactionSchema.safeParse({ ...tx(), occurred_on: '2026-02-29' }).success).toBe(false);
  });
});

describe('updateTransactionSchema', () => {
  it('accepts partial updates but forbids client_id and status', () => {
    expect(updateTransactionSchema.safeParse({ amount: 40_000 }).success).toBe(true);
    expect(updateTransactionSchema.safeParse({ client_id: newClientId() }).success).toBe(false);
    expect(updateTransactionSchema.safeParse({ status: 'pending' }).success).toBe(false);
  });
});

describe('transfer schemas', () => {
  const transfer = () => ({
    from_account_id: 1,
    to_account_id: 2,
    amount: 500_000,
    occurred_on: '2026-11-21',
    client_id: newClientId(),
  });

  it('accepts a valid transfer', () => {
    expect(createTransferSchema.safeParse(transfer()).success).toBe(true);
  });

  it('rejects the same account on both ends, on create and update', () => {
    const create = createTransferSchema.safeParse({ ...transfer(), to_account_id: 1 });
    expect(create.success).toBe(false);
    expect(create.error?.issues[0]?.path).toEqual(['to_account_id']);
    expect(updateTransferSchema.safeParse({ from_account_id: 4, to_account_id: 4 }).success).toBe(false);
  });

  it('allows a partial update of one end', () => {
    expect(updateTransferSchema.safeParse({ to_account_id: 3 }).success).toBe(true);
  });
});
