import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';
import type { Account, Category, SavingsAccountSummary, SavingsGoal, SavingsSummary } from '@/api/types';

const goal = (id: number, name: string, allocated: number, target: number, extra: Partial<SavingsGoal> = {}): SavingsGoal => ({
  id, account_id: 1, name, target_amount: target, allocated_amount: allocated, target_date: null, achieved_at: null,
  archived_at: null, sort_order: id, percent: Math.floor((allocated * 100) / target), reached: allocated >= target, monthly_needed: null, ...extra,
});

const savingsAccount: Account = {
  id: 1, name: 'BCA Tabungan', type: 'bank', opening_balance: 20_000_000, balance: 20_000_000, sort_order: 1, archived_at: null, is_savings: true,
};

function summary(extra: Partial<SavingsAccountSummary> = {}, accounts?: SavingsAccountSummary[]): SavingsSummary {
  const rows = accounts ?? [{
    account: savingsAccount, balance: 20_000_000, allocated: 15_000_000, unallocated: 5_000_000, overdrawn: 0,
    goals: [
      goal(10, 'Dana darurat', 10_000_000, 12_000_000),
      goal(11, 'Liburan Bali', 5_000_000, 10_000_000, { target_date: '2027-06-30', monthly_needed: 714_286 }),
    ],
    ...extra,
  }];
  return { accounts: rows, totals: { balance: 20_000_000, allocated: 15_000_000, unallocated: 5_000_000, overdrawn: 0 } };
}

const categories: Category[] = [
  { id: 7, kind: 'expense', name: 'Liburan', color: null, icon: null, archived_at: null },
  { id: 8, kind: 'income', name: 'Gaji', color: null, icon: null, archived_at: null },
];

const api = vi.hoisted(() => ({
  summary: vi.fn(), createGoal: vi.fn(), updateGoal: vi.fn(), removeGoal: vi.fn(), archiveGoal: vi.fn(), unarchiveGoal: vi.fn(),
  achieveGoal: vi.fn(), unachieveGoal: vi.fn(), allocate: vi.fn(), allocations: vi.fn(), move: vi.fn(), use: vi.fn(),
}));
vi.mock('@/api/savings', () => ({ savingsApi: api }));
const categoriesList = vi.hoisted(() => vi.fn());
vi.mock('@/api/categories', () => ({ categoriesApi: { list: categoriesList } }));

const { SavingsPage } = await import('./SavingsPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><SavingsPage /></MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  api.summary.mockResolvedValue(summary());
  categoriesList.mockResolvedValue(categories);
});

describe('SavingsPage', () => {
  it('shows the account summary and a card per goal with its percentage', async () => {
    renderPage();

    const region = await screen.findByRole('region', { name: 'BCA Tabungan' });
    expect(region).toHaveTextContent('Saldo Rp20.000.000 · Dialokasikan Rp15.000.000 · Belum dialokasikan Rp5.000.000');
    expect(within(region).getByRole('progressbar', { name: 'Progres Dana darurat' })).toHaveAttribute('aria-valuenow', '83');
    expect(within(region).getByRole('progressbar', { name: 'Progres Liburan Bali' })).toHaveAttribute('aria-valuenow', '50');
    expect(within(region).getByText('83%')).toBeInTheDocument();
    expect(within(region).getByText('50%')).toBeInTheDocument();
    expect(region).toHaveTextContent('perlu Rp714.286/bln');
  });

  it('warns when savings were spent and offers to reduce an allocation', async () => {
    api.summary.mockResolvedValue(summary({ overdrawn: 1_200_000 }));
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Tabungan terpakai Rp1.200.000');
    await userEvent.click(within(alert).getByRole('button', { name: 'Kurangi alokasi' }));

    expect(await screen.findByLabelText('Tujuan')).toBeInTheDocument();
    api.allocate.mockResolvedValue({});
    await userEvent.type(screen.getByLabelText('Nominal dikurangi'), '200000');
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Kurangi alokasi' }));

    await waitFor(() => expect(api.allocate).toHaveBeenCalled());
    expect(api.allocate).toHaveBeenCalledWith(10, expect.objectContaining({ amount: -200_000, client_id: expect.any(String) }));
  });

  it('invites to mark a savings account when there is none', async () => {
    api.summary.mockResolvedValue({ accounts: [], totals: { balance: 0, allocated: 0, unallocated: 0, overdrawn: 0 } });
    renderPage();

    expect(await screen.findByText(/Belum ada rekening tabungan/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Rekening' })).toHaveAttribute('href', '/accounts');
  });

  it('renders a negative unallocated amount without crashing', async () => {
    api.summary.mockResolvedValue(summary({ unallocated: -50_000 }));
    renderPage();

    expect(await screen.findByRole('region', { name: 'BCA Tabungan' })).toHaveTextContent('Belum dialokasikan −Rp50.000');
  });

  it('sends the use form with amount, category and a client_id, reusing the id on a second submit', async () => {
    api.use.mockRejectedValueOnce(new ApiError(422, 'Gagal', { amount: ['Melebihi alokasi.'] })).mockResolvedValue({});
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Gunakan Dana darurat' }));
    await userEvent.type(screen.getByLabelText('Nominal dipakai'), '300000');
    expect(within(screen.getByLabelText('Kategori')).queryByText('Gaji')).not.toBeInTheDocument();
    const submit = screen.getByRole('button', { name: 'Gunakan' });
    await userEvent.click(submit);
    await waitFor(() => expect(api.use).toHaveBeenCalledTimes(1));
    await userEvent.click(submit);
    await waitFor(() => expect(api.use).toHaveBeenCalledTimes(2));

    expect(api.use.mock.calls[0]![0]).toBe(10);
    expect(api.use.mock.calls[0]![1]).toEqual(expect.objectContaining({ amount: 300_000, category_id: 7, client_id: expect.any(String) }));
    expect(api.use.mock.calls[1]![1].client_id).toBe(api.use.mock.calls[0]![1].client_id);
  });

  it('shows a 422 on amount next to the field of the add form', async () => {
    api.allocate.mockRejectedValue(new ApiError(422, 'Gagal', { amount: ['Melebihi saldo yang belum dialokasikan.'] }));
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah alokasi Dana darurat' }));
    await userEvent.type(screen.getByLabelText('Nominal ditambahkan'), '9000000');
    await userEvent.click(screen.getByRole('button', { name: 'Tambah alokasi' }));

    expect(await screen.findByText('Melebihi saldo yang belum dialokasikan.')).toBeInTheDocument();
  });
});
