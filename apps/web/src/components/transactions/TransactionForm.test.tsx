import { CLIENT_ID_PATTERN } from '@personal-finance/shared';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import type { Category, Transaction, Wallet } from '@/api/types';
import { TransactionForm } from './TransactionForm';

const wallet = (id: number, name: string, extra: Partial<Wallet> = {}): Wallet => ({
  id, name, type: 'bank', visibility: 'shared', is_mine: false, opening_balance: 0, balance: 0, archived_at: null, ...extra,
});
const category = (id: number, name: string, kind: 'income' | 'expense', archived = false): Category => ({
  id, name, kind, color: '#000000', icon: null, archived_at: archived ? '2026-11-01T00:00:00Z' : null,
});

const wallets = [wallet(1, 'BCA'), wallet(2, 'Dompet Lama', { archived_at: '2026-10-01T00:00:00Z' })];
const categories = [category(10, 'Makan', 'expense'), category(11, 'Rokok', 'expense', true), category(20, 'Gaji', 'income')];

describe('TransactionForm', () => {
  it('submits an integer amount with household-today and a v4 client_id, reused on retry', async () => {
    const onSubmit = vi.fn()
      .mockRejectedValueOnce(new ApiError(0, 'jaringan'))
      .mockResolvedValueOnce(undefined);
    render(<TransactionForm wallets={wallets} categories={categories} today="2026-11-21" onSubmit={onSubmit} />);

    await userEvent.type(screen.getByLabelText('Nominal'), '125000');
    await userEvent.selectOptions(screen.getByLabelText('Kategori'), 'Makan');
    await userEvent.click(screen.getByRole('button', { name: 'Simpan' }));
    await userEvent.click(screen.getByRole('button', { name: 'Simpan' })); // ulangi setelah gagal

    expect(onSubmit).toHaveBeenCalledTimes(2);
    const [payload, firstId] = onSubmit.mock.calls[0]!;
    expect(payload).toEqual({ type: 'expense', amount: 125_000, wallet_id: 1, category_id: 10, occurred_on: '2026-11-21', note: null });
    expect(Number.isSafeInteger(payload.amount)).toBe(true);
    expect(firstId).toMatch(CLIENT_ID_PATTERN);
    expect(onSubmit.mock.calls[1]![1]).toBe(firstId); // retry → client_id SAMA, server tidak membuat dobel
  });

  it('requires an amount', async () => {
    const onSubmit = vi.fn();
    render(<TransactionForm wallets={wallets} categories={categories} today="2026-11-21" onSubmit={onSubmit} />);

    await userEvent.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Nominal wajib diisi.')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('offers only active categories of the chosen type and only active wallets', async () => {
    render(<TransactionForm wallets={wallets} categories={categories} today="2026-11-21" onSubmit={vi.fn()} />);

    const options = () => Array.from((screen.getByLabelText('Kategori') as HTMLSelectElement).options).map((o) => o.text);
    expect(options()).toEqual(['Tanpa kategori', 'Makan']);
    expect(Array.from((screen.getByLabelText('Wallet') as HTMLSelectElement).options).map((o) => o.text)).toEqual(['BCA']);

    await userEvent.click(screen.getByRole('radio', { name: 'Pemasukan' }));
    expect(options()).toEqual(['Tanpa kategori', 'Gaji']);
  });

  it('keeps an archived category (and archived wallet) when editing an old transaction', () => {
    const old: Transaction = {
      id: 5, wallet_id: 2, category_id: 11, type: 'expense', status: 'posted', amount: -25_000, occurred_on: '2026-10-01',
      note: 'lama', transfer_group_id: null, client_id: null, created_by: 1, created_at: null, updated_at: null,
    };
    render(<TransactionForm initial={old} wallets={wallets} categories={categories} today="2026-11-21" onSubmit={vi.fn()} />);

    expect(screen.getByLabelText('Kategori')).toHaveValue('11');
    expect(screen.getByRole('option', { name: 'Rokok (diarsip)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Wallet')).toHaveValue('2');
    expect(screen.getByLabelText('Nominal')).toHaveValue('25.000');
  });

  it('maps server 422 errors to fields', async () => {
    const onSubmit = vi.fn().mockRejectedValue(new ApiError(422, 'x', { category_id: ['Kategori ini sudah diarsip.'] }));
    render(<TransactionForm wallets={wallets} categories={categories} today="2026-11-21" onSubmit={onSubmit} />);

    await userEvent.type(screen.getByLabelText('Nominal'), '5000');
    await userEvent.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Kategori ini sudah diarsip.')).toBeInTheDocument();
  });

  it('asks for confirmation inside the form before deleting', async () => {
    const onDelete = vi.fn().mockResolvedValue(undefined);
    const old: Transaction = {
      id: 5, wallet_id: 1, category_id: 10, type: 'expense', status: 'posted', amount: -1000, occurred_on: '2026-11-01',
      note: null, transfer_group_id: null, client_id: null, created_by: 1, created_at: null, updated_at: null,
    };
    render(<TransactionForm initial={old} wallets={wallets} categories={categories} today="2026-11-21" onSubmit={vi.fn()} onDelete={onDelete} />);

    await userEvent.click(screen.getByRole('button', { name: 'Hapus' }));
    expect(onDelete).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Ya, hapus' }));
    expect(onDelete).toHaveBeenCalledOnce();
  });
});
