import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';
import type { Account } from '@/api/types';

const account = (id: number, name: string, type: Account['type'], balance: number, extra: Partial<Account> = {}): Account => ({
  id, name, type, opening_balance: balance, balance, sort_order: id, archived_at: null, ...extra,
});

const accounts = [
  account(1, 'BCA', 'bank', 4_500_000),
  account(2, 'Jago', 'bank', 500_000),
  account(3, 'GoPay', 'ewallet', -15_000),
  account(4, 'Dompet harian', 'cash', 120_000),
  account(5, 'Mandiri Lama', 'bank', 0, { archived_at: '2026-10-01T00:00:00Z' }),
];

const api = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(), unarchive: vi.fn(), remove: vi.fn(), adjust: vi.fn(),
}));
vi.mock('@/api/accounts', () => ({ accountsApi: api }));

const { AccountsPage } = await import('./AccountsPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><AccountsPage /></MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  api.list.mockResolvedValue(accounts);
});

describe('AccountsPage', () => {
  it('groups active accounts per type with subtotals and warns about a negative balance', async () => {
    renderPage();

    const bank = await screen.findByRole('region', { name: 'Rekening' });
    expect(within(bank).getByText('BCA')).toBeInTheDocument();
    expect(within(bank).getByText('Jago')).toBeInTheDocument();
    expect(within(bank).getByText('Rp5.000.000')).toBeInTheDocument(); // subtotal 4,5 jt + 0,5 jt

    const ewallet = screen.getByRole('region', { name: 'E-wallet' });
    expect(within(ewallet).getByText(/Saldo negatif/)).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Dompet' })).getByText('Dompet harian')).toBeInTheDocument();
  });

  it('hides archived accounts until asked, and they get no “adjust” action', async () => {
    renderPage();
    await screen.findByText('BCA');
    expect(screen.queryByText('Mandiri Lama')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Tampilkan akun diarsip \(1\)/ }));

    expect(screen.getByText('Mandiri Lama')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sesuaikan saldo Mandiri Lama' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Ubah Mandiri Lama' })).toBeInTheDocument();
  });

  it('previews the adjustment and sends the real balance as an integer', async () => {
    api.adjust.mockResolvedValue({ account: { ...accounts[3], balance: 95_000 }, adjustment: { id: 9 } });
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Sesuaikan saldo Dompet harian' }));
    await userEvent.type(screen.getByLabelText('Saldo sebenarnya'), '95000');
    expect(screen.getByTestId('adjust-diff')).toHaveTextContent('Akan dicatat penyesuaian −Rp25.000');

    await userEvent.click(screen.getByRole('button', { name: 'Sesuaikan' }));

    expect(api.adjust).toHaveBeenCalledWith(4, expect.objectContaining({ actual_balance: 95_000, note: null }));
    expect(api.adjust.mock.calls[0]![1].occurred_on).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('says so when the balance already matches', async () => {
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Sesuaikan saldo BCA' }));
    await userEvent.type(screen.getByLabelText('Saldo sebenarnya'), '4500000');

    expect(screen.getByTestId('adjust-diff')).toHaveTextContent('Saldo sudah sesuai');
  });

  it('creates an account with type and opening balance', async () => {
    api.create.mockResolvedValue(account(6, 'OVO', 'ewallet', 50_000));
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: /Tambah akun/ }));
    await userEvent.type(screen.getByLabelText('Nama'), 'OVO');
    await userEvent.selectOptions(screen.getByLabelText('Jenis'), 'ewallet');
    await userEvent.type(screen.getByLabelText('Saldo awal'), '50000');
    await userEvent.click(screen.getAllByRole('button', { name: 'Tambah akun' }).at(-1)!);

    expect(api.create).toHaveBeenCalledWith({ name: 'OVO', type: 'ewallet', opening_balance: 50_000 });
  });

  it('shows the server reason when an account with transactions cannot be deleted', async () => {
    api.remove.mockRejectedValue(new ApiError(422, 'Data tidak valid.', { account: ['Akun sudah punya transaksi. Arsipkan saja.'] }));
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Ubah BCA' }));
    await userEvent.click(screen.getByRole('button', { name: 'Hapus' }));
    await userEvent.click(screen.getByRole('button', { name: 'Ya, hapus' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Arsipkan saja');
  });
});
