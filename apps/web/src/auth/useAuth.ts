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
    },
  });
}

export function useRegister() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.register,
    onSuccess: (user) => {
      queryClient.setQueryData(qk.me, user);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      // `me` di-set null (bukan dihapus) supaya RequireAuth yang sedang mengamatinya langsung
      // pindah ke /login; data lain dihapus supaya tidak terlihat user berikutnya.
      queryClient.setQueryData(qk.me, null);
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== qk.me[0] });
    },
  });
}

/**
 * Nama / zona waktu. Zona waktu menentukan "hari ini" dan "bulan ini" (Keputusan #12), jadi rencana
 * dan dashboard dimuat ulang.
 */
export function useUpdateProfile() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: Parameters<typeof authApi.updateProfile>[0]) => authApi.updateProfile(body),
    onSuccess: (user) => {
      queryClient.setQueryData(qk.me, user);
      void queryClient.invalidateQueries({ queryKey: qk.plan() });
      void queryClient.invalidateQueries({ queryKey: qk.dashboard });
    },
  });
}

export function useChangePassword() {
  return useMutation({ mutationFn: (body: Parameters<typeof authApi.changePassword>[0]) => authApi.changePassword(body) });
}

/** Zona waktu user (Keputusan #12); fallback Asia/Jakarta sebelum `me` termuat. */
export function useTimezone(): string {
  return useMe().data?.timezone ?? 'Asia/Jakarta';
}
