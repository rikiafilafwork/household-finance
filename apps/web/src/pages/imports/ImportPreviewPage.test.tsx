import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ImportRow, StatementImport } from '@/api/imports';
import { qk } from '@/api/keys';
import type { Account, Category } from '@/api/types';

const accounts: Account[] = [{ id: 1, name: 'BCA', type: 'bank', opening_balance: 0, balance: 0, sort_order: 0, archived_at: null }];
const categories: Category[] = [
  { id: 1, kind: 'expense', name: 'Makan', color: null, icon: null, archived_at: null },
  { id: 2, kind: 'expense', name: 'Lama', color: null, icon: null, archived_at: '2026-01-01T00:00:00Z' },
  { id: 9, kind: 'income', name: 'Gaji', color: null, icon: null, archived_at: null },
];

const counts = { total: 5, new: 3, duplicate: 1, possible_duplicate: 1, invalid: 1, committed: 0, skipped: 0 };
const imp = (extra: Partial<StatementImport> = {}): StatementImport => ({
  id: 7, account_id: 1, format: 'generic', column_mapping: null, original_filename: 'mutasi-november.csv',
  headers: ['Tanggal', 'Keterangan', 'Jumlah'], status: 'ready', counts, error: null, created_at: '2026-11-25T03:00:00Z', ...extra,
});
const row = (id: number, rowNumber: number, extra: Partial<ImportRow>): ImportRow => ({
  id, row_number: rowNumber, occurred_on: '2026-11-03', amount: -25_000, description: 'KOPI', status: 'new',
  category_id: null, transaction_id: null, error: null, raw: [], ...extra,
});
const rows: ImportRow[] = [
  row(11, 2, { occurred_on: '2026-11-01', amount: 10_000_000, description: 'GAJI NOVEMBER' }),
  row(12, 3, {}),
  row(13, 4, {}),
  row(14, 5, { description: 'LISTRIK', amount: -350_000, status: 'possible_duplicate' }),
  row(15, 6, { description: 'PULSA', amount: -50_000, status: 'duplicate' }),
  row(16, 7, { occurred_on: null, amount: null, description: null, status: 'invalid', error: 'Tanggal "PEND" tidak cocok dengan format d/m/Y.', raw: ['PEND', 'TARIK', '100.000'] }),
];

const importsApi = vi.hoisted(() => ({ list: vi.fn(), upload: vi.fn(), get: vi.fn(), rows: vi.fn(), commit: vi.fn() }));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
const categoriesApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/imports', async (original) => ({ ...(await original<typeof import('@/api/imports')>()), importsApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));
vi.mock('@/api/categories', () => ({ categoriesApi }));

const { ImportPreviewPage } = await import('./ImportPreviewPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/imports/7']}>
        <Routes><Route path="/imports/:id" element={<ImportPreviewPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return queryClient;
}

const tr = (rowNumber: number) => screen.getByTestId(`row-${rowNumber}`);

beforeEach(() => {
  vi.clearAllMocks();
  importsApi.get.mockResolvedValue(imp());
  importsApi.rows.mockResolvedValue(rows);
  accountsApi.list.mockResolvedValue(accounts);
  categoriesApi.list.mockResolvedValue(categories);
});

describe('ImportPreviewPage', () => {
  it('waits for the worker and then shows the rows', async () => {
    importsApi.get.mockResolvedValueOnce(imp({ status: 'pending', counts: { ...counts, total: 0 } })).mockResolvedValue(imp());
    renderPage();

    expect(await screen.findByRole('status')).toHaveTextContent('Menunggu diproses');
    expect(importsApi.rows).not.toHaveBeenCalled();

    await screen.findByRole('table', { name: 'Baris mutasi' }, { timeout: 3_000 });
    expect(importsApi.rows).toHaveBeenCalledWith(7);
  });

  it('preselects new rows, leaves possible duplicates to the user and locks the rest', async () => {
    renderPage();
    expect(screen.queryByText(/Tidak ada baris baru/)).not.toBeInTheDocument();
    await screen.findByRole('table', { name: 'Baris mutasi' });

    const summary = screen.getByLabelText('Ringkasan');
    expect(summary).toHaveTextContent('Baru3');
    expect(summary).toHaveTextContent('Mungkin dobel1');

    expect(within(tr(2)).getByRole('checkbox')).toBeChecked();
    expect(within(tr(3)).getByRole('checkbox')).toBeChecked();
    expect(within(tr(5)).getByRole('checkbox')).not.toBeChecked();
    expect(tr(5)).toHaveTextContent('Mungkin dobel');
    expect(within(tr(6)).queryByRole('checkbox')).not.toBeInTheDocument();
    expect(tr(6)).toHaveTextContent('Sudah ada');
    expect(within(tr(7)).queryByRole('checkbox')).not.toBeInTheDocument();
    expect(tr(7)).toHaveTextContent('Tanggal "PEND" tidak cocok');
    expect(tr(7)).toHaveTextContent('PEND · TARIK · 100.000');

    expect(screen.getByText(/3 dari 4 baris dipilih/)).toHaveTextContent('+Rp9.950.000');
    expect(screen.getByRole('button', { name: 'Impor 3 transaksi' })).toBeEnabled();
  });

  it('offers categories matching the direction of the money, without archived ones', async () => {
    renderPage();
    await screen.findByRole('table', { name: 'Baris mutasi' });

    const options = (n: number) => [...(screen.getByLabelText(`Kategori baris ${n}`) as HTMLSelectElement).options].map((o) => o.text);
    expect(options(2)).toEqual(['Tanpa kategori', 'Gaji']);
    expect(options(3)).toEqual(['Tanpa kategori', 'Makan']);
  });

  it('commits the chosen rows with their categories and shows the outcome', async () => {
    importsApi.commit.mockResolvedValue({
      import: imp({ status: 'committed', counts: { ...counts, committed: 3, skipped: 1 } }),
      meta: { committed: 3, duplicate: 0, skipped: 1 },
    });
    renderPage();
    await screen.findByRole('table', { name: 'Baris mutasi' });

    await userEvent.selectOptions(screen.getByLabelText('Kategori baris 2'), 'Gaji');
    await userEvent.selectOptions(screen.getByLabelText('Kategori baris 3'), 'Makan');
    await userEvent.click(within(tr(4)).getByRole('checkbox'));   // kopi kedua tidak jadi
    await userEvent.click(within(tr(5)).getByRole('checkbox'));   // listrik dikonfirmasi bukan dobel
    expect(screen.getByLabelText('Kategori baris 4')).toBeDisabled();

    importsApi.get.mockResolvedValue(imp({ status: 'committed', counts: { ...counts, committed: 3, skipped: 1 } }));
    importsApi.rows.mockResolvedValue(rows.map((r) => (r.id === 11 || r.id === 12 || r.id === 14
      ? { ...r, status: 'committed', transaction_id: r.id + 100, category_id: r.id === 11 ? 9 : r.id === 12 ? 1 : null }
      : r.status === 'new' ? { ...r, status: 'skipped' } : r)));
    await userEvent.click(screen.getByRole('button', { name: 'Impor 3 transaksi' }));

    expect(importsApi.commit).toHaveBeenCalledWith(7, [
      { id: 11, category_id: 9 },
      { id: 12, category_id: 1 },
      { id: 14, category_id: null },
    ]);
    expect(await screen.findByText(/3 transaksi diimpor, 1 dilewati\./)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Lihat transaksi' })).toHaveAttribute('href', '/transactions');
    await waitFor(() => expect(tr(2)).toHaveTextContent('Diimpor'));
    expect(tr(2)).toHaveTextContent('Gaji');
    expect(screen.queryByRole('button', { name: /Impor \d+ transaksi/ })).not.toBeInTheDocument();
  });

  it('can select and unselect every importable row at once', async () => {
    renderPage();
    await screen.findByRole('table', { name: 'Baris mutasi' });
    const all = screen.getByLabelText('Pilih semua baris yang bisa diimpor');

    await userEvent.click(all);
    expect(screen.getByRole('button', { name: 'Impor 4 transaksi' })).toBeEnabled();
    await userEvent.click(all);
    expect(screen.getByRole('button', { name: 'Impor 0 transaksi' })).toBeDisabled();
  });

  it('shows the server’s reason when the commit is rejected', async () => {
    const { ApiError } = await import('@/api/client');
    importsApi.commit.mockRejectedValue(new ApiError(422, 'x', { rows: ['Baris file 2: kategori sudah diarsip.'] }));
    renderPage();
    await screen.findByRole('table', { name: 'Baris mutasi' });

    await userEvent.click(screen.getByRole('button', { name: 'Impor 3 transaksi' }));

    expect(await screen.findByText('Baris file 2: kategori sudah diarsip.')).toBeInTheDocument();
  });

  it('explains a failed import and points back to the upload', async () => {
    importsApi.get.mockResolvedValue(imp({ status: 'failed', error: 'Kolom "Keterangan" tidak ada di header (Tgl, Ket, Jumlah).' }));
    renderPage();

    expect(await screen.findByRole('alert')).toHaveTextContent('Kolom "Keterangan" tidak ada di header');
    expect(screen.getByRole('link', { name: /Unggah ulang/ })).toHaveAttribute('href', '/imports');
    expect(importsApi.rows).not.toHaveBeenCalled();
  });

  it('shows a finished import read-only', async () => {
    importsApi.get.mockResolvedValue(imp({ status: 'committed', counts: { ...counts, committed: 3, skipped: 1 } }));
    importsApi.rows.mockResolvedValue(rows.map((r) => (r.status === 'new' ? { ...r, status: 'committed', category_id: 1 } : r)));
    renderPage();

    await screen.findByRole('table', { name: 'Baris mutasi' });
    expect(screen.getByLabelText('Ringkasan')).toHaveTextContent('Diimpor3');
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(tr(3)).toHaveTextContent('Makan');
  });
});

describe('ImportPreviewPage — tidak ada yang baru', () => {
  it('lets the user close a re-uploaded statement where every row already exists', async () => {
    const allKnown = rows.map((r) => (r.status === 'invalid' ? r : { ...r, status: 'duplicate' as const }));
    importsApi.get.mockResolvedValue(imp({ counts: { ...counts, new: 0, possible_duplicate: 0, duplicate: 5 } }));
    importsApi.rows.mockResolvedValue(allKnown);
    importsApi.commit.mockResolvedValue({
      import: imp({ status: 'committed', counts: { ...counts, new: 0, possible_duplicate: 0, duplicate: 5 } }),
      meta: { committed: 0, duplicate: 0, skipped: 0 },
    });
    renderPage();

    await screen.findByRole('table', { name: 'Baris mutasi' });
    expect(await screen.findByText(/Tidak ada baris baru/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Impor \d+ transaksi/ })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Pilih semua baris yang bisa diimpor')).toBeDisabled();

    importsApi.get.mockResolvedValue(imp({ status: 'committed', counts: { ...counts, new: 0, possible_duplicate: 0, duplicate: 5 } }));
    await userEvent.click(screen.getByRole('button', { name: 'Tandai selesai' }));

    expect(importsApi.commit).toHaveBeenCalledWith(7, []);
    expect(await screen.findByText(/0 transaksi diimpor\./)).toBeInTheDocument();
  });
});

describe('ImportPreviewPage — saran kategori (#57)', () => {
  it('preselects suggested categories, marks them, and lets the user override or clear them', async () => {
    importsApi.rows.mockResolvedValue(rows.map((r) => (r.id === 11 ? { ...r, category_id: 9 } : r.id === 12 || r.id === 13 ? { ...r, category_id: 1 } : r)));
    importsApi.commit.mockResolvedValue({ import: imp({ status: 'committed' }), meta: { committed: 3, duplicate: 0, skipped: 1 } });
    renderPage();
    await screen.findByRole('table', { name: 'Baris mutasi' });

    expect(screen.getByLabelText('Kategori baris 2')).toHaveDisplayValue('Gaji');
    expect(screen.getByLabelText('Kategori baris 3')).toHaveDisplayValue('Makan');
    expect(screen.getByLabelText('Kategori baris 3')).toHaveAccessibleDescription('saran dari impor sebelumnya');
    expect(screen.getByLabelText('Kategori baris 5')).not.toHaveAccessibleDescription();

    await userEvent.selectOptions(screen.getByLabelText('Kategori baris 3'), 'Tanpa kategori');   // tolak saran
    expect(screen.getByLabelText('Kategori baris 3')).not.toHaveAccessibleDescription();

    await userEvent.click(screen.getByRole('button', { name: 'Impor 3 transaksi' }));

    expect(importsApi.commit).toHaveBeenCalledWith(7, [
      { id: 11, category_id: 9 },
      { id: 12, category_id: null },
      { id: 13, category_id: 1 },
    ]);
  });
});
