import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { qk } from '@/api/keys';
import { notificationsApi } from '@/api/notifications';
import type { AppNotification, LengthAwarePage } from '@/api/types';

type Page = LengthAwarePage<AppNotification, { unread_count: number }>;

/** Polling ringan: alert dari job terjadwal (reconcile) atau perangkat lain tetap muncul. */
export const NOTIFICATION_POLL_MS = 60_000;

export function useNotifications() {
  return useQuery({
    queryKey: qk.notifications,
    queryFn: () => notificationsApi.list({ perPage: 20 }),
    refetchInterval: NOTIFICATION_POLL_MS,
    refetchOnWindowFocus: true,
  });
}

/** Tandai dibaca: cache diperbarui langsung (badge turun tanpa menunggu refetch). */
export function useNotificationMutations() {
  const queryClient = useQueryClient();
  const patch = (fn: (page: Page) => Page) => queryClient.setQueryData<Page>(qk.notifications, (page) => (page ? fn(page) : page));
  const settle = () => queryClient.invalidateQueries({ queryKey: qk.notifications });

  return {
    read: useMutation({
      mutationFn: (id: string) => notificationsApi.read(id),
      onMutate: (id) => patch((page) => {
        const target = page.data.find((n) => n.id === id);
        if (!target || target.read_at) return page;
        const now = new Date().toISOString();
        return {
          ...page,
          data: page.data.map((n) => (n.id === id ? { ...n, read_at: now } : n)),
          meta: { ...page.meta, unread_count: Math.max(0, page.meta.unread_count - 1) },
        };
      }),
      onSettled: settle,
    }),
    readAll: useMutation({
      mutationFn: () => notificationsApi.readAll(),
      onMutate: () => patch((page) => {
        const now = new Date().toISOString();
        return { ...page, data: page.data.map((n) => ({ ...n, read_at: n.read_at ?? now })), meta: { ...page.meta, unread_count: 0 } };
      }),
      onSettled: settle,
    }),
  };
}
