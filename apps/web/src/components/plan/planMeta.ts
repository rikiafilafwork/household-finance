import type { PlanItem } from '@/api/plan';

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

