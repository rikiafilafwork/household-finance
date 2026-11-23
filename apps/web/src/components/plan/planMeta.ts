import type { PlanComparison, PlanItem } from '@/api/plan';
import type { Account } from '@/api/types';

/** Warna progress amplop (Architecture § Web): biru → kuning ≥ 80% → merah > 100%. */
export type EnvelopeTone = 'normal' | 'warning' | 'over';

export function envelopeTone(percent: number | null): EnvelopeTone {
  if (percent === null) return 'normal';
  if (percent > 100) return 'over';
  if (percent >= 80) return 'warning';
  return 'normal';
}

/**
 * Selisih checklist = aktual − rencana, hanya untuk item yang sudah dicentang (belum dicentang = belum ada aktual).
 * Pengeluaran: > 0 berarti lebih mahal dari rencana (merah). Pemasukan: > 0 berarti lebih banyak (baik).
 */
export function checklistDiff(item: Pick<PlanItem, 'status' | 'actual' | 'planned_amount'>): number | null {
  return item.status === 'done' ? item.actual - item.planned_amount : null;
}

export function isBadDiff(item: Pick<PlanItem, 'type'>, diff: number): boolean {
  return item.type === 'expense' ? diff > 0 : diff < 0;
}


export interface FreeMoney {
  /** Total saldo akun yang tidak diarsip. */
  balance: number;
  /** Dari server: belum dibayar + sisa ruang amplop. */
  stillToSpend: number;
  /** Saldo − masih akan keluar; negatif = saldo tidak cukup menutup rencana. */
  free: number;
}

/**
 * "Uang bebas" bulan berjalan: berapa yang tersisa setelah semua rencana pengeluaran bulan ini
 * terpenuhi. Komponen rencananya dihitung server (PlanComparisonQuery); di sini hanya selisih.
 */
export function freeMoney(accounts: Pick<Account, 'balance' | 'archived_at'>[], plan: Pick<PlanComparison, 'totals'>): FreeMoney {
  const balance = accounts.filter((a) => !a.archived_at).reduce((sum, a) => sum + a.balance, 0);
  const stillToSpend = plan.totals.still_to_spend;
  return { balance, stillToSpend, free: balance - stillToSpend };
}
