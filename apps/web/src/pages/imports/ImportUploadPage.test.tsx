import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import type { StatementImport } from '@/api/imports';
import { qk } from '@/api/keys';
import type { Account } from '@/api/types';

const bank = (id: number, name: string, extra: Partial<Account> = {}): Account => ({
  id, name, type: 'bank', opening_balance: 0, balance: 0, sort_order: 0, archived_at: null, is_savings: false, ...extra,
});
const accounts: Account[] = [
  bank(1, 'BCA'),
  bank(2, 'Muamalat'),
  bank(3, 'Bank lama', { archived_at: '2026-01-01T00:00:00Z' }),
  { ...bank(4, 'GoPay'), type: 'ewallet' },
];

const imported = (id: number, extra: Partial<StatementImport> = {}): StatementImport => ({
  id, account_id: 1, format: 'generic', column_mapping: null, original_filename: 'mutasi.csv', headers: null, status: 'ready',
  counts: { total: 0, new: 0, duplicate: 0, possible_duplicate: 0, invalid: 0, committed: 0, skipped: 0 },
  error: null, created_at: '2026-11-20T03:00:00Z', ...extra,
});

const importsApi = vi.hoisted(() => ({ list: vi.fn(), upload: vi.fn(), get: vi.fn(), rows: vi.fn(), commit: vi.fn() }));
const accountsApi = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock('@/api/imports', async (original) => ({ ...(await original<typeof import('@/api/imports')>()), importsApi }));
vi.mock('@/api/accounts', () => ({ accountsApi }));

const { ImportUploadPage } = await import('./ImportUploadPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  queryClient.setQueryData(qk.me, { id: 1, name: 'Riki', email: 'riki@example.com', timezone: 'Asia/Jakarta' });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/imports']}>
        <Routes>
          <Route path="/imports" element={<ImportUploadPage />} />
          <Route path="/imports/:id" element={<p>halaman preview</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const csv = (text: string, name = 'mutasi.csv') => new File([text], name, { type: 'text/csv' });
const INDONESIAN = 'Tanggal;Keterangan;Cabang;Debet;Kredit\n01/11/2026;GAJI;0000;;10.000.000,00\n03/11/2026;KOPI;0123;25.000,00;\n';

beforeEach(() => {
  vi.clearAllMocks();
  accountsApi.list.mockResolvedValue(accounts);
  importsApi.list.mockResolvedValue([]);
  importsApi.upload.mockResolvedValue(imported(7, { status: 'pending' }));
});

describe('ImportUploadPage', () => {
  it('offers only active bank accounts', async () => {
    renderPage();
    const select = await screen.findByLabelText('Rekening');

    expect([...(select as HTMLSelectElement).options].map((o) => o.text)).toEqual(['BCA', 'Muamalat']);
  });

  it('asks for a bank account first when there is none', async () => {
    accountsApi.list.mockResolvedValue([{ ...bank(4, 'GoPay'), type: 'ewallet' }]);
    renderPage();

    expect(await screen.findByText(/Belum ada rekening bank/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Tambahkan rekening bank' })).toHaveAttribute('href', '/accounts');
  });

  it('guesses the column mapping from the file header and uploads it', async () => {
    renderPage();
    await userEvent.upload(await screen.findByLabelText('File CSV'), csv(INDONESIAN));

    expect(await screen.findByRole('table', { name: 'Cuplikan file' })).toHaveTextContent('KOPI');
    expect(screen.getByLabelText('Kolom tanggal')).toHaveDisplayValue('Tanggal');
    expect(screen.getByLabelText('Format tanggal')).toHaveDisplayValue('31/12/2026');
    expect(screen.getByLabelText('Keterangan')).toBeChecked();
    expect(screen.getByLabelText('Cabang')).not.toBeChecked();
    expect(screen.getByLabelText('Kolom debet (keluar)')).toHaveDisplayValue('Debet');
    expect(screen.getByLabelText('Kolom kredit (masuk)')).toHaveDisplayValue('Kredit');
    expect(screen.getByLabelText('Pemisah desimal')).toHaveDisplayValue('Koma — 1.250.000,00');

    await userEvent.selectOptions(screen.getByLabelText('Rekening'), 'Muamalat');
    await userEvent.click(screen.getByRole('button', { name: /Unggah & tinjau/ }));

    expect(await screen.findByText('halaman preview')).toBeInTheDocument();
    expect(importsApi.upload).toHaveBeenCalledWith({
      account_id: 2,
      file: expect.any(File),
      column_mapping: {
        has_header: true, skip_rows: 0, date: 'Tanggal', date_format: 'd/m/Y', description: ['Keterangan'],
        debit: 'Debet', credit: 'Kredit', decimal_separator: ',',
      },
    });
  });

  it('skips a bank preamble and handles English amounts with DB/CR in one column', async () => {
    renderPage();
    await userEvent.upload(await screen.findByLabelText('File CSV'), csv(
      'Informasi Rekening\nNo. rekening : ,1234567890\nTanggal Transaksi,Keterangan,Cabang,Jumlah,Saldo\n'
      + '03/11,"TRSF E-BANKING DB",0000,"25,000.00 DB","975,000.00"\n05/11,BUNGA,0000,"1,234.00 CR","976,234.00"\n',
    ));

    const skip = await screen.findByLabelText('Baris dilewati di atas tabel');
    await userEvent.clear(skip);
    await userEvent.type(skip, '2');

    await waitFor(() => expect(screen.getByLabelText('Kolom tanggal')).toHaveDisplayValue('Tanggal Transaksi'));
    expect(screen.getByLabelText('Format tanggal')).toHaveDisplayValue('31/12 (tanpa tahun)');
    expect(screen.getByLabelText('Kolom nominal')).toHaveDisplayValue('Jumlah');
    expect(screen.getByLabelText('Pemisah desimal')).toHaveDisplayValue('Titik — 1,250,000.00');

    await userEvent.click(screen.getByRole('button', { name: /Unggah & tinjau/ }));
    await screen.findByText('halaman preview');
    expect(importsApi.upload.mock.calls[0]![0].column_mapping).toEqual({
      has_header: true, skip_rows: 2, date: 'Tanggal Transaksi', date_format: 'd/m', description: ['Keterangan'],
      amount: 'Jumlah', decimal_separator: '.',
    });
  });

  it('blocks the upload until the mapping is complete', async () => {
    renderPage();
    await userEvent.upload(await screen.findByLabelText('File CSV'), csv('a;b;c\n1;2;3\n'));

    await userEvent.click(await screen.findByRole('button', { name: /Unggah & tinjau/ }));

    expect(await screen.findByText('Pilih kolom tanggal.')).toBeInTheDocument();
    expect(importsApi.upload).not.toHaveBeenCalled();
  });

  it('reuses the last mapping of the account without showing the mapping form', async () => {
    importsApi.list.mockResolvedValue([
      imported(5, {
        column_mapping: { has_header: true, date: 'Tanggal', date_format: 'd/m/Y', description: ['Keterangan'], debit: 'Debet', credit: 'Kredit' },
      }),
    ]);
    renderPage();

    expect(await screen.findByLabelText(/Pakai pemetaan kolom terakhir/)).toBeChecked();
    expect(screen.getByText('Tanggal · Keterangan · Debet/Kredit')).toBeInTheDocument();

    await userEvent.upload(screen.getByLabelText('File CSV'), csv(INDONESIAN));
    expect(screen.queryByRole('table', { name: 'Cuplikan file' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Unggah & tinjau/ }));
    await screen.findByText('halaman preview');
    expect(importsApi.upload).toHaveBeenCalledWith({ account_id: 1, file: expect.any(File), column_mapping: null });
  });

  it('does not offer another account’s preset', async () => {
    importsApi.list.mockResolvedValue([imported(5, { account_id: 2, column_mapping: { date: 0, date_format: 'd/m/Y', description: [1], amount: 2 } })]);
    renderPage();
    await screen.findByLabelText('Rekening');

    expect(screen.queryByLabelText(/Pakai pemetaan kolom terakhir/)).not.toBeInTheDocument();
  });

  it('shows the server’s reason when the upload is rejected', async () => {
    importsApi.upload.mockRejectedValue(new ApiError(422, 'Periksa', { file: ['File harus berformat csv atau txt.'] }));
    renderPage();
    await userEvent.upload(await screen.findByLabelText('File CSV'), csv(INDONESIAN));
    await userEvent.click(screen.getByRole('button', { name: /Unggah & tinjau/ }));

    expect(await screen.findByText('File harus berformat csv atau txt.')).toBeInTheDocument();
    expect(screen.queryByText('halaman preview')).not.toBeInTheDocument();
  });

  it('lists previous imports with their outcome', async () => {
    importsApi.list.mockResolvedValue([
      imported(9, { original_filename: 'desember.csv', status: 'committed', counts: { ...imported(0).counts, committed: 42 } }),
      imported(8, { original_filename: 'november.csv', status: 'failed' }),
    ]);
    renderPage();

    const history = await screen.findByRole('list', { name: 'Riwayat impor' });
    expect(history).toHaveTextContent('desember.csv');
    expect(history).toHaveTextContent('42 transaksi diimpor');
    expect(history).toHaveTextContent('Gagal');
    expect(screen.getByRole('link', { name: /desember\.csv/ })).toHaveAttribute('href', '/imports/9');
  });
});
