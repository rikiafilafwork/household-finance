import { useInfiniteQuery } from '@tanstack/react-query';
import { ArrowLeftRight, Minus, Plus, Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { qk } from '@/api/keys';
import { cleanFilters, transactionsApi, type TransactionFilters } from '@/api/transactions';
import type { Transaction, TransactionType } from '@/api/types';
import { useMe } from '@/auth/useAuth';
import { NativeSelect } from '@/components/form/NativeSelect';
import { PageHeader } from '@/components/layout/PageHeader';
import { TransactionDialog, type DialogState } from '@/components/transactions/TransactionDialog';
import { TransactionList } from '@/components/transactions/TransactionList';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentHousehold, useIsOwner } from '@/hooks/useHouseholds';
import { useCategories, useWallets } from '@/hooks/useMoneyData';
import { todayIn } from '@/lib/dates';

const FILTER_KEYS = ['wallet_id', 'category_id', 'type', 'from', 'to', 'q'] as const;

function filtersFrom(params: URLSearchParams): TransactionFilters {
  const num = (k: string) => (params.get(k) ? Number(params.get(k)) : undefined);
  return cleanFilters({
    wallet_id: num('wallet_id'),
    category_id: num('category_id'),
    type: (params.get('type') as TransactionType | null) ?? undefined,
    from: params.get('from') ?? undefined,
    to: params.get('to') ?? undefined,
    q: params.get('q') ?? undefined,
  });
}

export function TransactionsPage() {
  const household = useCurrentHousehold();
  const isOwner = useIsOwner();
  const { data: me } = useMe();
  const [params, setParams] = useSearchParams();
  const filters = useMemo(() => filtersFrom(params), [params]);
  const { data: wallets = [] } = useWallets(household.id);
  const { data: categories = [] } = useCategories(household.id);
  const walletMap = useMemo(() => new Map(wallets.map((w) => [w.id, w])), [wallets]);
  const categoryMap = useMemo(() => new Map(categories.map((c) => [c.id, c])), [categories]);
  const today = todayIn(household.timezone);

  // `?new=expense|income|transfer` (dari tombol "Catat" di beranda) membuka dialog.
  const initialNew = params.get('new');
  const [dialog, setDialog] = useState<DialogState>(
    initialNew === 'expense' || initialNew === 'income' || initialNew === 'transfer' ? { mode: 'new', kind: initialNew } : null,
  );
  useEffect(() => {
    if (initialNew) {
      setParams((p) => { p.delete('new'); return p; }, { replace: true });
    }
  }, [initialNew, setParams]);

  // Pencarian ditunda 300 ms supaya tidak satu request per huruf.
  const [search, setSearch] = useState(filters.q ?? '');
  useEffect(() => {
    const handle = setTimeout(() => {
      if ((filters.q ?? '') !== search.trim()) setFilter('q', search.trim());
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  function setFilter(key: (typeof FILTER_KEYS)[number], value: string | undefined) {
    setParams((p) => {
      if (value) p.set(key, value);
      else p.delete(key);
      return p;
    }, { replace: true });
  }

  const query = useInfiniteQuery({
    queryKey: qk.transactions(household.id, filters),
    queryFn: ({ pageParam }) => transactionsApi.list(household.id, filters, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.next_cursor,
  });
  const transactions = query.data?.pages.flatMap((p) => p.data) ?? [];
  const hasFilters = FILTER_KEYS.some((k) => params.get(k));

  const canManage = (tx: Transaction) => isOwner || tx.created_by === me?.id;

  return (
    <>
      <PageHeader
        title="Transaksi"
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setDialog({ mode: 'new', kind: 'transfer' })}><ArrowLeftRight className="size-4" /> <span className="hidden sm:inline">Transfer</span></Button>
            <Button variant="outline" size="sm" onClick={() => setDialog({ mode: 'new', kind: 'income' })}><Plus className="size-4" /> <span className="hidden sm:inline">Pemasukan</span></Button>
            <Button size="sm" onClick={() => setDialog({ mode: 'new', kind: 'expense' })}><Minus className="size-4" /> Pengeluaran</Button>
          </>
        }
      />

      <div className="mb-4 grid gap-2 rounded-lg border bg-background p-3 md:grid-cols-[1fr_repeat(3,10rem)] md:items-center">
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input aria-label="Cari catatan" placeholder="Cari catatan…" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-8" />
        </div>
        <NativeSelect aria-label="Filter wallet" value={params.get('wallet_id') ?? ''} onChange={(e) => setFilter('wallet_id', e.target.value)}>
          <option value="">Semua wallet</option>
          {wallets.map((w) => <option key={w.id} value={w.id}>{w.name}{w.archived_at ? ' (arsip)' : ''}</option>)}
        </NativeSelect>
        <NativeSelect aria-label="Filter kategori" value={params.get('category_id') ?? ''} onChange={(e) => setFilter('category_id', e.target.value)}>
          <option value="">Semua kategori</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}{c.kind === 'income' ? ' (masuk)' : ''}{c.archived_at ? ' (arsip)' : ''}</option>)}
        </NativeSelect>
        <NativeSelect aria-label="Filter jenis" value={params.get('type') ?? ''} onChange={(e) => setFilter('type', e.target.value)}>
          <option value="">Semua jenis</option>
          <option value="expense">Pengeluaran</option>
          <option value="income">Pemasukan</option>
          <option value="transfer">Transfer</option>
          <option value="adjustment">Penyesuaian</option>
        </NativeSelect>
        <div className="flex flex-wrap items-center gap-2 md:col-span-4">
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            Dari <Input type="date" className="h-8 w-auto" value={params.get('from') ?? ''} onChange={(e) => setFilter('from', e.target.value)} />
          </label>
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            sampai <Input type="date" className="h-8 w-auto" value={params.get('to') ?? ''} min={params.get('from') ?? undefined} onChange={(e) => setFilter('to', e.target.value)} />
          </label>
          {hasFilters && (
            <Button variant="ghost" size="sm" onClick={() => { setSearch(''); setParams({}, { replace: true }); }}>
              <X className="size-4" /> Hapus filter
            </Button>
          )}
        </div>
      </div>

      {query.isPending && <div className="grid gap-3"><Skeleton className="h-20" /><Skeleton className="h-32" /></div>}
      {!query.isPending && transactions.length === 0 && (
        <div className="rounded-lg border border-dashed bg-background p-10 text-center text-sm text-muted-foreground">
          {hasFilters ? 'Tidak ada transaksi yang cocok dengan filter.' : 'Belum ada transaksi. Mulai dengan mencatat pengeluaran.'}
        </div>
      )}
      {transactions.length > 0 && (
        <TransactionList transactions={transactions} wallets={walletMap} categories={categoryMap} onSelect={(tx) => setDialog({ mode: 'edit', transaction: tx, canManage: canManage(tx) })} />
      )}
      {query.hasNextPage && (
        <div className="mt-4 flex justify-center">
          <Button variant="outline" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
            {query.isFetchingNextPage ? 'Memuat…' : 'Muat lagi'}
          </Button>
        </div>
      )}

      <TransactionDialog
        state={dialog}
        onClose={() => setDialog(null)}
        householdId={household.id}
        wallets={wallets.filter((w) => w.visibility === 'shared' || w.is_mine)}
        categories={categories}
        today={today}
      />
    </>
  );
}
