import type { PlanTemplate } from '@/api/templates';

export const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/**
 * Jadwal yang mudah dibaca: "Tiap bulan, tgl 25", "Tiap bulan, tgl 31 (akhir bulan)",
 * "Tiap tahun, 15 Maret", "Tiap Maret" (amplop tahunan), "Tiap bulan" (tanpa tanggal).
 */
export function scheduleLabel(t: Pick<PlanTemplate, 'frequency' | 'day_of_month' | 'month_of_year'>): string {
  const day = t.day_of_month;
  if (t.frequency === 'yearly') {
    const month = MONTH_NAMES[(t.month_of_year ?? 1) - 1];
    return day ? `Tiap tahun, ${day} ${month}` : `Tiap ${month}`;
  }
  if (!day) return 'Tiap bulan';
  return day >= 29 ? `Tiap bulan, tgl ${day} (atau akhir bulan)` : `Tiap bulan, tgl ${day}`;
}
