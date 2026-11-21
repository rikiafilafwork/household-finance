/**
 * Bentuk respons API (lihat apps/api/app/Http/Resources). Uang selalu integer rupiah.
 */
export type Id = number;
export type Role = 'owner' | 'member';
export type WalletType = 'cash' | 'bank' | 'ewallet';
export type Visibility = 'shared' | 'private';
export type TransactionType = 'income' | 'expense' | 'transfer' | 'adjustment';
export type TransactionStatus = 'posted' | 'pending';
export type CategoryKind = 'income' | 'expense';

export interface User { id: Id; name: string; email: string }

export interface Household {
  id: Id;
  name: string;
  currency: string;
  timezone: string;
  role: Role | null;
  created_at: string | null;
}

export interface Member { id: Id; name: string; email: string; role: Role; joined_at: string }

export interface Invitation {
  id: Id;
  email: string;
  role: Role;
  invited_by?: User;
  expires_at: string;
  created_at: string | null;
}

export interface InvitationPreview {
  household: { name: string };
  invited_by: { name: string };
  email: string;
  role: Role;
  expires_at: string;
}

export interface Wallet {
  id: Id;
  name: string;
  type: WalletType;
  visibility: Visibility;
  is_mine: boolean;
  opening_balance: number;
  balance: number;
  archived_at: string | null;
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
  wallet_id: Id;
  category_id: Id | null;
  category?: Category | null;
  type: TransactionType;
  status: TransactionStatus;
  amount: number; // ber-tanda, sudut pandang wallet
  occurred_on: string; // YYYY-MM-DD
  note: string | null;
  transfer_group_id: string | null;
  client_id: string | null;
  created_by: Id;
  created_at: string | null;
  updated_at: string | null;
}

export interface Transfer {
  transfer_group_id: string;
  from_wallet_id: Id;
  to_wallet_id: Id;
  amount: number; // magnitudo
  occurred_on: string;
  note: string | null;
  client_id: string | null;
  legs: { out: Transaction; in: Transaction };
}

export interface BudgetNumbers { limit_amount: number; spent: number; remaining: number; percent: number | null }

export interface BudgetItem extends BudgetNumbers { id: Id; category: Category }

export interface MonthlyBudget {
  month: string; // YYYY-MM
  items: BudgetItem[];
  totals: BudgetNumbers & { unbudgeted_spent: number };
}

export interface AppNotification {
  id: string;
  type: 'budget_threshold_reached' | 'reconcile_mismatch' | string;
  payload: Record<string, unknown>;
  read_at: string | null;
  created_at: string | null;
}

export interface BudgetThresholdPayload {
  household_id: Id;
  budget_id: Id;
  category: { id: Id; name: string };
  month: string;
  threshold: 80 | 100;
  spent: number;
  limit_amount: number;
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
