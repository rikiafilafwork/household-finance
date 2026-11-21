import type { QueryClient } from '@tanstack/react-query';
import type { TransactionFilters } from './transactions';
import type { Id } from './types';

/** Query key TanStack Query. Semua yang milik household diawali ['households', id]. */
export const qk = {
  me: ['me'] as const,
  households: ['households'] as const,
  household: (h: Id) => ['households', h] as const,
  members: (h: Id) => ['households', h, 'members'] as const,
  invitations: (h: Id) => ['households', h, 'invitations'] as const,
  wallets: (h: Id) => ['households', h, 'wallets'] as const,
  categories: (h: Id) => ['households', h, 'categories'] as const,
  transactions: (h: Id, filters?: TransactionFilters) =>
    (filters ? ['households', h, 'transactions', filters] : ['households', h, 'transactions']) as readonly unknown[],
  budgets: (h: Id, month?: string) => (month ? ['households', h, 'budgets', month] : ['households', h, 'budgets']) as readonly unknown[],
  notifications: ['notifications'] as const,
};

/**
 * Setelah uang bergerak (transaksi, transfer, sesuaikan saldo): saldo wallet, daftar transaksi,
 * dan budget (terpakai) semuanya berubah. Notifikasi ikut, karena bisa ada alert budget baru.
 */
export function invalidateMoney(queryClient: QueryClient, h: Id) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.wallets(h) }),
    queryClient.invalidateQueries({ queryKey: qk.transactions(h) }),
    queryClient.invalidateQueries({ queryKey: qk.budgets(h) }),
    queryClient.invalidateQueries({ queryKey: qk.notifications }),
  ]);
}
