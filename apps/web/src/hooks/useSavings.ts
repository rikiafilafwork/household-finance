import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { invalidateMoney, qk } from '@/api/keys';
import { savingsApi } from '@/api/savings';
import type { Id } from '@/api/types';

export function useSavings() {
  return useQuery({ queryKey: qk.savings, queryFn: savingsApi.summary });
}

/** Riwayat alokasi satu tujuan (halaman pertama saja). */
export function useGoalAllocations(goalId: Id) {
  return useQuery({ queryKey: qk.savingsAllocations(goalId), queryFn: () => savingsApi.allocations(goalId) });
}

/**
 * Tulis tujuan tabungan. Setiap mutasi menggeser angka tabungan, Beranda, dan saldo rekening;
 * "Gunakan" juga membuat transaksi (daftar transaksi, rencana, notifikasi) → invalidateMoney.
 */
export function useSavingsMutations() {
  const queryClient = useQueryClient();
  // qk.savings juga menyapu riwayat alokasi (prefix sama).
  const refresh = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.savings }),
    queryClient.invalidateQueries({ queryKey: qk.dashboard }),
    queryClient.invalidateQueries({ queryKey: qk.accounts }),
  ]);

  return {
    create: useMutation({ mutationFn: (body: Parameters<typeof savingsApi.createGoal>[0]) => savingsApi.createGoal(body), onSuccess: refresh }),
    update: useMutation({
      mutationFn: ({ id, body }: { id: Id; body: Parameters<typeof savingsApi.updateGoal>[1] }) => savingsApi.updateGoal(id, body),
      onSuccess: refresh,
    }),
    remove: useMutation({ mutationFn: (id: Id) => savingsApi.removeGoal(id), onSuccess: refresh }),
    archive: useMutation({ mutationFn: (id: Id) => savingsApi.archiveGoal(id), onSuccess: refresh }),
    achieve: useMutation({
      mutationFn: ({ id, achieved }: { id: Id; achieved: boolean }) => (achieved ? savingsApi.achieveGoal(id) : savingsApi.unachieveGoal(id)),
      onSuccess: refresh,
    }),
    allocate: useMutation({
      mutationFn: ({ goalId, body }: { goalId: Id; body: Parameters<typeof savingsApi.allocate>[1] }) => savingsApi.allocate(goalId, body),
      onSuccess: refresh,
    }),
    move: useMutation({
      mutationFn: ({ goalId, body }: { goalId: Id; body: Parameters<typeof savingsApi.move>[1] }) => savingsApi.move(goalId, body),
      onSuccess: refresh,
    }),
    use: useMutation({
      mutationFn: ({ goalId, body }: { goalId: Id; body: Parameters<typeof savingsApi.use>[1] }) => savingsApi.use(goalId, body),
      onSuccess: () => Promise.all([refresh(), invalidateMoney(queryClient)]),
    }),
  };
}
