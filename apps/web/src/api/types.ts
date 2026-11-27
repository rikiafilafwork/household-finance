/**
 * Bentuk respons API (lihat apps/api/app/Http/Resources). Uang selalu integer rupiah.
 */
export type Id = number;
export type AccountType = 'bank' | 'ewallet' | 'cash';
export type TransactionType = 'income' | 'expense' | 'transfer' | 'adjustment';
export type CategoryKind = 'income' | 'expense';

export interface User {
  id: Id;
  name: string;
  email: string;
  /** Zona waktu IANA; tanggal transaksi & bulan plan dihitung di zona ini (Keputusan #12). */
  timezone: string;
}

export interface Account {
  id: Id;
  name: string;
  type: AccountType;
  opening_balance: number;
  balance: number; // boleh negatif (diberi peringatan di UI)
  sort_order: number;
  archived_at: string | null;
  is_savings: boolean;
}

export interface Category {
  id: Id;
  kind: CategoryKind;
  name: string;
  color: string | null;
  icon: string | null;
  archived_at: string | null;
}

export interface Transaction {
  id: Id;
  account_id: Id;
  category_id: Id | null;
  category?: Category | null;
  type: TransactionType;
  amount: number; // ber-tanda, sudut pandang akun
  occurred_on: string; // YYYY-MM-DD
  note: string | null;
  transfer_group_id: string | null;
  client_id: string | null;
  plan_item_id: Id | null; // terhubung ke item checklist rencana
  created_at: string | null;
  updated_at: string | null;
}

export interface Transfer {
  transfer_group_id: string;
  from_account_id: Id;
  to_account_id: Id;
  amount: number; // magnitudo
  occurred_on: string;
  note: string | null;
  client_id: string | null;
  legs: { out: Transaction; in: Transaction };
}

export interface AppNotification {
  id: string;
  type: 'envelope_threshold_reached' | 'reconcile_mismatch' | string;
  payload: Record<string, unknown>;
  read_at: string | null;
  created_at: string | null;
}

export interface ReconcileMismatchPayload {
  mismatches: { account_id: Id; name: string; balance: number; expected: number; diff: number }[];
  fixed: boolean;
}

/** Saldo rekening tabungan kurang dari total alokasi tujuan (SavingsOverdrawn). */
export interface SavingsOverdrawnPayload {
  account_id: Id;
  name: string;
  balance: number;
  allocated: number;
  shortfall: number;
}

/** Amplop tembus 80% / 100% (job CheckEnvelopeThresholds). Angka dalam rupiah bulat. */
export interface EnvelopeThresholdPayload {
  plan_item_id: Id;
  name: string;
  category_id: Id;
  month: string; // YYYY-MM
  threshold: number; // 80 | 100
  spent: number;
  planned: number;
  percent: number;
}

export interface Data<T> { data: T }

export interface CursorPage<T> {
  data: T[];
  meta: { next_cursor: string | null; prev_cursor: string | null; per_page: number };
}

export interface LengthAwarePage<T, M = object> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number } & M;
}

export interface SavingsGoal {
  id: Id;
  account_id: Id;
  name: string;
  target_amount: number;
  allocated_amount: number;
  target_date: string | null; // Y-m-d
  achieved_at: string | null;
  archived_at: string | null;
  sort_order: number;
  percent: number;
  reached: boolean;
  /** Perlu ditabung per bulan sampai tenggat; null tanpa tenggat; 0 kalau target sudah terpenuhi. */
  monthly_needed: number | null;
}

export interface SavingsAccountSummary {
  account: Account;
  balance: number;
  allocated: number;
  unallocated: number; // boleh negatif kalau tabungan terpakai
  overdrawn: number;
  goals: SavingsGoal[];
}

export interface SavingsSummary {
  accounts: SavingsAccountSummary[];
  totals: { balance: number; allocated: number; unallocated: number; overdrawn: number };
}

export interface SavingsAllocation {
  id: Id;
  savings_goal_id: Id;
  amount: number; // bertanda: positif = tambah, negatif = kurangi
  occurred_on: string;
  note: string | null;
  transaction_id: Id | null;
  move_group_id: string | null;
  client_id: string;
  created_at: string | null;
}
