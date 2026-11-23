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
  plan: (month?: string) => (month ? ['plan', month] : ['plan']) as readonly unknown[],
  dashboard: ['dashboard'] as const,
};

/**
 * Setelah uang bergerak (transaksi, transfer, sesuaikan saldo, centang rencana): saldo akun,
 * daftar transaksi, angka rencana (aktual amplop / di luar rencana) dan dashboard berubah.
 * Notifikasi ikut (alert amplop).
 */
export function invalidateMoney(queryClient: QueryClient) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.accounts }),
    queryClient.invalidateQueries({ queryKey: qk.transactions() }),
    queryClient.invalidateQueries({ queryKey: ['transfers'] }),
    queryClient.invalidateQueries({ queryKey: qk.notifications }),
    queryClient.invalidateQueries({ queryKey: qk.plan() }),
    queryClient.invalidateQueries({ queryKey: qk.dashboard }),
  ]);
}
