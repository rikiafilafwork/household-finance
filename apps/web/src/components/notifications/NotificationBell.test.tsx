import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AppNotification } from '@/api/types';

const notificationsApi = vi.hoisted(() => ({ list: vi.fn(), read: vi.fn(), readAll: vi.fn() }));
vi.mock('@/api/notifications', () => ({ notificationsApi }));

const { NotificationBell } = await import('./NotificationBell');

const alert80: AppNotification = {
  id: 'a80',
  type: 'envelope_threshold_reached',
  payload: { plan_item_id: 6, name: 'Transport', category_id: 3, month: '2026-11', threshold: 80, spent: 510_000, planned: 600_000, percent: 85 },
  read_at: null,
  created_at: new Date().toISOString(),
};
const oldRead: AppNotification = {
  id: 'old',
  type: 'envelope_threshold_reached',
  payload: { plan_item_id: 7, name: 'Hiburan', category_id: 4, month: '2026-10', threshold: 100, spent: 320_000, planned: 300_000, percent: 106 },
  read_at: '2026-10-20T00:00:00Z',
  created_at: '2026-10-19T00:00:00Z',
};

const page = (data: AppNotification[], unread: number) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 20, unread_count: unread } });

function renderBell() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const router = createMemoryRouter(
    [{ path: '*', element: <><NotificationBell /><p data-testid="location">halaman</p></> }],
    { initialEntries: ['/'] },
  );
  render(<QueryClientProvider client={queryClient}><RouterProvider router={router} /></QueryClientProvider>);
  return { router, queryClient };
}

beforeEach(() => {
  vi.clearAllMocks();
  notificationsApi.list.mockResolvedValue(page([alert80, oldRead], 1));
  notificationsApi.read.mockResolvedValue({ ...alert80, read_at: new Date().toISOString() });
  notificationsApi.readAll.mockResolvedValue({});
});

describe('NotificationBell', () => {
  it('shows the unread count from the server on the bell', async () => {
    renderBell();
    expect(await screen.findByTestId('notification-count')).toHaveTextContent('1');
    expect(screen.getByRole('button', { name: 'Notifikasi, 1 belum dibaca' })).toBeInTheDocument();
  });

  it('caps the badge at 9+', async () => {
    notificationsApi.list.mockResolvedValue(page([alert80], 12));
    renderBell();
    expect(await screen.findByTestId('notification-count')).toHaveTextContent('9+');
  });

  it('hides the badge when everything is read', async () => {
    notificationsApi.list.mockResolvedValue(page([oldRead], 0));
    renderBell();
    await waitFor(() => expect(notificationsApi.list).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: 'Notifikasi' })).toBeInTheDocument();
    expect(screen.queryByTestId('notification-count')).not.toBeInTheDocument();
  });

  it('lists the 80% envelope alert, unread first-class', async () => {
    renderBell();
    await userEvent.click(await screen.findByRole('button', { name: /Notifikasi, 1 belum dibaca/ }));

    const row = await screen.findByTestId('notification-a80');
    expect(row).toHaveTextContent('Amplop Transport sudah 80%');
    expect(row).toHaveTextContent('terpakai Rp510.000 dari Rp600.000 (85%)');
    expect(row).toHaveAttribute('data-unread', 'true');
    expect(screen.getByTestId('notification-old')).not.toHaveAttribute('data-unread');
    expect(screen.getByTestId('notification-old')).toHaveTextContent('Amplop Hiburan terlampaui');
  });

  it('marks a notification read and opens the plan month when clicked', async () => {
    const { router } = renderBell();
    await userEvent.click(await screen.findByRole('button', { name: /belum dibaca/ }));
    let resolveRead!: (n: AppNotification) => void;
    notificationsApi.read.mockReturnValue(new Promise<AppNotification>((r) => { resolveRead = r; }));
    await userEvent.click(await screen.findByTestId('notification-a80'));

    expect(notificationsApi.read).toHaveBeenCalledWith('a80');
    expect(router.state.location.pathname + router.state.location.search).toBe('/plan?month=2026-11');
    // Badge turun langsung (optimistic), sebelum server menjawab.
    await waitFor(() => expect(screen.queryByTestId('notification-count')).not.toBeInTheDocument());
    notificationsApi.list.mockResolvedValue(page([{ ...alert80, read_at: '2026-11-24T00:00:00Z' }, oldRead], 0));
    resolveRead({ ...alert80, read_at: '2026-11-24T00:00:00Z' });
    await waitFor(() => expect(notificationsApi.list).toHaveBeenCalledTimes(2));
    expect(screen.queryByTestId('notification-count')).not.toBeInTheDocument();
  });

  it('does not call read again for an already-read notification', async () => {
    const { router } = renderBell();
    await userEvent.click(await screen.findByRole('button', { name: /belum dibaca/ }));
    await userEvent.click(await screen.findByTestId('notification-old'));

    expect(notificationsApi.read).not.toHaveBeenCalled();
    expect(router.state.location.search).toBe('?month=2026-10');
  });

  it('marks everything read', async () => {
    renderBell();
    await userEvent.click(await screen.findByRole('button', { name: /belum dibaca/ }));
    notificationsApi.list.mockResolvedValue(page([{ ...alert80, read_at: '2026-11-24T00:00:00Z' }, oldRead], 0));
    await userEvent.click(screen.getByRole('button', { name: /Tandai semua dibaca/ }));

    expect(notificationsApi.readAll).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(screen.queryByTestId('notification-count')).not.toBeInTheDocument());
  });

  it('explains what will appear when there are no notifications', async () => {
    notificationsApi.list.mockResolvedValue(page([], 0));
    renderBell();
    await userEvent.click(await screen.findByRole('button', { name: 'Notifikasi' }));
    expect(await screen.findByText(/Belum ada notifikasi/)).toBeInTheDocument();
  });
});
