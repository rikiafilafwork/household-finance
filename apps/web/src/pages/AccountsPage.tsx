import { AlertTriangle, Archive, Pencil, Plus, Scale } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { Account } from '@/api/types';
import { useTimezone } from '@/auth/useAuth';
import { AccountForm } from '@/components/accounts/AccountForm';
import { ACCOUNT_TYPES, accountTypeGroupLabel, accountTypeIcon } from '@/components/accounts/accountMeta';
import { AdjustBalanceForm } from '@/components/accounts/AdjustBalanceForm';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useAccountMutations } from '@/hooks/useAccountMutations';
import { useAccounts } from '@/hooks/useMoneyData';
import { todayIn } from '@/lib/dates';

type DialogState = { mode: 'new' } | { mode: 'edit'; account: Account } | { mode: 'adjust'; account: Account } | null;

function AccountRow({ account, onEdit, onAdjust }: { account: Account; onEdit: () => void; onAdjust?: () => void }) {
  return (
    <li className="flex items-center justify-between gap-3 py-2.5">
      <div className="grid min-w-0">
        <span className="truncate text-sm font-medium">{account.name}</span>
        {account.balance < 0 && (
          <span className="flex items-center gap-1 text-xs text-amber-700 dark:text-amber-500">
            <AlertTriangle className="size-3" aria-hidden /> Saldo negatif — ada transaksi yang belum dicatat?
          </span>
        )}
      </div>
      <div className="flex items-center gap-1">
        <MoneyText amount={account.balance} tone="balance" className="mr-1 text-sm font-medium" />
        {onAdjust && (
          <Button variant="ghost" size="icon" className="size-8" aria-label={`Sesuaikan saldo ${account.name}`} onClick={onAdjust}>
            <Scale className="size-4" />
          </Button>
        )}
        <Button variant="ghost" size="icon" className="size-8" aria-label={`Ubah ${account.name}`} onClick={onEdit}>
          <Pencil className="size-4" />
        </Button>
      </div>
    </li>
  );
}

/** Akun dikelompokkan per jenis (Rekening, E-wallet, Dompet). Arsip disembunyikan dari pilihan baru. */
export function AccountsPage() {
  const timezone = useTimezone();
  const { data: accounts, isPending } = useAccounts();
  const m = useAccountMutations();
  const [dialog, setDialog] = useState<DialogState>(null);
  const [showArchived, setShowArchived] = useState(false);

  const active = (accounts ?? []).filter((a) => !a.archived_at);
  const archived = (accounts ?? []).filter((a) => a.archived_at);
  const total = active.reduce((sum, a) => sum + a.balance, 0);
  const close = () => setDialog(null);

  let title = '';
  let body: React.ReactNode = null;
  if (dialog?.mode === 'new') {
    title = 'Tambah akun';
    body = <AccountForm onSubmit={async (payload) => { await m.create.mutateAsync(payload); toast.success('Akun ditambahkan.'); close(); }} />;
  } else if (dialog?.mode === 'edit') {
    const { account } = dialog;
    title = 'Ubah akun';
    body = (
      <AccountForm
        initial={account}
        onSubmit={async (body) => { await m.update.mutateAsync({ id: account.id, body }); toast.success('Akun diperbarui.'); close(); }}
        onArchive={async () => {
          await m.archive.mutateAsync({ id: account.id, archived: !account.archived_at });
          toast.success(account.archived_at ? 'Akun aktif lagi.' : 'Akun diarsip. Transaksi lamanya tetap tampil.');
          close();
        }}
        onDelete={async () => { await m.remove.mutateAsync(account.id); toast.success('Akun dihapus.'); close(); }}
      />
    );
  } else if (dialog?.mode === 'adjust') {
    const { account } = dialog;
    title = `Sesuaikan saldo ${account.name}`;
    body = (
      <AdjustBalanceForm
        account={account}
        today={todayIn(timezone)}
        onSubmit={async (body) => {
          const result = await m.adjust.mutateAsync({ id: account.id, body });
          toast.success(result.adjustment ? 'Saldo disesuaikan.' : 'Saldo sudah sesuai.');
          close();
        }}
      />
    );
  }

  return (
    <>
      <PageHeader
        title="Akun"
        description={active.length > 0 ? <>Total saldo <MoneyText amount={total} tone="balance" className="font-medium text-foreground" /></> : undefined}
        actions={<Button onClick={() => setDialog({ mode: 'new' })}><Plus /> Tambah akun</Button>}
      />

      {isPending && <Skeleton className="h-40" />}
      {!isPending && active.length === 0 && (
        <div className="rounded-lg border border-dashed bg-background p-10 text-center text-sm text-muted-foreground">
          Belum ada akun aktif. Tambahkan rekening bank, e-wallet, atau dompet tunai.
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {ACCOUNT_TYPES.map((type) => {
          const list = active.filter((a) => a.type === type);
          if (list.length === 0) return null;
          const Icon = accountTypeIcon[type];
          return (
            <Card key={type} aria-label={accountTypeGroupLabel[type]} role="region">
              <CardHeader>
                <CardDescription className="flex items-center gap-2"><Icon className="size-4" aria-hidden /> {accountTypeGroupLabel[type]}</CardDescription>
                <CardTitle className="text-xl"><MoneyText amount={list.reduce((s, a) => s + a.balance, 0)} tone="balance" /></CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="divide-y">
                  {list.map((a) => (
                    <AccountRow key={a.id} account={a} onEdit={() => setDialog({ mode: 'edit', account: a })} onAdjust={() => setDialog({ mode: 'adjust', account: a })} />
                  ))}
                </ul>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {archived.length > 0 && (
        <div className="mt-6">
          <Button variant="ghost" size="sm" onClick={() => setShowArchived((v) => !v)} aria-expanded={showArchived}>
            <Archive /> {showArchived ? 'Sembunyikan' : 'Tampilkan'} akun diarsip ({archived.length})
          </Button>
          {showArchived && (
            <ul className="mt-2 divide-y rounded-lg border bg-background px-4 opacity-80">
              {archived.map((a) => <AccountRow key={a.id} account={a} onEdit={() => setDialog({ mode: 'edit', account: a })} />)}
            </ul>
          )}
        </div>
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
