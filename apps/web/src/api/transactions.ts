import type { CreateTransactionInput, CreateTransferInput, UpdateTransactionInput, UpdateTransferInput } from '@personal-finance/shared';
import { api, unwrap } from './client';
import type { CursorPage, Id, Transaction, TransactionType, Transfer } from './types';

export interface TransactionFilters {
  account_id?: Id;
  category_id?: Id;
  type?: TransactionType;
  from?: string;
  to?: string;
  q?: string;
}

/** Buang filter kosong supaya URL & query key stabil. */
export function cleanFilters(filters: TransactionFilters): TransactionFilters {
  return Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as TransactionFilters;
}

export const transactionsApi = {
  list: async (filters: TransactionFilters, cursor?: string | null, perPage = 50) =>
    (await api.get<CursorPage<Transaction>>('/transactions', {
      params: { ...cleanFilters(filters), per_page: perPage, ...(cursor ? { cursor } : {}) },
    })).data,
  get: (id: Id) => unwrap(api.get<{ data: Transaction }>(`/transactions/${id}`)),
  /** 201 = baru, 200 = retry client_id yang sama (Keputusan #15). Keduanya sukses. */
  create: (body: CreateTransactionInput) => unwrap(api.post<{ data: Transaction }>('/transactions', body)),
  update: (id: Id, body: UpdateTransactionInput) => unwrap(api.patch<{ data: Transaction }>(`/transactions/${id}`, body)),
  remove: (id: Id) => api.delete(`/transactions/${id}`),
};

export const transfersApi = {
  get: (groupId: string) => unwrap(api.get<{ data: Transfer }>(`/transfers/${groupId}`)),
  create: (body: CreateTransferInput) => unwrap(api.post<{ data: Transfer }>('/transfers', body)),
  update: (groupId: string, body: UpdateTransferInput) => unwrap(api.patch<{ data: Transfer }>(`/transfers/${groupId}`, body)),
  remove: (groupId: string) => api.delete(`/transfers/${groupId}`),
};
