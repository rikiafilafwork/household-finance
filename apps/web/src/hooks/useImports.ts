import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { importsApi, PROCESSING, type ColumnMapping } from '@/api/imports';
import { invalidateMoney, qk } from '@/api/keys';
import type { Id } from '@/api/types';

export function useImports() {
  return useQuery({ queryKey: qk.imports, queryFn: importsApi.list });
}

/** Status import; di-poll tiap detik selama worker masih mem-parse file. */
export function useImport(id: Id, pollMs = 1_000) {
  return useQuery({
    queryKey: qk.importDetail(id),
    queryFn: () => importsApi.get(id),
    refetchInterval: (query) => (query.state.data && PROCESSING.includes(query.state.data.status) ? pollMs : false),
  });
}

export function useImportRows(id: Id, enabled: boolean) {
  return useQuery({ queryKey: qk.importRows(id), queryFn: () => importsApi.rows(id), enabled });
}

export function useImportMutations() {
  const queryClient = useQueryClient();

  return {
    upload: useMutation({
      mutationFn: (body: { account_id: Id; file: File; column_mapping: ColumnMapping | null }) => importsApi.upload(body),
      onSuccess: (created) => {
        queryClient.setQueryData(qk.importDetail(created.id), created);
        return queryClient.invalidateQueries({ queryKey: qk.imports, exact: true });
      },
    }),
    commit: useMutation({
      mutationFn: ({ id, rows }: { id: Id; rows: { id: Id; category_id: Id | null }[] }) => importsApi.commit(id, rows),
      onSuccess: (result) => {
        queryClient.setQueryData(qk.importDetail(result.import.id), result.import);
        return Promise.all([
          queryClient.invalidateQueries({ queryKey: qk.imports }),
          // Transaksi baru masuk: saldo, daftar transaksi, rencana, dashboard, alert amplop.
          invalidateMoney(queryClient),
        ]);
      },
    }),
  };
}
