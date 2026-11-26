import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router';
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { qk } from '@/api/keys';
import type { CashFlow, CashVsNonCash, CategoryBreakdown, MonthlySummary, TrendMonth } from '@/api/reports';
import type { Account } from '@/api/types';

const totals = (planned: number, actual: number, unplanned = 0) => ({ planned, actual, unplanned, unpaid: 0, envelope_remaining: 0 });
const summary: MonthlySummary = {
  month: '2026-11',
  income: totals(9_500_000, 8_000_000),
  expense: totals(5_100_000, 4_075_000, 180_000),
  net: 3_925_000,
  remaining_budget: 1_025_000,
  unrecorded: -50_000,
  previous: { month: '2026-10', income: 8_000_000, expense: 4_500_000, net: 3_500_000, change: { income: 0, expense: -425_000, net: 425_000 } },
};
const byCategory: CategoryBreakdown = {
  from: '2026-11', to: '2026-11', kind: 'expense', total_planned: 2_600_000, total_actual: 1_530_000,
  categories: [
    { category_id: 1, name: 'Makan', color: '#F97316', planned: 2_000_000, actual: 1_350_000, percent: 88 },
    { category_id: null, name: 'Tanpa kategori', color: null, planned: 600_000, actual: 180_000, percent: 11 },
  ],
};
const trend: TrendMonth[] = [
  { month: '2026-10', income_planned: 9_000_000, income_actual: 8_000_000, expense_planned: 5_000_000, expense_actual: 4_500_000, net: 3_500_000 },
  { month: '2026-11', income_planned: 9_500_000, income_actual: 8_000_000, expense_planned: 5_100_000, expense_actual: 4_075_000, net: 3_925_000 },
];
const flow: CashFlow = {
  from: '2026-11-01', to: '2026-11-20', account_id: null, opening_balance: 19_901_000, closing_balance: 15_776_000,
  days: Array.from({ length: 20 }, (_, i) => {
    const date = `2026-11-${String(i + 1).padStart(2, '0')}`;
    const net = date === '2026-11-05' ? -1_200_000 : date === '2026-11-15' ? -2_925_000 : 0;
    return { date, net, balance: date < '2026-11-05' ? 19_901_000 : date < '2026-11-15' ? 18_701_000 : 15_776_000 };
  }),
};
const cash: CashVsNonCash = {
  month: '2026-11', total: 4_075_000,
  types: [
    { type: 'bank', expense: 3_000_000, percent: 73, unrecorded: 0 },
    { type: 'ewallet', expense: 875_000, percent: 21, unrecorded: 0 },
    { type: 'cash', expense: 200_000, percent: 4, unrecorded: -50_000 },
  ],
};
const accounts: Account[] = [
  { id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 0, sort_order: 0, archived_at: null },
  { id: 2, name: 'Dompet', type: 'cash', opening_balance: 0, balance: 0, sort_order: 1, archived_at: null },
];

const reportsApi = vi.hoisted(() => ({
  summary: vi.fn(), byCategory: vi.fn(), trend: vi.fn(), cashFlow: vi.fn(), cashVsNonCash: vi.fn(),
  exportUrl: (from: string, to: string, d: string) => `/api/reports/export.csv?from=${from}&to=${to}&delimiter=${d}`,
}));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/reports', () => ({ reportsApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));

const { ReportsPage } = await import('./ReportsPage');

function Location() {
  const { search } = useLocation();
  return <output data-testid="search">{search}</output>;
}

function renderPage(path = '/reports') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}><ReportsPage /><Location /></MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeAll(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-20T03:00:00Z'));   // 20 Nov 2026, 10.00 WIB
});
afterAll(() => vi.useRealTimers());

beforeEach(() => {
  vi.clearAllMocks();
  reportsApi.summary.mockResolvedValue(summary);
  reportsApi.byCategory.mockResolvedValue(byCategory);
  reportsApi.trend.mockResolvedValue(trend);
  reportsApi.cashFlow.mockResolvedValue(flow);
  reportsApi.cashVsNonCash.mockResolvedValue(cash);
  accountsApi.list.mockResolvedValue(accounts);
});

describe('ReportsPage', () => {
  it('opens on the summary of the current month with changes from last month', async () => {
    renderPage();

    const kpis = await screen.findByLabelText('Ringkasan angka');
    expect(reportsApi.summary).toHaveBeenCalledWith('2026-11');
    expect(kpis).toHaveTextContent('PemasukanRp8.000.000sama dengan bulan lalu');
    expect(kpis).toHaveTextContent('PengeluaranRp4.075.000turun Rp425.000 dari bulan lalu');
    expect(kpis).toHaveTextContent('Selisih (net)+Rp3.925.000naik Rp425.000 dari bulan lalu');
    // Pengeluaran turun = baik (hijau); net naik = baik.
    expect(within(kpis).getByText(/turun/).closest('span')).toHaveClass('text-success');
    expect(screen.getByText(/Sisa anggaran/)).toHaveTextContent('Sisa anggaran: Rp1.025.000 · di luar rencana: Rp180.000');
    expect(screen.getByText('84% dari rencana')).toBeInTheDocument();          // pemasukan 8,0 / 9,5 jt
    expect(screen.getByText('79% dari rencana')).toBeInTheDocument();          // pengeluaran 4,075 / 5,1 jt
    expect(screen.getByRole('note')).toHaveTextContent('−Rp50.000');
    expect(screen.getByRole('note')).toHaveTextContent('tidak tercatat');
  });

  it('keeps the tab when the month changes, and the month when the tab changes', async () => {
    const user = userEvent.setup();
    renderPage('/reports?tab=trend');
    await screen.findByRole('table', { name: 'Tabel tren' });

    await user.click(screen.getByRole('button', { name: 'Bulan sebelumnya' }));
    await waitFor(() => expect(reportsApi.trend).toHaveBeenLastCalledWith('2026-10', 6));
    expect(screen.getByTestId('search')).toHaveTextContent('?tab=trend&month=2026-10');

    await user.click(screen.getByRole('tab', { name: 'Ringkasan' }));
    expect(screen.getByTestId('search')).toHaveTextContent('?month=2026-10');
    await waitFor(() => expect(reportsApi.summary).toHaveBeenCalledWith('2026-10'));
  });

  it('breaks spending down by category over a chosen range and kind', async () => {
    const user = userEvent.setup();
    renderPage('/reports?tab=categories');

    const table = await screen.findByRole('table', { name: 'Rincian kategori' });
    expect(reportsApi.byCategory).toHaveBeenCalledWith({ from: '2026-11', to: '2026-11', kind: 'expense' });
    expect(within(table).getAllByRole('row').map((r) => r.textContent)).toEqual([
      'KategoriRencanaAktualPorsi',
      'MakanRp2.000.000Rp1.350.00088%',
      'Tanpa kategoriRp600.000Rp180.00011%',
      'TotalRp2.600.000Rp1.530.000',
    ]);

    await user.selectOptions(screen.getByLabelText('Rentang'), '12 bulan');
    await user.selectOptions(screen.getByLabelText('Jenis'), 'Pemasukan');
    await waitFor(() => expect(reportsApi.byCategory).toHaveBeenLastCalledWith({ from: '2025-12', to: '2026-11', kind: 'income' }));
    expect(screen.getByText('Pemasukan per kategori')).toBeInTheDocument();
  });

  it('shows the trend newest month first and lets the user widen it', async () => {
    const user = userEvent.setup();
    renderPage('/reports?tab=trend');

    const table = await screen.findByRole('table', { name: 'Tabel tren' });
    expect(within(table).getAllByRole('row')[1]).toHaveTextContent('November 2026Rp8.000.000Rp4.075.000Rp5.100.000+Rp3.925.000');
    expect(screen.getByRole('img', { name: 'Tren 6 bulan: pemasukan dan pengeluaran' })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Jumlah bulan'), '24 bulan');
    await waitFor(() => expect(reportsApi.trend).toHaveBeenLastCalledWith('2026-11', 24));
  });

  it('shows the daily cash flow up to today, per account if chosen', async () => {
    const user = userEvent.setup();
    renderPage('/reports?tab=cash-flow');

    const table = await screen.findByRole('table', { name: 'Pergerakan harian' });
    expect(reportsApi.cashFlow).toHaveBeenCalledWith({ from: '2026-11-01', to: '2026-11-20' });
    expect(screen.getByLabelText('Saldo periode')).toHaveTextContent('Saldo awalRp19.901.000Saldo akhirRp15.776.000Perubahan−Rp4.125.000');
    expect(within(table).getAllByRole('row')).toHaveLength(3);                // hanya hari yang ada pergerakan
    expect(screen.getByRole('img', { name: 'Saldo berjalan harian' })).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Akun'), 'Dompet');
    await waitFor(() => expect(reportsApi.cashFlow).toHaveBeenLastCalledWith({ from: '2026-11-01', to: '2026-11-20', account: 2 }));
    expect(screen.queryByText(/saling meniadakan/)).not.toBeInTheDocument();
  });

  it('splits spending into bank, e-wallet and cash with unrecorded cash', async () => {
    renderPage('/reports?tab=cash');

    await screen.findByLabelText('Pengeluaran per jenis akun');
    expect(screen.getByTestId('cash-bank')).toHaveTextContent('RekeningRp3.000.000 · 73%');
    expect(screen.getByTestId('cash-cash')).toHaveTextContent('DompetRp200.000 · 4%');
    expect(screen.getByTestId('cash-cash')).toHaveTextContent('Tak tercatat (sesuaikan saldo): −Rp50.000');
    expect(screen.getByTestId('cash-ewallet')).not.toHaveTextContent('Tak tercatat');
  });

  it('exports the selected month, up to today for the current month', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByLabelText('Ringkasan angka');

    await user.click(screen.getByRole('button', { name: 'Export' }));
    expect(await screen.findByRole('menuitem', { name: /Excel \(Indonesia\)/ }))
      .toHaveAttribute('href', '/api/reports/export.csv?from=2026-11-01&to=2026-11-20&delimiter=semicolon');
    expect(screen.getByRole('menuitem', { name: /CSV standar/ })).toHaveAttribute('href', '/api/reports/export.csv?from=2026-11-01&to=2026-11-20&delimiter=comma');
  });

  it('exports a whole past month', async () => {
    const user = userEvent.setup();
    renderPage('/reports?month=2026-02');
    await screen.findByLabelText('Ringkasan angka');

    await user.click(screen.getByRole('button', { name: 'Export' }));
    expect(await screen.findByRole('menuitem', { name: /Excel/ })).toHaveAttribute('href', expect.stringContaining('from=2026-02-01&to=2026-02-28'));
  });
});
