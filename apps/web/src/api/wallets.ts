import { api, unwrap } from './client';
import type { Id, Transaction, Visibility, Wallet, WalletType } from './types';

const base = (h: Id) => `/households/${h}/wallets`;

export const walletsApi = {
  list: (h: Id, opts: { includeArchived?: boolean } = {}) =>
    unwrap(api.get<{ data: Wallet[] }>(base(h), { params: opts.includeArchived ? { include_archived: 1 } : {} })),
  create: (h: Id, body: { name: string; type: WalletType; visibility?: Visibility; opening_balance?: number }) =>
    unwrap(api.post<{ data: Wallet }>(base(h), body)),
  update: (h: Id, id: Id, body: { name?: string; type?: WalletType; opening_balance?: number }) =>
    unwrap(api.patch<{ data: Wallet }>(`${base(h)}/${id}`, body)),
  archive: (h: Id, id: Id) => unwrap(api.post<{ data: Wallet }>(`${base(h)}/${id}/archive`)),
  unarchive: (h: Id, id: Id) => unwrap(api.post<{ data: Wallet }>(`${base(h)}/${id}/unarchive`)),
  remove: (h: Id, id: Id) => api.delete(`${base(h)}/${id}`),
  adjust: (h: Id, id: Id, body: { actual_balance: number; occurred_on: string; note?: string | null }) =>
    unwrap(api.post<{ data: { wallet: Wallet; adjustment: Transaction | null } }>(`${base(h)}/${id}/adjust`, body)),
};
