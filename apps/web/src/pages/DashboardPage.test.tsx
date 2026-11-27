import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Dashboard } from '@/api/dashboard';
import { qk } from '@/api/keys';
import type { Account, Category } from '@/api/types';
import { monthIn, shiftMonth, todayIn } from '@/lib/dates';

const TZ = 'Asia/Jakarta';
const MONTH = monthIn(TZ);
const TODAY = todayIn(TZ);

// Angka meniru fixture Architecture #11 (lihat PlanFixture::architecture()).
const dashboard: Dashboard = {
  month: MONTH,
  is_current_month: true,
  balances: { bank: 5_000_000, ewallet: 250_000, cash: 150_000, savings: 0, total: 5_400_000 },
  income: { planned: 9_500_000, actual: 8_000_000, unplanned: 0, unpaid: 0, envelope_remaining: 1_500_000 },
  expense: { planned: 5_100_000, actual: 4_075_000, unplanned: 180_000, unpaid: 500_000, envelope_remaining: 740_000 },
  remaining_budget: 1_025_000,
  still_to_spend: 1_240_000,
  free_money: 4_160_000,
  categories: [
    { id: 11, name: 'Makan', color: '#f97316', planned: 2_000_000, actual: 1_350_000 },
    { id: 12, name: 'Transport', color: null, planned: 600_000, actual: 510_000 },
    { id: 13, name: 'Hiburan', color: null, planned: 300_000, actual: 320_000 },
    { id: 14, name: 'Belanja', color: null, planned: 0, actual: 180_000 },
    { id: 15, name: 'Pendidikan', color: null, planned: 0, actual: 0 },
  ],
  income_sources: [
    { id: 1, name: 'Gaji', planned: 8_000_000, actual: 8_000_000, done: true, skipped: false },
    { id: 9, name: 'Freelance', planned: 1_500_000, actual: 0, done: false, skipped: false },
  ],
  due_items: [
    { id: 3, type: 'expense', name: 'Listrik', due_on: '2000-01-01', planned: 500_000, account_id: 2, overdue: true },
    { id: 8, type: 'expense', name: 'Internet', due_on: TODAY, planned: 350_000, account_id: null, overdue: false },
  ],
  envelopes_warning: [
    { id: 7, name: 'Hiburan', planned: 300_000, actual: 320_000, percent: 106 },
    { id: 6, name: 'Transport', planned: 600_000, actual: 510_000, percent: 85 },
  ],
  trend: [5, 4, 3, 2, 1, 0].map((back) => ({ month: shiftMonth(MONTH, -back), income_actual: 9_000_000, expense_actual: 4_000_000 + back, expense_planned: 5_000_000 })),
  recent: [
    { id: 50, type: 'expense', account_id: 3, category_id: 11, amount: -45_000, occurred_on: TODAY, note: 'Nasi padang', plan_item_id: null },
  ],
  reconcile_ok: true,
  savings: { balance: 0, allocated: 0, unallocated: 0, overdrawn: false, goals: [] },
};

const accounts: Account[] = [
  { id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 5_000_000, sort_order: 0, archived_at: null, is_savings: false },
  { id: 2, name: 'GoPay', type: 'ewallet', opening_balance: 0, balance: 250_000, sort_order: 1, archived_at: null, is_savings: false },
  { id: 3, name: 'Dompet', type: 'cash', opening_balance: 0, balance: 150_000, sort_order: 2, archived_at: null, is_savings: false },
  { id: 4, name: 'Lama', type: 'bank', opening_balance: 0, balance: 999_000, sort_order: 3, archived_at: '2026-01-01T00:00:00Z', is_savings: false },
];
const categories: Category[] = [{ id: 11, kind: 'expense', name: 'Makan', color: null, icon: null, archived_at: null }];

const dashboardApi = vi.hoisted(() => ({ get: vi.fn() }));
const planApi = vi.hoisted(() => ({ get: vi.fn(), check: vi.fn(), generate: vi.fn() }));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
const categoriesApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/dashboard', () => ({ dashboardApi }));
vi.mock('@/api/plan', () => ({ planApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));
vi.mock('@/api/categories', () => ({ categoriesApi }));
vi.mock('@/api/notifications', () => ({ notificationsApi: { list: vi.fn() } }));

const { DashboardPage } = await import('./DashboardPage');

function renderPage(path = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: TZ });
  render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[path]}><DashboardPage /></MemoryRouter></QueryClientProvider>);
  return queryClient;
}

beforeEach(() => {
  vi.clearAllMocks();
  dashboardApi.get.mockResolvedValue(dashboard);
  accountsApi.list.mockResolvedValue(accounts);
  categoriesApi.list.mockResolvedValue(categories);
});

describe('DashboardPage — Beranda', () => {
  it('loads the dashboard of the current month in one request, no separate plan request', async () => {
    renderPage();
    await screen.findByTestId('kpi-balance');
    expect(dashboardApi.get).toHaveBeenCalledExactlyOnceWith(MONTH);
    expect(planApi.get).not.toHaveBeenCalled();
  });

  it('shows the four KPI cards exactly as the server computed them', async () => {
    renderPage();
    expect(await screen.findByTestId('kpi-balance')).toHaveTextContent('Total saldoRp5.400.000RekeningRp5.000.000E-walletRp250.000DompetRp150.000');
    expect(screen.getByTestId('kpi-income')).toHaveTextContent('PemasukanRp8.000.000dari rencana Rp9.500.000');
    expect(screen.getByTestId('kpi-expense')).toHaveTextContent('PengeluaranRp4.075.000dari rencana Rp5.100.000');
    expect(screen.getByTestId('kpi-unplanned')).toHaveTextContent('di luar rencana Rp180.000');
    expect(screen.getByTestId('kpi-remaining')).toHaveTextContent('Sisa anggaranRp1.025.000Rp500.000 belum dibayar');
    expect(screen.getByTestId('kpi-free')).toHaveTextContent('Uang bebas Rp4.160.000');
    // 8.000.000 / 9.500.000 = 84% (dibulatkan ke bawah)
    expect(within(screen.getByTestId('kpi-income')).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '84');
  });

  it('flags negative free money', async () => {
    dashboardApi.get.mockResolvedValue({ ...dashboard, free_money: -240_000 });
    renderPage();
    expect(await screen.findByTestId('kpi-free')).toHaveTextContent('Uang bebas −Rp240.000 — saldo tidak cukup menutup rencana');
  });

  it('hides free money for another month taken from the URL', async () => {
    const prev = shiftMonth(MONTH, -1);
    dashboardApi.get.mockResolvedValue({ ...dashboard, month: prev, is_current_month: false, free_money: null });
    renderPage(`/?month=${prev}`);
    await screen.findByTestId('kpi-remaining');
    expect(dashboardApi.get).toHaveBeenCalledWith(prev);
    expect(screen.queryByTestId('kpi-free')).not.toBeInTheDocument();
  });

  it('switches month through the picker and the URL', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('kpi-balance');
    await user.click(screen.getByRole('button', { name: 'Bulan sebelumnya' }));
    expect(dashboardApi.get).toHaveBeenLastCalledWith(shiftMonth(MONTH, -1));
    expect(screen.getByRole('button', { name: 'Bulan ini' })).toBeInTheDocument();
  });

  it('shows the reconcile banner only when balances do not match', async () => {
    renderPage();
    await screen.findByTestId('kpi-balance');
    expect(screen.queryByTestId('reconcile-banner')).not.toBeInTheDocument();
  });

  it('warns when reconcile finds a mismatch', async () => {
    dashboardApi.get.mockResolvedValue({ ...dashboard, reconcile_ok: false });
    renderPage();
    expect(await screen.findByTestId('reconcile-banner')).toHaveTextContent('accounts:reconcile --fix');
  });

  it('charts planned vs actual per category, biggest first, colored by percent, skipping empty rows', async () => {
    renderPage();
    const panel = await screen.findByTestId('panel-categories');
    const rows = within(panel).getAllByRole('listitem');
    expect(rows.map((r) => r.dataset.testid)).toEqual(['category-11', 'category-12', 'category-13', 'category-14']);
    expect(rows[0]).toHaveAttribute('data-tone', 'normal'); // 67%
    expect(rows[1]).toHaveAttribute('data-tone', 'warning'); // 85%
    expect(rows[2]).toHaveAttribute('data-tone', 'over'); // 106%
    expect(rows[3]).toHaveAttribute('data-tone', 'over'); // tanpa rencana
    expect(rows[0]).toHaveTextContent('MakanRp1.350.000 / Rp2.000.000');
  });

  it('lists income sources with their status and the 6-month trend', async () => {
    renderPage();
    expect(await screen.findByTestId('income-1')).toHaveTextContent('Gaji DiterimaRp8.000.000 / Rp8.000.000');
    expect(screen.getByTestId('income-9')).toHaveTextContent('Freelance BelumRp0 / Rp1.500.000');

    const rows = within(screen.getByTestId('trend-table')).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(6);
    expect(rows[5]).toHaveTextContent(`${MONTH}Rp9.000.000Rp4.000.000Rp5.000.000`);
  });

  it('lists items due soon or overdue and checks one quickly with the plan defaults', async () => {
    const user = userEvent.setup();
    planApi.check.mockResolvedValue({ item: {}, transaction: {} });
    renderPage();

    expect(await screen.findByTestId('due-3')).toHaveTextContent('ListrikTerlambat · 1 Jan');
    expect(screen.getByTestId('due-8')).toHaveTextContent('InternetHari ini');
    expect(screen.getByTestId('panel-due')).toHaveTextContent('Perlu dicentang (2)');

    await user.click(screen.getByRole('button', { name: 'Centang Listrik' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByLabelText('Dari akun')).toHaveValue('2');
    await user.click(within(dialog).getByRole('button', { name: 'Centang & catat' }));

    expect(planApi.check).toHaveBeenCalledWith(3, expect.objectContaining({ amount: 500_000, account_id: 2, occurred_on: TODAY, note: 'Listrik' }));
    // Setelah centang, dashboard dimuat ulang (invalidateMoney → qk.dashboard).
    await vi.waitFor(() => expect(dashboardApi.get).toHaveBeenCalledTimes(2));
  });

  it('flags envelopes from 80%, most used first', async () => {
    renderPage();
    const list = await screen.findByTestId('envelopes-warning');
    const text = list.textContent ?? '';
    expect(text).toContain('Hiburan106%');
    expect(text).toContain('lebih Rp20.000');
    expect(text).toContain('Transport85%');
    expect(text.indexOf('Hiburan')).toBeLessThan(text.indexOf('Transport'));
  });

  it('shows active account balances and the recent transactions', async () => {
    renderPage();
    const accountsPanel = await screen.findByTestId('panel-accounts');
    expect(await within(accountsPanel).findByText('BCA')).toBeInTheDocument();
    expect(within(accountsPanel).queryByText('Lama')).not.toBeInTheDocument();

    const recent = screen.getByTestId('recent');
    expect(await within(recent).findByText('Makan')).toBeInTheDocument();
    expect(recent).toHaveTextContent('Dompet · Nasi padang');
    expect(recent).toHaveTextContent('−Rp45.000');
  });

  it('offers to generate from templates when the month has no plan, without crashing', async () => {
    const user = userEvent.setup();
    const empty: Dashboard = {
      ...dashboard,
      income: { planned: 0, actual: 0, unplanned: 0, unpaid: 0, envelope_remaining: 0 },
      expense: { planned: 0, actual: 0, unplanned: 0, unpaid: 0, envelope_remaining: 0 },
      remaining_budget: 0, still_to_spend: 0, free_money: 5_400_000,
      categories: [], income_sources: [], due_items: [], envelopes_warning: [],
      trend: dashboard.trend.map((t) => ({ ...t, income_actual: 0, expense_actual: 0, expense_planned: 0 })),
      recent: [],
    };
    dashboardApi.get.mockResolvedValue(empty);
    planApi.generate.mockResolvedValue({ created: 4 });
    renderPage();

    const banner = await screen.findByTestId('empty-plan');
    expect(screen.getByText('Belum ada rencana atau pengeluaran berkategori bulan ini.')).toBeInTheDocument();
    expect(screen.getByText('Tidak ada tagihan jatuh tempo dalam 7 hari.')).toBeInTheDocument();
    await user.click(within(banner).getByRole('button', { name: 'Buat dari template' }));
    expect(planApi.generate).toHaveBeenCalledWith(MONTH);
  });

  it('does not show the empty-plan banner when the month has a plan', async () => {
    renderPage();
    await screen.findByTestId('kpi-balance');
    expect(screen.queryByTestId('empty-plan')).not.toBeInTheDocument();
  });
});
