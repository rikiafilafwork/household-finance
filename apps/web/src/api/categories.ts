import { api, unwrap } from './client';
import type { Category, CategoryKind, Id } from './types';

const base = '/categories';

export const categoriesApi = {
  list: (opts: { kind?: CategoryKind; includeArchived?: boolean } = {}) =>
    unwrap(api.get<{ data: Category[] }>(base, {
      params: { ...(opts.kind ? { kind: opts.kind } : {}), ...(opts.includeArchived ? { include_archived: 1 } : {}) },
    })),
  create: (body: { kind: CategoryKind; name: string; color?: string | null; icon?: string | null }) =>
    unwrap(api.post<{ data: Category }>(base, body)),
  update: (id: Id, body: { name?: string; color?: string | null; icon?: string | null }) =>
    unwrap(api.patch<{ data: Category }>(`${base}/${id}`, body)),
  archive: (id: Id) => unwrap(api.post<{ data: Category }>(`${base}/${id}/archive`)),
  unarchive: (id: Id) => unwrap(api.post<{ data: Category }>(`${base}/${id}/unarchive`)),
  remove: (id: Id) => api.delete(`${base}/${id}`),
};
