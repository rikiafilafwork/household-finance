import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApi } from '@/api/auth';
import { qk } from '@/api/keys';

/** User yang login, atau null. Dicek sekali saat aplikasi dibuka (tanpa toast/redirect). */
export function useMe() {
  return useQuery({ queryKey: qk.me, queryFn: authApi.me, staleTime: Infinity, retry: false });
}

export function useLogin() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.login,
    onSuccess: (user) => {
      queryClient.setQueryData(qk.me, user);
      void queryClient.invalidateQueries({ queryKey: qk.households });
    },
  });
}

export function useRegister() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.register,
    onSuccess: (user) => {
      queryClient.setQueryData(qk.me, user);
      void queryClient.invalidateQueries({ queryKey: qk.households });
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      // `me` di-set null (bukan dihapus) supaya RequireAuth yang sedang mengamatinya langsung
      // pindah ke /login; data household lain dihapus supaya tidak terlihat user berikutnya.
      queryClient.setQueryData(qk.me, null);
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== qk.me[0] });
    },
  });
}
