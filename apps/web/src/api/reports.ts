import { api, unwrap } from './client';
import type { PlanTotals } from './plan';
import type { AccountType, Id } from './types';

/**
 * Laporan (GET /api/reports/…, #58). Ringkasan/kategori/tren/tunai vs non-tunai memakai definisi
 * PlanComparisonQuery → angkanya sama dengan Rencana & Beranda. Cash flow per tanggal transaksi.
 */
export interface MonthlySummary {
  month: string;
  income: PlanTotals;
  expense: PlanTotals;
  net: number;
  remaining_budget: number;
  /** Total sesuaikan saldo bulan itu; negatif = uang hilang tak tercatat. */
  unrecorded: number;
  previous: {
    month: string;
    income: number;
    expense: number;
    net: number;
    change: { income: number; expense: number; net: number };
  };
}

export interface CategoryBreakdown {
  from: string;
  to: string;
  kind: 'income' | 'expense';
  total_planned: number;
  total_actual: number;
  categories: { category_id: Id | null; name: string; color: string | null; planned: number; actual: number; percent: number }[];
}

export interface TrendMonth {
  month: string;
  income_planned: number;
  income_actual: number;
  expense_planned: number;
  expense_actual: number;
  net: number;
}

export interface CashFlow {
  from: string;
  to: string;
  account_id: Id | null;
  opening_balance: number;
  closing_balance: number;
  days: { date: string; net: number; balance: number }[];
}

export interface CashVsNonCash {
  month: string;
  total: number;
  types: { type: AccountType; expense: number; percent: number; unrecorded: number }[];
}

export type ExportDelimiter = 'semicolon' | 'comma' | 'tab';

export const reportsApi = {
  summary: (month: string) => unwrap(api.get<{ data: MonthlySummary }>('/reports/summary', { params: { month } })),
  byCategory: (params: { from: string; to: string; kind: 'income' | 'expense' }) =>
    unwrap(api.get<{ data: CategoryBreakdown }>('/reports/by-category', { params })),
  trend: (end: string, months: number) => unwrap(api.get<{ data: TrendMonth[] }>('/reports/trend', { params: { end, months } })),
  cashFlow: (params: { from: string; to: string; account?: Id }) => unwrap(api.get<{ data: CashFlow }>('/reports/cash-flow', { params })),
  cashVsNonCash: (month: string) => unwrap(api.get<{ data: CashVsNonCash }>('/reports/cash-vs-noncash', { params: { month } })),
  /** URL unduhan langsung (cookie sesi ikut; browser yang menyimpan file streaming-nya). */
  exportUrl: (from: string, to: string, delimiter: ExportDelimiter) =>
    `/api/reports/export.csv?${new URLSearchParams({ from, to, delimiter }).toString()}`,
};
