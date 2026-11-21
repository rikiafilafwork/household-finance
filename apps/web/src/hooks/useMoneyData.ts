import { useQuery } from '@tanstack/react-query';
import { budgetsApi } from '@/api/budgets';
import { categoriesApi } from '@/api/categories';
import { qk } from '@/api/keys';
import { transactionsApi, type TransactionFilters } from '@/api/transactions';
import type { Id } from '@/api/types';
import { walletsApi } from '@/api/wallets';

/** Semua wallet terlihat (termasuk arsip; difilter di UI) — satu cache untuk semua halaman. */
export function useWallets(h: Id) {
  return useQuery({ queryKey: qk.wallets(h), queryFn: () => walletsApi.list(h, { includeArchived: true }) });
}

/** Semua kategori (termasuk arsip, supaya transaksi lama tetap bernama). */
export function useCategories(h: Id) {
  return useQuery({ queryKey: qk.categories(h), queryFn: () => categoriesApi.list(h, { includeArchived: true }), staleTime: 5 * 60_000 });
}

export function useBudget(h: Id, month: string) {
  return useQuery({ queryKey: qk.budgets(h, month), queryFn: () => budgetsApi.get(h, month) });
}

export function useRecentTransactions(h: Id, filters: TransactionFilters = {}, limit = 8) {
  return useQuery({
    queryKey: [...qk.transactions(h, filters), 'recent', limit],
    queryFn: async () => (await transactionsApi.list(h, filters, null, limit)).data,
  });
}
