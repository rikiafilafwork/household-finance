import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateMoney, qk } from '@/api/keys';
import { planApi, type CheckBody, type PlanItemBody } from '@/api/plan';
import type { Id } from '@/api/types';

/**
 * Tulis rencana. Yang menggerakkan uang (centang, batal centang) → invalidateMoney (akun, transaksi,
 * rencana, dashboard, notifikasi). Yang hanya mengubah rencana → rencana + dashboard.
 */
export function usePlanMutations() {
  const queryClient = useQueryClient();
  const plan = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: qk.plan() }),
    queryClient.invalidateQueries({ queryKey: qk.dashboard }),
    // Amplop baru/diubah bisa memicu alert ambang (job afterCommit).
    queryClient.invalidateQueries({ queryKey: qk.notifications }),
  ]);
  const money = () => invalidateMoney(queryClient);

  return {
    create: useMutation({ mutationFn: ({ month, body }: { month: string; body: PlanItemBody }) => planApi.createItem(month, body), onSuccess: plan }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: Partial<PlanItemBody> }) => planApi.updateItem(id, body), onSuccess: plan }),
    remove: useMutation({ mutationFn: (id: Id) => planApi.removeItem(id), onSuccess: plan }),
    skip: useMutation({ mutationFn: ({ id, skipped }: { id: Id; skipped: boolean }) => (skipped ? planApi.skip(id) : planApi.unskip(id)), onSuccess: plan }),
    copyFromPrevious: useMutation({ mutationFn: (month: string) => planApi.copyFromPrevious(month), onSuccess: plan }),
    // Gagal (mis. 409 sudah dicentang di perangkat lain) juga menyegarkan: tampilkan keadaan terbaru.
    check: useMutation({ mutationFn: ({ id, body }: { id: Id; body: CheckBody }) => planApi.check(id, body), onSettled: money }),
    uncheck: useMutation({ mutationFn: (id: Id) => planApi.uncheck(id), onSettled: money }),
  };
}
