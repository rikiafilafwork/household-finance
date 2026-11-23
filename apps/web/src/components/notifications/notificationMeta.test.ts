import { describe, expect, it } from 'vitest';
import type { AppNotification } from '@/api/types';
import { describeNotification, timeAgo } from './notificationMeta';

const envelope = (threshold: number, spent: number, percent: number): AppNotification => ({
  id: 'n1',
  type: 'envelope_threshold_reached',
  payload: { plan_item_id: 5, name: 'Makan', category_id: 1, month: '2026-11', threshold, spent, planned: 2_000_000, percent },
  read_at: null,
  created_at: '2026-11-24T10:00:00+07:00',
});

describe('describeNotification', () => {
  it('describes an 80% envelope alert as a warning that opens the plan of that month', () => {
    expect(describeNotification(envelope(80, 1_650_000, 82))).toEqual({
      title: 'Amplop Makan sudah 80%',
      body: 'November 2026: terpakai Rp1.650.000 dari Rp2.000.000 (82%).',
      href: '/plan?month=2026-11',
      tone: 'warning',
    });
  });

  it('describes a 100% envelope alert as exceeded', () => {
    const view = describeNotification(envelope(100, 2_100_000, 105));
    expect(view.title).toBe('Amplop Makan terlampaui');
    expect(view.tone).toBe('over');
  });

  it('describes a reconcile mismatch and links to accounts', () => {
    const view = describeNotification({
      id: 'n2', type: 'reconcile_mismatch', read_at: null, created_at: null,
      payload: { mismatches: [{ account_id: 1, name: 'BCA', balance: 10, expected: 0, diff: 10 }], fixed: false },
    });
    expect(view).toMatchObject({ title: 'Saldo akun tidak cocok', href: '/accounts', tone: 'over' });
    expect(view.body).toContain('BCA');
  });

  it('falls back gracefully for unknown types', () => {
    expect(describeNotification({ id: 'x', type: 'something_new', payload: {}, read_at: null, created_at: null }).title).toBe('Notifikasi');
  });
});

describe('timeAgo', () => {
  const now = new Date('2026-11-24T12:00:00Z');
  it.each([
    ['2026-11-24T11:59:40Z', 'baru saja'],
    ['2026-11-24T11:55:00Z', '5 menit lalu'],
    ['2026-11-24T09:00:00Z', '3 jam lalu'],
    ['2026-11-22T12:00:00Z', '2 hari lalu'],
  ])('%s → %s', (iso, expected) => {
    expect(timeAgo(iso, now)).toBe(expected);
  });
});
