import { formatIDR } from '@personal-finance/shared';
import type { SavingsGoal } from '@/api/types';

/** Warna bar progres: hijau begitu target tercapai. */
export function progressTone(goal: Pick<SavingsGoal, 'reached'>): 'reached' | 'ok' {
  return goal.reached ? 'reached' : 'ok';
}

/** "perlu Rp875.000/bln"; null tanpa tenggat atau kalau tidak ada yang perlu ditabung lagi. */
export function formatMonthlyNeeded(goal: Pick<SavingsGoal, 'monthly_needed'>): string | null {
  return goal.monthly_needed === null ? null : `perlu ${formatIDR(goal.monthly_needed)}/bln`;
}
