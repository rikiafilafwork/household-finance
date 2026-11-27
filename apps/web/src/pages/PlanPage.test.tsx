import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';
import type { PlanComparison, PlanItem } from '@/api/plan';
import type { Account, Category } from '@/api/types';
import { defaultDateIn, monthIn, shiftMonth, todayIn } from '@/lib/dates';

const TZ = 'Asia/Jakarta';
const MONTH = monthIn(TZ);

const item = (id: number, name: string, extra: Partial<PlanItem>): PlanItem => ({
  id, month: MONTH, type: 'expense', mode: 'checklist', name, category_id: 1, planned_amount: 100_000, account_id: 1, due_on: null,
  template_id: null, source: 'manual', sort_order: 0, skipped_at: null, auto_posted_at: null, status: 'planned', transaction: null, actual: 0, percent: null, ...extra,
});

// Angka total = fixture Architecture.md #11 (dihitung server; halaman tidak menghitung ulang).
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
    item(1, 'Gaji', { type: 'income', category_id: 9, planned_amount: 8_000_000, status: 'done', actual: 8_000_000, transaction: { id: 50, account_id: 1, amount: 8_000_000, occurred_on: `${MONTH}-25` } }),
    item(2, 'Pulsa', { category_id: 2, planned_amount: 150_000, status: 'done', actual: 165_000, transaction: { id: 51, account_id: 1, amount: -165_000, occurred_on: `${MONTH}-10` } }),
    item(3, 'Listrik', { category_id: 2, planned_amount: 500_000, account_id: 2 }),
    item(4, 'Asuransi', { category_id: 2, planned_amount: 1_000_000, status: 'skipped', skipped_at: '2026-11-01T00:00:00Z' }),
    item(5, 'Makan', { mode: 'envelope', category_id: 1, planned_amount: 2_000_000, actual: 1_350_000, percent: 67, account_id: null }),
    item(6, 'Transport', { mode: 'envelope', category_id: 3, planned_amount: 600_000, actual: 510_000, percent: 85, account_id: null }),
    item(7, 'Hiburan', { mode: 'envelope', category_id: 4, planned_amount: 300_000, actual: 320_000, percent: 106, account_id: null }),
  ],
};

const accounts: Account[] = [
  { id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 5_000_000, sort_order: 0, archived_at: null, is_savings: false },
  { id: 2, name: 'Jago', type: 'bank', opening_balance: 0, balance: 1_000_000, sort_order: 1, archived_at: null, is_savings: false },
];
const categories: Category[] = [
  { id: 1, kind: 'expense', name: 'Makan', color: '#F97316', icon: 'utensils', archived_at: null },
  { id: 2, kind: 'expense', name: 'Tagihan', color: '#8B5CF6', icon: 'receipt', archived_at: null },
  { id: 3, kind: 'expense', name: 'Transport', color: '#3B82F6', icon: 'car', archived_at: null },
  { id: 4, kind: 'expense', name: 'Hiburan', color: '#EC4899', icon: 'popcorn', archived_at: null },
  { id: 9, kind: 'income', name: 'Gaji', color: '#16A34A', icon: 'wallet', archived_at: null },
];

const planApi = vi.hoisted(() => ({
  get: vi.fn(), createItem: vi.fn(), copyFromPrevious: vi.fn(), generate: vi.fn(), updateItem: vi.fn(), removeItem: vi.fn(),
  skip: vi.fn(), unskip: vi.fn(), check: vi.fn(), uncheck: vi.fn(),
}));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
const categoriesApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/plan', () => ({ planApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));
vi.mock('@/api/categories', () => ({ categoriesApi }));
const transactionsApi = vi.hoisted(() => ({ create: vi.fn(), list: vi.fn() }));
vi.mock('@/api/transactions', () => ({ transactionsApi, transfersApi: { get: vi.fn(), create: vi.fn() } }));

const { PlanPage } = await import('./PlanPage');

function renderPage(url = '/plan') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: TZ });
  render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[url]}><PlanPage /></MemoryRouter></QueryClientProvider>);
}

const row = (id: number) => screen.getByTestId(`plan-item-${id}`);

beforeEach(() => {
  vi.clearAllMocks();
  planApi.get.mockResolvedValue(plan);
  accountsApi.list.mockResolvedValue(accounts);
  categoriesApi.list.mockResolvedValue(categories);
});

describe('PlanPage — tampilan', () => {
  it('shows the three summaries exactly as the API computed them', async () => {
    renderPage();

    expect(await screen.findByTestId('summary-income')).toHaveTextContent('Rp8.000.000');
    expect(screen.getByTestId('summary-income')).toHaveTextContent('dari rencana Rp9.500.000');
    expect(screen.getByTestId('summary-expense')).toHaveTextContent('Rp4.075.000');
    expect(screen.getByTestId('summary-expense')).toHaveTextContent('belum dibayar Rp500.000');
    expect(screen.getByTestId('summary-remaining')).toHaveTextContent('Sisa rencanaRp1.025.000');
    expect(planApi.get).toHaveBeenCalledWith(MONTH);
  });

  it('shows free money for the current month: all balances minus what the plan still has to spend', async () => {
    renderPage();
    const free = await screen.findByTestId('summary-free');

    expect(free).toHaveTextContent('Uang bebasRp4.760.000'); // 6.000.000 − 1.240.000
    expect(free).toHaveTextContent('Saldo semua rekening (tidak termasuk tabungan) Rp6.000.000 − masih akan keluar Rp1.240.000');
  });

  it('hides free money for other months, because balances are today\'s', async () => {
    renderPage(`/plan?month=${shiftMonth(MONTH, -1)}`);
    await screen.findByTestId('summary-remaining');

    expect(screen.queryByTestId('summary-free')).not.toBeInTheDocument();
  });

  it('splits items into income, fixed expenses and envelopes, with the unplanned row', async () => {
    renderPage();
    await screen.findByText('Pulsa');

    expect(within(screen.getByRole('region', { name: 'Pemasukan' })).getByText('Gaji')).toBeInTheDocument();
    const fixed = screen.getByRole('region', { name: 'Pengeluaran tetap' });
    expect(within(fixed).getAllByRole('checkbox').map((c) => c.getAttribute('aria-label')))
      .toEqual(['Batal centang Pulsa', 'Centang Listrik', 'Centang Asuransi']);
    const envelopes = screen.getByRole('region', { name: 'Amplop' });
    expect(within(envelopes).getAllByRole('progressbar')).toHaveLength(3);
    expect(within(envelopes).getByTestId('plan-unplanned')).toHaveTextContent('Rp180.000');
  });

  it('strikes through a checked item and shows its difference in red when it cost more', async () => {
    renderPage();
    await screen.findByText('Pulsa');

    expect(within(row(2)).getByText('Pulsa')).toHaveClass('line-through');
    expect(within(row(2)).getByRole('checkbox')).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByTestId('plan-diff-2')).toHaveTextContent('+Rp15.000');
    expect(within(screen.getByTestId('plan-diff-2')).getByText(/\+/)).toHaveClass('text-destructive');
    expect(screen.getByTestId('plan-diff-3')).toHaveTextContent('—'); // belum dicentang
  });

  it('marks an item checked by auto-post', async () => {
    planApi.get.mockResolvedValue({ ...plan, items: plan.items.map((i) => (i.id === 1 ? { ...i, auto_posted_at: `${MONTH}-25T00:20:00+07:00` } : i)) });
    renderPage();
    await screen.findByText('Gaji');

    expect(row(1)).toHaveTextContent('dicatat otomatis 25');
    expect(row(2)).toHaveTextContent('dicatat 10');
    expect(row(2)).not.toHaveTextContent('otomatis');
  });

  it('greys out a skipped item and does not let it be checked', async () => {
    renderPage();
    await screen.findByText('Asuransi');

    expect(row(4)).toHaveAttribute('data-status', 'skipped');
    expect(within(row(4)).getByText('Dilewati')).toBeInTheDocument();
    expect(within(row(4)).getByRole('checkbox')).toBeDisabled();
  });

  it('colors envelopes blue below 80%, yellow from 80% and red above 100%', async () => {
    renderPage();
    await screen.findByText('Makan');

    expect(within(row(5)).getByRole('progressbar')).toHaveAttribute('data-tone', 'normal');
    expect(within(row(6)).getByRole('progressbar')).toHaveAttribute('data-tone', 'warning');
    expect(within(row(7)).getByRole('progressbar')).toHaveAttribute('data-tone', 'over');
    expect(within(row(7)).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    expect(row(7)).toHaveTextContent('106% · lebih Rp20.000');
    expect(row(5)).toHaveTextContent('67% · sisa Rp650.000');
  });
});

describe('PlanPage — centang', () => {
  it('opens a small form prefilled from the item and books the actual amount', async () => {
    planApi.check.mockResolvedValue({ item: { ...plan.items[2], status: 'done' }, transaction: { id: 60 } });
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: 'Centang Listrik' }));
    const amount = screen.getByLabelText('Nominal dibayar');
    expect(amount).toHaveValue('500.000');
    expect(screen.getByLabelText('Dari rekening')).toHaveValue('2'); // rekening bawaan item
    expect(screen.getByLabelText('Tanggal')).toHaveValue(todayIn(TZ));

    await userEvent.clear(amount);
    await userEvent.type(amount, '525000');
    expect(screen.getByText(/selisih/)).toHaveTextContent('selisih +Rp25.000');
    expect(within(screen.getByText(/selisih/)).getByText(/\+/)).toHaveClass('text-destructive'); // bayar lebih = merah
    await userEvent.click(screen.getByRole('button', { name: 'Centang & catat' }));

    expect(planApi.check).toHaveBeenCalledWith(3, {
      client_id: expect.stringMatching(/^[0-9a-f-]{36}$/), amount: 525_000, account_id: 2, occurred_on: todayIn(TZ), note: 'Listrik',
    });
  });

  it('reuses the same client_id when the user retries after a network error (no duplicate)', async () => {
    planApi.check
      .mockRejectedValueOnce(new ApiError(0, 'Tidak bisa terhubung ke server.'))
      .mockResolvedValueOnce({ item: { ...plan.items[2], status: 'done' }, transaction: { id: 60 } });
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: 'Centang Listrik' }));
    await userEvent.click(screen.getByRole('button', { name: 'Centang & catat' }));
    await waitFor(() => expect(planApi.check).toHaveBeenCalledTimes(1));
    await userEvent.click(screen.getByRole('button', { name: 'Centang & catat' }));

    await waitFor(() => expect(planApi.check).toHaveBeenCalledTimes(2));
    const [first, second] = planApi.check.mock.calls;
    expect(second![1].client_id).toBe(first![1].client_id);
  });

  it('refreshes plan, accounts (and the rest of the money data) after checking', async () => {
    planApi.check.mockResolvedValue({ item: { ...plan.items[2], status: 'done' }, transaction: { id: 60 } });
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: 'Centang Listrik' }));
    await userEvent.click(screen.getByRole('button', { name: 'Centang & catat' }));

    await waitFor(() => expect(planApi.get).toHaveBeenCalledTimes(2));
    expect(accountsApi.list).toHaveBeenCalledTimes(2);
  });

  it('unchecks after confirmation, explaining the transaction is removed', async () => {
    planApi.uncheck.mockResolvedValue({ ...plan.items[1], status: 'planned' });
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: 'Batal centang Pulsa' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('Transaksi Rp165.000 yang dicatat saat mencentang akan dihapus');
    await userEvent.click(screen.getByRole('button', { name: 'Ya, batal centang' }));

    expect(planApi.uncheck).toHaveBeenCalledWith(2);
    await waitFor(() => expect(accountsApi.list).toHaveBeenCalledTimes(2));
  });
});

describe('PlanPage — bulan & item', () => {
  it('moves between months and copies the previous month into the shown month', async () => {
    planApi.copyFromPrevious.mockResolvedValue({ created: 4 });
    renderPage();
    await screen.findByText('Pulsa');

    await userEvent.click(screen.getByRole('button', { name: 'Bulan sebelumnya' }));
    await waitFor(() => expect(planApi.get).toHaveBeenLastCalledWith(shiftMonth(MONTH, -1)));

    await userEvent.click(screen.getByRole('button', { name: /Salin dari bulan lalu/ }));
    expect(planApi.copyFromPrevious).toHaveBeenCalledWith(shiftMonth(MONTH, -1));
  });

  it('fills the shown month from templates and reloads the plan', async () => {
    planApi.generate.mockResolvedValue({ created: 5 });
    renderPage();
    await screen.findByText('Pulsa');

    await userEvent.click(screen.getByRole('button', { name: 'Buat dari template' }));

    expect(planApi.generate).toHaveBeenCalledWith(MONTH);
    await waitFor(() => expect(planApi.get).toHaveBeenCalledTimes(2));
  });

  it('offers "Buat dari template" in the empty state of a month without a plan', async () => {
    planApi.get.mockResolvedValue({ ...plan, items: [] });
    planApi.generate.mockResolvedValue({ created: 0 });
    renderPage();

    expect(await screen.findByText(/Belum ada rencana untuk/)).toBeInTheDocument();
    const buttons = screen.getAllByRole('button', { name: 'Buat dari template' });
    expect(buttons).toHaveLength(2); // header + empty state
    await userEvent.click(buttons[1]!);
    expect(planApi.generate).toHaveBeenCalledWith(MONTH);
  });

  it('adds an envelope from the Amplop section without due date or account', async () => {
    planApi.createItem.mockResolvedValue(item(8, 'Belanja', { mode: 'envelope' }));
    renderPage();
    await screen.findByText('Pulsa');

    await userEvent.click(screen.getByRole('button', { name: 'Tambah amplop' }));
    expect(screen.getByRole('radio', { name: 'Amplop' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByLabelText('Jatuh tempo')).not.toBeInTheDocument();
    await userEvent.type(screen.getByLabelText('Nama'), 'Jajan');
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'Hiburan');
    await userEvent.type(screen.getByLabelText('Rencana'), '250000');
    await userEvent.click(screen.getByRole('button', { name: 'Tambah item' }));

    expect(planApi.createItem).toHaveBeenCalledWith(MONTH, {
      type: 'expense', mode: 'envelope', name: 'Jajan', category_id: 4, planned_amount: 250_000, account_id: null, due_on: null,
    });
  });

  it('locks type, mode and category when editing a checked item', async () => {
    renderPage();
    await screen.findByText('Pulsa');

    await userEvent.click(within(row(2)).getByRole('button', { name: 'Aksi Pulsa' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Ubah' }));

    expect(screen.getByRole('radio', { name: 'Pemasukan' })).toBeDisabled();
    expect(screen.getByRole('radio', { name: 'Amplop' })).toBeDisabled();
    expect(screen.getByLabelText('Kategori')).toBeDisabled();
  });

  it('skips an item from its menu, and offers no skip/delete for a checked one', async () => {
    planApi.skip.mockResolvedValue({ ...plan.items[2], status: 'skipped' });
    renderPage();
    await screen.findByText('Listrik');

    await userEvent.click(within(row(2)).getByRole('button', { name: 'Aksi Pulsa' }));
    expect(await screen.findByRole('menuitem', { name: 'Batal centang' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Hapus' })).not.toBeInTheDocument();
    await userEvent.keyboard('{Escape}');

    await userEvent.click(within(row(3)).getByRole('button', { name: 'Aksi Listrik' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Lewati bulan ini' }));
    expect(planApi.skip).toHaveBeenCalledWith(3);
  });
});

describe('PlanPage — catat dari amplop', () => {
  it('opens the expense form with the envelope category and today, and books it as a normal transaction', async () => {
    transactionsApi.create.mockResolvedValue({ id: 70 });
    renderPage();
    await userEvent.click(await screen.findByRole('button', { name: 'Catat pengeluaran Transport' }));

    const dialog = await screen.findByRole('dialog', { name: 'Catat pengeluaran — Transport' });
    expect(within(dialog).getByLabelText('Kategori')).toHaveValue('3');
    expect(within(dialog).getByLabelText('Tanggal')).toHaveValue(todayIn(TZ));
    await userEvent.type(within(dialog).getByLabelText('Nominal'), '25000');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(transactionsApi.create).toHaveBeenCalledTimes(1));
    expect(transactionsApi.create.mock.calls[0]![0]).toMatchObject({ type: 'expense', category_id: 3, amount: 25_000, occurred_on: todayIn(TZ) });
    expect(transactionsApi.create.mock.calls[0]![0]).not.toHaveProperty('plan_item_id');
    await waitFor(() => expect(planApi.get).toHaveBeenCalledTimes(2)); // angka amplop dimuat ulang
  });

  it('dates a transaction recorded from a past month inside that month', async () => {
    const past = shiftMonth(MONTH, -1);
    planApi.get.mockResolvedValue({ ...plan, month: past, items: plan.items.map((i) => ({ ...i, month: past })) });
    renderPage(`/plan?month=${past}`);
    await userEvent.click(await screen.findByRole('button', { name: 'Catat pengeluaran Makan' }));

    expect(await screen.findByLabelText('Tanggal')).toHaveValue(defaultDateIn(past, todayIn(TZ)));
    expect((screen.getByLabelText('Tanggal') as HTMLInputElement).value.startsWith(past)).toBe(true);
  });

  it('has no record button on checklist items or skipped envelopes, and explains how envelopes fill', async () => {
    planApi.get.mockResolvedValue({ ...plan, items: plan.items.map((i) => (i.id === 7 ? { ...i, status: 'skipped' as const, skipped_at: '2026-11-02T00:00:00Z' } : i)) });
    renderPage();
    await screen.findByText('Makan');

    expect(within(row(3)).queryByRole('button', { name: /^Catat/ })).not.toBeInTheDocument();
    expect(within(row(7)).queryByRole('button', { name: /^Catat/ })).not.toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Amplop' })).getByText(/berkurang dari transaksi pengeluaran berkategori sama/)).toBeInTheDocument();
  });
});
