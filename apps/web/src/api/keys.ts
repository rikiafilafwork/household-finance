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
  templates: ['plan-templates'] as const,
};

/**
 * Jeda cek ulang lonceng: job alert amplop berjalan di queue setelah commit. Diukur di stack
 * lokal (worker database, sleep 3 dtk): alert muncul 2–4 dtk setelah transaksi.
 */
export const NOTIFICATION_FOLLOW_UPS_MS = [3_000, 10_000] as const;

/**
 * Notifikasi disegarkan sekarang dan lagi setelah worker sempat memproses
 * CheckEnvelopeThresholds (ShouldQueueAfterCommit) — kalau tidak, alert 80% baru muncul
 * di polling berikutnya (60 dtk).
 */
export function refreshNotifications(queryClient: QueryClient) {
  for (const ms of NOTIFICATION_FOLLOW_UPS_MS) {
    setTimeout(() => void queryClient.invalidateQueries({ queryKey: qk.notifications }), ms);
  }
  return queryClient.invalidateQueries({ queryKey: qk.notifications });
}

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
    refreshNotifications(queryClient),
    queryClient.invalidateQueries({ queryKey: qk.plan() }),
    queryClient.invalidateQueries({ queryKey: qk.dashboard }),
  ]);
}
