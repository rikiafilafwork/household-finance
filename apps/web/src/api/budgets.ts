import { api, unwrap } from './client';
import type { Id, MonthlyBudget } from './types';

const base = (h: Id, month: string) => `/households/${h}/budgets/${month}`;

export const budgetsApi = {
  get: (h: Id, month: string) => unwrap(api.get<{ data: MonthlyBudget }>(base(h, month))),
  /** Daftar lengkap bulan itu; kategori yang tidak dikirim dihapus budget-nya. */
  set: (h: Id, month: string, items: { category_id: Id; limit_amount: number }[]) =>
    unwrap(api.put<{ data: MonthlyBudget }>(base(h, month), { items })),
  copyFromPrevious: async (h: Id, month: string) =>
    (await api.post<{ data: MonthlyBudget; meta: { copied: number } }>(`${base(h, month)}/copy-from-previous`)).data,
};
