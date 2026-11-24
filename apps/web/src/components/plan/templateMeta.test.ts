import { describe, expect, it } from 'vitest';
import { scheduleLabel } from './templateMeta';

describe('scheduleLabel', () => {
  it.each([
    [{ frequency: 'monthly', day_of_month: 25, month_of_year: null }, 'Tiap bulan, tgl 25'],
    [{ frequency: 'monthly', day_of_month: 31, month_of_year: null }, 'Tiap bulan, tgl 31 (atau akhir bulan)'],
    [{ frequency: 'monthly', day_of_month: null, month_of_year: null }, 'Tiap bulan'],
    [{ frequency: 'yearly', day_of_month: 15, month_of_year: 3 }, 'Tiap tahun, 15 Maret'],
    [{ frequency: 'yearly', day_of_month: null, month_of_year: 12 }, 'Tiap Desember'],
  ] as const)('%o → %s', (t, expected) => {
    expect(scheduleLabel(t)).toBe(expected);
  });
});
