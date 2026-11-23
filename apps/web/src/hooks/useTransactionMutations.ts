import type { CreateTransactionInput, CreateTransferInput, UpdateTransactionInput, UpdateTransferInput } from '@personal-finance/shared';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateMoney } from '@/api/keys';
import { transactionsApi, transfersApi } from '@/api/transactions';
import type { Id } from '@/api/types';

/** Semua tulisan uang → invalidasi akun, transaksi, transfer, notifikasi. */
export function useTransactionMutations() {
  const queryClient = useQueryClient();
  const onSuccess = () => invalidateMoney(queryClient);

  return {
    create: useMutation({ mutationFn: (body: CreateTransactionInput) => transactionsApi.create(body), onSuccess }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: UpdateTransactionInput }) => transactionsApi.update(id, body), onSuccess }),
    remove: useMutation({ mutationFn: (id: Id) => transactionsApi.remove(id), onSuccess }),
    createTransfer: useMutation({ mutationFn: (body: CreateTransferInput) => transfersApi.create(body), onSuccess }),
    updateTransfer: useMutation({ mutationFn: ({ groupId, body }: { groupId: string; body: UpdateTransferInput }) => transfersApi.update(groupId, body), onSuccess }),
    removeTransfer: useMutation({ mutationFn: (groupId: string) => transfersApi.remove(groupId), onSuccess }),
  };
}
