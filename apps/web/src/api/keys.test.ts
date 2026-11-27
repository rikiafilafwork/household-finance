import { QueryClient } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { invalidateMoney, NOTIFICATION_FOLLOW_UPS_MS, qk } from './keys';

afterEach(() => {
  vi.useRealTimers();
});

describe('invalidateMoney', () => {
  it('invalidates savings so the Tabungan page refetches balances', async () => {
    const queryClient = new QueryClient();
    const spy = vi.spyOn(queryClient, 'invalidateQueries');

    await invalidateMoney(queryClient);

    expect(spy.mock.calls.some(([filters]) => filters?.queryKey === qk.savings)).toBe(true);
  });

  it('rechecks notifications after the queued envelope job had time to run', async () => {
    vi.useFakeTimers();
    const queryClient = new QueryClient();
    const spy = vi.spyOn(queryClient, 'invalidateQueries');

    await invalidateMoney(queryClient);
    const notificationCalls = () => spy.mock.calls.filter(([filters]) => filters?.queryKey === qk.notifications).length;
    expect(notificationCalls()).toBe(1);

    vi.advanceTimersByTime(NOTIFICATION_FOLLOW_UPS_MS[0]);
    expect(notificationCalls()).toBe(2);
    vi.advanceTimersByTime(NOTIFICATION_FOLLOW_UPS_MS[1] - NOTIFICATION_FOLLOW_UPS_MS[0]);
    expect(notificationCalls()).toBe(3);
  });
});
