import { describe, expect, it } from 'vitest';
import { checklistDiff, envelopeTone, isBadDiff } from './planMeta';

describe('envelopeTone', () => {
  it.each([
    { percent: null, tone: 'normal' }, { percent: 0, tone: 'normal' }, { percent: 79, tone: 'normal' },
    { percent: 80, tone: 'warning' }, { percent: 100, tone: 'warning' }, { percent: 101, tone: 'over' }, { percent: 250, tone: 'over' },
  ] as const)('$percent persen → $tone', ({ percent, tone }) => {
    expect(envelopeTone(percent)).toBe(tone);
  });
});

describe('checklistDiff', () => {
  it('is null until the item is checked', () => {
    expect(checklistDiff({ status: 'planned', actual: 0, planned_amount: 500_000 })).toBeNull();
    expect(checklistDiff({ status: 'skipped', actual: 0, planned_amount: 500_000 })).toBeNull();
  });

  it('is actual minus planned once checked', () => {
    expect(checklistDiff({ status: 'done', actual: 165_000, planned_amount: 150_000 })).toBe(15_000);
    expect(checklistDiff({ status: 'done', actual: 480_000, planned_amount: 500_000 })).toBe(-20_000);
  });

  it('marks overspending (expense) and shortfall (income) as bad', () => {
    expect(isBadDiff({ type: 'expense' }, 15_000)).toBe(true);
    expect(isBadDiff({ type: 'expense' }, -20_000)).toBe(false);
    expect(isBadDiff({ type: 'income' }, -500_000)).toBe(true);
    expect(isBadDiff({ type: 'income' }, 100_000)).toBe(false);
  });
});

