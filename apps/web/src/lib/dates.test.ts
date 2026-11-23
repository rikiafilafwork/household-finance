import { describe, expect, it } from 'vitest';
import { formatDate, formatMonth, monthIn, shiftMonth, todayIn, defaultDateIn } from './dates';

describe('user-local dates (Keputusan #12)', () => {
  it('keeps 23:30 WIB on the same day even though UTC is still that day afternoon', () => {
    // 2026-11-21 23:30 WIB = 16:30 UTC
    expect(todayIn('Asia/Jakarta', new Date('2026-11-21T16:30:00Z'))).toBe('2026-11-21');
  });

  it('rolls to the next day after midnight WIB while UTC is still the previous day', () => {
    // 2026-11-22 00:15 WIB = 2026-11-21 17:15 UTC
    expect(todayIn('Asia/Jakarta', new Date('2026-11-21T17:15:00Z'))).toBe('2026-11-22');
    expect(monthIn('Asia/Jakarta', new Date('2026-11-30T17:15:00Z'))).toBe('2026-12');
  });

  it('shifts months across years', () => {
    expect(shiftMonth('2026-01', -1)).toBe('2025-12');
    expect(shiftMonth('2026-12', 1)).toBe('2027-01');
    expect(shiftMonth('2026-11', 0)).toBe('2026-11');
  });

  it('formats in Indonesian without timezone drift', () => {
    expect(formatMonth('2026-11')).toBe('November 2026');
    expect(formatDate('2026-11-01')).toBe('1 Nov 2026');
  });
});

describe('defaultDateIn (tanggal awal transaksi dari rencana)', () => {
  it('uses today in the current month', () => {
    expect(defaultDateIn('2026-11', '2026-11-24')).toBe('2026-11-24');
  });

  it('uses the last day of a past month, including leap February', () => {
    expect(defaultDateIn('2026-10', '2026-11-24')).toBe('2026-10-31');
    expect(defaultDateIn('2028-02', '2028-03-05')).toBe('2028-02-29');
    expect(defaultDateIn('2025-12', '2026-01-02')).toBe('2025-12-31');
  });

  it('uses the first day of a future month', () => {
    expect(defaultDateIn('2026-12', '2026-11-24')).toBe('2026-12-01');
  });
});
