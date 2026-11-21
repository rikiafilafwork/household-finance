import { api, unwrap } from './client';
import type { Category, CategoryKind, Id } from './types';

const base = (h: Id) => `/households/${h}/categories`;

export const categoriesApi = {
  list: (h: Id, opts: { kind?: CategoryKind; includeArchived?: boolean } = {}) =>
    unwrap(api.get<{ data: Category[] }>(base(h), {
      params: { ...(opts.kind ? { kind: opts.kind } : {}), ...(opts.includeArchived ? { include_archived: 1 } : {}) },
    })),
  create: (h: Id, body: { kind: CategoryKind; name: string; color?: string | null; icon?: string | null }) =>
    unwrap(api.post<{ data: Category }>(base(h), body)),
  update: (h: Id, id: Id, body: { name?: string; color?: string | null; icon?: string | null }) =>
    unwrap(api.patch<{ data: Category }>(`${base(h)}/${id}`, body)),
  archive: (h: Id, id: Id) => unwrap(api.post<{ data: Category }>(`${base(h)}/${id}/archive`)),
  unarchive: (h: Id, id: Id) => unwrap(api.post<{ data: Category }>(`${base(h)}/${id}/unarchive`)),
  remove: (h: Id, id: Id) => api.delete(`${base(h)}/${id}`),
};
