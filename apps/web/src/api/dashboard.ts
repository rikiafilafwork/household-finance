import { api, unwrap } from './client';
import type { PlanItemType, PlanTotals } from './plan';
import type { Id, TransactionType } from './types';

/**
 * GET /api/dashboard?month= (DashboardQuery, Keputusan #11). Semua angka rencana vs aktual berasal
 * dari PlanComparisonQuery yang sama dengan GET /plan/{month} — Beranda tidak menghitung ulang.
 */
export interface Dashboard {
  month: string;
  is_current_month: boolean;
  /** Saldo HARI INI akun aktif, per tipe. */
  balances: { bank: number; ewallet: number; cash: number; savings: number; total: number };
  income: PlanTotals;
  expense: PlanTotals;
  remaining_budget: number;
  still_to_spend: number;
  /** Saldo − masih akan keluar; null untuk bulan selain bulan berjalan. */
  free_money: number | null;
  /** Pengeluaran per kategori (rencana vs aktual); `id` null = baris "di luar rencana" tanpa kategori. */
  categories: { id: Id | null; name: string; color: string | null; planned: number; actual: number }[];
  income_sources: { id: Id; name: string; planned: number; actual: number; done: boolean; skipped: boolean }[];
  /** Checklist belum dicentang, jatuh tempo ≤ 7 hari dari hari ini atau sudah lewat. */
  due_items: { id: Id; type: PlanItemType; name: string; due_on: string; planned: number; account_id: Id | null; overdue: boolean }[];
  /** Amplop pengeluaran ≥ 80%, terbesar dulu. */
  envelopes_warning: { id: Id; name: string; planned: number; actual: number; percent: number }[];
  /** 6 bulan, terlama dulu, berakhir di `month`. */
  trend: { month: string; income_actual: number; expense_actual: number; expense_planned: number }[];
  recent: { id: Id; type: TransactionType; account_id: Id; category_id: Id | null; amount: number; occurred_on: string; note: string | null; plan_item_id: Id | null }[];
  reconcile_ok: boolean;
  /** Tabungan: saldo rekening tabungan, yang sudah dialokasikan ke tujuan, sisanya; `overdrawn` bila alokasi melebihi saldo. */
  savings: {
    balance: number;
    allocated: number;
    unallocated: number;
    overdrawn: boolean;
    goals: { id: Id; name: string; allocated_amount: number; target_amount: number | null; percent: number | null }[];
  };
}

export const dashboardApi = {
  get: (month: string) => unwrap(api.get<{ data: Dashboard }>('/dashboard', { params: { month } })),
};
