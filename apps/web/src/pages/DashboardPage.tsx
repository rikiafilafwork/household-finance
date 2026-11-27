import { percentOf } from '@personal-finance/shared';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CalendarCheck, Check, CircleCheck, Clock, Plus, Repeat } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import { dashboardApi, type Dashboard } from '@/api/dashboard';
import { qk } from '@/api/keys';
import type { Account, Category } from '@/api/types';
import { useTimezone } from '@/auth/useAuth';
import { accountTypeGroupLabel, accountTypeIcon, accountTypeLabel, ACCOUNT_TYPES } from '@/components/accounts/accountMeta';
import { CategoryChart, TrendChart } from '@/components/dashboard/DashboardCharts';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { CheckForm } from '@/components/plan/CheckForm';
import { MonthPicker, useMonthParam } from '@/components/plan/MonthPicker';
import { envelopeTone } from '@/components/plan/planMeta';
import { transactionLabel } from '@/components/transactions/TransactionList';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useAccounts, useCategories } from '@/hooks/useMoneyData';
import { usePlanMutations } from '@/hooks/usePlanMutations';
import { formatDate, formatMonth, monthIn, todayIn } from '@/lib/dates';
import { cn } from '@/lib/utils';

type DueItem = Dashboard['due_items'][number];

const linkClass = 'font-medium text-primary underline-offset-4 hover:underline';

/** Progress aktual terhadap rencana; > 100% tetap penuh. */
function Progress({ actual, planned, label, className }: { actual: number; planned: number; label: string; className?: string }) {
  const percent = planned > 0 ? percentOf(actual, planned) : 0;
  return (
    <div
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.min(percent, 100)}
      className="h-1.5 overflow-hidden rounded-full bg-muted"
    >
      <div className={cn('h-full rounded-full', className)} style={{ width: `${Math.min(percent, 100)}%` }} />
    </div>
  );
}

function Kpi({ label, testId, value, children }: { label: string; testId: string; value: ReactNode; children?: ReactNode }) {
  return (
    <Card data-testid={testId} className="gap-2">
      <CardHeader>
        <CardDescription>{label}</CardDescription>
        <CardTitle className="text-xl md:text-2xl">{value}</CardTitle>
      </CardHeader>
      {children && <CardContent className="grid gap-1.5 text-xs text-muted-foreground">{children}</CardContent>}
    </Card>
  );
}

function KpiCards({ d }: { d: Dashboard }) {
  const { balances, income, expense } = d;
  return (
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <Kpi testId="kpi-balance" label="Total saldo" value={<MoneyText amount={balances.total} tone="balance" />}>
        <dl className="grid gap-0.5">
          {ACCOUNT_TYPES.map((type) => (
            <div key={type} className="flex justify-between gap-2">
              <dt>{accountTypeGroupLabel[type]}</dt>
              <dd><MoneyText amount={balances[type]} tone="balance" className="text-foreground" /></dd>
            </div>
          ))}
        </dl>
      </Kpi>
      <Kpi testId="kpi-income" label="Pemasukan" value={<MoneyText amount={income.actual} />}>
        <Progress actual={income.actual} planned={income.planned} label="Pemasukan diterima dari rencana" className="bg-success" />
        <p>dari rencana <MoneyText amount={income.planned} /></p>
      </Kpi>
      <Kpi testId="kpi-expense" label="Pengeluaran" value={<MoneyText amount={expense.actual} />}>
        <Progress
          actual={expense.actual}
          planned={expense.planned}
          label="Pengeluaran terhadap rencana"
          className={{ normal: 'bg-primary', warning: 'bg-amber-500', over: 'bg-destructive' }[expense.planned > 0 ? envelopeTone(percentOf(expense.actual, expense.planned)) : 'normal']}
        />
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span>dari rencana <MoneyText amount={expense.planned} /></span>
          {expense.unplanned > 0 && (
            <Badge data-testid="kpi-unplanned" variant="outline" className="border-amber-500/50 text-amber-700 dark:text-amber-400">
              di luar rencana <MoneyText amount={expense.unplanned} />
            </Badge>
          )}
        </p>
      </Kpi>
      <Kpi testId="kpi-remaining" label="Sisa anggaran" value={<MoneyText amount={d.remaining_budget} tone="balance" />}>
        <p>{expense.unpaid > 0 ? <><MoneyText amount={expense.unpaid} /> belum dibayar</> : 'Semua tagihan sudah dibayar'}</p>
        {d.free_money !== null && (
          <p data-testid="kpi-free">
            Uang bebas <MoneyText amount={d.free_money} tone="balance" className="font-medium text-foreground" />
            {d.free_money < 0 && <span className="text-destructive"> — saldo tidak cukup menutup rencana</span>}
          </p>
        )}
      </Kpi>
    </div>
  );
}

function IncomeSources({ sources }: { sources: Dashboard['income_sources'] }) {
  if (sources.length === 0) {
    return <p className="text-sm text-muted-foreground">Belum ada rencana pemasukan bulan ini.</p>;
  }
  return (
    <ul data-testid="income-sources" className="grid divide-y">
      {sources.map((s) => (
        <li key={s.id} data-testid={`income-${s.id}`} className={cn('flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2', s.skipped && 'opacity-60')}>
          <span className="flex min-w-0 items-center gap-2 text-sm">
            <span className="truncate font-medium">{s.name}</span>
            {s.skipped
              ? <Badge variant="secondary">Dilewati</Badge>
              : s.done
                ? <Badge variant="outline" className="border-success/40 text-success"><CircleCheck /> Diterima</Badge>
                : <Badge variant="outline" className="text-muted-foreground"><Clock /> Belum</Badge>}
          </span>
          <span className="ml-auto whitespace-nowrap text-sm">
            <MoneyText amount={s.actual} className="font-medium" /> <span className="text-muted-foreground">/ <MoneyText amount={s.planned} /></span>
          </span>
        </li>
      ))}
    </ul>
  );
}

function DueList({ items, today, onCheck }: { items: Dashboard['due_items']; today: string; onCheck: (item: DueItem) => void }) {
  if (items.length === 0) {
    return <p className="text-sm text-muted-foreground">Tidak ada tagihan jatuh tempo dalam 7 hari.</p>;
  }
  return (
    <ul data-testid="due-items" className="grid divide-y">
      {items.map((i) => (
        <li key={i.id} data-testid={`due-${i.id}`} className="flex items-center gap-3 py-2">
          <div className="grid min-w-0 flex-1">
            <span className="truncate text-sm font-medium">{i.name}</span>
            <span className={cn('text-xs', i.overdue ? 'font-medium text-destructive' : 'text-muted-foreground')}>
              {i.overdue ? 'Terlambat · ' : i.due_on === today ? 'Hari ini · ' : ''}{formatDate(i.due_on, { day: 'numeric', month: 'short' })}
            </span>
          </div>
          <MoneyText amount={i.planned} className={cn('text-sm', i.type === 'income' && 'text-success')} />
          <Button variant="outline" size="sm" className="h-8 shrink-0 px-2.5" onClick={() => onCheck(i)} aria-label={`Centang ${i.name}`}>
            <Check className="size-3.5" /> Centang
          </Button>
        </li>
      ))}
    </ul>
  );
}

function EnvelopeWarnings({ envelopes }: { envelopes: Dashboard['envelopes_warning'] }) {
  if (envelopes.length === 0) {
    return <p className="text-sm text-muted-foreground">Semua amplop masih di bawah 80%.</p>;
  }
  return (
    <ul data-testid="envelopes-warning" className="grid gap-3">
      {envelopes.map((e) => {
        const tone = envelopeTone(e.percent);
        return (
          <li key={e.id} data-tone={tone} className="grid gap-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="flex min-w-0 items-center gap-2">
                <AlertTriangle className={cn('size-3.5 shrink-0', tone === 'over' ? 'text-destructive' : 'text-amber-600')} />
                <span className="truncate">{e.name}</span>
              </span>
              <span className={cn('text-xs font-medium tabular-nums', tone === 'over' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400')}>{e.percent}%</span>
            </div>
            <Progress actual={e.actual} planned={e.planned} label={`Terpakai ${e.name}`} className={tone === 'over' ? 'bg-destructive' : 'bg-amber-500'} />
            <span className="text-xs text-muted-foreground">
              <MoneyText amount={e.actual} /> / <MoneyText amount={e.planned} />
              {e.actual > e.planned ? <> · lebih <MoneyText amount={e.actual - e.planned} /></> : <> · sisa <MoneyText amount={e.planned - e.actual} /></>}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

function AccountBalances({ accounts }: { accounts: Account[] }) {
  if (accounts.length === 0) {
    return <p className="text-sm text-muted-foreground">Belum ada akun. <Link to="/accounts" className={linkClass}>Tambah rekening, e-wallet, atau dompet</Link>.</p>;
  }
  return (
    <ul className="grid gap-1">
      {accounts.map((a) => {
        const Icon = accountTypeIcon[a.type];
        return (
          <li key={a.id} className="flex items-center justify-between gap-3 py-1.5">
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
          </li>
        );
      })}
    </ul>
  );
}

function RecentList({ recent, accounts, categories }: { recent: Dashboard['recent']; accounts: Map<number, Account>; categories: Map<number, Category> }) {
  if (recent.length === 0) {
    return <p className="text-sm text-muted-foreground">Belum ada transaksi. Catat pengeluaran pertama Anda.</p>;
  }
  return (
    <ul data-testid="recent" className="grid divide-y">
      {recent.map((tx) => (
        <li key={tx.id} className="flex items-center justify-between gap-3 py-2.5">
          <div className="grid min-w-0">
            <span className="truncate text-sm font-medium">{transactionLabel(tx, categories)}</span>
            <span className="truncate text-xs text-muted-foreground">
              {formatDate(tx.occurred_on, { day: 'numeric', month: 'short' })} · {accounts.get(tx.account_id)?.name ?? 'Rekening'}{tx.note ? ` · ${tx.note}` : ''}
            </span>
          </div>
          <MoneyText amount={tx.amount} tone="signed" className="text-sm font-medium" />
        </li>
      ))}
    </ul>
  );
}

function Panel({ title, action, className, testId, children }: { title: string; action?: ReactNode; className?: string; testId?: string; children: ReactNode }) {
  return (
    <Card data-testid={testId} className={className}>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
        {action && <CardAction>{action}</CardAction>}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function EmptyPlan({ month, onGenerate, busy }: { month: string; onGenerate: () => void; busy: boolean }) {
  return (
    <div data-testid="empty-plan" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed bg-background p-4">
      <div className="flex items-start gap-3 text-sm">
        <CalendarCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
        <p>
          <span className="font-medium">Belum ada rencana untuk {formatMonth(month)}.</span>{' '}
          <span className="text-muted-foreground">Buat dari template bulanan, atau <Link to={`/plan?month=${month}`} className={linkClass}>susun di halaman Rencana</Link>.</span>
        </p>
      </div>
      <Button onClick={onGenerate} disabled={busy}><Repeat /> Buat dari template</Button>
    </div>
  );
}

/**
 * Beranda (Architecture § Dashboard web, #132). Satu request: GET /api/dashboard?month= — angka
 * rencana vs aktual dari PlanComparisonQuery yang sama dengan halaman Rencana. Halaman ini hanya
 * menyusun; tidak menjumlah ulang uang. Daftar akun (saldo per akun) dari cache akun bersama.
 */
export function DashboardPage() {
  const timezone = useTimezone();
  const current = monthIn(timezone);
  const today = todayIn(timezone);
  const [month, setMonth] = useMonthParam(current);
  const query = useQuery({ queryKey: [...qk.dashboard, month], queryFn: () => dashboardApi.get(month) });
  const { data: accounts = [] } = useAccounts();
  const { data: categories = [] } = useCategories();
  const m = usePlanMutations();
  const [checking, setChecking] = useState<DueItem | null>(null);

  const d = query.data;
  const active = accounts.filter((a) => !a.archived_at);
  const accountMap = new Map(accounts.map((a) => [a.id, a]));
  const categoryMap = new Map<number, Category>(categories.map((c) => [c.id, c]));
  const planLink = month === current ? '/plan' : `/plan?month=${month}`;
  const noPlan = d !== undefined && d.income.planned === 0 && d.expense.planned === 0;

  const generate = async () => {
    try {
      const { created } = await m.generate.mutateAsync(month);
      toast.success(created > 0 ? `${created} item dibuat dari template.` : 'Belum ada template aktif. Atur di Pengaturan → Template bulanan.');
    } catch {
      // Pesan galat sudah ditampilkan klien API (toast global).
    }
  };

  return (
    <>
      <PageHeader
        title="Beranda"
        actions={<Button asChild><Link to="/transactions?new=expense"><Plus className="size-4" /> Catat</Link></Button>}
      />
      <MonthPicker month={month} current={current} onChange={setMonth} />

      {query.isPending && (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}</div>
      )}
      {query.isError && <p className="rounded-lg border bg-background p-6 text-sm text-destructive">Beranda tidak bisa dimuat. Coba muat ulang halaman.</p>}

      {d && (
        <div className="grid gap-4">
          {!d.reconcile_ok && (
            <Alert data-testid="reconcile-banner" variant="destructive">
              <AlertTriangle />
              <AlertTitle>Saldo akun tidak cocok dengan riwayat transaksi</AlertTitle>
              <AlertDescription>
                <p>Ada akun yang saldonya berbeda dari jumlah transaksinya. Jalankan <code>php artisan accounts:reconcile --fix</code> untuk membetulkan.</p>
              </AlertDescription>
            </Alert>
          )}

          {noPlan && <EmptyPlan month={month} onGenerate={generate} busy={m.generate.isPending} />}

          <KpiCards d={d} />

          <div className="grid gap-4 lg:grid-cols-3">
            <Panel testId="panel-categories" title="Rencana vs aktual per kategori" className="lg:col-span-2" action={<Button asChild variant="ghost" size="sm"><Link to={planLink}>Rencana</Link></Button>}>
              <CategoryChart rows={d.categories} />
            </Panel>
            <Panel testId="panel-due" title={`Perlu dicentang${d.due_items.length > 0 ? ` (${d.due_items.length})` : ''}`}>
              <DueList items={d.due_items} today={today} onCheck={setChecking} />
            </Panel>

            <Panel testId="panel-income" title="Pemasukan per sumber">
              <IncomeSources sources={d.income_sources} />
            </Panel>
            <Panel testId="panel-trend" title="Tren 6 bulan" className="lg:col-span-2">
              <TrendChart trend={d.trend} month={d.month} />
            </Panel>

            <Panel testId="panel-envelopes" title="Amplop hampir habis">
              <EnvelopeWarnings envelopes={d.envelopes_warning} />
            </Panel>
            <Panel testId="panel-accounts" title="Saldo per rekening" action={<Button asChild variant="ghost" size="sm"><Link to="/accounts">Rekening</Link></Button>}>
              <AccountBalances accounts={active} />
            </Panel>
            <Panel testId="panel-recent" title="Transaksi terbaru" action={<Button asChild variant="ghost" size="sm"><Link to="/transactions">Semua</Link></Button>}>
              <RecentList recent={d.recent} accounts={accountMap} categories={categoryMap} />
            </Panel>
          </div>
        </div>
      )}

      <Dialog open={checking !== null} onOpenChange={(open) => !open && setChecking(null)}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{checking ? `Centang ${checking.name}` : 'Centang'}</DialogTitle>
            <DialogDescription className="sr-only">Catat transaksi untuk item rencana ini.</DialogDescription>
          </DialogHeader>
          {checking && (
            <CheckForm
              item={{ type: checking.type, name: checking.name, planned_amount: checking.planned, account_id: checking.account_id }}
              accounts={accounts}
              today={today}
              onSubmit={async (body) => {
                await m.check.mutateAsync({ id: checking.id, body });
                toast.success(`${checking.name} dicentang dan dicatat.`);
                setChecking(null);
              }}
            />
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
