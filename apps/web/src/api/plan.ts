import { api, unwrap } from './client';
import type { Id, Transaction } from './types';

export type PlanItemType = 'income' | 'expense';
export type PlanMode = 'checklist' | 'envelope';
export type PlanItemStatus = 'planned' | 'done' | 'skipped';

/** Item rencana + angka dari PlanComparisonQuery (actual, percent). Status diturunkan server. */
export interface PlanItem {
  id: Id;
  month: string; // YYYY-MM
  type: PlanItemType;
  mode: PlanMode;
  name: string;
  category_id: Id;
  planned_amount: number; // positif
  account_id: Id | null;
  due_on: string | null;
  template_id: Id | null;
  source: string;
  sort_order: number;
  skipped_at: string | null;
  status: PlanItemStatus;
  transaction: { id: Id; account_id: Id; amount: number; occurred_on: string } | null;
  actual: number; // positif
  percent: number | null;
}

export interface PlanTotals {
  planned: number;
  actual: number;
  unplanned: number;
  unpaid: number;
  /** Σ max(0, rencana − terpakai) amplop yang tidak di-skip. */
  envelope_remaining: number;
}

export interface PlanComparison {
  month: string;
  totals: {
    income: PlanTotals;
    expense: PlanTotals;
    /** Rencana pengeluaran − aktual pengeluaran. */
    remaining_budget: number;
    /** Checklist pengeluaran belum dibayar + sisa ruang amplop pengeluaran. */
    still_to_spend: number;
  };
  categories: { type: PlanItemType; category_id: Id | null; name: string; planned: number; actual: number }[];
  items: PlanItem[];
}

export interface PlanItemBody {
  type: PlanItemType;
  mode: PlanMode;
  name: string;
  category_id: Id;
  planned_amount: number;
  account_id: Id | null;
  due_on: string | null;
}

export interface CheckBody {
  client_id: string;
  amount: number;
  account_id: Id;
  occurred_on: string;
  note: string | null;
}

export const planApi = {
  get: (month: string) => unwrap(api.get<{ data: PlanComparison }>(`/plan/${month}`)),
  createItem: (month: string, body: PlanItemBody) => unwrap(api.post<{ data: PlanItem }>(`/plan/${month}/items`, body)),
  copyFromPrevious: (month: string) => unwrap(api.post<{ data: { created: number } }>(`/plan/${month}/copy-from-previous`)),
  /** "Buat dari template": idempoten, item yang sudah ada dilewati. */
  generate: (month: string) => unwrap(api.post<{ data: { created: number } }>(`/plan/${month}/generate`)),
  updateItem: (id: Id, body: Partial<PlanItemBody>) => unwrap(api.patch<{ data: PlanItem }>(`/plan-items/${id}`, body)),
  removeItem: (id: Id) => api.delete(`/plan-items/${id}`),
  skip: (id: Id) => unwrap(api.post<{ data: PlanItem }>(`/plan-items/${id}/skip`)),
  unskip: (id: Id) => unwrap(api.post<{ data: PlanItem }>(`/plan-items/${id}/unskip`)),
  check: (id: Id, body: CheckBody) => unwrap(api.post<{ data: { item: PlanItem; transaction: Transaction } }>(`/plan-items/${id}/check`, body)),
  uncheck: (id: Id) => unwrap(api.post<{ data: PlanItem }>(`/plan-items/${id}/uncheck`)),
};
