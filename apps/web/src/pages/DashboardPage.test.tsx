import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { qk } from '@/api/keys';
import type { PlanComparison, PlanItem } from '@/api/plan';
import type { Account } from '@/api/types';
import { monthIn, shiftMonth, todayIn } from '@/lib/dates';

const TZ = 'Asia/Jakarta';
const MONTH = monthIn(TZ);
const TODAY = todayIn(TZ);
const YESTERDAY_DUE = TODAY.endsWith('-01') ? null : `${MONTH}-01`;

const item = (id: number, name: string, extra: Partial<PlanItem>): PlanItem => ({
  id, month: MONTH, type: 'expense', mode: 'checklist', name, category_id: 1, planned_amount: 100_000, account_id: 1, due_on: null,
  template_id: null, source: 'manual', sort_order: 0, skipped_at: null, status: 'planned', transaction: null, actual: 0, percent: null, ...extra,
});

const plan: PlanComparison = {
  month: MONTH,
  totals: {
    income: { planned: 9_500_000, actual: 8_000_000, unplanned: 0, unpaid: 0, envelope_remaining: 1_500_000 },
    expense: { planned: 5_100_000, actual: 4_075_000, unplanned: 180_000, unpaid: 500_000, envelope_remaining: 740_000 },
    remaining_budget: 1_025_000,
    still_to_spend: 1_240_000,
  },
  categories: [],
  items: [
    item(1, 'Gaji', { type: 'income', status: 'done', actual: 8_000_000 }),
    item(2, 'Pulsa', { status: 'done', actual: 165_000 }),
    item(3, 'Listrik', { planned_amount: 500_000, due_on: YESTERDAY_DUE }),
    item(4, 'Asuransi', { status: 'skipped', skipped_at: '2026-11-01T00:00:00Z' }),
    item(8, 'Internet', { planned_amount: 350_000, due_on: `${MONTH}-28` }),
    item(5, 'Makan', { mode: 'envelope', planned_amount: 2_000_000, actual: 1_350_000, percent: 67, account_id: null }),
    item(6, 'Transport', { mode: 'envelope', planned_amount: 600_000, actual: 510_000, percent: 85, account_id: null }),
    item(7, 'Hiburan', { mode: 'envelope', planned_amount: 300_000, actual: 320_000, percent: 106, account_id: null }),
  ],
};

const accounts: Account[] = [
  { id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 5_000_000, sort_order: 0, archived_at: null },
  { id: 2, name: 'GoPay', type: 'ewallet', opening_balance: 0, balance: 250_000, sort_order: 1, archived_at: null },
  { id: 3, name: 'Dompet', type: 'cash', opening_balance: 0, balance: 150_000, sort_order: 2, archived_at: null },
  { id: 4, name: 'Lama', type: 'bank', opening_balance: 0, balance: 999_000, sort_order: 3, archived_at: '2026-01-01T00:00:00Z' },
];

const planApi = vi.hoisted(() => ({ get: vi.fn() }));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
const categoriesApi = vi.hoisted(() => ({ list: vi.fn() }));
const transactionsApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/plan', () => ({ planApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));
vi.mock('@/api/categories', () => ({ categoriesApi }));
vi.mock('@/api/transactions', () => ({ transactionsApi }));

const { DashboardPage } = await import('./DashboardPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: TZ });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><DashboardPage /></MemoryRouter></QueryClientProvider>);
  return queryClient;
}

beforeEach(() => {
  vi.clearAllMocks();
  planApi.get.mockResolvedValue(plan);
  accountsApi.list.mockResolvedValue(accounts);
  categoriesApi.list.mockResolvedValue([]);
  transactionsApi.list.mockResolvedValue({ data: [], meta: { next_cursor: null, prev_cursor: null, per_page: 8 } });
});

describe('DashboardPage — Beranda sederhana', () => {
  it('totals active balances per account type, ignoring archived accounts', async () => {
    renderPage();
    const [total] = await screen.findAllByText('Rp5.400.000');
    expect(total!.closest('[data-slot="card-header"]'))
      .toHaveTextContent('Rekening Rp5.000.000 · E-wallet Rp250.000 · Tunai Rp150.000');
    expect(screen.queryByText('Lama')).not.toBeInTheDocument();
  });

  it('shows the plan totals of the current month exactly as the plan API computed them', async () => {
    renderPage();
    const card = await screen.findByTestId('dashboard-plan');

    expect(planApi.get).toHaveBeenCalledWith(MONTH);
    expect(await within(card).findByTestId('plan-income')).toHaveTextContent('Rp8.000.000dari Rp9.500.000');
    expect(within(card).getByTestId('plan-expense')).toHaveTextContent('Rp4.075.000dari Rp5.100.000');
    expect(within(card).getByTestId('plan-remaining')).toHaveTextContent('Rp1.025.000');
  });

  it('shows the remaining plan next to all account balances and the free money', async () => {
    renderPage();
    const block = await screen.findByTestId('plan-free');

    expect(screen.getByTestId('plan-remaining')).toHaveTextContent('Sisa rencanaRp1.025.000');
    // 5.000.000 + 250.000 + 150.000 (arsip tidak dihitung) − 1.240.000 dari server
    expect(block).toHaveTextContent('Saldo semua akunRp5.400.000');
    expect(block).toHaveTextContent('Uang bebasRp4.160.000');
    expect(block).toHaveTextContent('masih akan keluar Rp1.240.000 (tagihan belum dibayar Rp500.000 + sisa amplop Rp740.000)');
    expect(block).not.toHaveTextContent('tidak cukup');
  });

  it('warns in red when the balance cannot cover the rest of the plan', async () => {
    accountsApi.list.mockResolvedValue([{ ...accounts[0]!, balance: 1_000_000 }]);
    renderPage();
    const block = await screen.findByTestId('plan-free');

    expect(await within(block).findByText('−Rp240.000')).toHaveClass('text-destructive');
    expect(block).toHaveTextContent('Saldo tidak cukup untuk menutup rencana bulan ini.');
  });

  it('shares the plan cache with the Rencana page (same query key)', async () => {
    const queryClient = renderPage();
    await screen.findByTestId('plan-income');
    expect(queryClient.getQueryData(qk.plan(MONTH))).toBe(plan);
  });

  it('lists unchecked checklist items by due date, skipping done and skipped ones', async () => {
    renderPage();
    const pending = await screen.findByTestId('plan-pending');

    expect(pending).toHaveTextContent('Belum dicentang (2)');
    const text = pending.textContent ?? '';
    expect(text).not.toContain('Pulsa');
    expect(text).not.toContain('Asuransi');
    expect(text).not.toContain('Gaji');
    if (YESTERDAY_DUE) {
      expect(text.indexOf('Listrik')).toBeLessThan(text.indexOf('Internet'));
      expect(within(pending).getByText(/^lewat/)).toHaveClass('text-destructive');
    }
  });

  it('flags envelopes from 80%, most used first', async () => {
    renderPage();
    const hot = await screen.findByTestId('plan-envelopes');

    expect(hot).toHaveTextContent('Hiburan106%');
    expect(hot).toHaveTextContent('Transport85%');
    expect(hot).not.toHaveTextContent('Makan');
    expect((hot.textContent ?? '').indexOf('Hiburan')).toBeLessThan((hot.textContent ?? '').indexOf('Transport'));
  });

  it('invites to create a plan when the month is empty', async () => {
    planApi.get.mockResolvedValue({ ...plan, items: [] });
    renderPage();
    expect(await screen.findByText(/Belum ada rencana bulan ini/)).toBeInTheDocument();
    expect(screen.queryByTestId('plan-income')).not.toBeInTheDocument();
  });

  it('does not load another month', async () => {
    renderPage();
    await screen.findByTestId('plan-income');
    expect(planApi.get).not.toHaveBeenCalledWith(shiftMonth(MONTH, -1));
  });
});
