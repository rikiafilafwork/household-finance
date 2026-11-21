import type { CreateTransactionInput, CreateTransferInput, UpdateTransactionInput, UpdateTransferInput } from '@household-finance/shared';
import { api, unwrap } from './client';
import type { CursorPage, Id, Transaction, TransactionStatus, TransactionType, Transfer } from './types';

export interface TransactionFilters {
  wallet_id?: Id;
  category_id?: Id;
  type?: TransactionType;
  status?: TransactionStatus;
  from?: string;
  to?: string;
  q?: string;
}

const base = (h: Id) => `/households/${h}`;

/** Buang filter kosong supaya URL & query key stabil. */
export function cleanFilters(filters: TransactionFilters): TransactionFilters {
  return Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as TransactionFilters;
}

export const transactionsApi = {
  list: async (h: Id, filters: TransactionFilters, cursor?: string | null, perPage = 50) =>
    (await api.get<CursorPage<Transaction>>(`${base(h)}/transactions`, {
      params: { ...cleanFilters(filters), per_page: perPage, ...(cursor ? { cursor } : {}) },
    })).data,
  get: (h: Id, id: Id) => unwrap(api.get<{ data: Transaction }>(`${base(h)}/transactions/${id}`)),
  /** 201 = baru, 200 = retry client_id yang sama (Keputusan #11). Keduanya sukses. */
  create: (h: Id, body: CreateTransactionInput) => unwrap(api.post<{ data: Transaction }>(`${base(h)}/transactions`, body)),
  update: (h: Id, id: Id, body: UpdateTransactionInput) => unwrap(api.patch<{ data: Transaction }>(`${base(h)}/transactions/${id}`, body)),
  remove: (h: Id, id: Id) => api.delete(`${base(h)}/transactions/${id}`),
};

export const transfersApi = {
  get: (h: Id, groupId: string) => unwrap(api.get<{ data: Transfer }>(`${base(h)}/transfers/${groupId}`)),
  create: (h: Id, body: CreateTransferInput) => unwrap(api.post<{ data: Transfer }>(`${base(h)}/transfers`, body)),
  update: (h: Id, groupId: string, body: UpdateTransferInput) => unwrap(api.patch<{ data: Transfer }>(`${base(h)}/transfers/${groupId}`, body)),
  remove: (h: Id, groupId: string) => api.delete(`${base(h)}/transfers/${groupId}`),
};
