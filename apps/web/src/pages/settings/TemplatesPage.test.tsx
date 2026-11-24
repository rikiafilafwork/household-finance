import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';
import type { PlanTemplate } from '@/api/templates';
import type { Account, Category } from '@/api/types';

const tpl = (id: number, name: string, extra: Partial<PlanTemplate>): PlanTemplate => ({
  id, type: 'expense', mode: 'checklist', name, category_id: 2, planned_amount: 500_000, account_id: 1,
  frequency: 'monthly', day_of_month: 20, month_of_year: null, auto_post: false, is_active: true, ...extra,
});
const templates: PlanTemplate[] = [
  tpl(1, 'Gaji', { type: 'income', category_id: 9, planned_amount: 8_000_000, day_of_month: 25, auto_post: true }),
  tpl(2, 'Listrik', {}),
  tpl(3, 'Asuransi', { frequency: 'yearly', month_of_year: 3, day_of_month: 15, planned_amount: 1_000_000 }),
  tpl(4, 'Makan', { mode: 'envelope', category_id: 1, planned_amount: 2_000_000, day_of_month: null, account_id: null }),
  tpl(5, 'Netflix', { is_active: false, planned_amount: 186_000 }),
];
const accounts: Account[] = [{ id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 5_000_000, sort_order: 0, archived_at: null }];
const categories: Category[] = [
  { id: 1, kind: 'expense', name: 'Makan', color: '#F97316', icon: 'utensils', archived_at: null },
  { id: 2, kind: 'expense', name: 'Tagihan', color: '#8B5CF6', icon: 'receipt', archived_at: null },
  { id: 9, kind: 'income', name: 'Gaji', color: '#16A34A', icon: 'wallet', archived_at: null },
];

const templatesApi = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() }));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
const categoriesApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/templates', () => ({ templatesApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));
vi.mock('@/api/categories', () => ({ categoriesApi }));

const { TemplatesPage } = await import('./TemplatesPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><TemplatesPage /></MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  templatesApi.list.mockResolvedValue(templates);
  accountsApi.list.mockResolvedValue(accounts);
  categoriesApi.list.mockResolvedValue(categories);
});

const row = (id: number) => screen.getByTestId(`template-${id}`);

describe('TemplatesPage — daftar', () => {
  it('groups active templates and shows schedule, auto-post and yearly badges', async () => {
    renderPage();
    await screen.findByText('Gaji');

    expect(within(screen.getByRole('region', { name: 'Pemasukan' })).getByText('Gaji')).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Pengeluaran tetap' })).getAllByRole('listitem').map((li) => li.dataset.testid))
      .toEqual(['template-2', 'template-3']);
    expect(within(screen.getByRole('region', { name: 'Amplop' })).getByText('Makan')).toBeInTheDocument();

    expect(row(1)).toHaveTextContent('Auto-post');
    expect(row(1)).toHaveTextContent('Gaji · Tiap bulan, tgl 25 · BCA');
    expect(row(1)).toHaveTextContent('Rp8.000.000');
    expect(row(3)).toHaveTextContent('Tahunan');
    expect(row(3)).toHaveTextContent('Tiap tahun, 15 Maret');
    expect(row(4)).toHaveTextContent('Makan · Tiap bulan');
    expect(row(4)).not.toHaveTextContent('BCA');
  });

  it('keeps inactive templates folded away', async () => {
    renderPage();
    await screen.findByText('Gaji');

    expect(screen.queryByText('Netflix')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /Tampilkan template nonaktif \(1\)/ }));
    expect(row(5)).toHaveTextContent('Nonaktif');
  });
});

describe('TemplatesPage — tambah & ubah', () => {
  it('adds a monthly auto-post bill and sends integer rupiah', async () => {
    templatesApi.create.mockResolvedValue(tpl(6, 'Internet', {}));
    renderPage();
    await screen.findByText('Gaji');

    await userEvent.click(screen.getByRole('button', { name: 'Tambah tagihan / cicilan' }));
    const dialog = await screen.findByRole('dialog', { name: 'Template baru' });
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Internet');
    await userEvent.selectOptions(within(dialog).getByLabelText('Kategori'), 'Tagihan');
    await userEvent.type(within(dialog).getByLabelText('Rencana'), '350000');
    await userEvent.type(within(dialog).getByLabelText('Tanggal jatuh tempo'), '10');
    await userEvent.selectOptions(within(dialog).getByLabelText('Akun bawaan'), 'BCA');
    await userEvent.click(within(dialog).getByRole('checkbox', { name: /Centang otomatis/ }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah template' }));

    await waitFor(() => expect(templatesApi.create).toHaveBeenCalledWith({
      type: 'expense', mode: 'checklist', name: 'Internet', category_id: 2, planned_amount: 350_000, account_id: 1,
      frequency: 'monthly', day_of_month: 10, month_of_year: null, auto_post: true, is_active: true,
    }));
    await waitFor(() => expect(templatesApi.list).toHaveBeenCalledTimes(2));
  });

  it('adds an envelope without day, account or auto-post', async () => {
    templatesApi.create.mockResolvedValue(tpl(7, 'Transport', {}));
    renderPage();
    await screen.findByText('Gaji');

    await userEvent.click(screen.getByRole('button', { name: 'Tambah amplop' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByLabelText('Tanggal jatuh tempo')).not.toBeInTheDocument();
    expect(within(dialog).queryByLabelText('Akun bawaan')).not.toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Transport');
    await userEvent.selectOptions(within(dialog).getByLabelText('Kategori'), 'Makan');
    await userEvent.type(within(dialog).getByLabelText('Rencana'), '600000');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah template' }));

    await waitFor(() => expect(templatesApi.create).toHaveBeenCalledWith(expect.objectContaining({
      mode: 'envelope', day_of_month: null, account_id: null, auto_post: false, frequency: 'monthly',
    })));
  });

  it('asks for the month of a yearly template and for day + account when auto-posting', async () => {
    renderPage();
    await screen.findByText('Gaji');

    await userEvent.click(screen.getByRole('button', { name: 'Tambah template' }));
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText('Nama'), 'Pajak motor');
    await userEvent.selectOptions(within(dialog).getByLabelText('Kategori'), 'Tagihan');
    await userEvent.type(within(dialog).getByLabelText('Rencana'), '450000');
    await userEvent.click(within(dialog).getByRole('radio', { name: 'Tiap tahun' }));
    await userEvent.click(within(dialog).getByRole('checkbox', { name: /Centang otomatis/ }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah template' }));

    expect(await within(dialog).findByText('Pilih bulannya.')).toBeInTheDocument();
    expect(within(dialog).getByText('Auto-post butuh tanggal.')).toBeInTheDocument();
    expect(within(dialog).getByText('Auto-post butuh akun.')).toBeInTheDocument();
    expect(templatesApi.create).not.toHaveBeenCalled();

    await userEvent.selectOptions(within(dialog).getByLabelText('Bulan'), 'Juli');
    await userEvent.type(within(dialog).getByLabelText('Tanggal jatuh tempo'), '31');
    await userEvent.selectOptions(within(dialog).getByLabelText('Akun bawaan'), 'BCA');
    templatesApi.create.mockResolvedValue(tpl(8, 'Pajak motor', {}));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah template' }));
    await waitFor(() => expect(templatesApi.create).toHaveBeenCalledWith(expect.objectContaining({ frequency: 'yearly', month_of_year: 7, day_of_month: 31, auto_post: true, account_id: 1 })));
  });

  it('shows a server rejection next to the field (e.g. clashing envelope)', async () => {
    templatesApi.update.mockRejectedValue(new ApiError(422, 'Invalid', { category_id: ['Sudah ada template amplop aktif untuk kategori ini di bulan yang sama.'] }));
    renderPage();
    await screen.findByText('Makan');

    await userEvent.click(within(row(4)).getByRole('button', { name: /^Makan/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Ubah template' });
    expect(within(dialog).getByLabelText('Nama')).toHaveValue('Makan');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    expect(await within(dialog).findByText(/Sudah ada template amplop aktif/)).toBeInTheDocument();
  });
});

describe('TemplatesPage — nonaktif & hapus', () => {
  it('deactivates a template from its menu', async () => {
    templatesApi.update.mockResolvedValue({ ...templates[1], is_active: false });
    renderPage();
    await screen.findByText('Listrik');

    await userEvent.click(within(row(2)).getByRole('button', { name: 'Aksi Listrik' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Nonaktifkan' }));

    expect(templatesApi.update).toHaveBeenCalledWith(2, { is_active: false });
  });

  it('deletes after confirming, explaining that generated items stay', async () => {
    templatesApi.remove.mockResolvedValue({});
    renderPage();
    await screen.findByText('Listrik');

    await userEvent.click(within(row(2)).getByRole('button', { name: 'Aksi Listrik' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Hapus' }));
    const dialog = await screen.findByRole('dialog', { name: 'Hapus template Listrik?' });
    expect(dialog).toHaveTextContent('Rencana yang sudah dibuat dari template ini tetap ada.');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Ya, hapus' }));

    expect(templatesApi.remove).toHaveBeenCalledWith(2);
  });
});
