import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CalendarCheck, Circle, Plus } from 'lucide-react';
import { Link } from 'react-router';
import { qk } from '@/api/keys';
import { planApi } from '@/api/plan';
import type { Account, AccountType, Category, Transaction } from '@/api/types';
import { accountTypeIcon, accountTypeLabel } from '@/components/accounts/accountMeta';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { envelopeTone } from '@/components/plan/planMeta';
import { transactionLabel } from '@/components/transactions/TransactionList';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useMe, useTimezone } from '@/auth/useAuth';
import { useAccounts, useCategories, useRecentTransactions } from '@/hooks/useMoneyData';
import { formatDate, formatMonth, monthIn, todayIn } from '@/lib/dates';
import { cn } from '@/lib/utils';

const TYPE_ORDER: AccountType[] = ['bank', 'ewallet', 'cash'];

function BalancesCard({ accounts }: { accounts: Account[] }) {
  const total = accounts.reduce((sum, a) => sum + a.balance, 0);
  const byType = TYPE_ORDER.map((type) => ({ type, sum: accounts.filter((a) => a.type === type).reduce((s, a) => s + a.balance, 0) }))
    .filter((row) => accounts.some((a) => a.type === row.type));

  return (
    <Card>
      <CardHeader>
        <CardDescription>Total saldo</CardDescription>
        <CardTitle className="text-2xl md:text-3xl"><MoneyText amount={total} tone="balance" /></CardTitle>
        {byType.length > 1 && (
          <p className="text-sm text-muted-foreground">
            {byType.map((row, i) => (
              <span key={row.type}>{i > 0 && ' · '}{accountTypeLabel[row.type]} <MoneyText amount={row.sum} tone="balance" className="text-foreground" /></span>
            ))}
          </p>
        )}
      </CardHeader>
      <CardContent className="grid gap-1">
        {accounts.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Belum ada akun. <Link to="/accounts" className="font-medium text-primary underline-offset-4 hover:underline">Tambah rekening, e-wallet, atau dompet</Link>.
          </p>
        )}
        {accounts.map((a) => {
          const Icon = accountTypeIcon[a.type];
          return (
            <div key={a.id} className="flex items-center justify-between gap-3 rounded-md py-1.5">
              <span className="flex min-w-0 items-center gap-2.5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"><Icon className="size-4 text-muted-foreground" /></span>
                <span className="grid min-w-0">
                  <span className="truncate text-sm font-medium">{a.name}</span>
                  <span className="text-xs text-muted-foreground">{accountTypeLabel[a.type]}</span>
                </span>
              </span>
              <span className="flex items-center gap-1.5">
                {a.balance < 0 && <AlertTriangle className="size-3.5 text-amber-600" aria-label="Saldo negatif" />}
                <MoneyText amount={a.balance} tone="balance" className="text-sm font-medium" />
              </span>
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}

function PlanStat({ label, actual, planned, testId }: { label: string; actual: number; planned: number; testId: string }) {
  return (
    <div data-testid={testId} className="grid gap-0.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <MoneyText amount={actual} className="text-base font-semibold" />
      <span className="text-xs text-muted-foreground">dari <MoneyText amount={planned} /></span>
    </div>
  );
}

/**
 * Ringkasan rencana bulan ini. Angka total langsung dari GET /plan/{month} (PlanComparisonQuery) —
 * cache yang sama dengan halaman Rencana, jadi angkanya tidak mungkin berbeda.
 */
function PlanCard({ month, today }: { month: string; today: string }) {
  const { data: plan, isPending, isError } = useQuery({ queryKey: qk.plan(month), queryFn: () => planApi.get(month) });
  const pending = (plan?.items ?? [])
    .filter((i) => i.mode === 'checklist' && i.status === 'planned')
    .sort((a, b) => (a.due_on ?? '9999').localeCompare(b.due_on ?? '9999'));
  const hotEnvelopes = (plan?.items ?? [])
    .filter((i) => i.mode === 'envelope' && i.type === 'expense' && i.status !== 'skipped' && envelopeTone(i.percent) !== 'normal')
    .sort((a, b) => (b.percent ?? 0) - (a.percent ?? 0));

  return (
    <Card data-testid="dashboard-plan">
      <CardHeader>
        <CardDescription>Rencana {formatMonth(month)}</CardDescription>
        <CardAction><Button asChild variant="ghost" size="sm"><Link to="/plan">Buka</Link></Button></CardAction>
      </CardHeader>
      <CardContent className="grid gap-4">
        {isPending && <Skeleton className="h-32" />}
        {isError && <p className="text-sm text-muted-foreground">Rencana gagal dimuat.</p>}
        {plan && plan.items.length === 0 && (
          <div className="flex items-start gap-3 text-sm text-muted-foreground">
            <CalendarCheck className="mt-0.5 size-4 shrink-0" />
            <p>
              Belum ada rencana bulan ini.{' '}
              <Link to="/plan" className="font-medium text-primary underline-offset-4 hover:underline">Buat checklist tagihan dan amplop</Link>.
            </p>
          </div>
        )}
        {plan && plan.items.length > 0 && (
          <>
            <div className="grid grid-cols-3 gap-3">
              <PlanStat testId="plan-income" label="Pemasukan" actual={plan.totals.income.actual} planned={plan.totals.income.planned} />
              <PlanStat testId="plan-expense" label="Pengeluaran" actual={plan.totals.expense.actual} planned={plan.totals.expense.planned} />
              <div data-testid="plan-remaining" className="grid content-start gap-0.5">
                <span className="text-xs text-muted-foreground">Sisa anggaran</span>
                <MoneyText amount={plan.totals.remaining_budget} tone="balance" className="text-base font-semibold" />
              </div>
            </div>

            <div data-testid="plan-pending" className="grid gap-1">
              <p className="text-xs font-medium text-muted-foreground">
                {pending.length === 0 ? 'Semua checklist sudah dicentang atau dilewati.' : `Belum dicentang (${pending.length})`}
              </p>
              {pending.slice(0, 3).map((i) => {
                const late = i.due_on !== null && i.due_on < today;
                return (
                  <div key={i.id} className="flex items-center justify-between gap-3 text-sm">
                    <span className="flex min-w-0 items-center gap-2">
                      <Circle className="size-3.5 shrink-0 text-muted-foreground" />
                      <span className="truncate">{i.name}</span>
                      {i.due_on && (
                        <span className={cn('shrink-0 text-xs', late ? 'font-medium text-destructive' : 'text-muted-foreground')}>
                          {late ? 'lewat ' : ''}{formatDate(i.due_on, { day: 'numeric', month: 'short' })}
                        </span>
                      )}
                    </span>
                    <MoneyText amount={i.planned_amount} className="text-sm" />
                  </div>
                );
              })}
              {pending.length > 3 && <Link to="/plan" className="text-xs font-medium text-primary underline-offset-4 hover:underline">+{pending.length - 3} lainnya</Link>}
            </div>

            {hotEnvelopes.length > 0 && (
              <div data-testid="plan-envelopes" className="grid gap-1">
                <p className="text-xs font-medium text-muted-foreground">Amplop hampir habis</p>
                {hotEnvelopes.map((i) => {
                  const tone = envelopeTone(i.percent);
                  return (
                    <div key={i.id} className="flex items-center justify-between gap-3 text-sm">
                      <span className="flex min-w-0 items-center gap-2">
                        <AlertTriangle className={cn('size-3.5 shrink-0', tone === 'over' ? 'text-destructive' : 'text-amber-600')} />
                        <span className="truncate">{i.name}</span>
                      </span>
                      <span className={cn('text-xs font-medium tabular-nums', tone === 'over' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400')}>
                        {i.percent}%
                      </span>
                    </div>
                  );
                })}
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

function RecentCard({ accounts }: { accounts: Account[] }) {
  const { data: transactions, isPending } = useRecentTransactions();
  const { data: categories = [] } = useCategories();
  const accountMap = new Map(accounts.map((a) => [a.id, a]));
  const categoryMap = new Map<number, Category>(categories.map((c) => [c.id, c]));

  return (
    <Card className="md:col-span-2">
      <CardHeader>
        <CardDescription>Transaksi terakhir</CardDescription>
        <CardAction><Button asChild variant="ghost" size="sm"><Link to="/transactions">Semua</Link></Button></CardAction>
      </CardHeader>
      <CardContent className="grid divide-y">
        {isPending && <Skeleton className="h-32" />}
        {transactions?.length === 0 && <p className="py-2 text-sm text-muted-foreground">Belum ada transaksi. Catat pengeluaran pertama Anda.</p>}
        {transactions?.map((tx: Transaction) => (
          <div key={tx.id} className="flex items-center justify-between gap-3 py-2.5">
            <div className="grid min-w-0">
              <span className="truncate text-sm font-medium">{transactionLabel(tx, categoryMap)}</span>
              <span className="truncate text-xs text-muted-foreground">
                {formatDate(tx.occurred_on, { day: 'numeric', month: 'short' })} · {accountMap.get(tx.account_id)?.name ?? 'Akun'}{tx.note ? ` · ${tx.note}` : ''}
              </span>
            </div>
            <MoneyText amount={tx.amount} tone="signed" className="text-sm font-medium" />
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

/**
 * Beranda sederhana (#40): saldo per tipe akun, ringkasan rencana bulan ini, transaksi terbaru.
 * Dashboard penuh (KPI, chart) lewat GET /api/dashboard menyusul di Minggu 5 (#132).
 */
export function DashboardPage() {
  const { data: me } = useMe();
  const timezone = useTimezone();
  const { data: accounts, isPending } = useAccounts();
  const active = (accounts ?? []).filter((a) => !a.archived_at);

  return (
    <>
      <PageHeader
        title="Beranda"
        description={me ? `Halo, ${me.name}` : undefined}
        actions={
          <Button asChild>
            <Link to="/transactions?new=expense"><Plus className="size-4" /> Catat</Link>
          </Button>
        }
      />
      <div className="grid gap-4 md:grid-cols-2">
        {isPending ? <Skeleton className="h-64" /> : <BalancesCard accounts={active} />}
        <PlanCard month={monthIn(timezone)} today={todayIn(timezone)} />
        <RecentCard accounts={accounts ?? []} />
      </div>
    </>
  );
}
