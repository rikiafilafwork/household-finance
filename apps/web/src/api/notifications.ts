import { api, unwrap } from './client';
import type { AppNotification, LengthAwarePage } from './types';

export const notificationsApi = {
  list: async (opts: { unread?: boolean; perPage?: number } = {}) =>
    (await api.get<LengthAwarePage<AppNotification, { unread_count: number }>>('/notifications', {
      params: {
        ...(opts.unread ? { unread: 1 } : {}),
        per_page: opts.perPage ?? 20,
      },
    })).data,
  read: (id: string) => unwrap(api.post<{ data: AppNotification }>(`/notifications/${id}/read`)),
  readAll: () => api.post('/notifications/read-all'),
};
