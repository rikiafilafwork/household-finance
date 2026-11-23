import { api, unwrap } from './client';
import type { Account, AccountType, Id, Transaction } from './types';

const base = '/accounts';

export const accountsApi = {
  list: (opts: { includeArchived?: boolean } = {}) =>
    unwrap(api.get<{ data: Account[] }>(base, { params: opts.includeArchived ? { include_archived: 1 } : {} })),
  create: (body: { name: string; type: AccountType; opening_balance?: number; sort_order?: number }) =>
    unwrap(api.post<{ data: Account }>(base, body)),
  update: (id: Id, body: { name?: string; type?: AccountType; opening_balance?: number; sort_order?: number }) =>
    unwrap(api.patch<{ data: Account }>(`${base}/${id}`, body)),
  archive: (id: Id) => unwrap(api.post<{ data: Account }>(`${base}/${id}/archive`)),
  unarchive: (id: Id) => unwrap(api.post<{ data: Account }>(`${base}/${id}/unarchive`)),
  remove: (id: Id) => api.delete(`${base}/${id}`),
  adjust: (id: Id, body: { actual_balance: number; occurred_on: string; note?: string | null }) =>
    unwrap(api.post<{ data: { account: Account; adjustment: Transaction | null } }>(`${base}/${id}/adjust`, body)),
};
