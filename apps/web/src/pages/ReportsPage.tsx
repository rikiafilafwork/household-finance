import { percentOf } from '@personal-finance/shared';
import { useQuery } from '@tanstack/react-query';
import { ArrowDownRight, ArrowUpRight, Download } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { qk } from '@/api/keys';
import { reportsApi, type CashFlow, type CashVsNonCash, type ExportDelimiter, type MonthlySummary } from '@/api/reports';
import type { AccountType } from '@/api/types';
import { useTimezone } from '@/auth/useAuth';
import { accountTypeGroupLabel } from '@/components/accounts/accountMeta';
import { CategoryChart, TrendChart } from '@/components/dashboard/DashboardCharts';
import { NativeSelect } from '@/components/form/NativeSelect';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { MonthPicker, useMonthParam } from '@/components/plan/MonthPicker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useAccounts } from '@/hooks/useMoneyData';
import { formatDate, formatMonth, lastDayOf, monthIn, shiftMonth, todayIn } from '@/lib/dates';
import { cn } from '@/lib/utils';

const TABS = [
  ['summary', 'Ringkasan'],
  ['categories', 'Kategori'],
  ['trend', 'Tren'],
  ['cash-flow', 'Cash flow'],
  ['cash', 'Tunai vs non-tunai'],
] as const;
type Tab = (typeof TABS)[number][0];

/** Akhir rentang untuk bulan ini = hari ini (tidak ada transaksi masa depan); bulan lalu = hari terakhir. */
function monthEnd(month: string, today: string): string {
  const last = lastDayOf(month);
  return last > today ? today : last;
}

function Loading() {
  return <Skeleton className="h-48 w-full" />;
}

/** Selisih vs bulan lalu: naik/turun + nominal. Untuk pengeluaran, naik = buruk (merah). */
function Change({ value, higherIsBetter }: { value: number; higherIsBetter: boolean }) {
  if (value === 0) return <span className="text-xs text-muted-foreground">sama dengan bulan lalu</span>;
  const good = value > 0 === higherIsBetter;
  const Icon = value > 0 ? ArrowUpRight : ArrowDownRight;
  return (
    <span className={cn('flex items-center gap-1 text-xs', good ? 'text-success' : 'text-destructive')}>
      <Icon className="size-3.5" aria-hidden />
      {value > 0 ? 'naik' : 'turun'} <MoneyText amount={Math.abs(value)} /> dari bulan lalu
    </span>
  );
}

function Kpi({ label, children, footer }: { label: string; children: ReactNode; footer: ReactNode }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="grid gap-1 px-4">
        <p className="text-sm text-muted-foreground">{label}</p>
        <div className="text-2xl font-semibold tabular-nums">{children}</div>
        {footer}
      </CardContent>
    </Card>
  );
}

function PlanVsActual({ label, planned, actual, higherIsBetter }: { label: string; planned: number; actual: number; higherIsBetter: boolean }) {
  const pct = planned > 0 ? percentOf(actual, planned) : null;
  const bad = pct !== null && (higherIsBetter ? pct < 100 : pct > 100);
  return (
    <div className="grid gap-1.5">
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <span>{label}</span>
        <span><MoneyText amount={actual} className="font-medium" /> <span className="text-muted-foreground">/ <MoneyText amount={planned} /></span></span>
      </div>
      <div className="h-2 rounded-full bg-muted" aria-hidden>
        <div className={cn('h-full rounded-full', bad ? 'bg-destructive' : 'bg-primary')} style={{ width: `${Math.min(100, pct ?? (actual > 0 ? 100 : 0))}%` }} />
      </div>
      <p className="text-xs text-muted-foreground">{pct === null ? 'Tanpa rencana' : `${pct}% dari rencana`}</p>
    </div>
  );
}

function SummaryTab({ summary }: { summary: MonthlySummary }) {
  return (
    <div className="grid gap-4">
      <div className="grid gap-3 md:grid-cols-3" aria-label="Ringkasan angka">
        <Kpi label="Pemasukan" footer={<Change value={summary.previous.change.income} higherIsBetter />}>
          <MoneyText amount={summary.income.actual} />
        </Kpi>
        <Kpi label="Pengeluaran" footer={<Change value={summary.previous.change.expense} higherIsBetter={false} />}>
          <MoneyText amount={summary.expense.actual} />
        </Kpi>
        <Kpi label="Selisih (net)" footer={<Change value={summary.previous.change.net} higherIsBetter />}>
          <MoneyText amount={summary.net} tone="signed" />
        </Kpi>
      </div>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Rencana vs aktual</CardTitle>
          <CardDescription>
            Sisa anggaran: <MoneyText amount={summary.remaining_budget} tone="balance" /> · di luar rencana: <MoneyText amount={summary.expense.unplanned} />
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <PlanVsActual label="Pemasukan" planned={summary.income.planned} actual={summary.income.actual} higherIsBetter />
          <PlanVsActual label="Pengeluaran" planned={summary.expense.planned} actual={summary.expense.actual} higherIsBetter={false} />
        </CardContent>
      </Card>
      {summary.unrecorded !== 0 && (
        <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm" role="note">
          Penyesuaian saldo bulan ini: <MoneyText amount={summary.unrecorded} tone="signed" />.{' '}
          {summary.unrecorded < 0 ? 'Ada uang keluar yang tidak tercatat (biasanya dompet tunai).' : 'Ada uang masuk yang tidak tercatat.'}
        </p>
      )}
    </div>
  );
}

const RANGES = [1, 3, 6, 12] as const;

function CategoriesTab({ month }: { month: string }) {
  const [kind, setKind] = useState<'expense' | 'income'>('expense');
  const [range, setRange] = useState<(typeof RANGES)[number]>(1);
  const from = shiftMonth(month, -(range - 1));
  const { data, isPending } = useQuery({
    queryKey: qk.reports('by-category', from, month, kind),
    queryFn: () => reportsApi.byCategory({ from, to: month, kind }),
  });

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-1">
          <CardTitle className="text-base">{kind === 'expense' ? 'Pengeluaran' : 'Pemasukan'} per kategori</CardTitle>
          <CardDescription>{range === 1 ? formatMonth(month) : `${formatMonth(from)} – ${formatMonth(month)}`}</CardDescription>
        </div>
        <div className="flex gap-2">
          <NativeSelect aria-label="Jenis" className="h-9" value={kind} onChange={(e) => setKind(e.target.value as 'expense' | 'income')}>
            <option value="expense">Pengeluaran</option>
            <option value="income">Pemasukan</option>
          </NativeSelect>
          <NativeSelect aria-label="Rentang" className="h-9" value={range} onChange={(e) => setRange(Number(e.target.value) as (typeof RANGES)[number])}>
            {RANGES.map((r) => <option key={r} value={r}>{r === 1 ? '1 bulan' : `${r} bulan`}</option>)}
          </NativeSelect>
        </div>
      </CardHeader>
      <CardContent className="grid gap-6">
        {isPending || !data ? <Loading /> : (
          <>
            <CategoryChart rows={data.categories.map((c) => ({ id: c.category_id, name: c.name, color: c.color, planned: c.planned, actual: c.actual }))} />
            <Table aria-label="Rincian kategori">
              <TableHeader>
                <TableRow><TableHead>Kategori</TableHead><TableHead className="text-right">Rencana</TableHead><TableHead className="text-right">Aktual</TableHead><TableHead className="text-right">Porsi</TableHead></TableRow>
              </TableHeader>
              <TableBody>
                {data.categories.map((c) => (
                  <TableRow key={c.category_id ?? 'none'}>
                    <TableCell>{c.name}</TableCell>
                    <TableCell className="text-right"><MoneyText amount={c.planned} /></TableCell>
                    <TableCell className="text-right"><MoneyText amount={c.actual} /></TableCell>
                    <TableCell className="text-right tabular-nums">{c.percent}%</TableCell>
                  </TableRow>
                ))}
                <TableRow className="font-medium">
                  <TableCell>Total</TableCell>
                  <TableCell className="text-right"><MoneyText amount={data.total_planned} /></TableCell>
                  <TableCell className="text-right"><MoneyText amount={data.total_actual} /></TableCell>
                  <TableCell />
                </TableRow>
              </TableBody>
            </Table>
          </>
        )}
      </CardContent>
    </Card>
  );
}

function TrendTab({ month }: { month: string }) {
  const [months, setMonths] = useState(6);
  const { data, isPending } = useQuery({ queryKey: qk.reports('trend', month, months), queryFn: () => reportsApi.trend(month, months) });

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-end justify-between gap-3">
        <CardTitle className="text-base">Tren {months} bulan</CardTitle>
        <NativeSelect aria-label="Jumlah bulan" className="h-9 w-auto" value={months} onChange={(e) => setMonths(Number(e.target.value))}>
          {[6, 12, 24].map((m) => <option key={m} value={m}>{m} bulan</option>)}
        </NativeSelect>
      </CardHeader>
      <CardContent className="grid gap-6">
        {isPending || !data ? <Loading /> : (
          <>
            <TrendChart trend={data} month={month} title={`Tren ${months} bulan`} />
            <Table aria-label="Tabel tren">
              <TableHeader>
                <TableRow><TableHead>Bulan</TableHead><TableHead className="text-right">Pemasukan</TableHead><TableHead className="text-right">Pengeluaran</TableHead><TableHead className="text-right">Rencana keluar</TableHead><TableHead className="text-right">Net</TableHead></TableRow>
              </TableHeader>
              <TableBody>
                {[...data].reverse().map((m) => (
                  <TableRow key={m.month}>
                    <TableCell>{formatMonth(m.month)}</TableCell>
                    <TableCell className="text-right"><MoneyText amount={m.income_actual} /></TableCell>
                    <TableCell className="text-right"><MoneyText amount={m.expense_actual} /></TableCell>
                    <TableCell className="text-right text-muted-foreground"><MoneyText amount={m.expense_planned} /></TableCell>
                    <TableCell className="text-right"><MoneyText amount={m.net} tone="signed" /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** Garis saldo berjalan harian (SVG tanpa library) + batang net harian di bawahnya. */
function CashFlowChart({ flow }: { flow: CashFlow }) {
  const W = 600;
  const H = 180;
  const pad = 8;
  const balances = flow.days.map((d) => d.balance);
  const lo = Math.min(...balances, flow.opening_balance);
  const hi = Math.max(...balances, flow.opening_balance);
  const span = hi - lo || 1;
  const x = (i: number) => (flow.days.length <= 1 ? W / 2 : pad + (i * (W - 2 * pad)) / (flow.days.length - 1));
  // Geometri viewBox, bukan perhitungan uang.
  const y = (v: number) => pad + (H - 2 * pad) * (1 - (v - lo) / span);
  const points = flow.days.map((d, i) => `${x(i)},${y(d.balance)}`).join(' ');

  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Saldo berjalan harian">
      {lo < 0 && hi > 0 && <line x1={0} x2={W} y1={y(0)} y2={y(0)} strokeDasharray="3 3" className="stroke-destructive/60" />}
      <polyline points={points} fill="none" strokeWidth={2} className="stroke-primary" />
      {flow.days.map((d, i) => d.net !== 0 && <circle key={d.date} cx={x(i)} cy={y(d.balance)} r={2.5} className={d.net > 0 ? 'fill-success' : 'fill-sky-500'} />)}
    </svg>
  );
}

function CashFlowTab({ month, today }: { month: string; today: string }) {
  const { data: accounts = [] } = useAccounts();
  const [account, setAccount] = useState<number | null>(null);
  const from = `${month}-01`;
  const to = monthEnd(month, today);
  const { data, isPending } = useQuery({
    queryKey: qk.reports('cash-flow', from, to, account),
    queryFn: () => reportsApi.cashFlow({ from, to, ...(account === null ? {} : { account }) }),
  });
  const moving = (data?.days ?? []).filter((d) => d.net !== 0);

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-1">
          <CardTitle className="text-base">Cash flow</CardTitle>
          <CardDescription>Uang masuk & keluar per hari dan saldo berjalan ({formatDate(from)} – {formatDate(to)}).</CardDescription>
        </div>
        <NativeSelect aria-label="Akun" className="h-9 w-auto" value={account ?? ''} onChange={(e) => setAccount(e.target.value === '' ? null : Number(e.target.value))}>
          <option value="">Semua akun</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}{a.archived_at ? ' (arsip)' : ''}</option>)}
        </NativeSelect>
      </CardHeader>
      <CardContent className="grid gap-4">
        {isPending || !data ? <Loading /> : (
          <>
            <dl className="grid grid-cols-2 gap-3 text-sm md:grid-cols-3" aria-label="Saldo periode">
              <div><dt className="text-muted-foreground">Saldo awal</dt><dd className="font-medium"><MoneyText amount={data.opening_balance} tone="balance" /></dd></div>
              <div><dt className="text-muted-foreground">Saldo akhir</dt><dd className="font-medium"><MoneyText amount={data.closing_balance} tone="balance" /></dd></div>
              <div><dt className="text-muted-foreground">Perubahan</dt><dd className="font-medium"><MoneyText amount={data.closing_balance - data.opening_balance} tone="signed" /></dd></div>
            </dl>
            <CashFlowChart flow={data} />
            {account === null && <p className="text-xs text-muted-foreground">Transfer antar akun sendiri saling meniadakan di tampilan semua akun.</p>}
            {moving.length === 0 ? (
              <p className="text-sm text-muted-foreground">Tidak ada pergerakan uang di periode ini.</p>
            ) : (
              <Table aria-label="Pergerakan harian">
                <TableHeader>
                  <TableRow><TableHead>Tanggal</TableHead><TableHead className="text-right">Net</TableHead><TableHead className="text-right">Saldo</TableHead></TableRow>
                </TableHeader>
                <TableBody>
                  {moving.map((d) => (
                    <TableRow key={d.date}>
                      <TableCell>{formatDate(d.date)}</TableCell>
                      <TableCell className="text-right"><MoneyText amount={d.net} tone="signed" /></TableCell>
                      <TableCell className="text-right"><MoneyText amount={d.balance} tone="balance" /></TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

function CashTab({ report }: { report: CashVsNonCash }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Tunai vs non-tunai</CardTitle>
        <CardDescription>Pengeluaran {formatMonth(report.month)} menurut jenis akun pembayarnya. Total <MoneyText amount={report.total} />.</CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="grid gap-4" aria-label="Pengeluaran per jenis akun">
          {report.types.map((t) => (
            <li key={t.type} data-testid={`cash-${t.type}`} className="grid gap-1.5">
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span>{accountTypeGroupLabel[t.type as AccountType]}</span>
                <span><MoneyText amount={t.expense} className="font-medium" /> <span className="text-muted-foreground">· {t.percent}%</span></span>
              </div>
              <div className="h-2 rounded-full bg-muted" aria-hidden><div className="h-full rounded-full bg-primary" style={{ width: `${t.percent}%` }} /></div>
              {t.unrecorded !== 0 && (
                <p className="text-xs text-muted-foreground">Tak tercatat (sesuaikan saldo): <MoneyText amount={t.unrecorded} tone="signed" /></p>
              )}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function ExportMenu({ from, to }: { from: string; to: string }) {
  const href = (d: ExportDelimiter) => reportsApi.exportUrl(from, to, d);
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline"><Download className="size-4" /> Export</Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">Transaksi {formatDate(from)} – {formatDate(to)}</DropdownMenuLabel>
        <DropdownMenuItem asChild><a href={href('semicolon')} download>Excel (Indonesia) — pemisah ;</a></DropdownMenuItem>
        <DropdownMenuItem asChild><a href={href('comma')} download>CSV standar — pemisah ,</a></DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * Laporan keuangan pribadi (Minggu 7): ringkasan, kategori, tren, cash flow, tunai vs non-tunai,
 * export CSV. Sengaja hanya di web. Angka rencana vs aktual sama dengan Rencana & Beranda.
 */
export function ReportsPage() {
  const timezone = useTimezone();
  const current = monthIn(timezone);
  const today = todayIn(timezone);
  const [month, setMonth] = useMonthParam(current);
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS.find(([t]) => t === params.get('tab'))?.[0]) ?? 'summary';
  const setTab = (next: string) => {
    const p = new URLSearchParams(params);
    if (next === 'summary') p.delete('tab'); else p.set('tab', next);
    setParams(p);
  };

  const summary = useQuery({ queryKey: qk.reports('summary', month), queryFn: () => reportsApi.summary(month), enabled: tab === 'summary' });
  const cash = useQuery({ queryKey: qk.reports('cash', month), queryFn: () => reportsApi.cashVsNonCash(month), enabled: tab === 'cash' });

  return (
    <>
      <PageHeader title="Laporan" description="Ringkasan keuangan pribadi dari transaksi dan rencana Anda." actions={<ExportMenu from={`${month}-01`} to={monthEnd(month, today)} />} />
      <MonthPicker month={month} current={current} onChange={setMonth} />
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="mb-4 flex h-auto flex-wrap">
          {TABS.map(([value, label]) => <TabsTrigger key={value} value={value}>{label}</TabsTrigger>)}
        </TabsList>
        <TabsContent value="summary">{summary.data ? <SummaryTab summary={summary.data} /> : <Loading />}</TabsContent>
        <TabsContent value="categories"><CategoriesTab month={month} /></TabsContent>
        <TabsContent value="trend"><TrendTab month={month} /></TabsContent>
        <TabsContent value="cash-flow"><CashFlowTab month={month} today={today} /></TabsContent>
        <TabsContent value="cash">{cash.data ? <CashTab report={cash.data} /> : <Loading />}</TabsContent>
      </Tabs>
    </>
  );
}
