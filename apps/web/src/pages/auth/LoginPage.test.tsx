import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';

const login = vi.fn();
vi.mock('@/api/auth', () => ({ authApi: { me: vi.fn(async () => null), login: (b: unknown) => login(b) } }));

const { LoginPage } = await import('./LoginPage');

function renderAt(url: string) {
  const router = createMemoryRouter(
    [
      { path: '/login', element: <LoginPage /> },
      { path: '*', element: <p>Halaman tujuan</p> },
    ],
    { initialEntries: [url] },
  );
  render(
    <QueryClientProvider client={new QueryClient()}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

// Kurung kurawal wajib: fungsi yang DIKEMBALIKAN beforeEach dianggap cleanup oleh Vitest
// (mockReset() mengembalikan mock-nya sendiri → login() terpanggil lagi setelah test).
beforeEach(() => {
  login.mockReset();
});

describe('LoginPage', () => {
  it('validates on the client before calling the API', async () => {
    renderAt('/login');

    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }));

    expect(await screen.findByText('Email wajib diisi.')).toBeInTheDocument();
    expect(screen.getByText('Kata sandi wajib diisi.')).toBeInTheDocument();
    expect(login).not.toHaveBeenCalled();
  });

  it('shows the server 422 message on the email field and clears the password', async () => {
    login.mockRejectedValue(new ApiError(422, 'x', { email: ['Identitas tersebut tidak cocok dengan data kami.'] }));
    renderAt('/login');

    await userEvent.type(screen.getByLabelText('Email'), 'a@example.com');
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'salah');
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }));

    expect(await screen.findByText('Identitas tersebut tidak cocok dengan data kami.')).toBeInTheDocument();
    expect(screen.getByLabelText('Kata sandi')).toHaveValue('');
  });

  it('goes to ?next after a successful login, prefilled from ?email', async () => {
    login.mockResolvedValue({ id: 1, name: 'Riki', email: 'a@example.com' });
    const router = renderAt('/login?next=%2Finvitations%2Fabc&email=a%40example.com');

    expect(screen.getByLabelText('Email')).toHaveValue('a@example.com');
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'rahasia-panjang');
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }));

    expect(await screen.findByText('Halaman tujuan')).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/invitations/abc');
    expect(login).toHaveBeenCalledWith({ email: 'a@example.com', password: 'rahasia-panjang', remember: true });
  });

  it('never redirects to another site', async () => {
    login.mockResolvedValue({ id: 1, name: 'Riki', email: 'a@example.com' });
    const router = renderAt('/login?next=%2F%2Fevil.example');

    await userEvent.type(screen.getByLabelText('Email'), 'a@example.com');
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'x');
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }));

    await screen.findByText('Halaman tujuan');
    expect(router.state.location.pathname).toBe('/');
  });
});
