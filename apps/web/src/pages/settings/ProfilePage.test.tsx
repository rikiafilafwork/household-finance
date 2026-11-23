import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';

const authApi = vi.hoisted(() => ({ me: vi.fn(), updateProfile: vi.fn(), changePassword: vi.fn() }));
vi.mock('@/api/auth', () => ({ authApi }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const { ProfilePage, timezoneOptions } = await import('./ProfilePage');

const me = { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' };

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, me);
  queryClient.setQueryData(qk.plan('2026-11'), { month: '2026-11' });
  render(<QueryClientProvider client={queryClient}><ProfilePage /></QueryClientProvider>);
  return queryClient;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('ProfilePage', () => {
  it('saves a new timezone, updates the logged-in user and reloads month-based data', async () => {
    authApi.updateProfile.mockResolvedValue({ ...me, timezone: 'Asia/Makassar' });
    const queryClient = renderPage();

    expect(screen.getByLabelText('Email')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Simpan profil' })).toBeDisabled(); // belum ada perubahan
    await userEvent.selectOptions(screen.getByLabelText('Zona waktu'), 'Asia/Makassar');
    await userEvent.click(screen.getByRole('button', { name: 'Simpan profil' }));

    await waitFor(() => expect(authApi.updateProfile).toHaveBeenCalledWith({ name: 'Riki', timezone: 'Asia/Makassar' }));
    await waitFor(() => expect(queryClient.getQueryData(qk.me)).toMatchObject({ timezone: 'Asia/Makassar' }));
    expect(queryClient.getQueryState(qk.plan('2026-11'))?.isInvalidated).toBe(true);
  });

  it('shows server validation next to the field', async () => {
    authApi.updateProfile.mockRejectedValue(new ApiError(422, 'Invalid', { name: ['Nama terlalu panjang.'] }));
    renderPage();

    await userEvent.type(screen.getByLabelText('Nama'), ' A');
    await userEvent.click(screen.getByRole('button', { name: 'Simpan profil' }));
    expect(await screen.findByText('Nama terlalu panjang.')).toBeInTheDocument();
  });

  it('checks the password confirmation before calling the server', async () => {
    renderPage();

    await userEvent.type(screen.getByLabelText('Kata sandi saat ini'), 'lama12345');
    await userEvent.type(screen.getByLabelText('Kata sandi baru'), 'baru12345');
    await userEvent.type(screen.getByLabelText('Ulangi kata sandi baru'), 'beda12345');
    await userEvent.click(screen.getByRole('button', { name: 'Ganti kata sandi' }));

    expect(await screen.findByText('Konfirmasi kata sandi tidak sama.')).toBeInTheDocument();
    expect(authApi.changePassword).not.toHaveBeenCalled();
  });

  it('reports a wrong current password from the server and clears the form on success', async () => {
    authApi.changePassword.mockRejectedValueOnce(new ApiError(422, 'Invalid', { current_password: ['Kata sandi salah.'] })).mockResolvedValueOnce({});
    renderPage();

    await userEvent.type(screen.getByLabelText('Kata sandi saat ini'), 'salah1234');
    await userEvent.type(screen.getByLabelText('Kata sandi baru'), 'baru12345');
    await userEvent.type(screen.getByLabelText('Ulangi kata sandi baru'), 'baru12345');
    await userEvent.click(screen.getByRole('button', { name: 'Ganti kata sandi' }));
    expect(await screen.findByText('Kata sandi salah.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Ganti kata sandi' }));
    await waitFor(() => expect(authApi.changePassword).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByLabelText('Kata sandi baru')).toHaveValue(''));
  });
});

describe('timezoneOptions', () => {
  it('keeps the current zone selectable even if the browser list lacks it, without duplicating Indonesian zones', () => {
    const options = timezoneOptions('Etc/Unknown-Zone');
    expect(options).toContain('Etc/Unknown-Zone');
    expect(options).not.toContain('Asia/Jakarta');
  });
});
