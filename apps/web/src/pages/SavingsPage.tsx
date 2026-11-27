import { AlertTriangle, Plus } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import type { SavingsAccountSummary, SavingsGoal } from '@/api/types';
import { useTimezone } from '@/auth/useAuth';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { AllocateForm } from '@/components/savings/AllocateForm';
import { GoalCard, type GoalAction } from '@/components/savings/GoalCard';
import { GoalForm } from '@/components/savings/GoalForm';
import { MoveForm } from '@/components/savings/MoveForm';
import { UseSavingsForm } from '@/components/savings/UseSavingsForm';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useCategories } from '@/hooks/useMoneyData';
import { useGoalAllocations, useSavingsMutations, useSavings } from '@/hooks/useSavings';
import { formatDate, todayIn } from '@/lib/dates';

type DialogState =
  | { mode: 'new' }
  | { mode: 'edit'; goal: SavingsGoal }
  | { mode: 'add' | 'reduce' | 'move' | 'use' | 'history'; goal: SavingsGoal }
  | { mode: 'reduce-pick'; goals: SavingsGoal[] }
  | null;

function Totals({ balance, allocated, unallocated }: { balance: number; allocated: number; unallocated: number }) {
  return (
    <span className="block text-sm text-muted-foreground">
      Saldo <MoneyText amount={balance} tone="balance" className="font-medium text-foreground" />
      {' · '}Dialokasikan <MoneyText amount={allocated} className="font-medium text-foreground" />
      {' · '}Belum dialokasikan <MoneyText amount={unallocated} tone="balance" className="font-medium text-foreground" />
    </span>
  );
}

function GoalHistory({ goal }: { goal: SavingsGoal }) {
  const { data, isPending } = useGoalAllocations(goal.id);
  if (isPending) return <Skeleton className="h-24" />;
  if (!data || data.data.length === 0) return <p className="text-sm text-muted-foreground">Belum ada riwayat alokasi.</p>;
  return (
    <ul className="divide-y">
      {data.data.map((a) => (
        <li key={a.id} className="flex items-center justify-between gap-3 py-2 text-sm">
          <div className="grid min-w-0">
            <span className="truncate">{a.note ?? (a.transaction_id ? 'Dipakai untuk pengeluaran' : a.move_group_id ? 'Dipindahkan' : 'Alokasi')}</span>
            <span className="text-xs text-muted-foreground">{formatDate(a.occurred_on)}</span>
          </div>
          <MoneyText amount={a.amount} tone="signed" className="font-medium" />
        </li>
      ))}
    </ul>
  );
}

function AccountSection({ row, onAction, onReducePick }: {
  row: SavingsAccountSummary;
  onAction: (goal: SavingsGoal, action: GoalAction) => void;
  onReducePick: () => void;
}) {
  return (
    <Card aria-label={row.account.name} role="region">
      <CardHeader>
        <CardDescription>Rekening: {row.account.name}</CardDescription>
        <Totals balance={row.balance} allocated={row.allocated} unallocated={row.unallocated} />
      </CardHeader>
      <CardContent className="grid gap-3">
        {row.overdrawn > 0 && (
          <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <span className="flex items-center gap-2">
              <AlertTriangle className="size-4" aria-hidden /> Tabungan terpakai <MoneyText amount={row.overdrawn} className="font-medium" />
            </span>
            {row.goals.some((g) => g.allocated_amount > 0) && <Button size="sm" variant="outline" onClick={onReducePick}>Kurangi alokasi</Button>}
          </div>
        )}
        {row.goals.length === 0 ? (
          <p className="text-sm text-muted-foreground">Belum ada tujuan di rekening ini.</p>
        ) : (
          <ul className="divide-y">
            {row.goals.map((g) => (
              <GoalCard key={g.id} goal={g} canMove={row.goals.length > 1} onAction={(action) => onAction(g, action)} />
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

/** Tabungan: tujuan per rekening tabungan, alokasi, dan "Gunakan" untuk belanja dari tabungan. */
export function SavingsPage() {
  const timezone = useTimezone();
  const today = todayIn(timezone);
  const { data, isPending } = useSavings();
  const { data: categories } = useCategories();
  const m = useSavingsMutations();
  const [dialog, setDialog] = useState<DialogState>(null);
  const close = () => setDialog(null);

  const rows = data?.accounts ?? [];
  const expenseCategories = (categories ?? []).filter((c) => c.kind === 'expense' && !c.archived_at);

  let title = '';
  let body: React.ReactNode = null;
  if (dialog?.mode === 'new') {
    title = 'Tujuan baru';
    body = (
      <GoalForm
        accounts={rows.map((r) => r.account)}
        onSubmit={async (payload) => { await m.create.mutateAsync(payload); toast.success('Tujuan ditambahkan.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'edit') {
    const { goal } = dialog;
    const account = rows.find((r) => r.account.id === goal.account_id)?.account;
    title = 'Ubah tujuan';
    body = (
      <GoalForm
        initial={goal}
        accounts={account ? [account] : []}
        onSubmit={async ({ name, target_amount, target_date }) => {
          await m.update.mutateAsync({ id: goal.id, body: { name, target_amount, target_date } });
          toast.success('Tujuan diperbarui.');
          close();
        }}
        onAchieve={async () => { await m.achieve.mutateAsync({ id: goal.id, achieved: !goal.achieved_at }); close(); }}
        onArchive={async () => { await m.archive.mutateAsync(goal.id); toast.success('Tujuan diarsip.'); close(); }}
        onDelete={async () => { await m.remove.mutateAsync(goal.id); toast.success('Tujuan dihapus.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'add' || dialog?.mode === 'reduce') {
    const { goal, mode } = dialog;
    title = `${mode === 'add' ? 'Tambah' : 'Kurangi'} alokasi ${goal.name}`;
    body = (
      <AllocateForm
        goals={[goal]} goal={goal} mode={mode} today={today}
        onSubmit={async (goalId, bodyData) => { await m.allocate.mutateAsync({ goalId, body: bodyData }); toast.success('Alokasi disimpan.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'reduce-pick') {
    title = 'Kurangi alokasi';
    body = (
      <AllocateForm
        goals={dialog.goals} mode="reduce" pickGoal today={today}
        onSubmit={async (goalId, bodyData) => { await m.allocate.mutateAsync({ goalId, body: bodyData }); toast.success('Alokasi dikurangi.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'move') {
    const { goal } = dialog;
    const targets = rows.find((r) => r.account.id === goal.account_id)?.goals.filter((g) => g.id !== goal.id) ?? [];
    title = `Pindah alokasi ${goal.name}`;
    body = (
      <MoveForm
        from={goal} targets={targets}
        onSubmit={async (bodyData) => { await m.move.mutateAsync({ goalId: goal.id, body: bodyData }); toast.success('Alokasi dipindahkan.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'use') {
    const { goal } = dialog;
    title = `Gunakan ${goal.name}`;
    body = (
      <UseSavingsForm
        goal={goal} categories={expenseCategories} today={today}
        onSubmit={async (bodyData) => { await m.use.mutateAsync({ goalId: goal.id, body: bodyData }); toast.success('Pengeluaran dicatat.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'history') {
    title = `Riwayat ${dialog.goal.name}`;
    body = <GoalHistory goal={dialog.goal} />;
  }

  return (
    <>
      <PageHeader
        title="Tabungan"
        description={rows.length > 1 && data ? <Totals {...data.totals} /> : undefined}
        actions={rows.length > 0 ? <Button onClick={() => setDialog({ mode: 'new' })}><Plus /> Tujuan baru</Button> : undefined}
      />

      {isPending && <Skeleton className="h-40" />}
      {!isPending && rows.length === 0 && (
        <div className="rounded-lg border border-dashed bg-background p-10 text-center text-sm text-muted-foreground">
          Belum ada rekening tabungan. Tandai rekening sebagai rekening tabungan di halaman{' '}
          <Link to="/accounts" className="font-medium text-foreground underline">Rekening</Link>.
        </div>
      )}

      <div className="grid gap-4">
        {rows.map((row) => (
          <AccountSection
            key={row.account.id}
            row={row}
            onAction={(goal, action) => setDialog({ mode: action, goal })}
            onReducePick={() => setDialog({ mode: 'reduce-pick', goals: row.goals.filter((g) => g.allocated_amount > 0) })}
          />
        ))}
      </div>

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
