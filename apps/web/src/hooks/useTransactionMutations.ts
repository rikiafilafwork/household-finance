import type { CreateTransactionInput, CreateTransferInput, UpdateTransactionInput, UpdateTransferInput } from '@personal-finance/shared';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateMoney } from '@/api/keys';
import { transactionsApi, transfersApi } from '@/api/transactions';
import type { Id } from '@/api/types';

/** Semua tulisan uang → invalidasi wallets, transactions, budgets, notifications (#32). */
export function useTransactionMutations(h: Id) {
  const queryClient = useQueryClient();
  const onSuccess = () => invalidateMoney(queryClient, h);

  return {
    create: useMutation({ mutationFn: (body: CreateTransactionInput) => transactionsApi.create(h, body), onSuccess }),
    update: useMutation({ mutationFn: ({ id, body }: { id: Id; body: UpdateTransactionInput }) => transactionsApi.update(h, id, body), onSuccess }),
    remove: useMutation({ mutationFn: (id: Id) => transactionsApi.remove(h, id), onSuccess }),
    createTransfer: useMutation({ mutationFn: (body: CreateTransferInput) => transfersApi.create(h, body), onSuccess }),
    updateTransfer: useMutation({ mutationFn: ({ groupId, body }: { groupId: string; body: UpdateTransferInput }) => transfersApi.update(h, groupId, body), onSuccess }),
    removeTransfer: useMutation({ mutationFn: (groupId: string) => transfersApi.remove(h, groupId), onSuccess }),
  };
}
