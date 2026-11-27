import { describe, expect, it } from 'vitest';
import { formatMonthlyNeeded, progressTone } from './savingsMeta';

describe('savingsMeta', () => {
  it('formatMonthlyNeeded is null without a deadline and formats rupiah per month otherwise', () => {
    expect(formatMonthlyNeeded({ monthly_needed: null })).toBeNull();
    expect(formatMonthlyNeeded({ monthly_needed: 875_000 })).toBe('perlu Rp875.000/bln');
  });

  it('progressTone is reached only when the target is met', () => {
    expect(progressTone({ reached: true })).toBe('reached');
    expect(progressTone({ reached: false })).toBe('ok');
  });
});
