import { useMutation, useQueryClient } from '@tanstack/react-query';
import { accountsApi } from '@/api/accounts';
import { categoriesApi } from '@/api/categories';
import { invalidateMoney, qk } from '@/api/keys';
import type { Id } from '@/api/types';

type AccountBody = Parameters<typeof accountsApi.update>[1];
type CategoryBody = Parameters<typeof categoriesApi.update>[1];

/** Tulis akun. Ubah saldo awal / sesuaikan saldo menggeser saldo → invalidasi semua data uang. */
export function useAccountMutations() {
  const queryClient = useQueryClient();
  const money = () => invalidateMoney(queryClient);

  return {
    create: useMutation({ mutationFn: (body: Parameters<typeof accountsApi.create>[0]) => accountsApi.create(body), onSuccess: money }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: AccountBody }) => accountsApi.update(id, body), onSuccess: money }),
    archive: useMutation({
      mutationFn: ({ id, archived }: { id: Id; archived: boolean }) => (archived ? accountsApi.archive(id) : accountsApi.unarchive(id)),
      onSuccess: money,
    }),
    remove: useMutation({ mutationFn: (id: Id) => accountsApi.remove(id), onSuccess: money }),
    adjust: useMutation({
      mutationFn: ({ id, body }: { id: Id; body: Parameters<typeof accountsApi.adjust>[1] }) => accountsApi.adjust(id, body),
      onSuccess: money,
    }),
  };
}

/** Tulis kategori. Transaksi membawa nama/warna kategori → ikut diinvalidasi. */
export function useCategoryMutations() {
  const queryClient = useQueryClient();
  const refresh = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.categories }),
    queryClient.invalidateQueries({ queryKey: qk.transactions() }),
  ]);

  return {
    create: useMutation({ mutationFn: (body: Parameters<typeof categoriesApi.create>[0]) => categoriesApi.create(body), onSuccess: refresh }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: CategoryBody }) => categoriesApi.update(id, body), onSuccess: refresh }),
    archive: useMutation({
      mutationFn: ({ id, archived }: { id: Id; archived: boolean }) => (archived ? categoriesApi.archive(id) : categoriesApi.unarchive(id)),
      onSuccess: refresh,
    }),
    remove: useMutation({ mutationFn: (id: Id) => categoriesApi.remove(id), onSuccess: refresh }),
  };
}
