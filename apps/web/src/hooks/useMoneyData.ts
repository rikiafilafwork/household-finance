import { useQuery } from '@tanstack/react-query';
import { accountsApi } from '@/api/accounts';
import { categoriesApi } from '@/api/categories';
import { qk } from '@/api/keys';
import { transactionsApi, type TransactionFilters } from '@/api/transactions';

/** Semua akun (termasuk arsip; difilter di UI) — satu cache untuk semua halaman. */
export function useAccounts() {
  return useQuery({ queryKey: qk.accounts, queryFn: () => accountsApi.list({ includeArchived: true }) });
}

/** Semua kategori (termasuk arsip, supaya transaksi lama tetap bernama). */
export function useCategories() {
  return useQuery({ queryKey: qk.categories, queryFn: () => categoriesApi.list({ includeArchived: true }), staleTime: 5 * 60_000 });
}

export function useRecentTransactions(filters: TransactionFilters = {}, limit = 8) {
  return useQuery({
    queryKey: [...qk.transactions(filters), 'recent', limit],
    queryFn: async () => (await transactionsApi.list(filters, null, limit)).data,
  });
}
