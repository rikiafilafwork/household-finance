import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, CopyPlus, Plus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { toast } from 'sonner';
import { ApiError } from '@/api/client';
import { qk } from '@/api/keys';
import { planApi, type PlanComparison, type PlanItem, type PlanItemType, type PlanMode } from '@/api/plan';
import type { Category } from '@/api/types';
import { useTimezone } from '@/auth/useAuth';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { CheckForm } from '@/components/plan/CheckForm';
import { PlanItemForm } from '@/components/plan/PlanItemForm';
import { ChecklistRow, EnvelopeRow, UnplannedRow, type RowAction } from '@/components/plan/PlanRows';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useAccounts, useCategories } from '@/hooks/useMoneyData';
import { usePlanMutations } from '@/hooks/usePlanMutations';
import { formatMonth, monthIn, shiftMonth, todayIn } from '@/lib/dates';
import { cn } from '@/lib/utils';

type DialogState =
  | { mode: 'new'; preset: { type: PlanItemType; mode: PlanMode } }
  | { mode: 'edit' | 'check' | 'uncheck' | 'delete'; item: PlanItem }
  | null;

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

function SummaryCard({ label, children, footer, testId }: { label: string; children: ReactNode; footer?: ReactNode; testId: string }) {
  return (
    <Card data-testid={testId} className="gap-2">
      <CardHeader>
        <CardDescription>{label}</CardDescription>
        <CardTitle className="text-xl md:text-2xl">{children}</CardTitle>
      </CardHeader>
      {footer && <CardContent className="text-xs text-muted-foreground">{footer}</CardContent>}
    </Card>
  );
}

function Summary({ plan }: { plan: PlanComparison }) {
  const { income, expense, remaining_budget } = plan.totals;
  return (
    <div className="mb-6 grid gap-3 sm:grid-cols-3">
      <SummaryCard testId="summary-income" label="Pemasukan" footer={<>dari rencana <MoneyText amount={income.planned} /></>}>
        <MoneyText amount={income.actual} />
      </SummaryCard>
      <SummaryCard
        testId="summary-expense"
        label="Pengeluaran"
        footer={<>dari rencana <MoneyText amount={expense.planned} />{expense.unpaid > 0 && <> · belum dibayar <MoneyText amount={expense.unpaid} /></>}</>}
      >
        <MoneyText amount={expense.actual} />
      </SummaryCard>
      <SummaryCard testId="summary-remaining" label="Sisa anggaran" footer="Rencana pengeluaran − aktual pengeluaran">
        <MoneyText amount={remaining_budget} tone="balance" />
      </SummaryCard>
    </div>
  );
}

function Section({ title, total, children, onAdd, addLabel, columns }: {
  title: string;
  total?: ReactNode;
  children: ReactNode;
  onAdd: () => void;
  addLabel: string;
  columns?: boolean;
}) {
  return (
    <section aria-label={title} className="mb-6">
      <div className="mb-1 flex items-end justify-between gap-3 px-1">
        <h2 className="text-sm font-semibold">{title}</h2>
        {total && <span className="text-xs text-muted-foreground">{total}</span>}
      </div>
      <div className="rounded-lg border bg-background px-4">
        {columns && (
          <div aria-hidden className="hidden gap-3 border-b py-2 text-xs text-muted-foreground md:flex">
            <span className="flex-1 pl-9">Item</span>
            <span className="w-28 text-right">Rencana</span>
            <span className="w-28 text-right">Aktual</span>
            <span className="w-28 text-right">Selisih</span>
            <span className="w-8" />
          </div>
        )}
        <ul className="divide-y">{children}</ul>
        <div className="border-t py-1.5">
          <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={onAdd}><Plus /> {addLabel}</Button>
        </div>
      </div>
    </section>
  );
}

/**
 * Rencana bulanan (Keputusan #7–#8). Semua angka dari GET /api/plan/{month} (PlanComparisonQuery);
 * halaman ini tidak menghitung ulang total — hanya selisih per baris dan warna progress.
 */
export function PlanPage() {
  const timezone = useTimezone();
  const [params, setParams] = useSearchParams();
  const current = monthIn(timezone);
  const requested = params.get('month');
  const month = requested && MONTH.test(requested) ? requested : current;
  const setMonth = (m: string) => setParams(m === current ? {} : { month: m });

  const planQuery = useQuery({ queryKey: qk.plan(month), queryFn: () => planApi.get(month) });
  const { data: accounts = [] } = useAccounts();
  const { data: categories = [] } = useCategories();
  const m = usePlanMutations();
  const [dialog, setDialog] = useState<DialogState>(null);
  const close = () => setDialog(null);

  const plan = planQuery.data;
  const categoryMap = new Map<number, Category>(categories.map((c) => [c.id, c]));
  const items = plan?.items ?? [];
  const income = items.filter((i) => i.type === 'income');
  const fixed = items.filter((i) => i.type === 'expense' && i.mode === 'checklist');
  const envelopes = items.filter((i) => i.type === 'expense' && i.mode === 'envelope');
  const today = todayIn(timezone);

  const act = (item: PlanItem) => async (action: RowAction) => {
    if (action === 'skip' || action === 'unskip') {
      try {
        await m.skip.mutateAsync({ id: item.id, skipped: action === 'skip' });
        toast.success(action === 'skip' ? `${item.name} dilewati bulan ini.` : `${item.name} masuk rencana lagi.`);
      } catch {
        // Pesan galat sudah ditampilkan klien API (toast global).
      }
      return;
    }
    setDialog({ mode: action, item });
  };

  const copyFromPrevious = async () => {
    try {
      const { created } = await m.copyFromPrevious.mutateAsync(month);
      toast.success(created > 0 ? `${created} item disalin dari ${formatMonth(shiftMonth(month, -1))}.` : 'Tidak ada item baru untuk disalin.');
    } catch {
      // Pesan galat sudah ditampilkan klien API (toast global).
    }
  };

  const addFor = (type: PlanItemType, mode: PlanMode) => () => setDialog({ mode: 'new', preset: { type, mode } });

  let title = '';
  let body: ReactNode = null;
  if (dialog?.mode === 'new' || dialog?.mode === 'edit') {
    const editing = dialog.mode === 'edit' ? dialog.item : undefined;
    title = editing ? 'Ubah item' : 'Tambah item rencana';
    body = (
      <PlanItemForm
        month={month}
        initial={editing}
        preset={dialog.mode === 'new' ? dialog.preset : undefined}
        accounts={accounts}
        categories={categories}
        onSubmit={async (payload) => {
          if (editing) await m.update.mutateAsync({ id: editing.id, body: payload });
          else await m.create.mutateAsync({ month, body: payload });
          toast.success(editing ? 'Item diperbarui.' : 'Item ditambahkan.');
          close();
        }}
      />
    );
  } else if (dialog?.mode === 'check') {
    const { item } = dialog;
    title = `Centang ${item.name}`;
    body = (
      <CheckForm
        item={item}
        accounts={accounts}
        today={today}
        onSubmit={async (payload) => {
          await m.check.mutateAsync({ id: item.id, body: payload });
          toast.success(`${item.name} dicentang dan dicatat.`);
          close();
        }}
      />
    );
  } else if (dialog?.mode === 'uncheck' || dialog?.mode === 'delete') {
    const { item } = dialog;
    const unchecking = dialog.mode === 'uncheck';
    title = unchecking ? `Batal centang ${item.name}?` : `Hapus ${item.name}?`;
    body = (
      <Confirm
        message={unchecking
          ? <>Transaksi <MoneyText amount={item.actual} className="font-medium" /> yang dicatat saat mencentang akan dihapus dan saldo akunnya dikembalikan.</>
          : 'Item dihapus dari rencana bulan ini. Kalau hanya tidak terjadi bulan ini, pilih “Lewati” saja.'}
        confirmLabel={unchecking ? 'Ya, batal centang' : 'Ya, hapus'}
        onConfirm={async () => {
          if (unchecking) await m.uncheck.mutateAsync(item.id);
          else await m.remove.mutateAsync(item.id);
          toast.success(unchecking ? 'Centang dibatalkan.' : 'Item dihapus.');
          close();
        }}
      />
    );
  }

  return (
    <>
      <PageHeader
        title="Rencana"
        actions={(
          <>
            <Button variant="outline" onClick={copyFromPrevious} disabled={m.copyFromPrevious.isPending}><CopyPlus /> Salin dari bulan lalu</Button>
            <Button onClick={addFor('expense', 'checklist')}><Plus /> Tambah item</Button>
          </>
        )}
      />

      <nav aria-label="Pilih bulan" className="mb-4 flex items-center gap-1">
        <Button variant="ghost" size="icon" aria-label="Bulan sebelumnya" onClick={() => setMonth(shiftMonth(month, -1))}><ChevronLeft /></Button>
        <h2 className="min-w-40 text-center text-base font-semibold" aria-live="polite">{formatMonth(month)}</h2>
        <Button variant="ghost" size="icon" aria-label="Bulan berikutnya" onClick={() => setMonth(shiftMonth(month, 1))}><ChevronRight /></Button>
        {month !== current && <Button variant="link" size="sm" onClick={() => setMonth(current)}>Bulan ini</Button>}
      </nav>

      {planQuery.isPending && <Skeleton className="h-64" />}
      {planQuery.isError && <p className="rounded-lg border bg-background p-6 text-sm text-destructive">Rencana tidak bisa dimuat. Coba muat ulang halaman.</p>}

      {plan && (
        <>
          <Summary plan={plan} />

          {items.length === 0 && (
            <div className="mb-6 rounded-lg border border-dashed bg-background p-8 text-center">
              <p className="text-sm font-medium">Belum ada rencana untuk {formatMonth(month)}.</p>
              <p className="mt-1 text-sm text-muted-foreground">Salin dari bulan lalu, atau mulai dengan gaji, tagihan, dan amplop belanja harian.</p>
            </div>
          )}

          <Section title="Pemasukan" columns total={<>aktual <MoneyText amount={plan.totals.income.actual} /> / <MoneyText amount={plan.totals.income.planned} /></>} onAdd={addFor('income', 'checklist')} addLabel="Tambah pemasukan">
            {income.map((item) => item.mode === 'checklist'
              ? <ChecklistRow key={item.id} item={item} category={categoryMap.get(item.category_id)} onAction={act(item)} />
              : <EnvelopeRow key={item.id} item={item} category={categoryMap.get(item.category_id)} onAction={act(item)} />)}
            {plan.totals.income.unplanned > 0 && (
              <UnplannedRow label="Pemasukan lain" amount={plan.totals.income.unplanned} hint="Di luar rencana: pemasukan tanpa item di bulan ini" />
            )}
          </Section>

          <Section title="Pengeluaran tetap" columns total={plan.totals.expense.unpaid > 0 ? <>belum dibayar <MoneyText amount={plan.totals.expense.unpaid} /></> : undefined} onAdd={addFor('expense', 'checklist')} addLabel="Tambah tagihan / cicilan">
            {fixed.map((item) => <ChecklistRow key={item.id} item={item} category={categoryMap.get(item.category_id)} onAction={act(item)} />)}
          </Section>

          <Section title="Amplop" onAdd={addFor('expense', 'envelope')} addLabel="Tambah amplop">
            {envelopes.map((item) => <EnvelopeRow key={item.id} item={item} category={categoryMap.get(item.category_id)} onAction={act(item)} />)}
            {(plan.totals.expense.unplanned > 0 || envelopes.length > 0) && (
              <UnplannedRow label="Di luar rencana" amount={plan.totals.expense.unplanned} hint="Pengeluaran di kategori tanpa amplop dan tanpa item" />
            )}
          </Section>
        </>
      )}

      <Dialog open={dialog !== null} onOpenChange={(open) => !open && close()}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription className="sr-only">{title}</DialogDescription>
          </DialogHeader>
          {body}
        </DialogContent>
      </Dialog>
    </>
  );
}

function Confirm({ message, confirmLabel, onConfirm }: { message: ReactNode; confirmLabel: string; onConfirm: () => Promise<unknown> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted-foreground">{message}</p>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <div className="flex justify-end">
        <Button
          variant="destructive"
          disabled={busy}
          className={cn(busy && 'opacity-70')}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await onConfirm();
            } catch (e) {
              setError(e instanceof ApiError ? e.message : 'Terjadi kesalahan. Coba lagi.');
            } finally {
              setBusy(false);
            }
          }}
        >
          {confirmLabel}
        </Button>
      </div>
    </div>
  );
}
