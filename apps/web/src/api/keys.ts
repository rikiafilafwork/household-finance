import type { QueryClient } from '@tanstack/react-query';
import type { TransactionFilters } from './transactions';

/** Query key TanStack Query. Semua data milik user yang login; dihapus saat logout. */
export const qk = {
  me: ['me'] as const,
  accounts: ['accounts'] as const,
  categories: ['categories'] as const,
  transactions: (filters?: TransactionFilters) =>
    (filters ? ['transactions', filters] : ['transactions']) as readonly unknown[],
  transfer: (groupId: string) => ['transfers', groupId] as const,
  notifications: ['notifications'] as const,
};

/**
 * Setelah uang bergerak (transaksi, transfer, sesuaikan saldo): saldo akun dan daftar transaksi
 * berubah. Notifikasi ikut (mis. alert amplop nanti). Plan & dashboard menyusul di Minggu 3/5.
 */
export function invalidateMoney(queryClient: QueryClient) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.accounts }),
    queryClient.invalidateQueries({ queryKey: qk.transactions() }),
    queryClient.invalidateQueries({ queryKey: ['transfers'] }),
    queryClient.invalidateQueries({ queryKey: qk.notifications }),
  ]);
}
