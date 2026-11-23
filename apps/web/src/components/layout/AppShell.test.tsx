import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { qk } from '@/api/keys';

vi.mock('@/api/auth', () => ({ authApi: { me: vi.fn(async () => null), logout: vi.fn() } }));

const { AppShell } = await import('./AppShell');

function renderAt(url: string) {
  const queryClient = new QueryClient();
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  const router = createMemoryRouter(
    [{ element: <AppShell />, children: [{ path: '*', element: <p>Isi halaman</p> }] }],
    { initialEntries: [url] },
  );
  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

describe('AppShell (tanpa household)', () => {
  it('links the five main sections to flat routes, without any household id', () => {
    renderAt('/transactions');

    const [desktop] = screen.getAllByRole('navigation', { name: 'Menu utama' });
    const links = within(desktop!).getAllByRole('link');
    expect(links.map((a) => [a.textContent?.trim(), a.getAttribute('href')])).toEqual([
      ['Beranda', '/'],
      ['Rencana', '/plan'],
      ['Transaksi', '/transactions'],
      ['Akun', '/accounts'],
      ['Pengaturan', '/settings'],
    ]);
    expect(within(desktop!).getByRole('link', { name: 'Transaksi' })).toHaveAttribute('aria-current', 'page');
  });

  it('shows the same five tabs on mobile, with no “Lainnya” overflow menu', () => {
    renderAt('/');

    const [, mobile] = screen.getAllByRole('navigation', { name: 'Menu utama' });
    expect(within(mobile!).getAllByRole('link')).toHaveLength(5);
    expect(screen.queryByText('Lainnya')).not.toBeInTheDocument();
    expect(screen.queryByText(/household/i)).not.toBeInTheDocument();
  });
});
