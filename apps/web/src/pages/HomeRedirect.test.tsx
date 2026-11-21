import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Household } from '@/api/types';

let households: Household[] = [];
vi.mock('@/api/households', () => ({ householdsApi: { list: vi.fn(async () => households) } }));
vi.mock('@/api/auth', () => ({ authApi: { me: vi.fn(async () => ({ id: 1, name: 'Riki', email: 'r@example.com' })) } }));

const { HomeRedirect } = await import('./HomeRedirect');
const { AppShell } = await import('@/components/layout/AppShell');

const h = (id: number, name: string): Household => ({ id, name, currency: 'IDR', timezone: 'Asia/Jakarta', role: 'owner', created_at: null });

function renderAt(url: string) {
  const router = createMemoryRouter(
    [
      { path: '/', element: <HomeRedirect /> },
      { path: '/onboarding', element: <p>Onboarding</p> },
      { path: '/h/:householdId', element: <AppShell />, children: [{ index: true, element: <p>Beranda isi</p> }] },
    ],
    { initialEntries: [url] },
  );
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

beforeEach(() => {
  localStorage.clear();
});

describe('household routing', () => {
  it('sends users without a household to onboarding', async () => {
    households = [];
    renderAt('/');
    expect(await screen.findByText('Onboarding')).toBeInTheDocument();
  });

  it('opens the last used household, else the first', async () => {
    households = [h(3, 'A Keluarga'), h(7, 'Kantor')];
    localStorage.setItem('hf:last-household', '7');
    const router = renderAt('/');
    await screen.findByText('Beranda isi');
    expect(router.state.location.pathname).toBe('/h/7');
  });

  it('falls back to the first household when the remembered one is gone', async () => {
    households = [h(3, 'A Keluarga')];
    localStorage.setItem('hf:last-household', '99');
    const router = renderAt('/');
    await screen.findByText('Beranda isi');
    expect(router.state.location.pathname).toBe('/h/3');
  });

  it('redirects away from a household the user is not a member of, and remembers visits', async () => {
    households = [h(3, 'A Keluarga')];
    const router = renderAt('/h/42');
    await screen.findByText('Beranda isi');
    expect(router.state.location.pathname).toBe('/h/3');
    expect(localStorage.getItem('hf:last-household')).toBe('3');
  });

  it('shows the household name in the switcher', async () => {
    households = [h(3, 'Keluarga Riki')];
    renderAt('/h/3');
    expect(await screen.findByRole('button', { name: 'Ganti household' })).toHaveTextContent('Keluarga Riki');
  });
});
