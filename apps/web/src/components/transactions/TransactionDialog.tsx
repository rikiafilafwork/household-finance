import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { transfersApi } from '@/api/transactions';
import { qk } from '@/api/keys';
import type { Account, Category, Transaction } from '@/api/types';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useTransactionMutations } from '@/hooks/useTransactionMutations';
import { formatDate } from '@/lib/dates';
import { MoneyText } from '@/components/money/MoneyText';
import { TransactionForm } from './TransactionForm';
import { TransferForm } from './TransferForm';

export type DialogState =
  | { mode: 'new'; kind: 'expense' | 'income' | 'transfer'; preset?: { category_id?: Category['id']; occurred_on?: string }; title?: string }
  | { mode: 'edit'; transaction: Transaction }
  | null;

const titles = { expense: 'Catat pengeluaran', income: 'Catat pemasukan', transfer: 'Transfer antar rekening' } as const;

export function TransactionDialog({ state, onClose, accounts, categories, today }: {
  state: DialogState;
  onClose: () => void;
  accounts: Account[];
  categories: Category[];
  today: string;
}) {
  const m = useTransactionMutations();
  const done = (message: string) => {
    toast.success(message);
    onClose();
  };

  const editing = state?.mode === 'edit' ? state.transaction : null;
  const transferQuery = useQuery({
    queryKey: qk.transfer(editing?.transfer_group_id ?? ''),
    queryFn: () => transfersApi.get(editing!.transfer_group_id!),
    enabled: !!editing?.transfer_group_id,
    retry: false,
  });

  let title = '';
  let body: React.ReactNode = null;

  if (state?.mode === 'new' && state.kind !== 'transfer') {
    title = state.title ?? titles[state.kind];
    body = (
      <TransactionForm
        key={state.kind}
        defaultType={state.kind}
        preset={state.preset}
        accounts={accounts}
        categories={categories}
        today={today}
        submitLabel="Simpan"
        onSubmit={async (payload, client_id) => {
          await m.create.mutateAsync({ ...payload, client_id });
          done(payload.type === 'expense' ? 'Pengeluaran dicatat.' : 'Pemasukan dicatat.');
        }}
      />
    );
  } else if (state?.mode === 'new') {
    title = titles.transfer;
    body = (
      <TransferForm
        accounts={accounts}
        today={today}
        onSubmit={async (payload, client_id) => {
          await m.createTransfer.mutateAsync({ ...payload, client_id });
          done('Transfer dicatat.');
        }}
      />
    );
  } else if (editing && (editing.type === 'income' || editing.type === 'expense')) {
    title = 'Ubah transaksi';
    body = (
      <TransactionForm
        initial={editing}
        accounts={accounts}
        categories={categories}
        today={today}
        onSubmit={async (payload) => {
          await m.update.mutateAsync({ id: editing.id, body: payload });
          done('Transaksi diperbarui.');
        }}
        onDelete={async () => {
          await m.remove.mutateAsync(editing.id);
          done('Transaksi dihapus.');
        }}
      />
    );
  } else if (editing && editing.type === 'transfer') {
    title = 'Transfer';
    if (transferQuery.isPending) body = <Skeleton className="h-48" />;
    else if (transferQuery.isError) {
      body = <ReadOnly tx={editing} accounts={accounts} categories={categories} note="Transfer tidak bisa dimuat. Coba lagi sebentar." />;
    } else {
      const transfer = transferQuery.data;
      body = (
        <TransferForm
          initial={transfer}
          accounts={accounts}
          today={today}
          onSubmit={async (payload) => {
            await m.updateTransfer.mutateAsync({ groupId: transfer.transfer_group_id, body: payload });
            done('Transfer diperbarui.');
          }}
          onDelete={async () => {
            await m.removeTransfer.mutateAsync(transfer.transfer_group_id);
            done('Transfer dihapus.');
          }}
        />
      );
    }
  } else if (editing) {
    title = 'Penyesuaian saldo';
    body = <ReadOnly tx={editing} accounts={accounts} categories={categories} note="Penyesuaian saldo dibuat dari halaman Rekening (Sesuaikan saldo) dan tidak diubah di sini. Hapus untuk membatalkannya." onDelete={async () => {
      await m.remove.mutateAsync(editing.id);
      done('Penyesuaian saldo dibatalkan.');
    }} />;
  }

  return (
    <Dialog open={state !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{title}</DialogDescription>
        </DialogHeader>
        {body}
      </DialogContent>
    </Dialog>
  );
}

function ReadOnly({ tx, accounts, categories, note, onDelete }: { tx: Transaction; accounts: Account[]; categories: Category[]; note: string; onDelete?: () => Promise<unknown> }) {
  const account = accounts.find((a) => a.id === tx.account_id);
  const category = tx.category ?? categories.find((c) => c.id === tx.category_id);
  return (
    <div className="grid gap-3 text-sm">
      <MoneyText amount={tx.amount} tone="signed" className="text-2xl font-semibold" />
      <dl className="grid grid-cols-[6rem_1fr] gap-y-1.5">
        <dt className="text-muted-foreground">Tanggal</dt><dd>{formatDate(tx.occurred_on, { dateStyle: 'long' })}</dd>
        <dt className="text-muted-foreground">Rekening</dt><dd>{account?.name ?? '—'}</dd>
        {category && (<><dt className="text-muted-foreground">Kategori</dt><dd>{category.name}</dd></>)}
        {tx.note && (<><dt className="text-muted-foreground">Catatan</dt><dd>{tx.note}</dd></>)}
      </dl>
      <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{note}</p>
      {onDelete && <ConfirmDelete onDelete={onDelete} />}
    </div>
  );
}

/** Hapus dua langkah, sama seperti di form transaksi. */
function ConfirmDelete({ onDelete }: { onDelete: () => Promise<unknown> }) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  if (!confirming) return <Button type="button" variant="outline" className="text-destructive" onClick={() => setConfirming(true)}>Hapus</Button>;
  return (
    <div className="flex gap-2">
      <Button type="button" variant="destructive" disabled={busy} onClick={async () => { setBusy(true); try { await onDelete(); } finally { setBusy(false); } }}>Ya, hapus</Button>
      <Button type="button" variant="ghost" onClick={() => setConfirming(false)}>Batal</Button>
    </div>
  );
}
