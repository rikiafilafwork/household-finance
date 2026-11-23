import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import type { Category } from '@/api/types';

const category = (id: number, name: string, kind: Category['kind'], archived = false): Category => ({
  id, name, kind, color: '#F97316', icon: 'utensils', archived_at: archived ? '2026-11-01T00:00:00Z' : null,
});

const api = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(), unarchive: vi.fn(), remove: vi.fn() }));
vi.mock('@/api/categories', () => ({ categoriesApi: api }));

const { CategoriesPage } = await import('./CategoriesPage');

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><CategoriesPage /></MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  api.list.mockResolvedValue([
    category(1, 'Makan', 'expense'),
    category(2, 'Transport', 'expense'),
    category(3, 'Rokok', 'expense', true),
    category(4, 'Gaji', 'income'),
  ]);
});

describe('CategoriesPage', () => {
  it('lists active categories per kind and keeps archived ones behind a toggle', async () => {
    renderPage();

    expect(await screen.findByText('Makan')).toBeInTheDocument();
    expect(screen.queryByText('Gaji')).not.toBeInTheDocument();
    expect(screen.queryByText('Rokok')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Tampilkan kategori diarsip \(1\)/ }));
    expect(screen.getByText('Rokok')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('tab', { name: 'Pemasukan' }));
    expect(screen.getByText('Gaji')).toBeInTheDocument();
    expect(screen.queryByText('Makan')).not.toBeInTheDocument();
  });

  it('creates a category of the selected kind with the chosen color and icon', async () => {
    api.create.mockResolvedValue(category(5, 'Bonus', 'income'));
    renderPage();
    await screen.findByText('Makan');

    await userEvent.click(screen.getByRole('tab', { name: 'Pemasukan' }));
    await userEvent.click(screen.getByRole('button', { name: /Tambah kategori/ }));
    await userEvent.type(screen.getByLabelText('Nama'), 'Bonus');
    await userEvent.click(screen.getByRole('button', { name: 'Warna #16A34A' }));
    await userEvent.click(screen.getByRole('button', { name: 'Ikon gift' }));
    await userEvent.click(screen.getAllByRole('button', { name: 'Tambah kategori' }).at(-1)!);

    expect(api.create).toHaveBeenCalledWith({ kind: 'income', name: 'Bonus', color: '#16A34A', icon: 'gift' });
  });

  it('archives a category from its edit dialog', async () => {
    api.archive.mockResolvedValue(category(2, 'Transport', 'expense', true));
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Transport' }));
    await userEvent.click(screen.getByRole('button', { name: 'Arsipkan' }));

    expect(api.archive).toHaveBeenCalledWith(2);
  });

  it('explains that a used category must be archived instead of deleted', async () => {
    api.remove.mockRejectedValue(new ApiError(422, 'Data tidak valid.', { category: ['Kategori sudah dipakai transaksi. Arsipkan saja.'] }));
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: 'Makan' }));
    await userEvent.click(screen.getByRole('button', { name: 'Hapus' }));
    await userEvent.click(screen.getByRole('button', { name: 'Ya, hapus' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Arsipkan');
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });
});
