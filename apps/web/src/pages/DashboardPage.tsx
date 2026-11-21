import { Lock, Plus } from 'lucide-react';
import { Link } from 'react-router';
import type { Category, Transaction, Wallet } from '@/api/types';
import { BudgetProgress, ProgressBar } from '@/components/budgets/BudgetProgress';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { walletTypeIcon, walletTypeLabel } from '@/components/wallets/walletMeta';
import { useCurrentHousehold, useIsOwner } from '@/hooks/useHouseholds';
import { useBudget, useCategories, useRecentTransactions, useWallets } from '@/hooks/useMoneyData';
import { formatDate, formatMonth, monthIn } from '@/lib/dates';

function WalletsCard({ wallets }: { wallets: Wallet[] }) {
  const shared = wallets.filter((w) => w.visibility === 'shared');
  const mine = wallets.filter((w) => w.visibility === 'private');
  const sum = (list: Wallet[]) => list.reduce((total, w) => total + w.balance, 0);

  return (
    <Card>
      <CardHeader>
        <CardDescription>Saldo bersama</CardDescription>
        <CardTitle className="text-2xl md:text-3xl"><MoneyText amount={sum(shared)} tone="balance" /></CardTitle>
        {mine.length > 0 && (
          <p className="text-sm text-muted-foreground">
            + wallet pribadi Anda <MoneyText amount={sum(mine)} tone="balance" className="text-foreground" />
          </p>
        )}
      </CardHeader>
      <CardContent className="grid gap-1">
        {wallets.length === 0 && <p className="text-sm text-muted-foreground">Belum ada wallet.</p>}
        {wallets.map((w) => {
          const Icon = walletTypeIcon[w.type];
          return (
            <div key={w.id} className="flex items-center justify-between gap-3 rounded-md py-1.5">
              <span className="flex min-w-0 items-center gap-2.5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"><Icon className="size-4 text-muted-foreground" /></span>
                <span className="grid min-w-0">
                  <span className="flex items-center gap-1 truncate text-sm font-medium">
                    {w.name} {w.visibility === 'private' && <Lock className="size-3 text-muted-foreground" aria-label="pribadi" />}
                  </span>
                  <span className="text-xs text-muted-foreground">{walletTypeLabel[w.type]}{w.visibility === 'private' ? ' · pribadi' : ''}</span>
                </span>
              </span>
              <MoneyText amount={w.balance} tone="balance" className="text-sm font-medium" />
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}

function BudgetCard({ householdId, month, canManage }: { householdId: number; month: string; canManage: boolean }) {
  const { data, isPending } = useBudget(householdId, month);
  const base = `/h/${householdId}/budgets`;

  return (
    <Card>
      <CardHeader>
        <CardDescription>Budget {formatMonth(month)}</CardDescription>
        {data && data.items.length > 0 && (
          <CardTitle className="text-lg">
            <MoneyText amount={data.totals.spent} /> <span className="text-sm font-normal text-muted-foreground">dari <MoneyText amount={data.totals.limit_amount} /></span>
          </CardTitle>
        )}
        <CardAction><Button asChild variant="ghost" size="sm"><Link to={base}>Lihat</Link></Button></CardAction>
      </CardHeader>
      <CardContent className="grid gap-4">
        {isPending && <Skeleton className="h-24" />}
        {data && data.items.length === 0 && (
          <div className="text-sm text-muted-foreground">
            Belum ada budget bulan ini.{' '}
            {canManage && <Link to={base} className="font-medium text-primary underline-offset-4 hover:underline">Atur budget</Link>}
          </div>
        )}
        {data && data.items.length > 0 && (
          <>
            <ProgressBar percent={data.totals.percent} className="h-2.5" />
            {[...data.items].sort((a, b) => (b.percent ?? 0) - (a.percent ?? 0)).slice(0, 5).map((item) => <BudgetProgress key={item.id} item={item} />)}
            {data.totals.unbudgeted_spent > 0 && (
              <p className="text-xs text-muted-foreground">Pengeluaran di luar budget: <MoneyText amount={data.totals.unbudgeted_spent} /></p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

function describe(tx: Transaction, categories: Map<number, Category>) {
  if (tx.type === 'transfer') return tx.amount < 0 ? 'Transfer keluar' : 'Transfer masuk';
  if (tx.type === 'adjustment') return 'Penyesuaian saldo';
  return (tx.category ?? (tx.category_id ? categories.get(tx.category_id) : undefined))?.name ?? (tx.type === 'income' ? 'Pemasukan' : 'Pengeluaran');
}

function RecentCard({ householdId }: { householdId: number }) {
  const { data: transactions, isPending } = useRecentTransactions(householdId);
  const { data: wallets = [] } = useWallets(householdId);
  const { data: categories = [] } = useCategories(householdId);
  const walletMap = new Map(wallets.map((w) => [w.id, w]));
  const categoryMap = new Map(categories.map((c) => [c.id, c]));

  return (
    <Card className="md:col-span-2">
      <CardHeader>
        <CardDescription>Transaksi terakhir</CardDescription>
        <CardAction><Button asChild variant="ghost" size="sm"><Link to={`/h/${householdId}/transactions`}>Semua</Link></Button></CardAction>
      </CardHeader>
      <CardContent className="grid divide-y">
        {isPending && <Skeleton className="h-32" />}
        {transactions?.length === 0 && <p className="py-2 text-sm text-muted-foreground">Belum ada transaksi. Catat pengeluaran pertama Anda.</p>}
        {transactions?.map((tx) => (
          <div key={tx.id} className="flex items-center justify-between gap-3 py-2.5">
            <div className="grid min-w-0">
              <span className="truncate text-sm font-medium">{describe(tx, categoryMap)}</span>
              <span className="truncate text-xs text-muted-foreground">
                {formatDate(tx.occurred_on, { day: 'numeric', month: 'short' })} · {walletMap.get(tx.wallet_id)?.name ?? 'Wallet'}{tx.note ? ` · ${tx.note}` : ''}
                {tx.status === 'pending' ? ' · menunggu' : ''}
              </span>
            </div>
            <MoneyText amount={tx.amount} tone="signed" className="text-sm font-medium" />
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

export function DashboardPage() {
  const household = useCurrentHousehold();
  const canManage = useIsOwner();
  const { data: wallets, isPending } = useWallets(household.id);
  const month = monthIn(household.timezone);
  const active = (wallets ?? []).filter((w) => !w.archived_at);

  return (
    <>
      <PageHeader
        title="Beranda"
        description={household.name}
        actions={
          <Button asChild>
            <Link to={`/h/${household.id}/transactions?new=expense`}><Plus className="size-4" /> Catat</Link>
          </Button>
        }
      />
      <div className="grid gap-4 md:grid-cols-2">
        {isPending ? <Skeleton className="h-64" /> : <WalletsCard wallets={active} />}
        <BudgetCard householdId={household.id} month={month} canManage={canManage} />
        <RecentCard householdId={household.id} />
      </div>
    </>
  );
}
